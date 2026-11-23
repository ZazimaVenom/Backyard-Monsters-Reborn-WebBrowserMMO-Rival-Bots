@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
echo Backyard Monsters Reborn - preserve and upgrade an existing world
echo Stop Apache/the HTTP server and the world worker. Keep MySQL running.
echo This command keeps existing accounts, bases, rankings and all 1,000 bots.
echo.
call "%~dp0bin\find-php.cmd"
if errorlevel 1 goto failed
set "BMR_UPGRADE_MODE=%~1"
if not defined BMR_UPGRADE_MODE set "BMR_UPGRADE_MODE=--check"
if /I "%BMR_UPGRADE_MODE%"=="--check" goto run
if /I "%BMR_UPGRADE_MODE%"=="--apply" goto run
echo Usage: UPGRADE-WORLD.cmd [--check^|--apply]
exit /b 2
:run
"%BMR_PHP%" "%~dp0bin\upgrade.php" %BMR_UPGRADE_MODE%
set "BMR_UPGRADE_RESULT=%ERRORLEVEL%"
echo.
if not "%BMR_UPGRADE_RESULT%"=="0" goto failed
if /I "%BMR_UPGRADE_MODE%"=="--check" echo To apply this upgrade, run UPGRADE-WORLD.cmd --apply
if not defined BYM_NO_PAUSE pause
exit /b 0
:failed
echo Upgrade stopped. Read the message above before restarting the game.
if not defined BYM_NO_PAUSE pause
exit /b 1
