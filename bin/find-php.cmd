@echo off
rem Called by the Windows launchers. Return the selected executable in BMR_PHP.
rem Prefer an explicit override, then this installation, then the system PATH.
set "BMR_PHP="
if defined PHP_EXE (
  if exist "%PHP_EXE%" (
    set "BMR_PHP=%PHP_EXE%"
    exit /b 0
  )
  echo PHP_EXE points to a file that does not exist. Correct it and try again.
  exit /b 1
)
for %%D in ("%~dp0..") do set "BMR_SCAN=%%~fD"
set "BMR_SCAN_COUNT=0"
:scan_parent
if exist "%BMR_SCAN%\php\php.exe" (
  set "BMR_PHP=%BMR_SCAN%\php\php.exe"
  goto found
)
set /a BMR_SCAN_COUNT+=1 >nul
if %BMR_SCAN_COUNT% GEQ 32 goto search_path
for %%D in ("%BMR_SCAN%\..") do set "BMR_PARENT=%%~fD"
if /I "%BMR_PARENT%"=="%BMR_SCAN%" goto search_path
set "BMR_SCAN=%BMR_PARENT%"
goto scan_parent
:search_path
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined BMR_PHP set "BMR_PHP=%%P"
:found
set "BMR_SCAN="
set "BMR_PARENT="
set "BMR_SCAN_COUNT="
if defined BMR_PHP exit /b 0
echo PHP was not found. Keep the game inside XAMPP's htdocs folder,
echo or set PHP_EXE to the full path to php.exe.
exit /b 1
