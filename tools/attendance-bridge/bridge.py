#!/usr/bin/env python3
"""BusinessOS Attendance Bridge."""

from __future__ import annotations

import argparse
import hashlib
import json
import socket
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

VERSION = "1.0.0"
DISCOVERY_PORTS = [4370, 5010, 51211, 80, 443, 8000, 8080, 8443, 3000, 3002, 9000]
HTTP_PORTS = {80: "http", 443: "https", 8000: "http", 8080: "http", 8443: "https", 3000: "http", 3002: "https", 9000: "http"}


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class BridgeClient:
    def __init__(self, server: str, bridge: str, token: str, state_path: Path, interval: int = 5):
        self.server = server.rstrip("/")
        self.bridge = bridge
        self.token = token
        self.state_path = state_path
        self.interval = max(2, interval)
        self.state = self._load_state()
        self.ssl_context = ssl.create_default_context()

    def api(self, path: str, method: str = "GET", payload: dict[str, Any] | None = None) -> dict[str, Any]:
        url = f"{self.server}/api/attendance/bridge/{self.bridge}{path}"
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            url,
            data=data,
            method=method,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.token}",
                "User-Agent": f"BusinessOS-Attendance-Bridge/{VERSION}",
            },
        )
        with urllib.request.urlopen(request, timeout=20, context=self.ssl_context) as response:
            return json.loads(response.read().decode("utf-8"))

    def heartbeat(self) -> None:
        self.api("/heartbeat", "POST", {
            "hostname": socket.gethostname(),
            "local_ips": local_ips(),
            "version": VERSION,
        })

    def process_jobs(self) -> None:
        response = self.api("/jobs")
        for job in response.get("jobs", []):
            job_id = str(job.get("id", ""))
            if not job_id:
                continue
            try:
                if job.get("type") == "detect":
                    result = discover_ip(str(job.get("payload", {}).get("ip", "")))
                    self.api(f"/jobs/{job_id}/result", "POST", {"ok": True, **result})
                else:
                    self.api(f"/jobs/{job_id}/result", "POST", {
                        "ok": False,
                        "error": f"Unsupported bridge job: {job.get('type')}",
                    })
            except Exception as exc:
                self.api(f"/jobs/{job_id}/result", "POST", {"ok": False, "error": str(exc)[:4000]})

    def sync_devices(self) -> None:
        response = self.api("/devices")
        if not response.get("attendance_enabled"):
            return
        for device in response.get("devices", []):
            if device.get("connection_type") != "zkteco_tcp":
                continue
            try:
                self.sync_zkteco(device)
            except Exception as exc:
                log(f"{device.get('name', device.get('id'))}: sync failed: {exc}")

    def sync_zkteco(self, device: dict[str, Any]) -> None:
        try:
            from zk import ZK
        except ImportError:
            log("pyzk is not installed; ZKTeco log synchronization is disabled.")
            return

        host = str(device.get("host") or "").strip()
        if not host:
            return

        port = int(device.get("port") or 4370)
        timeout = int(device.get("timeout_seconds") or 8)
        password_raw = str(device.get("password") or "0").strip()
        password = int(password_raw) if password_raw.isdigit() else 0
        device_id = str(device["id"])

        zk = ZK(host, port=port, timeout=timeout, password=password, force_udp=False, ommit_ping=True)
        connection = None
        try:
            connection = zk.connect()
            punches = connection.get_attendance() or []
        finally:
            if connection is not None:
                try:
                    connection.disconnect()
                except Exception:
                    pass

        last_iso = self.state.get("devices", {}).get(device_id, {}).get("last_punch")
        if last_iso:
            try:
                cutoff = datetime.fromisoformat(last_iso.replace("Z", "+00:00"))
            except ValueError:
                cutoff = datetime.now(timezone.utc) - timedelta(days=30)
        else:
            cutoff = datetime.now(timezone.utc) - timedelta(days=30)

        records: list[dict[str, Any]] = []
        newest = cutoff
        for punch in punches:
            timestamp = getattr(punch, "timestamp", None)
            if not isinstance(timestamp, datetime):
                continue
            if timestamp.tzinfo is None:
                timestamp = timestamp.astimezone()
            timestamp_utc = timestamp.astimezone(timezone.utc)
            if timestamp_utc <= cutoff:
                continue

            user_id = str(getattr(punch, "user_id", getattr(punch, "uid", "")))
            status = str(getattr(punch, "status", ""))
            punch_type = str(getattr(punch, "punch", ""))
            external_id = hashlib.sha256(
                f"{device_id}|{user_id}|{timestamp_utc.isoformat()}|{status}|{punch_type}".encode("utf-8")
            ).hexdigest()
            records.append({
                "external_id": external_id,
                "device_user_id": user_id,
                "timestamp": timestamp_utc.isoformat(),
                "punch_type": punch_type or status or None,
                "verification_type": "unknown",
            })
            newest = max(newest, timestamp_utc)

        records.sort(key=lambda item: item["timestamp"])
        for offset in range(0, len(records), 500):
            self.api(f"/devices/{device_id}/records", "POST", {"records": records[offset:offset + 500]})

        if records:
            self.state.setdefault("devices", {}).setdefault(device_id, {})["last_punch"] = newest.isoformat()
            self._save_state()
            log(f"{device.get('name', device_id)}: synced {len(records)} punch(es).")

    def run(self) -> None:
        log(f"BusinessOS Attendance Bridge {VERSION} starting on {socket.gethostname()}.")
        next_device_sync = 0.0
        while True:
            try:
                self.heartbeat()
                self.process_jobs()
                now = time.time()
                if now >= next_device_sync:
                    self.sync_devices()
                    next_device_sync = now + 30
            except urllib.error.HTTPError as exc:
                log(f"Cloud API HTTP {exc.code}: {exc.reason}")
            except urllib.error.URLError as exc:
                log(f"Cloud API unavailable: {exc.reason}")
            except Exception as exc:
                log(f"Bridge loop error: {exc}")
            time.sleep(self.interval)

    def _load_state(self) -> dict[str, Any]:
        try:
            return json.loads(self.state_path.read_text(encoding="utf-8"))
        except Exception:
            return {"devices": {}}

    def _save_state(self) -> None:
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.state_path.with_suffix(".tmp")
        temporary.write_text(json.dumps(self.state, indent=2), encoding="utf-8")
        temporary.replace(self.state_path)


