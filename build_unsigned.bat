@echo off
setlocal enabledelayedexpansion

rem Determine script directory (no trailing backslash)
set "SCRIPT_DIR=%~dp0"
if "%SCRIPT_DIR:~-1%"=="\" set "SCRIPT_DIR=%SCRIPT_DIR:~0,-1%"

echo.
echo = Building (Windows wrapper) =
echo This wrapper will run the existing build_unsigned.sh inside WSL if available.
echo.

where wsl.exe >nul 2>&1
if errorlevel 1 (
  echo WSL not found.
  echo xcodebuild and the iOS build tools require macOS.
  echo Options:
  echo  - Run this repository on a macOS machine and execute build_unsigned.sh
  echo  - Install WSL and a Linux distro, then re-run this script (WSL must include bash)
  echo  - Or run the commands manually on macOS
  exit /b 127
)

for /f "usebackq delims=" %%P in (`wsl wslpath -a "%SCRIPT_DIR%"`) do set "WSLROOT=%%P"

echo Running build_unsigned.sh inside WSL at "%WSLROOT%"
wsl bash -lc "cd '%WSLROOT%' && ./build_unsigned.sh"

endlocal
