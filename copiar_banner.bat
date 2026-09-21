@echo off
chcp 65001 > nul
title Atualizar Banner Helix
echo ============================================================
echo   Copiando o Banner Oficial para o Projeto Helix
echo ============================================================
echo.

set SOURCE=C:\Users\Win11\.gemini\antigravity\brain\7d996493-41d0-4511-82cd-d833505ab93b\.user_uploaded\media_1790018657820.jpg

if not exist "%SOURCE%" (
    echo [AVISO] Arquivo temporário não encontrado no caminho padrão:
    echo %SOURCE%
    echo Verifique se o arquivo ainda existe.
    pause
    exit /b
)

if not exist "images" mkdir "images"
if not exist "uploads\banners" mkdir "uploads\banners"

copy /Y "%SOURCE%" "images\banner-principal.jpg" > nul
copy /Y "%SOURCE%" "uploads\banners\banner-principal.jpg" > nul

echo [OK] Banner copiado com sucesso para:
echo      - images\banner-principal.jpg
echo      - uploads\banners\banner-principal.jpg
echo.
echo Agora o banner está salvo localmente no seu projeto!
echo Basta fazer o commit / deploy na Vercel.
echo ============================================================
pause