def local_ips() -> list[str]:
    values: set[str] = set()
    try:
        for info in socket.getaddrinfo(socket.gethostname(), None):
            ip = info[4][0]
            if ip and not ip.startswith("127.") and ip != "::1":
                values.add(ip)
    except OSError:
        pass
    return sorted(values)


def tcp_open(ip: str, port: int, timeout: float = 0.4) -> bool:
    try:
        with socket.create_connection((ip, port), timeout=timeout):
            return True
    except OSError:
        return False


def http_probe(ip: str, port: int, scheme: str, path: str) -> dict[str, Any] | None:
    host = f"[{ip}]" if ":" in ip else ip
    default_port = (scheme == "http" and port == 80) or (scheme == "https" and port == 443)
    base = f"{scheme}://{host}" + ("" if default_port else f":{port}")
    request = urllib.request.Request(base + path, method="GET", headers={
        "User-Agent": f"BusinessOS-Attendance-Bridge/{VERSION}",
        "Accept": "*/*",
    })
    opener = urllib.request.build_opener(NoRedirectHandler())
    try:
        response = opener.open(request, timeout=1.25)
        body = response.read(16384).decode("utf-8", errors="ignore")
        headers = " ".join([
            response.headers.get("Server", ""),
            response.headers.get("WWW-Authenticate", ""),
            response.headers.get("X-Powered-By", ""),
        ])
        return {"scheme": scheme, "port": port, "status": response.status, "text": (headers + " " + body)[:20000]}
    except urllib.error.HTTPError as exc:
        body = exc.read(16384).decode("utf-8", errors="ignore")
        headers = " ".join([
            exc.headers.get("Server", ""),
            exc.headers.get("WWW-Authenticate", ""),
            exc.headers.get("X-Powered-By", ""),
        ])
        return {"scheme": scheme, "port": port, "status": exc.code, "text": (headers + " " + body)[:20000]}
    except Exception:
        return None


def discover_ip(ip: str) -> dict[str, Any]:
    if not ip:
        raise ValueError("Discovery job is missing a device IP.")

    open_ports = [port for port in DISCOVERY_PORTS if tcp_open(ip, port)]
    evidence: list[dict[str, Any]] = []
    for port in open_ports:
        if port not in HTTP_PORTS:
            continue
        scheme = HTTP_PORTS[port]
        root = http_probe(ip, port, scheme, "/")
        if root:
            evidence.append(root)
        isapi = http_probe(ip, port, scheme, "/ISAPI/System/deviceInfo")
        if isapi:
            text = str(isapi.get("text", "")).lower()
            isapi["isapi"] = (
                int(isapi.get("status", 0)) in (200, 401, 403)
                and ("hikvision" in text or "isapi" in text)
            )
            evidence.append(isapi)

    return {"open_ports": open_ports, "http_evidence": evidence}


def log(message: str) -> None:
    stamp = datetime.now().astimezone().isoformat(timespec="seconds")
    print(f"[{stamp}] {message}", flush=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="BusinessOS Attendance Bridge")
    parser.add_argument("--config", default="bridge.json")
    parser.add_argument("--server")
    parser.add_argument("--bridge")
    parser.add_argument("--token")
    parser.add_argument("--interval", type=int, default=5)
    args = parser.parse_args()

    config_path = Path(args.config).resolve()
    config = json.loads(config_path.read_text(encoding="utf-8")) if config_path.exists() else {}
    server = args.server or config.get("server")
    bridge = args.bridge or config.get("bridge")
    token = args.token or config.get("token")

    if not server or not bridge or not token:
        print("server, bridge and token are required (CLI arguments or bridge.json).", file=sys.stderr)
        return 2

    state_path = config_path.with_name("bridge-state.json")
    BridgeClient(str(server), str(bridge), str(token), state_path, args.interval).run()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
