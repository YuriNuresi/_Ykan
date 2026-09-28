' Avvia il Bridge Ykan in background, senza aprire nessuna finestra, con un'icona nella
' tray di Windows (vicino all'orologio, magari dentro la freccia ^) dove si puo' fare
' Riavvia / Termina / Apri log. Il lavoro vero lo fa tray.ps1.
' Pensato per essere lanciato dalla cartella Avvio automatico di Windows
' (vedi install-autostart.bat, che lo mette li' con un click).
' Non installa le dipendenze: va eseguito npm install / start-bridge.bat
' almeno una volta a mano, prima di attivare l'avvio automatico.

Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)

Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = scriptDir
' 0 = finestra nascosta, False = non aspettare che il processo finisca
shell.Run "powershell.exe -NoProfile -STA -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptDir & "\tray.ps1""", 0, False
