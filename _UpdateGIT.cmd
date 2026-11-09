@echo off
cls
set "POWERSHELL=C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe"

echo [1/3] Wijzigingen committen en pushen naar GitHub...
for /f %%I in ('%POWERSHELL% -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%I"

git -c gc.auto=0 -c maintenance.auto=false add -A
if errorlevel 1 goto :error

git -c gc.auto=0 -c maintenance.auto=false diff --cached --quiet
if errorlevel 1 (
    git -c gc.auto=0 -c maintenance.auto=false commit -m "Update %TODAY% (via _UpdateGIT.cmd)"
    if errorlevel 1 goto :error

    git -c gc.auto=0 -c maintenance.auto=false push
    if errorlevel 1 goto :error
) else (
    echo Geen wijzigingen om te committen.
)

echo.
echo [2/3] Git-repository stofzuigen...
echo n| git gc --prune=now
if errorlevel 1 (
    echo [WARNING] Git-repository kon niet volledig worden gestofzuigd. Dit is meestal een tijdelijke lock door Windows, OneDrive, antivirus of een ander proces.
    echo [WARNING] De update is al gecommit en gepusht; het script gaat door.
)

echo.
echo [3/3] Git-status tonen...
git -c gc.auto=0 -c maintenance.auto=false status --short

echo.
echo Klaar. De situatie is bijgewerkt.
echo.
pause
exit /b 0

:error
echo.
echo ER IS IETS MISGEGAAN. Zie de melding hierboven.
echo.
pause
exit /b 1
