# LibreNMS 本地网络监控实验室

> 一个在 **Windows** 上完整运行的 LibreNMS 监控环境，附带自研的 SNMP 模拟器（支持 v1/v2c/**v3 authPriv**）和 **SSH LLDP 拓扑发现**工具。
>
> 无需 Linux、无需 MySQL、无需真实网络设备 —— 开箱即用的网络监控学习与演示环境。

---

## 目录

- [✨ 特性](#-特性)
- [🏗️ 系统架构](#️-系统架构)
- [📦 环境要求](#-环境要求)
- [🚀 快速开始](#-快速开始)
- [🔐 SNMPv3 安全配置](#-snmpv3-安全配置)
- [🔗 SSH 拓扑发现](#-ssh-拓扑发现)
- [📁 目录结构](#-目录结构)
- [🛠️ 开发与贡献](#️-开发与贡献)
- [📜 开源协议](#-开源协议)
- [📤 推送到 GitHub](#-推送到-github)
- [❓ 常见问题](#-常见问题)

---

## ✨ 特性

| 特性 | 说明 |
|------|------|
| 🖥️ **纯 Windows 运行** | PHP 7.4 + SQLite，无需 Linux/MySQL |
| 🌐 **LibreNMS Web UI** | 完整监控界面，设备/端口/告警/拓扑/地图 |
| 🎛️ **3 台模拟网络设备** | HP ProCurve 3500yl / Cisco IOS-XE / Huawei VRP |
| 🔒 **SNMPv3 authPriv** | HMAC-SHA-96 认证 + AES-128-CFB 加密（RFC 3414） |
| 🔄 **v1/v2c/v3 兼容** | 三套协议同时可用，平滑迁移 |
| 🛰️ **SSH 拓扑发现** | SNMP 不通时通过 SSH + LLDP 递归绘制网络拓扑 |
| 📊 **丰富样例数据** | 32 端口 / 传感器 / 告警规则 / syslog / 账单 |
| ⚡ **一键启动** | `start_local.cmd` 启动模拟器 + Web 服务 |

---

## 🏗️ 系统架构

```
┌─────────────────────────────────────────────────────────┐
│                    浏览器 (http://127.0.0.1:5656)          │
└──────────────────────────┬──────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────┐
│          LibreNMS (PHP 7.4 + Laravel + SQLite)            │
│  - Web UI / Discovery / Poller / Alerts                  │
│  - 通过 Symfony Process 调用外部 SNMP/fping 二进制          │
└──────┬──────────────────┬───────────────────┬───────────┘
       │ snmpget/walk     │ fping            │ (直接读库)
       ▼                  ▼                   ▼
┌──────────────┐  ┌──────────────┐  ┌────────────────────┐
│ netsnmp_wrap │  │  fping.php   │  │  SQLite DB         │
│  (PHP raw)   │  │  (模拟ICMP)  │  │  librenms.sqlite   │
└──────┬───────┘  └──────────────┘  └────────────────────┘
       │ UDP:11611
       ▼
┌─────────────────────────────────────────────────────────┐
│          SNMP 模拟器 (_tools/snmp_sim/)                    │
│  - SnmpAgent.php (v1/v2c/v3 USM)                          │
│  - devices.php (HP 127.0.0.2 / Cisco 127.0.0.3 / Huawei) │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│          SSH 拓扑发现 (可选)                                │
│  ssh_mock.php :2222  ←→  ssh_topology.php → links 表     │
└─────────────────────────────────────────────────────────┘
```

---

## 📦 环境要求

| 组件 | 版本 | 说明 |
|------|------|------|
| 操作系统 | Windows 10/11 | 推荐 x64 |
| PHP | 7.4.x (NTS x64) | 需启用 `snmp`、`sockets`、`pdo_sqlite`、`openssl`、`mbstring` 扩展 |
| 浏览器 | 任意现代浏览器 | 访问 Web UI |

> 本仓库不包含 PHP 二进制（体积过大），需自行下载 PHP 7.4 for Windows 并解压到 `_tools/php74/` 目录，或修改启动脚本指向本地 PHP。

---

## 🚀 快速开始

### 1. 准备 PHP

下载 [PHP 7.4 NTS x64 for Windows](https://windows.php.net/downloads/releases/php-7.4.33-nts-Win32-vc15-x64.zip)，解压到 `_tools/php74/`，确保 `php.exe` 可用。

### 2. 一键启动

```cmd
start_local.cmd
```

脚本会自动：
1. 启动 SNMP 模拟器（监听 127.0.0.2-4:11611）
2. 启动 LibreNMS Web 服务（http://127.0.0.1:5656）
3. 轮询等待就绪

### 3. 访问

- **Web UI**: http://127.0.0.1:5656
- **账号**: `admin` / `admin`

### 4. 启动 SSH 拓扑模拟（可选）

```cmd
_tools\php74\php.exe _tools\bin\ssh_mock.php
```

### 5. 执行 SSH 拓扑发现

```cmd
_tools\php74\php.exe _tools\bin\ssh_topology.php --start=127.0.0.2
```

执行后访问 http://127.0.0.1:5656/map/dependency 查看拓扑图。

### 6. 停止

```cmd
stop_local.cmd
```

---

## 🔐 SNMPv3 安全配置

### 为什么不用 SNMPv2c？

SNMPv2c 使用**明文 community 字符串**，抓包即可获取读写权限，存在严重安全风险。SNMPv3 提供：
- **认证 (Auth)**：HMAC 校验消息完整性与来源
- **加密 (Priv)**：AES 加密报文内容，防止窃听

### 本环境 v3 凭证

| 参数 | 值 |
|------|-----|
| Security Level | `authPriv`（认证+加密） |
| Username | `librenms` |
| Auth Protocol | `SHA` (HMAC-SHA-96) |
| Auth Password | `AuthPass123` |
| Priv Protocol | `AES` (AES-128-CFB) |
| Priv Password | `PrivPass456` |

### 命令行测试

```cmd
_tools\php74\php.exe _tools\bin\netsnmp_wrap.php snmpget ^
  -v3 -u librenms -a SHA -A AuthPass123 -x AES -X PrivPass456 -l authPriv ^
  -On 127.0.0.3:11611 .1.3.6.1.2.1.1.5.0
```

### 生产环境安全建议

1. 必须使用 `authPriv` 级别
2. 认证算法优先 `SHA-256` / `SHA-512`
3. 加密算法选 `AES-256`
4. 密码 ≥ 12 位，含大小写+数字+符号
5. ACL 限制 UDP 161 仅允许监控服务器访问
6. 定期轮换 SNMPv3 用户密码

### 配置位置

- 模拟器设备定义：`_tools/snmp_sim/devices.php`
- 模拟器入口：`_tools/snmp_sim/run.php`
- LibreNMS 默认凭证：`LibreNMS-offline/src/lnms/config.php`
- 设备级凭证：SQLite `devices` 表

---

## 🔗 SSH 拓扑发现

当设备**未开启 SNMP** 或 SNMP 不通时，可通过 SSH 登录设备执行 LLDP 命令，递归发现邻居并绘制拓扑。

### 工作流程

1. 从 `--start` 指定的设备 SSH 登录
2. 按厂商执行 LLDP 命令
3. 解析输出，提取邻居 chassisId / portId / sysName
4. 将可管理的邻居入队继续 BFS 遍历
5. 链路写入 LibreNMS `links` 表（`protocol='ssh-lldp'`）

### 厂商 LLDP 命令

| 厂商 | 命令 |
|------|------|
| Cisco | `show lldp neighbors detail` |
| Huawei | `display lldp neighbor` |
| HP / H3C | `show lldp info remote-device` |

### 接入真实设备

当前 `sshExec()` 使用 `fsockopen` 连接 mock 端点。接入真实设备时替换为 [phpseclib](https://phpseclib.com/)：

```php
$ssh = new \phpseclib\Net\SSH2($host, 22);
$ssh->login($user, $pass);
$output = $ssh->exec($cmd);
```

---

## 📁 目录结构

```
IT-network-Rack-Cable-trae/
├── start_local.cmd              # 一键启动脚本
├── stop_local.cmd               # 停止脚本
├── reset_local.cmd              # 重置数据库
├── LICENSE                      # GPLv3 协议
├── README.md                    # 本文档
├── .gitignore
│
├── docs/
│   └── deployment-troubleshooting.html   # 部署排错文档
│
├── _tools/
│   ├── snmp_sim/                # SNMP 模拟器（核心）
│   │   ├── SnmpAgent.php        # SNMP 代理 (v1/v2c/v3)
│   │   ├── Ber.php              # BER/ASN.1 编解码
│   │   ├── MibBuilder.php       # 内存 OID 树
│   │   ├── DeviceMib.php        # 设备 MIB 构建器
│   │   ├── devices.php          # 3 台设备定义
│   │   └── run.php              # 模拟器入口
│   │
│   ├── bin/                     # CLI 工具
│   │   ├── netsnmp_wrap.php     # net-snmp 包装器 (raw UDP + v3)
│   │   ├── ssh_mock.php         # SSH 命令行模拟器
│   │   ├── ssh_topology.php     # SSH LLDP 拓扑发现
│   │   ├── fping.php            # fping 模拟
│   │   ├── snmpget/walk/bulkwalk/getnext/translate.{cmd,bat}
│   │   └── seed_sample_data.php # 样例数据播种
│   │
│   └── php74/                   # PHP 7.4 运行时（需自行下载，已 gitignore）
│
└── LibreNMS-offline/
    └── src/lnms/                # LibreNMS 源码（修改版）
        ├── config.php           # 指向 _tools/bin 包装器
        ├── .env                 # SQLite 配置
        ├── app/
        │   ├── Providers/AppServiceProvider.php  # SQLite REGEXP UDF
        │   └── Translation/WindowsSafeTranslator.php
        └── storage/librenms.sqlite  # 数据库（已 gitignore，用 seed 脚本重建）
```

---

## 🛠️ 开发与贡献

### 开发更新使用协议（Contributor Agreement）

本项目采用 **GPLv3** 开源协议。所有贡献者需遵守以下约定：

1. **代码许可**：你提交的所有代码默认以 GPLv3 协议授权，与项目整体协议一致。
2. **原创声明**：你保证提交的代码为原创或已获得合法授权，不侵犯第三方知识产权。
3. **衍生作品**：基于本项目修改或衍生的作品必须同样以 GPLv3 开源，并保留原始版权声明。
4. **专利授权**：贡献者授予项目永久、全球、免版税的专利许可，用于实现本项目功能。
5. **不提供担保**：贡献者按"现状"提供代码，不承担任何质量担保责任。
6. **署名要求**：衍生作品需保留原作者（yanghaoji@126.com）及贡献者的版权声明。

### 贡献流程

1. Fork 本仓库
2. 创建功能分支：`git checkout -b feature/your-feature`
3. 提交修改：`git commit -m "feat: 描述你的修改"`
4. 推送分支：`git push origin feature/your-feature`
5. 提交 Pull Request

### 提交信息规范

```
<type>: <subject>

<body>

<footer>
```

- `type`: `feat`(新功能) / `fix`(修复) / `docs`(文档) / `refactor`(重构) / `test`(测试) / `chore`(构建)
- `subject`: 简明描述（不超过 50 字符）
- `body`: 详细说明（可选）

### 代码规范

- PHP 遵循 [PSR-12](https://www.php-fig.org/psr/psr-12/)
- 提交前确保无语法错误：`php -l <file>`
- 模拟器代码需兼容 PHP 7.4

---

## 📜 开源协议

本项目基于 [GNU General Public License v3.0](LICENSE) 开源。

```
Copyright (C) 2026 yanghaoji (yanghaoji@126.com)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

> 本项目包含 LibreNMS 衍生代码（GPLv3），因此整体采用 GPLv3 协议以保持兼容。

---

## 📤 推送到 GitHub

### 前提：安装 Git

当前环境未检测到 Git，请先安装：
- 下载：https://git-scm.com/download/win
- 安装时勾选 "Add Git to PATH"

### 首次推送步骤

```cmd
:: 1. 进入项目目录
cd e:\IT-network-Rack-Cable-trae

:: 2. 初始化仓库
git init

:: 3. 配置用户信息
git config user.name "yanghaoji"
git config user.email "yanghaoji@126.com"

:: 4. 添加所有文件（.gitignore 会自动排除大文件和依赖）
git add .

:: 5. 首次提交
git commit -m "feat: initial commit - LibreNMS local lab with SNMPv3 and SSH topology"

:: 6. 在 GitHub 新建仓库后，添加远程地址
git remote add origin https://github.com/<your-username>/<repo-name>.git

:: 7. 推送到 GitHub
git branch -M main
git push -u origin main
```

### 日常更新

```cmd
git add .
git commit -m "fix: 修复 xxx 问题"
git push
```

---

## ❓ 常见问题

| 问题 | 解决方案 |
|------|----------|
| SNMP 模拟器未响应 | 检查 `_tools/snmp_sim/run.php` 进程，确认端口 11611 未被占用 |
| SNMPv3 认证失败 | 确认凭证：`-u librenms -A AuthPass123 -X PrivPass456 -a SHA -x AES -l authPriv` |
| Web 页面无法访问 | 确认 PHP 内置服务器运行，端口 5656 可用 |
| MIB 模块找不到警告 | 无害警告，设置环境变量 `MIBDIRS` 指向 `LibreNMS-offline/src/lnms/mibs` |
| 拓扑图不显示链路 | 执行 `ssh_topology.php` 后刷新 `/map/dependency` 页面 |

详细排错见 [docs/deployment-troubleshooting.html](docs/deployment-troubleshooting.html)。

---

## 📧 联系方式

- 作者：yanghaoji
- 邮箱：yanghaoji@126.com
- 项目地址：（推送 GitHub 后填写）

---

*本项目仅供网络监控学习与演示使用，请勿用于生产环境。*
