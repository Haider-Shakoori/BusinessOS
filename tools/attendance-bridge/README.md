# BusinessOS Attendance Bridge

The bridge runs inside a customer's LAN so the cloud-hosted BusinessOS ERP can work with attendance devices that use private addresses such as 192.168.x.x or 10.x.x.x.

## What it does

- Maintains outbound HTTPS communication with BusinessOS. No inbound router port is required.
- Sends heartbeats so BusinessOS knows whether the bridge is online.
- Receives device IP discovery jobs and probes the device locally.
- Detects the common attendance ports used by the BusinessOS discovery engine.
- Synchronizes ZKTeco-compatible devices over TCP/4370 with the optional pyzk adapter.
- Uploads normalized punches through the authenticated bridge API.
- Keeps a local high-water mark while the server independently de-duplicates events.

## Windows installation

1. Open BusinessOS > Settings > Attendance Devices > Local Attendance Bridge.
2. Create a bridge and copy its UUID and token.
3. Copy this folder to a Windows PC that can reach the attendance machine.
4. Open PowerShell as Administrator.
5. Run: `.\\install-windows.ps1 -Server "https://erp.businessos.af" -Bridge "UUID" -Token "TOKEN"`

The installer creates a Python virtual environment under C:\\ProgramData\\BusinessOS\\AttendanceBridge, installs the ZK adapter, writes bridge.json, and creates a SYSTEM scheduled task that starts at Windows boot.

## Security

The bridge only initiates outbound HTTPS calls. Its token can be regenerated in BusinessOS at any time. Device passwords remain encrypted in BusinessOS and are only returned to the authenticated bridge assigned to the device.

## Adapter coverage

The bridge can discover all device families configured by BusinessOS. Native log pulling in this first bridge release is enabled for ZKTeco-compatible TCP/4370 devices, including many eSSL/OEM variants. Suprema, Hikvision, Dahua, Anviz, Matrix and other families continue to use their API/push/vendor-server profiles until their model-specific native adapters are enabled.
