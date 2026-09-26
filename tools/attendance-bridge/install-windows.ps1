param(
    [Parameter(Mandatory=$true)][string]$Server,
    [Parameter(Mandatory=$true)][string]$Bridge,
    [Parameter(Mandatory=$true)][string]$Token,
    [string]$InstallDir = "$env:ProgramData\BusinessOS\AttendanceBridge"
)

$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null

$SourceDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Copy-Item "$SourceDir\bridge.py" "$InstallDir\bridge.py" -Force
Copy-Item "$SourceDir\requirements.txt" "$InstallDir\requirements.txt" -Force

$Python = Get-Command py -ErrorAction SilentlyContinue
if (-not $Python) {
    throw "Python launcher (py.exe) was not found. Install Python 3.11+ first."
}

& py -3 -m venv "$InstallDir\venv"
& "$InstallDir\venv\Scripts\python.exe" -m pip install --upgrade pip
& "$InstallDir\venv\Scripts\pip.exe" install -r "$InstallDir\requirements.txt"

@{
    server = $Server
    bridge = $Bridge
    token = $Token
} | ConvertTo-Json | Set-Content "$InstallDir\bridge.json" -Encoding UTF8

$Action = New-ScheduledTaskAction -Execute "$InstallDir\venv\Scripts\python.exe" -Argument ('"' + "$InstallDir\bridge.py" + '" --config "' + "$InstallDir\bridge.json" + '"')
$Trigger = New-ScheduledTaskTrigger -AtStartup
$Settings = New-ScheduledTaskSettingsSet -RestartCount 10 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName "BusinessOS Attendance Bridge" -Action $Action -Trigger $Trigger -Settings $Settings -Principal $Principal -Force | Out-Null
Start-ScheduledTask -TaskName "BusinessOS Attendance Bridge"
Write-Host "BusinessOS Attendance Bridge installed and started."
