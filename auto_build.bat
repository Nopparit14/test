@echo off
setlocal
cd /d "%~dp0"
title Auto Build OGIOS

echo ========================================================
echo          OGIOS Auto Build and Push Launcher
echo ========================================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0auto_build.ps1"

echo.
echo Press any key to exit...
pause >nul
