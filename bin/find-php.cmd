@echo off
rem Called by the Windows launchers. Return the selected executable in BMH_PHP.
rem Prefer an explicit override, then this installation, then the system PATH.
set "BMH_PHP="
if defined PHP_EXE (
  if exist "%PHP_EXE%" (
    set "BMH_PHP=%PHP_EXE%"
    exit /b 0
  )
  echo PHP_EXE points to a file that does not exist. Correct it and try again.
  exit /b 1
)
for %%D in ("%~dp0..") do set "BMH_SCAN=%%~fD"
set "BMH_SCAN_COUNT=0"
:scan_parent
if exist "%BMH_SCAN%\php\php.exe" (
  set "BMH_PHP=%BMH_SCAN%\php\php.exe"
  goto found
)
set /a BMH_SCAN_COUNT+=1 >nul
if %BMH_SCAN_COUNT% GEQ 32 goto search_path
for %%D in ("%BMH_SCAN%\..") do set "BMH_PARENT=%%~fD"
if /I "%BMH_PARENT%"=="%BMH_SCAN%" goto search_path
set "BMH_SCAN=%BMH_PARENT%"
goto scan_parent
:search_path
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined BMH_PHP set "BMH_PHP=%%P"
:found
set "BMH_SCAN="
set "BMH_PARENT="
set "BMH_SCAN_COUNT="
if defined BMH_PHP exit /b 0
echo PHP was not found. Keep the game inside XAMPP's htdocs folder,
echo or set PHP_EXE to the full path to php.exe.
exit /b 1
