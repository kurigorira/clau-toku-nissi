@echo off
rem 病院日誌・医事統計表 入力システム  バックアップ
rem Windowsのタスクスケジューラから毎日実行する。
rem
rem   schtasks /create /tn "病院日誌バックアップ" ^
rem     /tr "C:\Apache24\htdocs\nissi\tools\backup.bat" /sc daily /st 22:00 /ru SYSTEM
rem
rem 保存先と世代数は config\config.php の backup 設定で決まる。

cd /d C:\Apache24\htdocs\nissi
C:\php\php.exe db\tools\backup.php
exit /b %ERRORLEVEL%
