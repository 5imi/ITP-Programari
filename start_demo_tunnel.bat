@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "LINK_FILE=%~dp0link_cloudflare.txt"
set "CF_EXE="

if exist "%~dp0tools\cloudflared.exe" set "CF_EXE=%~dp0tools\cloudflared.exe"
if not defined CF_EXE if exist "D:\Proiecte\BUZDEA APP\tools\cloudflared.exe" set "CF_EXE=D:\Proiecte\BUZDEA APP\tools\cloudflared.exe"
if not defined CF_EXE (
  for /f "delims=" %%I in ('where cloudflared 2^>nul') do if not defined CF_EXE set "CF_EXE=%%I"
)

if not defined CF_EXE (
  echo [EROARE] Nu am gasit cloudflared.exe.
  echo Pune-l in "%~dp0tools\cloudflared.exe" sau in PATH.
  pause
  exit /b 1
)

echo ============================================================
echo   ACW - Falticeni - Demo ITP (Server Local + Cloudflare)
echo ============================================================
echo.

powershell -NoProfile -Command "$c = New-Object System.Net.Sockets.TcpClient; try { $c.Connect('127.0.0.1', 8000); $c.Close(); exit 0 } catch { exit 1 }"
if errorlevel 1 (
  echo [1/2] Pornesc serverul local pe http://127.0.0.1:8000 ...
  start "ITP-Demo-Server" /min python -c "import http.server; H=type('H',(http.server.SimpleHTTPRequestHandler,),{'do_GET':lambda s:(setattr(s,'path','/dashboard.html') or super(H,s).do_GET()) if s.path.split('?',1)[0] in ('/','/dashboard.html') else (super(H,s).do_GET() if s.path.split('?',1)[0].startswith('/references/ui-libs/') else s.send_error(404))}); http.server.ThreadingHTTPServer(('127.0.0.1',8000),H).serve_forever()"
  timeout /t 2 /nobreak >nul
) else (
  echo [1/2] Serverul local pe 127.0.0.1:8000 ruleaza deja.
)

echo [2/2] Pornesc tunnel-ul Cloudflare... (nu inchide aceasta fereastra cat timp folosesti link-ul)
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "$saved=$false; & $env:CF_EXE tunnel --no-autoupdate --url http://127.0.0.1:8000 2>&1 | ForEach-Object { $l=$_.ToString(); if (-not $saved -and $l -match 'https://[a-z0-9-]+\.trycloudflare\.com') { $u=$Matches[0]; [System.IO.File]::WriteAllText($env:LINK_FILE, $u + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false)); try { Set-Clipboard -Value $u } catch {}; $saved=$true; Write-Host ''; Write-Host '============================================================' -ForegroundColor Green; Write-Host ('  LINK ACTIV: ' + $u) -ForegroundColor Green; Write-Host ('  Salvat in:  ' + $env:LINK_FILE) -ForegroundColor Cyan; Write-Host '  Link-ul a fost copiat automat in Clipboard (Ctrl+V)!' -ForegroundColor Yellow; Write-Host '============================================================' -ForegroundColor Green; Write-Host '' } }"

echo.
echo Tunnel-ul Cloudflare s-a oprit.
pause
