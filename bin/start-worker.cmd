@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem Keep the old variable as an optional alias; PHP_EXE takes precedence.
if not defined PHP_EXE if defined BYM_PHP set "PHP_EXE=%BYM_PHP%"
call "%~dp0..\START-WORLD.cmd" %*
exit /b %ERRORLEVEL%
