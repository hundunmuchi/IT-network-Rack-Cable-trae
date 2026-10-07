@echo off
REM stop_local.cmd -- 停止本地 LibreNMS + SNMP 模拟器后台进程
REM 杀掉标题为 LibreNMS_SNMP_Simulator / LibreNMS_WebServer 的窗口进程。

echo [..] 停止 SNMP 模拟器...
taskkill /FI "WINDOWTITLE eq LibreNMS_SNMP_Simulator*" /F 2>nul

echo [..] 停止 LibreNMS Web 服务...
taskkill /FI "WINDOWTITLE eq LibreNMS_WebServer*" /F 2>nul

echo [..] 额外清理：杀掉占用 5656 / 11611 的 php.exe
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":5656" ^| findstr "LISTENING"') do taskkill /pid %%a /F 2>nul
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":11611" ^| findstr "LISTENING"') do taskkill /pid %%a /F 2>nul

echo [OK] 后台进程已停止
timeout /t 2 /nobreak >nul
