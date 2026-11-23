@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
echo Backyard Monsters Reborn - optional XAMPP world worker
echo.
echo Start Apache and MySQL in the XAMPP Control Panel.
echo Open the game's public folder through your normal localhost URL.
echo Signed-in game browsers activate bots automatically; this worker is optional.
echo Bots pause when no players are online, even with this window open.
echo This launcher does not start another HTTP server or database.
echo.
call "%~dp0START-WORLD.cmd" %*
exit /b %ERRORLEVEL%
