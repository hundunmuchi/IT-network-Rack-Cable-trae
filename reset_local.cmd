@echo off
REM reset_local.cmd -- 重置 SQLite 数据库并重新执行 discovery/poller
REM 用法：先 start_local.cmd 启动后台服务，然后运行此脚本

setlocal
set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "PHP=%ROOT%\_tools\php74\php.exe"
set "LNMS_DIR=%ROOT%\LibreNMS-offline\src\lnms"
set "MIBDIRS=%LNMS_DIR%\mibs"

set "MIBDIRS=%MIBDIRS%"
"%PHP%" -r "putenv('MIBDIRS='.$_ENV['MIBDIRS']); var_dump(getenv('MIBDIRS'));" 2>nul

echo [..] 1/4 重新迁移 SQLite 数据库...
pushd "%LNMS_DIR%"
"%PHP%" artisan migrate:fresh --seed --force 2>&1 | findstr /R /C:"Dropped" /C:"Created" /C:"Migration" /C:"Seeding" /C:"Database" /C:"Error" /C:"Exception"
popd

echo.
echo [..] 2/4 添加 3 个设备 (HP/Cisco/Huawei)...
pushd "%LNMS_DIR%"
"%PHP%" artisan snmp:create 127.0.0.2 --community=public --port=11611 -P 2 2>&1 | findstr /R /C:"device_id" /C:"Added" /C:"Error"
"%PHP%" artisan snmp:create 127.0.0.3 --community=public --port=11611 -P 2 2>&1 | findstr /R /C:"device_id" /C:"Added" /C:"Error"
"%PHP%" artisan snmp:create 127.0.0.4 --community=public --port=11611 -P 2 2>&1 | findstr /R /C:"device_id" /C:"Added" /C:"Error"
popd

echo.
echo [..] 3/4 运行 discovery...
"%PHP%" "%LNMS_DIR%\discovery.php" -h all 2>&1 | findstr /R /C:"OS:" /C:"Discovered" /C:"Error" /C:"SQLSTATE"

echo.
echo [..] 4/4 运行 poller...
"%PHP%" "%LNMS_DIR%\poller.php" -h all 2>&1 | findstr /R /C:"Runtime" /C:"completed" /C:"Error"

echo.
echo ============================================
echo  系统已就绪：访问 http://127.0.0.1:5656
echo  模拟设备：
echo    HP    (127.0.0.2)  -- ProCurve 3500yl
echo    Cisco (127.0.0.3)  -- Catalyst 3850 IOS-XE
echo    Huawei(127.0.0.4)  -- S5700-28C-HI VRP
echo ============================================
pause
endlocal
