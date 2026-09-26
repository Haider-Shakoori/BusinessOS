#!/usr/bin/env python3

import importlib.util
import unittest
from pathlib import Path
from zoneinfo import ZoneInfo

MODULE_PATH = Path(__file__).with_name("bridge.py")
SPEC = importlib.util.spec_from_file_location("businessos_attendance_bridge", MODULE_PATH)
BRIDGE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(BRIDGE)


class AttendanceBridgeTest(unittest.TestCase):
    def test_hikvision_event_normalization_is_stable_and_utc(self):
        event = {
            "serialNo": 991,
            "major": 5,
            "minor": 75,
            "time": "2026-09-26T08:15:30+04:30",
            "employeeNoString": "EMP-017",
            "cardNo": "10017",
            "doorNo": 1,
            "currentVerifyMode": "face",
            "attendanceStatus": "checkIn",
        }

        first = BRIDGE.normalize_hikvision_event(
            event,
            "device-uuid",
            ZoneInfo("Asia/Kabul"),
        )
        second = BRIDGE.normalize_hikvision_event(
            event,
            "device-uuid",
            ZoneInfo("Asia/Kabul"),
        )

        self.assertIsNotNone(first)
        self.assertEqual(first, second)
        self.assertEqual(first["device_user_id"], "EMP-017")
        self.assertEqual(first["verification_type"], "face")
        self.assertEqual(first["punch_type"], "checkIn")
        self.assertEqual(first["timestamp"], "2026-09-26T03:45:30+00:00")
        self.assertEqual(len(first["external_id"]), 64)

    def test_naive_hikvision_timestamp_uses_device_timezone(self):
        event = {
            "time": "2026-09-26 08:00:00",
            "employeeNoString": "9",
            "minor": 9,
            "currentVerifyMode": "card",
        }

        record = BRIDGE.normalize_hikvision_event(
            event,
            "device-uuid",
            ZoneInfo("Asia/Kabul"),
        )

        self.assertEqual(record["timestamp"], "2026-09-26T03:30:00+00:00")
        self.assertEqual(record["verification_type"], "card")

    def test_hikvision_event_without_person_or_time_is_ignored(self):
        self.assertIsNone(
            BRIDGE.normalize_hikvision_event(
                {"time": "2026-09-26T08:00:00+04:30"},
                "device-uuid",
                ZoneInfo("Asia/Kabul"),
            )
        )
        self.assertIsNone(
            BRIDGE.normalize_hikvision_event(
                {"employeeNoString": "17"},
                "device-uuid",
                ZoneInfo("Asia/Kabul"),
            )
        )

    def test_device_base_url_infers_scheme_and_port(self):
        self.assertEqual(
            BRIDGE.device_base_url({"host": "192.168.1.25", "port": 80}),
            "http://192.168.1.25",
        )
        self.assertEqual(
            BRIDGE.device_base_url({"host": "192.168.1.25", "port": 8443}),
            "https://192.168.1.25:8443",
        )
        self.assertEqual(
            BRIDGE.device_base_url({"base_url": "https://attendance.local/"}),
            "https://attendance.local",
        )


if __name__ == "__main__":
    unittest.main()
