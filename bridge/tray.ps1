# Ykan Bridge nella tray di Windows: avvia il Bridge (node bridge-server.js) nascosto e mette
# un'icona vicino all'orologio con il menu Riavvia / Termina / Apri log. Icona verde = attivo,
# rossa = fermo. Nessuna dipendenza: solo PowerShell + WinForms, gia' presenti in Windows.
# Lanciato da autostart-hidden.vbs (o a mano: powershell -STA -File tray.ps1).
# NB: script volutamente solo ASCII (PowerShell 5.1 legge i file senza BOM come ANSI).

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$here   = Split-Path -Parent $MyInvocation.MyCommand.Path
$port   = 51820
$logOut = Join-Path $here 'bridge.log'
$logErr = Join-Path $here 'bridge.err.log'

# Una sola tray alla volta
$created = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\YkanBridgeTray', [ref]$created)
if (-not $created) { exit }

# taskkill /T: chiude anche i figli del bridge (le shell/claude aperte nei terminali della board)
function Stop-Tree($procId) { & taskkill.exe /PID $procId /T /F 2>&1 | Out-Null }

# Se un bridge occupa gia' la porta (avviato a mano, o dal vecchio avvio nascosto senza tray)
# lo chiude, cosi' quello nuovo parte sempre pulito. Tocca solo processi che sono il bridge.
function Free-Port {
    foreach ($c in @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)) {
        $p = Get-CimInstance Win32_Process -Filter "ProcessId=$($c.OwningProcess)" -ErrorAction SilentlyContinue
        if ($p -and $p.CommandLine -match 'bridge-server|ykan-bridge') { Stop-Tree $p.ProcessId }
    }
}

function New-StatusIcon($color) {
    $bmp = New-Object System.Drawing.Bitmap 32, 32
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $g.FillEllipse((New-Object System.Drawing.SolidBrush $color), 1, 1, 30, 30)
    $font = New-Object System.Drawing.Font('Segoe UI', 18, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
    $fmt = New-Object System.Drawing.StringFormat
    $fmt.Alignment = [System.Drawing.StringAlignment]::Center
    $fmt.LineAlignment = [System.Drawing.StringAlignment]::Center
    $g.DrawString('Y', $font, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, 1, 32, 32), $fmt)
    $g.Dispose()
    [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
}
$iconOn  = New-StatusIcon ([System.Drawing.Color]::FromArgb(22, 163, 74))
$iconOff = New-StatusIcon ([System.Drawing.Color]::FromArgb(220, 38, 38))

$script:node = $null
$script:wasRunning = $false

function Test-Running { return ($script:node -ne $null) -and (-not $script:node.HasExited) }

function Update-State {
    $running = Test-Running
    $ni.Icon = if ($running) { $iconOn } else { $iconOff }
    $ni.Text = if ($running) { "Ykan Bridge - attivo (porta $port)" } else { 'Ykan Bridge - FERMO' }
    $miStatus.Text = if ($running) { "Ykan Bridge - attivo (PID $($script:node.Id))" } else { 'Ykan Bridge - fermo' }
    $miRestart.Text = if ($running) { 'Riavvia' } else { 'Avvia' }
    if ($script:wasRunning -and -not $running) {
        $ni.ShowBalloonTip(4000, 'Ykan Bridge', 'Il Bridge si e'' fermato. Menu > Apri log per il motivo.', [System.Windows.Forms.ToolTipIcon]::Warning)
    }
    $script:wasRunning = $running
}

function Start-Bridge {
    Free-Port
    $script:node = Start-Process -FilePath 'node' -ArgumentList 'bridge-server.js' -WorkingDirectory $here `
        -WindowStyle Hidden -PassThru -RedirectStandardOutput $logOut -RedirectStandardError $logErr
    $script:wasRunning = $true
    Update-State
}

function Stop-Bridge {
    if ($script:node -ne $null -and -not $script:node.HasExited) { Stop-Tree $script:node.Id }
    $script:node = $null
    $script:wasRunning = $false
}

$ni = New-Object System.Windows.Forms.NotifyIcon
$menu = New-Object System.Windows.Forms.ContextMenuStrip
$miStatus = $menu.Items.Add('Ykan Bridge')
$miStatus.Enabled = $false
[void]$menu.Items.Add('-')
$miRestart = $menu.Items.Add('Riavvia')
$miLog = $menu.Items.Add('Apri log')
[void]$menu.Items.Add('-')
$miExit = $menu.Items.Add('Termina')

$miRestart.add_Click({ Stop-Bridge; Start-Sleep -Milliseconds 400; Start-Bridge })
$miLog.add_Click({ if (Test-Path $logOut) { Start-Process notepad.exe $logOut } })
$miExit.add_Click({
    Stop-Bridge
    $timer.Stop()
    $ni.Visible = $false
    $ni.Dispose()
    [System.Windows.Forms.Application]::Exit()
})
$ni.ContextMenuStrip = $menu
$ni.add_DoubleClick({ if (Test-Path $logOut) { Start-Process notepad.exe $logOut } })

# Controlla ogni 2 secondi che il Bridge sia vivo (se cade, l'icona diventa rossa)
$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 2000
$timer.add_Tick({ Update-State })

Start-Bridge
$ni.Visible = $true
$timer.Start()
[System.Windows.Forms.Application]::Run()
$mutex.ReleaseMutex()
