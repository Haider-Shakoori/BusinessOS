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
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

VERSION = "1.1.0"
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
            connection_type = str(device.get("connection_type") or "")
            try:
                if connection_type == "zkteco_tcp":
                    self.sync_zkteco(device)
                elif connection_type == "isapi":
                    self.sync_hikvision_isapi(device)
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

    def sync_hikvision_isapi(self, device: dict[str, Any]) -> None:
        device_id = str(device["id"])
        base_url = device_base_url(device)
        username = str(device.get("username") or "").strip()
        password = str(device.get("password") or "")
        timeout = int(device.get("timeout_seconds") or 8)
        config = device.get("connection_config") if isinstance(device.get("connection_config"), dict) else {}

        if not base_url or not username:
            log(f"{device.get('name', device_id)}: Hikvision ISAPI requires base URL/host and username.")
            return

        page_size = max(1, min(2000, int(config.get("page_size") or 200)))
        overlap_minutes = max(1, min(120, int(config.get("overlap_minutes") or 5)))
        major = int(config.get("major") if config.get("major") is not None else 0)
        minor = int(config.get("minor") if config.get("minor") is not None else 0)
        cutoff = self._device_cutoff(device_id, overlap_minutes)
        end_utc = datetime.now(timezone.utc)
        device_tz = resolve_timezone(str(device.get("timezone") or "UTC"))
        search_id = hashlib.sha256(
            f"{device_id}|{int(end_utc.timestamp())}".encode("utf-8")
        ).hexdigest()[:32]
        opener = hikvision_opener(
            base_url,
            username,
            password,
            bool(device.get("tls_verify", True)),
        )

        records: list[dict[str, Any]] = []
        newest = cutoff
        position = 0
        max_pages = 500

        for _ in range(max_pages):
            payload = {
                "AcsEventCond": {
                    "searchID": search_id,
                    "searchResultPosition": position,
                    "maxResults": page_size,
                    "major": major,
                    "minor": minor,
                    "startTime": cutoff.astimezone(device_tz).isoformat(timespec="seconds"),
                    "endTime": end_utc.astimezone(device_tz).isoformat(timespec="seconds"),
                }
            }
            response = http_json(
                opener,
                f"{base_url}/ISAPI/AccessControl/AcsEvent?format=json",
                payload,
                timeout,
            )
            acs_event = response.get("AcsEvent") if isinstance(response, dict) else None
            if not isinstance(acs_event, dict):
                raise RuntimeError("Hikvision ISAPI response did not contain AcsEvent.")

            items = acs_event.get("InfoList")
            if not isinstance(items, list) or not items:
                break

            for item in items:
                if not isinstance(item, dict):
                    continue
                record = normalize_hikvision_event(item, device_id, device_tz)
                if record is None:
                    continue
                timestamp_utc = datetime.fromisoformat(
                    record["timestamp"].replace("Z", "+00:00")
                )
                if timestamp_utc < cutoff:
                    continue
                records.append(record)
                newest = max(newest, timestamp_utc)

            position += len(items)
            total = int(acs_event.get("totalMatches") or position)
            status = str(acs_event.get("responseStatusStrg") or "").upper()
            if position >= total or status in {"NO MATCH", "NOMATCH"}:
                break

        self._upload_records(device, records, newest)
        if records:
            log(f"{device.get('name', device_id)}: synced {len(records)} Hikvision event(s).")

    def _device_cutoff(self, device_id: str, overlap_minutes: int = 5) -> datetime:
        last_iso = self.state.get("devices", {}).get(device_id, {}).get("last_punch")
        if last_iso:
            try:
                last = datetime.fromisoformat(str(last_iso).replace("Z", "+00:00"))
                if last.tzinfo is None:
                    last = last.replace(tzinfo=timezone.utc)
                return last.astimezone(timezone.utc) - timedelta(minutes=overlap_minutes)
            except ValueError:
                pass
        return datetime.now(timezone.utc) - timedelta(days=30)

    def _upload_records(
        self,
        device: dict[str, Any],
        records: list[dict[str, Any]],
        newest: datetime,
    ) -> None:
        if not records:
            return

        device_id = str(device["id"])
        unique = {record["external_id"]: record for record in records}
        ordered = sorted(unique.values(), key=lambda item: item["timestamp"])

        for offset in range(0, len(ordered), 500):
            self.api(
                f"/devices/{device_id}/records",
                "POST",
                {"records": ordered[offset:offset + 500]},
            )

        current = self.state.get("devices", {}).get(device_id, {}).get("last_punch")
        current_dt = None
        if current:
            try:
                current_dt = datetime.fromisoformat(str(current).replace("Z", "+00:00"))
            except ValueError:
                current_dt = None
        if current_dt is None or newest > current_dt.astimezone(timezone.utc):
            self.state.setdefault("devices", {}).setdefault(device_id, {})["last_punch"] = newest.isoformat()
            self._save_state()

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


