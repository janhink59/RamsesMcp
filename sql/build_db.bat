@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

:: ----------------------------------------------------------
:: RamsesDB - Nástroj pro sestavení databázových skriptů
:: ----------------------------------------------------------

:: Nastavení cest (předpokládá, že vše je ve stejné složce)
set "MANIFEST_FILE=build_list.txt"
set "OUTPUT_FILE=--McpDeploy.sql"

:: Kontrola, zda existuje manifest
if not exist "%MANIFEST_FILE%" (
	echo [CHYBA] Soubor manifestu "%MANIFEST_FILE%" nebyl nalezen!
	echo Vytvorte soubor "%MANIFEST_FILE%".txt se seznamem SQL skriptu.
	pause
	exit /b 1
)

:: Vyčištění (smazání) předchozího výstupu, pokud existuje
if exist "%OUTPUT_FILE%" del "%OUTPUT_FILE%"

:: Vložení neviditelného znaku BOM (Byte Order Mark) pro UTF-8
:: Toto donutí SSMS, aby soubor automaticky otevřelo s kódováním UTF-8
powershell -Command "[IO.File]::WriteAllBytes('%OUTPUT_FILE%', [byte[]](239,187,191))"

echo Sestavuji skript "%OUTPUT_FILE%"...
echo.

:: Zápis hlavičky do výsledného souboru (změněno z > na >>, aby se nesmazal BOM)
echo /* ========================================== >> "%OUTPUT_FILE%"
echo  * RamsesDB Auto-Deploy Build >> "%OUTPUT_FILE%"
echo  * Vygenerováno %date% %time% >> "%OUTPUT_FILE%"
echo  * ========================================== */ >> "%OUTPUT_FILE%"
echo. >> "%OUTPUT_FILE%"

set "FILE_COUNT=0"

:: Čtení manifestu řádek po řádku
for /f "usebackq tokens=* delims=" %%A in ("%MANIFEST_FILE%") do (
	set "LINE=%%A"
	
	:: Ignorování prázdných řádků
	if not "!LINE!"=="" (
		:: Kontrola, zda řádek nezačíná komentářem (#)
		set "FIRST_CHAR=!LINE:~0,1!"
		if not "!FIRST_CHAR!"=="#" (
			
			:: Kontrola existence SQL souboru
			if exist "!LINE!" (
				echo Pridavam: !LINE!
				
				:: Zápis komentáře, odkud kód pochází
				echo /* --- Nacteno z: !LINE! --- */ >> "%OUTPUT_FILE%"
				
				:: Připojení obsahu SQL souboru k výstupu
				type "!LINE!" >> "%OUTPUT_FILE%"
				
				:: Vložení nového řádku a příkazu GO pro oddělení dávek (batche) v MSSQL
				echo. >> "%OUTPUT_FILE%"
				echo GO >> "%OUTPUT_FILE%"
				echo. >> "%OUTPUT_FILE%"
				
				set /a FILE_COUNT+=1
			) else (
				echo [VAROVANI] Soubor '!LINE!' neexistuje, preskakuji...
			)
		)
	)
)

echo.
echo ----------------------------------------------------------
echo Automaticke nacteni mcp_tool_*.sql ...
echo ----------------------------------------------------------
for %%F in (mcp_tool_*.sql) do (
	if exist "%%F" (
		echo Pridavam nastroj: %%F
		echo /* --- Automaticky nacteno z adresare: %%F --- */ >> "%OUTPUT_FILE%"
		type "%%F" >> "%OUTPUT_FILE%"
		echo. >> "%OUTPUT_FILE%"
		echo GO >> "%OUTPUT_FILE%"
		echo. >> "%OUTPUT_FILE%"
		set /a FILE_COUNT+=1
	)
)

echo.
echo ==========================================================
echo HOTOVO! Bylo spojeno %FILE_COUNT% souboru do "%OUTPUT_FILE%".
echo ==========================================================
pause
