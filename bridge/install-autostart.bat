@echo off
setlocal
set "here=%~dp0"
set "target=%here%autostart-hidden.vbs"
set "startup=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "shortcut=%startup%\Ykan Bridge.lnk"
set "vbs=%temp%\ykan_mkshortcut.vbs"

if not exist "%here%node_modules" (
    echo Le dipendenze del Bridge non risultano installate.
    echo Esegui prima start-bridge.bat una volta, poi rilancia questo file.
    pause
    exit /b 1
)

> "%vbs%" echo Set oWS = WScript.CreateObject("WScript.Shell")
>> "%vbs%" echo sLinkFile = "%shortcut%"
>> "%vbs%" echo Set oLink = oWS.CreateShortcut(sLinkFile)
>> "%vbs%" echo oLink.TargetPath = "%target%"
>> "%vbs%" echo oLink.WorkingDirectory = "%here%"
>> "%vbs%" echo oLink.Description = "Avvia il Bridge locale di Ykan all'accesso a Windows"
>> "%vbs%" echo oLink.Save

cscript //nologo "%vbs%"
del "%vbs%"

echo.
echo Fatto: il Bridge Ykan si avviera' da solo, in background, ad ogni accesso a Windows.
echo Scorciatoia creata in: %shortcut%
echo Per disattivarlo, esegui uninstall-autostart.bat.
pause
