@echo off
rem Run the agent in a visible window (for troubleshooting). Close the window to stop it.
cd /d "%~dp0"
.venv\Scripts\python.exe -m svagent
pause
