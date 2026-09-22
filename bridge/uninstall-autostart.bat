@echo off
set "shortcut=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\Ykan Bridge.lnk"
if exist "%shortcut%" (
    del "%shortcut%"
    echo Avvio automatico del Bridge Ykan rimosso.
) else (
    echo Nessun avvio automatico da rimuovere.
)
pause
