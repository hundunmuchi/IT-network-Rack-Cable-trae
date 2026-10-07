# LibreNMS 本地化部署会话总结

> 会话日期：2026-10-06  
> 工作目录：`e:\IT-network-Rack-Cable-trae`  
> 目标：在本地 Windows 环境运行 LibreNMS + 模拟 HP/Cisco/Huawei 网络设备

---

## 1. 项目背景

LibreNMS 是基于 PHP/Laravel 的网络监控系统，原生仅支持 Linux。本会话目标：
1. 在本地 Windows 上让 LibreNMS 跑起来（PHP 7.4 + SQLite）
2. 部署本地模拟网络环境，包含 HP / Cisco / Huawei 三种网络设备
3. 让整个监控链路（discovery → poller → Web UI）端到端跑通

---

## 2. 完成的工作

### 2.1 本地运行环境
- ✅ 使用 PHP 7.4.33 + SQLite（无需 MySQL）
- ✅ 启用 PHP SNMP 扩展（`php_snmp.dll`）
- ✅ Laravel 配置：`.env` 设置 `DB_CONNECTION=sqlite`，`INSTALL=false`
- ✅ 通过 PHP 内置服务器 `php -S 127.0.0.1:5656 server.php` 运行 Web

### 2.2 SNMP 模拟器（纯 PHP 实现）
- ✅ `Ber.php` — BER/ASN.1 编解码器（INTEGER/OCTET STRING/OID/SEQUENCE/IpAddress/Counter32/TimeTicks 等）
- ✅ `MibBuilder.php` — 内存 OID 树构建
- ✅ `DeviceMib.php` — 设备 MIB 构建器
- ✅ `devices.php` — 3 个设备定义（HP ProCurve / Cisco IOS-XE / Huawei VRP）
- ✅ `SnmpAgent.php` — PHP SNMP 代理
- ✅ `run.php` — 模拟器入口，监听 127.0.0.2-4:11611，community=public

### 2.3 net-snmp CLI 包装器
- ✅ `netsnmp_wrap.php` — 模拟 snmpget/snmpwalk/snmpbulkwalk/snmpgetnext/snmptranslate
- ✅ 实现 raw UDP GETNEXT（PHP `snmp2_getnext` 不返回 OID）
- ✅ 格式化输出为 `OID = "value"` 形式（LibreNMS SnmpResponse 解析器期望）
- ✅ `.bat` 副本（PHP `is_executable` 在 Windows 不识别 `.cmd`）
- ✅ `fping.php` — 模拟 fping，输出到 STDERR（LibreNMS 从 STDERR 读取）

### 2.4 LibreNMS 配置
- ✅ `config.php` — 指向包装器路径（用正斜杠避免 `whereis` 调用）
- ✅ `AppServiceProvider.php` — 注册 SQLite REGEXP UDF（SQLite 无原生 REGEXP）
- ✅ `AppServiceProvider.php` — 注册 WindowsSafeTranslator（解决 Windows 大小写不敏感问题）
- ✅ `WindowsSafeTranslator.php` — 扩展 Laravel 翻译器，避免 `__('Plugins')` 等冲突

### 2.5 样例数据
- ✅ 3 台设备：HP ProCurve 3500yl / Cisco IOS-XE 17.6.1 / Huawei VRP 5.170
- ✅ 32 个端口（HP 15 + Cisco 9 + Huawei 8）
- ✅ 3 个 IPv4 地址（10.0.0.2/3/4）
- ✅ 3 个位置（北京 HQ 大厦 A/C、上海 DC 大厦 B，含 GPS 坐标）
- ✅ 6 个设备分组（Core Switches / 按厂商 / 按地域）
- ✅ 6 条告警规则（设备宕机 / 端口 down / CPU>80% / 内存>90% / 磁盘>85% / 接口错误）
- ✅ 12 个传感器（温度 / 湿度 / 风扇转速 / 电压）
- ✅ 3 个内存池（HP 50% / Cisco 60% / Huawei 60%）
- ✅ 3 个存储（flash / bootflash，25%/40%/37% 已用）
- ✅ 3 个 CPU 处理器（HP 23% / Cisco 58% / Huawei 41%）
- ✅ 15 条 syslog 日志（链路 up/down、STP、OSPF、环境告警等）
- ✅ 76 条事件日志（设备发现、传感器告警、端口状态变更等）
- ✅ 3 条 LLDP 链路（HP↔Cisco↔Huawei↔HP 环形拓扑）
- ✅ 6 条 MAC 表（FDB）记录
- ✅ 1 个带宽账单（95th percentile，CUST-001）

---

## 3. 关键文件清单

