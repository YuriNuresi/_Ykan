# Crea l'icona del Bridge (ykan-bridge.ico, ponte verde) e il collegamento "Ykan Bridge"
# nel menu Start, come un programma: si apre con Start → Ykan Bridge e mette l'icona nella tray.
# Se il Bridge e' gia' acceso, tray.ps1 non ne avvia un secondo.
# Uso: tasto destro → Esegui con PowerShell (oppure da install-startmenu.bat).

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$ico = Join-Path $here 'ykan-bridge.ico'

Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap 64, 64
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = 'AntiAlias'
$g.Clear([System.Drawing.Color]::Transparent)
$g.FillEllipse((New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(22, 163, 74))), 0, 0, 63, 63)
$pen = New-Object System.Drawing.Pen ([System.Drawing.Color]::White), 5
$pen.StartCap = 'Round'; $pen.EndCap = 'Round'
$g.DrawLine($pen, 10, 44, 54, 44)                 # impalcato
$g.DrawArc($pen, 12, 14, 40, 40, 180, 180)        # arco
foreach ($x in 22, 32, 42) { $g.DrawLine($pen, $x, 30, $x, 44) }
$g.Dispose()
$icon = [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
$fs = [System.IO.File]::Create($ico)
$icon.Save($fs)
$fs.Close()

$lnk = Join-Path ([Environment]::GetFolderPath('Programs')) 'Ykan Bridge.lnk'
$ws = New-Object -ComObject WScript.Shell
$s = $ws.CreateShortcut($lnk)
$s.TargetPath = 'wscript.exe'
$s.Arguments = '"' + (Join-Path $here 'autostart-hidden.vbs') + '"'
$s.WorkingDirectory = $here
$s.IconLocation = $ico
$s.Description = 'Avvia il Bridge locale di Ykan (icona nella tray)'
$s.Save()
Write-Host "Fatto: menu Start -> Ykan Bridge ($lnk)"
