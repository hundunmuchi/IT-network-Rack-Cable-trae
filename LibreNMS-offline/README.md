# LibreNMS 麒麟桌面 V10 SP1 完全离线部署包

把 LibreNMS 网络监控系统封装成**真正自包含的离线部署包**：**全程无需任何联网**，
**在 Windows 端已经构建完成**，目标麒麟机完全离线即可一键部署。

目标环境：**麒麟桌面版 V10 SP1（deb 系，x86_64）+ 海光 Hygon C86-3G + 内核 5.4.18-110-generic**。

> LibreNMS 正是网络/机柜交换机 IT 设备的监控工具：SNMP 采集、LLDP/CDP 链路发现、拓扑、端口、告警。

---

## 一、这个包里已经有什么（全部就绪，拷贝即用）

| 组成 | 说明 |
|------|------|
| `src/lnms/` | **LibreNMS 22.10.0** 完整源码 + **vendor/（110 个 PHP 依赖，已在 Windows 端生成）** |
| `src/composer.phar` | 内置 Composer（备用） |
| `osrepo/debs/` | **320 个 .deb + Packages/Packages.gz 本地索引 + SHA256SUMS**（从麒麟官方源快照） |
| `scripts/` | 预检 / 一键部署 / 自检 三个脚本（+ 备用生成脚本） |
| `config/lnms/` | nginx / php-fpm / config.php 参考模板 |

**软件源来源**：`http://archive.kylinos.cn/kylin/KYLIN-ALL 10.1`（麒麟桌面 V10 SP1 官方源，main+universe），
已按需解析完整依赖闭包（nginx 1.18、PHP 7.4.3、MariaDB 10.3.31、rrdtool、snmp、fping、mtr、python3 等）。

---

## 二、使用方法（目标机完全离线）

把整个 `LibreNMS-offline` 目录拷到目标机任意位置（如 `/root/LibreNMS-offline`），然后：

```bash
cd /root/LibreNMS-offline
sudo bash scripts/preflight_check.sh        # ① 预检
sudo bash scripts/stage2_deploy_offline.sh  # ② 离线一键部署
```

部署脚本会自动：
1. 从本地 `osrepo/debs` 离线安装全部系统依赖（apt file:// 本地源，`--no-install-recommends`）；
2. 部署 LibreNMS 到 `/opt/librenms`，创建 `librenms` 用户与权限；
3. 配置 php-fpm / nginx / mariadb；
4. 初始化数据库（`.env` + `artisan key:generate` + `migrate --seed --force`）；
5. 创建管理员账号，配置 cron / logrotate / 防火墙；
6. 运行 `selfcheck.sh` 自检并打印访问地址、账号、密码。

### 可调参数（环境变量）

| 变量 | 默认 | 说明 |
|------|------|------|
| `LNMS_DIR` | `/opt/librenms` | 安装目录 |
| `LNMS_APP_URL` | `http://<主机名>` | 对外访问地址 |
| `LNMS_DB_PASS` | 随机 | 数据库密码 |
| `LNMS_ADMIN_USER` / `LNMS_ADMIN_PASS` | `admin` / 随机 | 管理员 |
| `LNMS_TIMEZONE` | `Asia/Shanghai` | 时区 |
| `FORCE_REINSTALL` | 0 | =1 覆盖已存在的 `/opt/librenms`（**会删数据，谨慎**） |

示例：
```bash
sudo LNMS_APP_URL=http://192.168.1.50 LNMS_ADMIN_PASS='YourPass' bash scripts/stage2_deploy_offline.sh
```

---

## 三、部署后

- 浏览器打开访问地址 → 管理员登录。
- 添加设备：`Devices → Add Device`，填 IP 与 SNMP 团体字串（默认 `public`），LibreNMS 自动发现端口/链路并画拓扑。
- 常用命令（以 `librenms` 用户）：
  ```bash
  sudo -u librenms php /opt/librenms/artisan device:add <ip> --v1 -c public
  ```
- 每日调度由 `/etc/cron.d/librenms` 接管（每 5 分钟采集 + 每分钟 `artisan schedule:run` + 每日维护）。
- 离线环境已设 `update=0`，避免自动更新触发 git 拉取。

---

## 四、日志与排错

所有脚本都会把输出**同时**写到终端和 `logs/` 下的日志文件（追加写入，保留多次运行记录）：

| 日志文件 | 来源 | 内容 |
|----------|------|------|
| `logs/stage2.log` | 部署 | 部署全过程的步骤、结果、错误（带时间戳） |
| `logs/stage2.trace.log` | 部署 | `set -x` 逐条命令追踪（含每条命令的退出码），用于精确定位失败命令 |
| `logs/preflight.log` | 预检 | 预检各项结果与警告 |
| `logs/selfcheck.log` | 自检 | 部署后自检各项结果 |