### 模拟器与工具
| 路径 | 用途 |
|---|---|
| `_tools/snmp_sim/Ber.php` | BER/ASN.1 编解码 |
| `_tools/snmp_sim/MibBuilder.php` | 内存 OID 树 |
| `_tools/snmp_sim/DeviceMib.php` | 设备 MIB 构建器 |
| `_tools/snmp_sim/devices.php` | 3 设备定义 |
| `_tools/snmp_sim/SnmpAgent.php` | PHP SNMP 代理 |
| `_tools/snmp_sim/run.php` | 模拟器入口 |
| `_tools/bin/netsnmp_wrap.php` | net-snmp CLI 包装器 |
| `_tools/bin/snmpget.{cmd,bat}` | snmpget 包装 |
| `_tools/bin/snmpwalk.{cmd,bat}` | snmpwalk 包装 |
| `_tools/bin/snmpbulkwalk.{cmd,bat}` | snmpbulkwalk 包装 |
| `_tools/bin/snmpgetnext.{cmd,bat}` | snmpgetnext 包装 |
| `_tools/bin/snmptranslate.{cmd,bat}` | snmptranslate 包装 |
| `_tools/bin/fping.php` | fping 包装 |
| `_tools/bin/fping.{cmd,bat}` | fping 启动脚本 |

### 数据播种脚本
| 路径 | 用途 |
|---|---|
| `_tools/bin/seed_sample_data.php` | 主样例数据播种（位置/分组/告警/传感器/内存/存储/syslog/事件日志/链路/FDB/账单） |
| `_tools/bin/add_ports.php` | 为 Cisco/Huawei 补端口 + IPv4 + LLDP 链路 |
| `_tools/bin/add_procs.php` | 为 Cisco/Huawei 补 CPU 处理器 |
| `_tools/bin/reset_admin_pass.php` | 重置 admin 密码为 `admin`（bcrypt） |
| `_tools/bin/check_db_state.php` | 检查 DB 状态 |
| `_tools/bin/check_devices.php` | 检查 devices 表 |
| `_tools/bin/clear_db_config.php` | 清理 DB 中的 binary 配置 |
| `_tools/bin/describe_devices.php` / `describe_more.php` | 查看 DB schema |

### LibreNMS 修改
| 路径 | 修改内容 |
|---|---|
| `LibreNMS-offline/src/lnms/.env` | SQLite 配置、`INSTALL=false`、APP_KEY |
| `LibreNMS-offline/src/lnms/config.php` | 指向 _tools/bin 包装器路径 |
| `LibreNMS-offline/src/lnms/app/Providers/AppServiceProvider.php` | 注册 SQLite REGEXP UDF + WindowsSafeTranslator |
| `LibreNMS-offline/src/lnms/app/Translation/WindowsSafeTranslator.php` | 解决 Windows 大小写不敏感的 lang 文件冲突 |
| `LibreNMS-offline/src/lnms/storage/librenms.sqlite` | SQLite 数据库（含 3 设备 + 样例数据） |

### 启动脚本
| 路径 | 用途 |
|---|---|
| `start_local.cmd` | 一键启动（SNMP 模拟器 + LibreNMS Web） |

---

## 4. 启动与停止

### 启动
```cmd
e:\IT-network-Rack-Cable-trae\start_local.cmd
```
或手动分两步：
```cmd
:: 1. 启动 SNMP 模拟器
e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe e:\IT-network-Rack-Cable-trae\_tools\snmp_sim\run.php

:: 2. 启动 LibreNMS Web（在另一个窗口）
cd e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms
e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe -S 127.0.0.1:5656 server.php
```

### 停止
关闭对应窗口的 PHP 进程，或用任务管理器结束所有 `php.exe`。

### 访问
- Web UI: http://127.0.0.1:5656/login
- 用户名: `admin`
- 密码: `admin`

### 重置样例数据
```cmd
e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe e:\IT-network-Rack-Cable-trae\_tools\bin\seed_sample_data.php
e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe e:\IT-network-Rack-Cable-trae\_tools\bin\add_ports.php
e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe e:\IT-network-Rack-Cable-trae\_tools\bin\add_procs.php
```

---

## 5. 遇到的主要问题与解决方案

