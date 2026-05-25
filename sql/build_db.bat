@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

REM ----------------------------------------------------------
REM RamsesDB - Nastroj pro sestaveni databazovych skriptu
REM ----------------------------------------------------------

REM Nastaveni cest (predpoklada, ze vse je ve stejne slozce)
set "MANIFEST_FILE=build_list.txt"
set "OUTPUT_FILE=--McpDeploy.sql"

REM Kontrola, zda existuje manifest
if not exist "%MANIFEST_FILE%" (
	echo [CHYBA] Soubor manifestu "%MANIFEST_FILE%" nebyl nalezen!
	echo Vytvorte soubor "%MANIFEST_FILE%" se seznamem SQL skriptu.
	pause
	exit /b 1
)

REM Vycisteni (smazani) predchoziho vystupu, pokud existuje
if exist "%OUTPUT_FILE%" del "%OUTPUT_FILE%"

REM Vlozeni neviditelneho znaku BOM (Byte Order Mark) pro UTF-8
REM Toto donuti SSMS, aby soubor automaticky otevrelo s kodovanim UTF-8
powershell -Command "[IO.File]::WriteAllBytes('%OUTPUT_FILE%', [byte[]](239,187,191))"

echo Sestavuji skript "%OUTPUT_FILE%"...
echo.

REM Zapis hlavicky do vysledneho souboru (ciste ASCII)
echo /* ========================================== >> "%OUTPUT_FILE%"
echo  * RamsesDB Auto-Deploy Build >> "%OUTPUT_FILE%"
echo  * Vygenerovano %date% %time% >> "%OUTPUT_FILE%"
echo  * ========================================== */ >> "%OUTPUT_FILE%"
echo. >> "%OUTPUT_FILE%"

set "FILE_COUNT=0"

REM Cteni manifestu radek po radku
for /f "usebackq tokens=* delims=" %%A in ("%MANIFEST_FILE%") do (
	set "LINE=%%A"
	
	REM Ignorovani prazdnych radku
	if not "!LINE!"=="" (
		REM Kontrola, zda radek nezacina komentarem (#)
		set "FIRST_CHAR=!LINE:~0,1!"
		if not "!FIRST_CHAR!"=="#" (
			
			REM Kontrola existence SQL souboru
			if exist "!LINE!" (
				echo Pridavam: !LINE!
				
				REM Zapis komentare cistym ASCII textem pro bezpeci kodovani
				echo /* --- Nacteno z: !LINE! --- */ >> "%OUTPUT_FILE%"
				
				REM Pripojeni obsahu SQL souboru k vystupu
				type "!LINE!" >> "%OUTPUT_FILE%"
				
				REM Vlozeni noveho radku a prikazu GO pro oddeleni davek v MSSQL
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
		
		REM Zapis metadatoveho komentare cistym ASCII textem
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