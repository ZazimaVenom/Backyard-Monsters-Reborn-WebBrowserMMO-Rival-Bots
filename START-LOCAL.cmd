@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
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
"%BMR_PHP%" "%~dp0bin\local-check.php" port-free 8080
if errorlevel 1 (
  pause
  exit /b 1
)
echo Starting Backyard Monsters Reborn at http://127.0.0.1:8080
echo If you are using XAMPP Apache, use its localhost game URL instead.
echo START-XAMPP.cmd starts an optional worker for that setup.
echo Signed-in game browsers activate bots automatically.
echo Bots pause when no players are online, even if a worker stays open.
echo Close the PHP server window to stop serving the game.
echo The separate worker window is optional and may be closed.
start "Backyard Monsters - Local HTTP" "%BMR_PHP%" -S 127.0.0.1:8080 -t "%~dp0public"
"%BMR_PHP%" "%~dp0bin\local-check.php" ready 8080
if errorlevel 1 (
  echo Check the PHP server window, then close it before retrying.
  pause
  exit /b 1
)
start "" "http://127.0.0.1:8080/"
if not exist "%~dp0var\config.php" (
  echo Complete setup.php in your browser. It creates the world and 1,000 bots.
  echo When setup is complete, press any key to open the optional worker.
  echo Or close this launcher; the game already runs bots while players are online.
  pause >nul
)
if exist "%~dp0var\config.php" (
  start "Backyard Monsters - World Worker" "%BMR_PHP%" "%~dp0bin\worker.php"
) else (
  echo Setup is not complete. Finish it in the browser, then sign in to activate bots.
  pause
)
endlocal