| 问题 | 原因 | 解决方案 |
|---|---|---|
| `snmp2_getnext` 不返回 OID | PHP SNMP 模块限制 | 用 raw UDP socket 自己实现 GETNEXT（`rawSnmpGetnext()`） |
| Ber::parseTlv 返回关联数组 | API 设计 | 改用 `$tlv['tag']` / `$tlv['value']` |
| 字符串值未带引号 | SnmpResponse 期望引号 | 在 `formatVarbind()` STRING case 中加引号转义 |
| `fping` 输出到 STDOUT | LibreNMS 从 STDERR 读 | 改 `fwrite(STDERR, ...)` |
| PHP SNMP 模块加载时输出 MIB 警告污染 STDERR | MIBDIRS 未设置 | 在 fping.cmd 中设置 `MIBDIRS` 环境变量 |
| LibreNMS 调用 Linux `whereis` 失败 | `Config::locateBinary()` 在路径不含 `/` 时调用 whereis | 在 config.php 用正斜杠路径 |
| `is_executable` 不识别 `.cmd` | Windows PHP 限制 | 创建 `.bat` 副本 |
| `is_executable('foo.bat')` 返回 false | Windows PHP 对 .bat 也返回 false | 无法绕过，需要其他方式（见下） |
| `__('Plugins')` 返回数组 | Windows 大小写不敏感文件系统使 `Plugins.php` 匹配 `plugins.php` | 创建 `WindowsSafeTranslator` 检测并回退 |
| SQLite 无 REGEXP 函数 | SQLite 不支持 MySQL REGEXP | 在 AppServiceProvider 注册 PHP 实现的 UDF |
| Login 页 `/@vite/client` 404 | 浏览器缓存或 service worker | Laravel 自动 302 重定向到 /login，无需处理 |
| `htmlspecialchars() expects parameter 1 to be string, array given` | 上述翻译器冲突 | WindowsSafeTranslator 解决 |
| storage 重复插入失败 | UNIQUE 约束 (device_id, storage_mib, storage_index) | DELETE 全表再插入 |
| sensors sensor_custom CHECK 失败 | enum 期望 'No'/'Yes' 不是 0/1 | 改传 `'No'` |
| Syslog 表无 `datetime` 列 | schema 实际是 `timestamp` | 用 `timestamp` 列 |

---

## 6. 当前系统状态

### 已完成
- ✅ LibreNMS Web 可正常访问（HTTP 200，登录页样式正常）
- ✅ admin 用户密码已重置为 `admin`（bcrypt 验证通过）
- ✅ 3 台模拟设备（HP/Cisco/Huawei）能响应 SNMP 查询
- ✅ 设备已添加到 LibreNMS SQLite DB（device_id 2/3/4）
- ✅ 32 个端口发现并入库
- ✅ 完整样例数据（分组/告警/传感器/内存/存储/syslog/事件日志/链路/FDB/账单）

### 仍可继续的工作
- ⚠️ LibreNMS `Config::get('fping')` 仍可能返回默认值 `'fping'`，需 patch `Config.php` 的 `processConfig()` 在 Windows 跳过 persist
- ⚠️ Discovery 自动 polling 未完全跑通（设备状态、端口数据已通过手动播种补齐）
- ⚠️ `stage2_deploy_offline.sh` 中 Kylin 上 HTTP 000 问题（todo #7）
- ⚠️ `selfcheck.sh` 中 Web 检测可加强（todo #8）
- ⚠️ 一键启动脚本 [start_local.cmd](file:///start_local.cmd) 可优化（todo #9）

---

## 7. 关键技术细节

### 7.1 LibreNMS 配置加载流程
```
Config::load()
  → loadDefaults()      // 从 config_definitions.json
  → loadDB()            // 从 DB config 表
  → loadUserConfigFile() // 从 config.php
  → processConfig()     // 验证 + 持久化 binary 路径
```

`processConfig()` 中的 binary 循环会在 `is_executable()` 失败时调用 `persist()`，导致 DB 中存入默认值覆盖 config.php 的设置。Windows 上 `is_executable` 对 `.bat` 也返回 false，所以这个问题在 Windows 上无法绕过，除非直接 patch `Config.php`。

### 7.2 SNMP 协议细节
- LibreNMS 通过 Symfony Process 调用外部 `snmpget`/`snmpwalk`/`snmpbulkwalk`/`snmpgetnext`/`snmptranslate`/`fping` 二进制
- 默认 flags: `-OQXUte`（quick print + extended index + enum as int + raw timeticks + raw hex）
- SnmpResponse 解析器期望 `OID = value` 格式，字符串值需带引号

### 7.3 Windows 大小写不敏感问题
- `__('Plugins')` 在 Linux 上找不到 `Plugins.php` 文件，返回原字符串
- 在 Windows 上 `file_exists('Plugins.php')` 匹配到 `plugins.php`（小写），返回整个 lang 文件数组
- 影响 `__('Plugins')`、`__('Smokeping')` 等所有首字母大写且与 lang 文件同名的调用
- 解决方案：`WindowsSafeTranslator` 在 `getLine()` 中检测这种情况并返回 null，让 Laravel 回退到原 key

---

## 8. 主要展示页面

| URL | 内容 |
|---|---|
| `/` | Dashboard 概览 |
| `/devices` | 设备列表 |
| `/device/2` | HP 设备详情 |
| `/device/3` | Cisco 设备详情 |
| `/device/4` | Huawei 设备详情 |
| `/maps` | 地理地图（北京/上海标记） |
| `/map/dependency` | LLDP 拓扑依赖图 |
| `/alerts/rules` | 6 条告警规则 |
| `/syslog` | syslog 列表 |
| `/eventlog` | 事件日志 |
| `/groups` | 6 个设备分组 |
| `/billing` | 带宽账单 |
| `/ports` | 全局端口列表 |

---

*本文档由 2026-10-06 的会话生成，记录了 LibreNMS 本地化部署的完整过程。*