**排错建议：**
1. 部署失败 → 先看 `logs/stage2.log` 中最后一条 `[错误]` / `[警告]` 所在的**步骤号**；
2. 若主日志不足以判断 → 看 `logs/stage2.trace.log`，按时间对应失败步骤，能看到**实际执行的完整命令**及其退出码；
3. 自检失败 → 对照 `logs/selfcheck.log` 的 `[错误]` 项定位（缺 PHP 模块 / 缺程序 / 服务未起 / Web 无响应）；
4. 如需控制日志体积，设 `LNMS_DEBUG=0` 可关闭命令追踪（默认 1=开启）；追踪日志含实际执行的命令（可能含密码），排错后可删除。

> 提示：`logs/` 在出厂时为空，首次在目标机运行 `preflight` / `stage2` 后才生成上述日志。

---

## 五、已完成的验证（出厂状态）

| 校验项 | 结果 |
|--------|------|
| LibreNMS 版本 / PHP 兼容 | 22.10.0，支持 PHP 7.3+/8.0，匹配麒麟源 PHP 7.4.3 |
| 数据库版本 | MariaDB 10.3.31（≥ LibreNMS 下限 10.2.2，满足） |
| PHP 依赖 vendor | 110 个包已生成（68.9MB），autoload.php 就绪 |
| 离线软件源 | 320 个 .deb + Packages 索引 + SHA256SUMS 已生成，闭包解析无缺失 |
| 脚本语法 | `bash -n` 全部通过 |
| 数据库迁移方式 | 与源码一致（`.env` + `artisan migrate --seed --force`） |
| 调度方式 | 官方 `librenms.nonroot.cron`（含 `artisan schedule:run`） |

## 五、限制与须知

1. **实机验证只能在目标机完成**：Windows 无法装 deb/systemd/PHP。部署脚本内置 `selfcheck.sh` 会在目标机做实机自检，请以它的输出为准。
2. **离线源与目标 OS 匹配**：本包源快照针对麒麟桌面 V10 SP1 x86_64（`10.1` 源）。若目标机是其它大版本/其它源，请用 `scripts/stage1_build_offline_package.sh`（在联网的同款麒麟机上）重新生成 `osrepo/`。
3. **Python 依赖**：核心 PyMySQL 由 `python3-pymysql` 系统包覆盖（LibreNMS 的 check_requirements.py 仅校验 PyMySQL）；个别可选功能（如 `command_runner`）若需可另行用 pip 补齐。
4. **MariaDB 10.3**：满足 LibreNMS 下限，但低于官方推荐 10.5；功能正常，个别高级特性可能受限。
5. **SELinux**：若目标机为 Enforcing，部署脚本已做尽力而为的上下文配置；失败需人工处理（见官方文档 SELinux 节）。
6. **离线源字段完整性**：`osrepo/debs/Packages` 已保留源的全部字段（含 `Multi-Arch`/`Provides`，这是满足 `python3:any` 等 `:any` 依赖的必要条件）；部署脚本使用**隔离的 apt 列表目录**，不受目标机残留的旧 apt 缓存干扰。若你此前部署失败，只需把更新后的 `osrepo/debs/Packages`、`Packages.gz` 与 `scripts/stage2_deploy_offline.sh` 覆盖到目标机后**重跑一次**即可，无需其它清理。
7. **目标机已装更高版本库**：若目标机已装有比麒麟源更新的库（如 `libsnmp35` 2.6，而本地/源为 2.3），`snmp` 的精确 `=` 依赖会冲突。部署脚本已加 `--allow-downgrades`（允许降级到与源一致的版本）并内置“已装 net-snmp 状态诊断”与冲突包移除兜底，通常一次即可通过；部署日志会打印目标机已装版本供核对。
8. **无关预装包 postinst 失败不阻断**：目标机可能预装有驱动类包（如 `aic8800fdrvpackage` 无线网卡驱动）的 postinst 脚本编译/加载失败，导致 `dpkg` 整体返回非零而让 `apt` 报"失败"。部署脚本已改为"失败后**按关键包实际安装状态校验**"——只要 LibreNMS 所需依赖均已装成 `ii` 即继续后续步骤，并尽力修复该无关包的 dpkg 半配置状态。
9. **独立监听端口（避免与既有网站冲突）**：LibreNMS 默认绑定 **8080** 端口（脚本顶部 `LNMS_HTTP_PORT` 可改），不与系统既有网站的 80 端口冲突。部署时会先做端口占用检查，若所选端口被占用会中止并提示改端口。`.env` 的 `APP_URL`、nginx 站点监听、防火墙放行、自检 Web 检查均自动跟随该端口。访问地址形如 `http://<主机名>:8080`。