def resolve_timezone(name: str):
    try:
        return ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError):
        return timezone.utc


def device_base_url(device: dict[str, Any]) -> str:
    configured = str(device.get("base_url") or "").strip().rstrip("/")
    if configured:
        return configured

    host = str(device.get("host") or "").strip()
    if not host:
        return ""

    port = int(device.get("port") or 80)
    scheme = "https" if port in (443, 8443) else "http"
    default_port = (scheme == "http" and port == 80) or (scheme == "https" and port == 443)
    return f"{scheme}://{host}" + ("" if default_port else f":{port}")


def hikvision_opener(
    base_url: str,
    username: str,
    password: str,
    tls_verify: bool,
):
    password_manager = urllib.request.HTTPPasswordMgrWithDefaultRealm()
    password_manager.add_password(None, base_url, username, password)
    handlers: list[Any] = [
        urllib.request.HTTPDigestAuthHandler(password_manager),
        urllib.request.HTTPBasicAuthHandler(password_manager),
    ]

    if base_url.lower().startswith("https://"):
        context = ssl.create_default_context() if tls_verify else ssl._create_unverified_context()
        handlers.append(urllib.request.HTTPSHandler(context=context))

    return urllib.request.build_opener(*handlers)


def http_json(
    opener,
    url: str,
    payload: dict[str, Any],
    timeout: int,
) -> dict[str, Any]:
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": f"BusinessOS-Attendance-Bridge/{VERSION}",
        },
    )
    with opener.open(request, timeout=timeout) as response:
        body = response.read().decode("utf-8", errors="replace")
    decoded = json.loads(body)
    if not isinstance(decoded, dict):
        raise RuntimeError("Device returned a non-object JSON response.")
    return decoded


def normalize_hikvision_event(
    event: dict[str, Any],
    device_id: str,
    device_tz,
) -> dict[str, Any] | None:
    raw_time = str(event.get("time") or event.get("dateTime") or "").strip()
    user_id = str(
        event.get("employeeNoString")
        or event.get("employeeNo")
        or event.get("cardNo")
        or ""
    ).strip()
    if not raw_time or not user_id:
        return None

    try:
        occurred = datetime.fromisoformat(raw_time.replace("Z", "+00:00"))
    except ValueError:
        return None
    if occurred.tzinfo is None:
        occurred = occurred.replace(tzinfo=device_tz)
    occurred_utc = occurred.astimezone(timezone.utc)

    verification_raw = str(
        event.get("currentVerifyMode")
        or event.get("verifyMode")
        or event.get("attendanceStatus")
        or ""
    ).lower()
    if "face" in verification_raw:
        verification = "face"
    elif "finger" in verification_raw:
        verification = "fingerprint"
    elif "card" in verification_raw:
        verification = "card"
    elif "password" in verification_raw or "pin" in verification_raw:
        verification = "pin"
    else:
        verification = "unknown"

    punch_type = str(
        event.get("attendanceStatus")
        or event.get("statusValue")
        or event.get("minor")
        or ""
    ).strip()

    fingerprint = "|".join([
        device_id,
        str(event.get("serialNo") or ""),
        user_id,
        occurred_utc.isoformat(),
        str(event.get("major") or ""),
        str(event.get("minor") or ""),
        str(event.get("doorNo") or ""),
        str(event.get("cardNo") or ""),
    ])
    return {
        "external_id": hashlib.sha256(fingerprint.encode("utf-8")).hexdigest(),
        "device_user_id": user_id,
        "timestamp": occurred_utc.isoformat(),
        "punch_type": punch_type or None,
        "verification_type": verification,
    }


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
    handlers = [NoRedirectHandler()]
    if scheme == "https":
        handlers.append(urllib.request.HTTPSHandler(context=ssl._create_unverified_context()))
    opener = urllib.request.build_opener(*handlers)
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
