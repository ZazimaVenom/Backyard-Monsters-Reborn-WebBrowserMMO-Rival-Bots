@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\Build-Client.ps1" %*
set "BUILD_RESULT=%ERRORLEVEL%"
if not "%BUILD_RESULT%"=="0" (
  echo.
  echo Build failed. Read the error above and the build log in the logs folder.
) else (
  echo.
  echo Build complete. The output folder is shown above.
)
pause
exit /b %BUILD_RESULT%
