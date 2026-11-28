@echo off
chcp 65001 >nul
title ITP-Programari - Cloudflare Tunnel
echo ===================================================
echo   Pornire Tunel Cloudflare pentru ITP-Programari
echo ===================================================
echo.

REM Verificare daca serverul PHP pe portul 8080 este pornit
netstat -ano | findstr ":8080" | findstr "LISTENING" >nul
if errorlevel 1 (
    echo [INFO] Serverul PHP nu este activ pe portul 8080.
    echo [INFO] Se porneste serverul PHP in fundal...
    start "ITP-PHP-Server" /min cmd /c "php -S 127.0.0.1:8080 router.php"
    timeout /t 2 /nobreak >nul
) else (
    echo [OK] Serverul PHP este activ pe portul 8080.
)

echo [INFO] Se conecteaza la Cloudflare...
echo Copiati adresa https://...trycloudflare.com afisata mai jos:
echo.

"%~dp0tools\cloudflared.exe" tunnel --url http://127.0.0.1:8080 --no-autoupdate
pause
