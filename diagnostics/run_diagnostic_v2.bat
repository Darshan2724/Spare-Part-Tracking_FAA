@echo off
title SpareTrack Server Diagnostic V2 (Read-Only)
cd /d "%~dp0"
echo ================================================================
echo    SPARETRACK SERVER PERFORMANCE DIAGNOSTIC - VERSION 2 (V2)
echo ================================================================
echo.
echo  Safety Note: This script is 100%% READ-ONLY.
echo  It does NOT delete, edit, or modify any database data.
echo  The SpareTrack system continues running normally.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0server_diagnostic_v2.ps1"
echo.
echo ================================================================
echo Diagnostic V2 complete! Please share the generated txt log file.
echo ================================================================
pause
