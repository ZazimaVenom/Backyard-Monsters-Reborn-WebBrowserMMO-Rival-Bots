@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
if not exist "%~dp0var\config.php" (
  echo The world has not been installed yet.
  echo With XAMPP, start Apache and MySQL and open the game's public/setup.php.
  echo Otherwise, run START-LOCAL.cmd and complete setup.php.
  pause
  exit /b 1
)
call "%~dp0bin\find-php.cmd"
if errorlevel 1 (
  pause
  exit /b 1
)
"%BMR_PHP%" -r "exit(version_compare(PHP_VERSION,'8.2.0','>=') && extension_loaded('pdo_mysql') ? 0 : 1);" >nul 2>nul
if errorlevel 1 (
  echo PHP 8.2 or newer with the pdo_mysql extension is required.
  echo Enable extension=pdo_mysql in php.ini and try again.
  pause
  exit /b 1
)
echo Optional worker: game browsers also advance bots automatically.
echo Bots run only while a signed-in player has the game open.
echo Closing this window does not stop browser-driven activity.
"%BMR_PHP%" "%~dp0bin\worker.php" %*
set "BMR_EXIT=%ERRORLEVEL%"
if not "%BMR_EXIT%"=="0" pause
exit /b %BMR_EXIT%
