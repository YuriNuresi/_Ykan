@echo off
title Ykan Bridge
cd /d "%~dp0"
if not exist "node_modules" (
    echo Prima esecuzione: installo le dipendenze del Bridge...
    call npm install
    if errorlevel 1 (
        echo.
        echo npm install e' fallito. Serve Node.js installato ^(nodejs.org^).
        pause
        exit /b 1
    )
)
node bridge-server.js
pause
