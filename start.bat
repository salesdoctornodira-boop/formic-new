@echo off
rem ============================================================
rem  CCS Platform - zapusk dlya Windows (dvoynoy klik po faylu)
rem  Otkroyet prilozhenie v brauzere: http://localhost:8080
rem ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"
set "PORT=8080"
set "PHPEXE="

rem --- 1) PHP uzhe ustanovlen v sisteme? ---
where php >nul 2>nul && set "PHPEXE=php"

rem --- 2) Portativnyy PHP uzhe ryadom? ---
if "%PHPEXE%"=="" if exist "bin\win\php\php.exe" set "PHPEXE=bin\win\php\php.exe"

rem --- 3) Skachat portativnyy PHP odin raz (nuzhen internet) ---
if "%PHPEXE%"=="" (
  echo PHP ne nayden - skachivayu portativnyy PHP odin raz, podozhdite...
  if not exist "bin\win" mkdir "bin\win"
  powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop';" ^
    "try { [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;" ^
    "$u='https://windows.php.net/downloads/releases/php-8.3.31-nts-Win32-vs16-x64.zip';" ^
    "Invoke-WebRequest -Uri $u -OutFile 'bin\win\php.zip';" ^
    "Expand-Archive -Path 'bin\win\php.zip' -DestinationPath 'bin\win\php' -Force;" ^
    "Remove-Item 'bin\win\php.zip';" ^
    "if (Test-Path 'bin\win\php\php.ini-production' -and -not (Test-Path 'bin\win\php\php.ini')) {" ^
    "  $ini = Get-Content 'bin\win\php\php.ini-production';" ^
    "  $ini = $ini -replace '^;extension_dir = \"ext\"','extension_dir = \"ext\"';" ^
    "  $ini = $ini -replace '^;extension=pdo_sqlite','extension=pdo_sqlite';" ^
    "  $ini = $ini -replace '^;extension=sqlite3','extension=sqlite3';" ^
    "  Set-Content 'bin\win\php\php.ini' $ini }" ^
    "} catch { Write-Host ('OSHIBKA: ' + $_); exit 1 }"
  if exist "bin\win\php\php.exe" set "PHPEXE=bin\win\php\php.exe"
)

if "%PHPEXE%"=="" (
  echo.
  echo Ne udalos avtomaticheski ustanovit PHP.
  echo Variant 1: otkroyte PowerShell i vypolnite komandu:  winget install PHP.PHP
  echo            zatem snova dvazhdy klyknite start.bat
  echo Variant 2: poprosite kollegu s Mac zapustit start.command
  echo.
  pause
  exit /b 1
)

echo.
echo CCS Platform zapuskaetsya na http://localhost:%PORT%
echo (chtoby ostanovit - prosto zakroyte eto okno)
echo.
start "" "http://localhost:%PORT%"
"%PHPEXE%" -S localhost:%PORT%
