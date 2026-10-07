@echo off
REM ===========================================================================
REM start_local.cmd -- 一键启动 LibreNMS + SNMP 模拟器（本地 Windows 开发环境）
REM
REM 用法: 双击此文件，或在 PowerShell 中执行 .\start_local.cmd
REM
REM 会做以下事情：
REM   1) 后台启动 PHP SNMP 模拟器（监听 127.0.0.2-4:11611，HP/Cisco/Huawei）
REM   2) 后台启动 LibreNMS PHP 内置服务器 (http://127.0.0.1:5656)
REM   3) 等待用户按 Ctrl-C 退出
REM
REM 配套脚本：stop_local.cmd  (停止所有后台进程)
REM           reset_local.cmd (重置 SQLite 数据库并重新 discovery)
REM ===========================================================================

setlocal enabledelayedexpansion

set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "PHP=%ROOT%\_tools\php74\php.exe"
set "SIM=%ROOT%\_tools\snmp_sim\run.php"
set "LNMS_DIR=%ROOT%\LibreNMS-offline\src\lnms"
set "PIDFILE=%ROOT%\_tools\run\pids.txt"
set "RUNDIR=%ROOT%\_tools\run"

if not exist "%RUNDIR%" mkdir "%RUNDIR%"

REM ---- 1. 启动 SNMP 模拟器 ----
echo [..] 启动 SNMP 模拟器 (HP/Cisco/Huawei on 127.0.0.2-4:11611)...
start "LibreNMS_SNMP_Simulator" /MIN "%PHP%" "%SIM%"
echo %TIME% SNMP sim started>> "%PIDFILE%.log"

REM 等待模拟器就绪（轮询 snmpget 一次，最多 5 秒）
set /a tries=0
:wait_sim
"%PHP%" -r "$r=@snmp2_get('127.0.0.2:11611','public','1.3.6.1.2.1.1.1.0',500000); if($r!==false){exit(0);}else{exit(1);}" 2>nul
if %errorlevel%==0 (
    echo [OK] SNMP 模拟器就绪
    goto sim_ready
)
set /a tries+=1
if %tries% geq 10 (
    echo [错误] SNMP 模拟器未响应，请检查 _tools\snmp_sim\run.php
    pause
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto wait_sim

:sim_ready

REM ---- 2. 启动 LibreNMS PHP 内置服务器 ----
echo [..] 启动 LibreNMS Web 服务 (http://127.0.0.1:5656)...
pushd "%LNMS_DIR%"
start "LibreNMS_WebServer" /MIN "%PHP%" -S 127.0.0.1:5656 -t "%LNMS_DIR%\html" "%LNMS_DIR%\server.php"
popd
echo %TIME% Web server started>> "%PIDFILE%.log"

REM 等待 Web 就绪
set /a tries=0
:wait_web
"%PHP%" -r "$r=@file_get_contents('http://127.0.0.1:5656/',false,stream_context_create(['http'=>['timeout'=>2]])); if($r!==false){exit(0);}else{exit(1);}" 2>nul
if %errorlevel%==0 (
    echo [OK] LibreNMS Web 服务就绪
    goto web_ready
)
set /a tries+=1
if %tries% geq 15 (
    echo [错误] LibreNMS Web 服务未响应
    echo       请手动运行: cd %LNMS_DIR% ^&^& %PHP% -S 127.0.0.1:5656 -t %LNMS_DIR%
    pause
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto wait_web

:web_ready

echo.
echo ============================================
echo  LibreNMS 本地环境已启动
echo ============================================
echo   Web  地址: http://127.0.0.1:5656 (HTTP 302 -> /login)
echo   设备  IP : 127.0.0.2 (HP ProCurve)
echo              127.0.0.3 (Cisco IOS-XE)
echo              127.0.0.4 (Huawei VRP)
echo   SNMP  端口: 11611 (community: public)
echo.
echo   首次运行需要执行 discovery 与添加设备，请运行:
echo     %ROOT%\reset_local.cmd
echo.
echo   停止服务: 双击 stop_local.cmd
echo ============================================
echo.
echo 按任意键关闭此窗口（后台进程会继续运行）
pause >nul
endlocal
