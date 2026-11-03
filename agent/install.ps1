# Installs the agent on Windows: Python environment, dependencies, and a task that starts it when you sign in.
# Run from PowerShell in this folder:   powershell -ExecutionPolicy Bypass -File .\install.ps1
$ErrorActionPreference = "Stop"
$dir = $PSScriptRoot
Set-Location $dir

if (-not (Get-Command python -ErrorAction SilentlyContinue)) {
    throw "Python 3.10 or newer is required. Install it from python.org (tick 'Add python.exe to PATH') and run this again."
}
if (-not (Test-Path "$dir\.venv")) { python -m venv "$dir\.venv" }
& "$dir\.venv\Scripts\python.exe" -m pip install --upgrade pip | Out-Null
& "$dir\.venv\Scripts\python.exe" -m pip install -r "$dir\requirements.txt"

if (-not (Test-Path "$dir\.env")) {
    Copy-Item "$dir\.env.example" "$dir\.env"
    Write-Host "Created .env. Fill it in, save, and close Notepad to continue."
    Start-Process notepad "$dir\.env" -Wait
}

Write-Host "Running checks..."
& "$dir\.venv\Scripts\python.exe" -m svagent check

# OBS needs a signed-in desktop, so the agent runs as a logon task for this user, not as a background service.
$action = New-ScheduledTaskAction -Execute "$dir\.venv\Scripts\pythonw.exe" -Argument "-m svagent" -WorkingDirectory $dir
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
Register-ScheduledTask -TaskName "SermonVideoAgent" -Action $action -Trigger $trigger -Settings $settings `
    -Description "Scheduled Video Recorder agent" -Force | Out-Null
Start-ScheduledTask -TaskName "SermonVideoAgent"
Write-Host "Done. The agent is running and will start at every sign-in. Log: $dir\agent.log"
Write-Host "Reminder: turn on automatic Windows sign-in and start OBS at sign-in (or let the agent start it)."
