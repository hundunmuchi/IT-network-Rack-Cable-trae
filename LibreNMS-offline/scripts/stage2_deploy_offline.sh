#!/usr/bin/env bash
###############################################################################
#  stage2_deploy_offline.sh —— LibreNMS 离线一键部署
#
#  【在哪里运行】
#    在【离线目标机】麒麟 V10 SP1（Hygon C86-3G，x86_64）上、以 root 运行。
#    前置：已运行过 stage1 生成离线仓库（或该目录已自带 osrepo/ 与 vendor/）。
#
#  【做什么】
#    1. 从本地离线仓库安装 PHP/nginx/mariadb/rrdtool/net-snmp 等全部依赖
#    2. 从 osrepo/pip 离线安装 Python 依赖
#    3. 部署 LibreNMS 到 /opt/librenms，创建用户与权限
#    4. 配置 php-fpm / nginx / mariadb
#    5. 初始化数据库（.env + key:generate + migrate --seed --force）
#    6. 创建管理员账号，配置 cron / logrotate / 防火墙
#    7. 运行 selfcheck 自检并给出访问地址
#
#  用法:
#    sudo bash scripts/stage2_deploy_offline.sh
#  可调环境变量:
#    LNMS_DIR      安装目录           默认 /opt/librenms
#    LNMS_APP_URL  对外访问地址        默认 http://$(hostname)
#    LNMS_DB_PASS  数据库密码          默认随机生成
#    LNMS_ADMIN_USER / LNMS_ADMIN_PASS 管理员账号  默认 admin/随机
#    LNMS_TIMEZONE 时区               默认 Asia/Shanghai
#    FORCE_REINSTALL=1  覆盖已存在的 /opt/librenms（谨慎）
#    LNMS_DEBUG=0   关闭命令追踪日志（默认 1=开启，用于排错）
#
#  【日志】
#    主日志:  $BUNDLE/logs/stage2.log   （终端同步显示，追加写入）
#    追踪日志: $BUNDLE/logs/stage2.trace.log （set -x 逐条命令，仅写文件，便于定位失败命令）
###############################################################################
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$SCRIPT_DIR/.." && pwd)"
OSREPO="$BUNDLE/osrepo"
LNSM_SRC="$BUNDLE/src/lnms"
LOG_DIR="$BUNDLE/logs"
LOG_FILE="$LOG_DIR/stage2.log"
TRACE_FILE="$LOG_DIR/stage2.trace.log"
mkdir -p "$LOG_DIR"

# 主日志：终端 + 追加写文件；命令追踪：仅写 TRACE_FILE（不污染主日志）
: > "$TRACE_FILE" 2>/dev/null || true
exec > >(tee -a "$LOG_FILE") 2>&1
exec 4> >(sed -u 's/^/    >> /' >> "$TRACE_FILE")
if [[ "${LNMS_DEBUG:-1}" == "1" ]]; then
    BASH_XTRACEFD=4
    set -x
fi

echo "=================== LibreNMS offline deploy (stage2) ==================="
echo "bundle   : $BUNDLE"
echo "log      : $LOG_FILE"
echo "trace    : $TRACE_FILE"
echo "started  : $(date '+%F %T')"

die() { echo " [错误] $* （详见日志: $LOG_FILE）" >&2; exit 1; }
info() { echo " [ .. ] $*"; }
ok()   { echo " [ OK ] $*"; }
warn() { echo " [警告] $*"; }
step() { echo; echo "===== [$(date '+%F %T')] $* ====="; }

[[ "$EUID" -ne 0 ]] && die "请以 root 运行: sudo bash $0"

# ---------- 可调配置 ----------
LNMS_DIR="${LNMS_DIR:-/opt/librenms}"
LNMS_USER="${LNMS_USER:-librenms}"
LNMS_DB="${LNMS_DB:-librenms}"
LNMS_DB_USER="${LNMS_DB_USER:-librenms}"
LNMS_DB_PASS="${LNMS_DB_PASS:-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20)}"
LNMS_HTTP_PORT="${LNMS_HTTP_PORT:-5656}"
LNMS_APP_URL="${LNMS_APP_URL:-http://$(hostname):$LNMS_HTTP_PORT}"
LNMS_ADMIN_USER="${LNMS_ADMIN_USER:-admin}"
LNMS_ADMIN_PASS="${LNMS_ADMIN_PASS:-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16)}"
LNMS_TIMEZONE="${LNMS_TIMEZONE:-Asia/Shanghai}"

# ---------- 识别包管理器 ----------
PKGMGR=""
command -v dnf >/dev/null 2>&1 && PKGMGR="rpm"
command -v yum  >/dev/null 2>&1 && PKGMGR="rpm"
command -v apt-get >/dev/null 2>&1 && PKGMGR="deb"
[[ -z "$PKGMGR" ]] && die "无法识别包管理器"
info "包管理器: $PKGMGR"

# 根据包管理器确定 Web 运行用户 / PHP-FPM 相关路径（FPM_POOL_DIR/FPM_INI 依赖 PHP 安装，
# 故延后到"步骤 4 配置 PHP-FPM"时再按已装版本计算，避免此刻 /etc/php 尚为空导致路径错误）
WEB_USER="nginx"; FPM_SOCK_DIR="/run/php-fpm"; NGINX_SITED="/etc/nginx/conf.d"
if [[ "$PKGMGR" == "deb" ]]; then WEB_USER="www-data"; FPM_SOCK_DIR="/run/php"; NGINX_SITED="/etc/nginx/sites-available"; fi
FPM_SOCK="$FPM_SOCK_DIR/librenms.sock"
FPM_POOL_DIR="/etc/php-fpm.d"; FPM_INI="/etc/php.ini"; FPM_SERVICE="php-fpm"   # rpm 系占位；deb 系在步骤4按已装 PHP 版本覆盖

info "WEB_USER=$WEB_USER  FPM_SOCK=$FPM_SOCK  FPM_POOL_DIR=$FPM_POOL_DIR"

# 离线仓库完整性检查
[[ -d "$LNSM_SRC" ]] || die "缺少 src/lnms，离线包不完整"
[[ -f "$BUNDLE/src/composer.phar" ]] || warn "缺少 composer.phar（如 vendor 已存在可忽略）"

# ---------- 端口占用预检：每次启动先检查目标端口，被占用则不进行后续步骤 ----------
port_used() { (ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null) | grep -qE "[:.]$1\b"; }
if port_used "$LNMS_HTTP_PORT"; then
    die "端口 $LNMS_HTTP_PORT 已被占用，部署中止。请先释放该端口，或在脚本顶部把 LNMS_HTTP_PORT 改为其它空闲端口后重跑"
fi
info "端口 $LNMS_HTTP_PORT 空闲，将用于 LibreNMS 站点"

# ---------- 步骤 1: 从离线仓库安装系统依赖 ----------
step "步骤 1/7 安装系统依赖（离线仓库）"
RPM_PKGS="cronie fping git ImageMagick mariadb-server mariadb mtr net-snmp net-snmp-utils nginx php-fpm php-cli php-common php-curl php-gd php-mbstring php-process php-snmp php-xml php-zip php-mysqlnd php-ldap rrdtool python3 python3-pip"
DEB_PKGS="nginx php-fpm php-cli php-common php-curl php-gd php-mbstring php-mysql php-snmp php-xml php-zip php-ldap php-intl php-bcmath mariadb-server mariadb-client rrdtool snmp snmpd fping mtr python3 python3-pip python3-pymysql python3-dotenv python3-redis python3-psutil git imagemagick whois cron logrotate"

if [[ "$PKGMGR" == "rpm" ]]; then
    PKG_DIR="$OSREPO/rpms"
    if [[ -d "$PKG_DIR/repodata" ]]; then
        REPO="/etc/yum.repos.d/librenms-offline.repo"
        cat > "$REPO" <<EOF
[librenms-offline]
name=LibreNMS Offline Repository
baseurl=file://$PKG_DIR
enabled=1
gpgcheck=0
EOF
        info "已写入本地源 $REPO"
        dnf -y --disablerepo='*' --enablerepo=librenms-offline install $RPM_PKGS || die "离线安装系统依赖失败"
    else
        warn "osrepo/rpms 缺少 repodata，将尝试逐个 rpm 安装"
        ( cd "$PKG_DIR" && rpm -Uvh --nodeps --force *.rpm ) || warn "rpm 批量安装存在失败，请检查"
    fi
else
    PKG_DIR="$OSREPO/debs"
    if [[ -f "$PKG_DIR/Packages.gz" ]]; then
        SRC="/etc/apt/sources.list.d/librenms-offline.list"
        cat > "$SRC" <<EOF
deb [trusted=yes] file://$PKG_DIR ./
EOF
        export DEBIAN_FRONTEND=noninteractive
        # 用独立列表目录：避免 apt 读到目标机残留的 /var/lib/apt/lists 旧缓存
        APT_LISTS="$LOG_DIR/apt-lists"
        mkdir -p "$APT_LISTS"
        # 诊断：目标机已装的高版本库（如 libsnmp35 2.6）是"精确依赖冲突"常见来源
        info "已装 net-snmp 状态诊断（若显示 2.6 即与本地 2.3 冲突）："
        dpkg -l libsnmp35 snmp snmpd libsnmp-base 2>/dev/null | awk '$1=="ii"{print "  已装: "$2" "$3}' || true
        apt-get update -o Dir::Etc::sourcelist="$SRC" -o Dir::Etc::sourceparts='-' -o Dir::State::lists="$APT_LISTS"
        # 允许降级：目标机若已装更高版本库，apt 默认"保持现状"导致 snmp 的精确 = 依赖冲突，
        # 加 --allow-downgrades 让 apt 可按本地仓库版本（与麒麟源一致的 2.3）降级替换
        # 首次安装（允许降级）；若因无关预装包（如 aic8800fdrvpackage）postinst 失败导致 apt 非零，不直接中断
        apt-get install -y --no-install-recommends --allow-downgrades -o Dir::State::lists="$APT_LISTS" $DEB_PKGS \
          || { warn "apt 首次安装返回非零，尝试移除冲突的 net-snmp 系后重装 ...";
               apt-get remove -y --purge libsnmp35 snmp snmpd libsnmp-base 2>/dev/null || true;
               apt-get install -y --no-install-recommends -o Dir::State::lists="$APT_LISTS" $DEB_PKGS \
                 || warn "apt 安装仍有非零返回（多为无关预装包 postinst 失败，如 aic8800fdrvpackage），继续按关键包校验"; }
        # 关键依赖就绪校验：以包实际安装状态为准，不受无关预装包配置错误影响
        MISS=""
        for p in $DEB_PKGS; do dpkg -l "$p" 2>/dev/null | grep -q '^ii' || MISS="$MISS $p"; done
        if [[ -n "$MISS" ]]; then die "关键依赖未装齐:$MISS"; fi
        # 尽力修复无关预装包 aic8800fdrvpackage 的 dpkg 半配置状态（其 postinst 编译/加载无线驱动失败，与 LibreNMS 无关）
        if dpkg -l aic8800fdrvpackage 2>/dev/null | grep -qE '^i[FUHR]'; then
            warn "修复预装包 aic8800fdrvpackage 的 dpkg 状态（避免其配置失败持续干扰后续 dpkg 操作）"
            dpkg --force-all --configure aic8800fdrvpackage 2>/dev/null || true
        fi
    else
        warn "osrepo/debs 缺少 Packages.gz，将尝试逐个 dpkg 安装"
        ( cd "$PKG_DIR" && dpkg -i *.deb ) || warn "dpkg 批量安装存在失败，请检查"
    fi
fi
ok "系统依赖安装完成"

# ---------- 步骤 2: 离线安装 Python 依赖 ----------
step "步骤 2/7 安装 Python 依赖（离线轮子）"
PIP_DIR="$OSREPO/pip"
REQ="$LNSM_SRC/requirements.txt"
if [[ -d "$PIP_DIR" ]] && [[ -f "$REQ" ]]; then
    export PIP_BREAK_SYSTEM_PACKAGES=1
    python3 -m pip install --no-index --find-links="$PIP_DIR" -r "$REQ" || warn "Python 依赖安装部分失败（可能已有）"
    ok "Python 依赖就绪"
else
    warn "缺少 pip 轮子或 requirements.txt，跳过（若系统已有依赖可继续）"
fi

# ---------- 步骤 3: 部署 LibreNMS 源码 ----------
step "步骤 3/7 部署 LibreNMS 到 $LNMS_DIR"
if [[ -e "$LNMS_DIR" ]]; then
    if [[ "${FORCE_REINSTALL:-0}" == "1" ]]; then
        info "FORCE_REINSTALL=1，覆盖 $LNMS_DIR"
        rm -rf "$LNMS_DIR"
        cp -a "$LNSM_SRC" "$LNMS_DIR"
        ok "已重新部署源码到 $LNMS_DIR"
    else
        warn "$LNMS_DIR 已存在，跳过源码拷贝（保留现有部署），继续应用配置并启动服务"
    fi
else
    cp -a "$LNSM_SRC" "$LNMS_DIR"
    ok "已部署源码到 $LNMS_DIR"
fi
id "$LNMS_USER" >/dev/null 2>&1 || useradd -r -M -d "$LNMS_DIR" -s /usr/sbin/nologin "$LNMS_USER"
groupadd -f "$LNMS_USER" >/dev/null 2>&1 || true
usermod -a -G "$LNMS_USER" "$WEB_USER" >/dev/null 2>&1 || true
chown -R "$LNMS_USER":"$LNMS_USER" "$LNMS_DIR"
chmod 755 "$LNMS_DIR"

# 关键可写目录的 ACL（web 与 CLI 用户都能写）
command -v setfacl >/dev/null 2>&1 && {
    setfacl -R -m g::rwx "$LNMS_DIR/rrd" "$LNMS_DIR/logs" "$LNMS_DIR/bootstrap/cache" "$LNMS_DIR/storage" 2>/dev/null || true
    setfacl -d -m g::rwx "$LNMS_DIR/rrd" "$LNMS_DIR/logs" "$LNMS_DIR/bootstrap/cache" "$LNMS_DIR/storage" 2>/dev/null || true
}
ok "LibreNMS 已部署到 $LNMS_DIR"

# ---------- 步骤 4: 配置 php-fpm 与 nginx ----------
step "步骤 4/7 配置 PHP-FPM 与 Nginx"

# 依赖已装，按实际 PHP 版本确定 FPM 路径（麒麟源固定 PHP 7.4，兜底 7.4）
if [[ "$PKGMGR" == "deb" ]]; then
    PV="$(ls /etc/php 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -1)"
    [[ -z "$PV" ]] && PV="7.4"
    FPM_POOL_DIR="/etc/php/$PV/fpm/pool.d"
    FPM_INI="/etc/php/$PV/fpm/php.ini"
    FPM_SERVICE="php${PV}-fpm"
else
    FPM_POOL_DIR="/etc/php-fpm.d"; FPM_INI="/etc/php.ini"; FPM_SERVICE="php-fpm"
fi
info "FPM_POOL_DIR=$FPM_POOL_DIR  FPM_INI=$FPM_INI"

# 4.1 php.ini：时区
if [[ -f "$FPM_INI" ]]; then
    if grep -q '^date.timezone' "$FPM_INI"; then sed -i "s|^date.timezone.*|date.timezone = $LNMS_TIMEZONE|" "$FPM_INI"
    else sed -i "s|;date.timezone.*|date.timezone = $LNMS_TIMEZONE|" "$FPM_INI"; fi
    ok "php.ini 时区已设为 $LNMS_TIMEZONE"
fi

# 4.2 php-fpm pool：librenms
mkdir -p "$FPM_POOL_DIR"
cat > "$FPM_POOL_DIR/librenms.conf" <<EOF
[librenms]
user = $WEB_USER
group = $WEB_USER
listen = $FPM_SOCK
listen.owner = $WEB_USER
listen.group = $WEB_USER
listen.mode = 0660
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 2
pm.max_spare_servers = 10
pm.max_requests = 500
catch_workers_output = yes
php_admin_value[error_log] = $LNMS_DIR/logs/php-fpm-error.log
php_admin_flag[log_errors] = on
EOF
ok "php-fpm 池已写入 $FPM_POOL_DIR/librenms.conf"

# 4.3 nginx 站点
mkdir -p "$NGINX_SITED"
cat > "$NGINX_SITED/librenms.conf" <<EOF
server {
    listen $LNMS_HTTP_PORT;
    listen [::]:$LNMS_HTTP_PORT;
    server_name _;
    root $LNMS_DIR/html;
    index index.php;

    charset utf-8;
    gzip on;
    gzip_types text/css application/javascript text/javascript application/x-javascript image/svg+xml text/plain text/xsd text/xsl text/xml image/x-icon;
    client_max_body_size 100M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location /api/v0 {
        try_files \$uri \$uri/ /api_v0.php?\$query_string;
    }
    location ~ \.php {
        include fastcgi.conf;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:$FPM_SOCK;
    }
    location ~ /\.ht {
        deny all;
    }
}
EOF

# deb 系：注册站点 + 关闭默认站
if [[ "$PKGMGR" == "deb" ]]; then
    ln -sf "$NGINX_SITED/librenms.conf" "/etc/nginx/sites-enabled/librenms.conf" 2>/dev/null || true
    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
fi

# rpm 系：移除 nginx.conf 默认 server 的 default_server，避免冲突
if [[ "$PKGMGR" == "rpm" ]] && [[ -f /etc/nginx/nginx.conf ]]; then
    sed -i 's/^[[:space:]]*listen[[:space:]]*80 default_server;/    listen 80;/; s/^[[:space:]]*listen[[:space:]]*\[::\]:80 default_server;/    listen [::]:80;/' /etc/nginx/nginx.conf
fi
nginx -t >/dev/null 2>&1 && ok "nginx 配置语法通过" || warn "nginx 配置语法检查失败（请见 $LOG_FILE）"
ok "nginx 站点已配置"

# ---------- 步骤 5: 初始化数据库 ----------
step "步骤 5/7 初始化数据库"
systemctl enable mariadb >/dev/null 2>&1 || true
systemctl start mariadb || systemctl restart mariadb
sleep 2
mysql -uroot -e "SELECT 1" >/dev/null 2>&1 || die "无法以 root 连接 MariaDB"
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$LNMS_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if mysql -uroot -e "SELECT 1 FROM mysql.user WHERE User='$LNMS_DB_USER'" | grep -q 1; then
    info "数据库用户 $LNMS_DB_USER 已存在，重置其密码以匹配当前 .env（避免重跑后密码失配导致 Access denied）"
    mysql -uroot -e "ALTER USER '$LNMS_DB_USER'@'localhost' IDENTIFIED BY '$LNMS_DB_PASS'; FLUSH PRIVILEGES;" \
      || mysql -uroot -e "SET PASSWORD FOR '$LNMS_DB_USER'@'localhost' = PASSWORD('$LNMS_DB_PASS'); FLUSH PRIVILEGES;"
else
    mysql -uroot -e "CREATE USER IF NOT EXISTS '$LNMS_DB_USER'@'localhost' IDENTIFIED BY '$LNMS_DB_PASS';"
fi
mysql -uroot -e "GRANT ALL PRIVILEGES ON \`$LNMS_DB\`.* TO '$LNMS_DB_USER'@'localhost'; FLUSH PRIVILEGES;"
ok "数据库 $LNMS_DB / 用户 $LNMS_DB_USER 已就绪"

# 5.1 写入 .env（DB 凭证 / APP_URL）
cd "$LNMS_DIR"
if [[ ! -f .env ]]; then cp .env.example .env; fi
sed -i '/^DB_/d; /^APP_URL=/d; /^APP_KEY=/d' .env
cat >> .env <<EOF
DB_HOST=localhost
DB_DATABASE=$LNMS_DB
DB_USERNAME=$LNMS_DB_USER
DB_PASSWORD=$LNMS_DB_PASS
APP_URL=$LNMS_APP_URL
EOF

# 5.2 config.php 基础配置（追加方式，末尾定义覆盖默认，稳妥可靠）
if [[ ! -f config.php ]]; then cp config.php.default config.php; fi
if ! grep -q "librenms_offline_settings" config.php; then
    cat >> config.php <<EOF

// ===== 离线部署自动生成（librenms_offline_settings）=====
\$config['user'] = '$LNMS_USER';
\$config['update'] = 0;               // 离线部署：禁用自动更新，避免 daily.sh 触发 git 拉取
EOF
fi
chown "$LNMS_USER":"$LNMS_USER" .env config.php

# 5.3 生成 APP_KEY 并迁移数据库（以 librenms 用户运行）
sudo -u "$LNMS_USER" php "$LNMS_DIR/artisan" key:generate --force 2>&1 || php "$LNMS_DIR/artisan" key:generate --force
# 确保 .env/config.php 对 web 运行用户（www-data/nginx）可读，否则 php-fpm 读不到 APP_KEY 会 500
chmod 644 "$LNMS_DIR/.env" "$LNMS_DIR/config.php" 2>/dev/null || true
grep -qE '^APP_KEY=.{20,}$' "$LNMS_DIR/.env" || warn "APP_KEY 缺失或格式异常，请检查 .env"
sudo -u "$LNMS_USER" php "$LNMS_DIR/artisan" migrate --seed --force 2>&1 || warn "数据库迁移返回非零，请检查日志"
ok "数据库结构已初始化（migrate --seed --force）"

# ---------- 步骤 6: 管理员账号 + cron + logrotate + 防火墙 ----------
step "步骤 6/7 管理员账号 / cron / logrotate / 防火墙"

# 6.1 管理员
if sudo -u "$LNMS_USER" php "$LNMS_DIR/artisan" user:add "$LNMS_ADMIN_USER" --password="$LNMS_ADMIN_PASS" --role=admin --email="$LNMS_ADMIN_USER@localhost" 2>&1; then
    ok "管理员 $LNMS_ADMIN_USER 已创建"
else
    if mysql -uroot -e "SELECT 1 FROM \`$LNMS_DB\`.users WHERE username='$LNMS_ADMIN_USER' AND auth_type='mysql'" 2>/dev/null | grep -q 1; then
        info "管理员 $LNMS_ADMIN_USER 已存在，跳过（密码维持原状）"
    else
        warn "创建管理员 $LNMS_ADMIN_USER 失败，请检查日志"
    fi
fi

# 6.2 cron（官方 nonroot 调度，内含 artisan schedule:run 每分钟）
if [[ -f "$LNMS_DIR/librenms.nonroot.cron" ]]; then
    sed "s|/opt/librenms|$LNMS_DIR|g" "$LNMS_DIR/librenms.nonroot.cron" > /etc/cron.d/librenms
    chmod 644 /etc/cron.d/librenms
    ok "cron 已写入 /etc/cron.d/librenms"
fi

# 6.3 logrotate
if [[ -f "$LNMS_DIR/misc/librenms.logrotate" ]]; then
    sed "s|/opt/librenms|$LNMS_DIR|g" "$LNMS_DIR/misc/librenms.logrotate" > /etc/logrotate.d/librenms
    ok "logrotate 已配置"
fi

# 6.4 防火墙（尽力而为）
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active firewalld >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port="$LNMS_HTTP_PORT/tcp" >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    ok "防火墙已放行端口 $LNMS_HTTP_PORT"
elif command -v ufw >/dev/null 2>&1; then
    ufw allow "$LNMS_HTTP_PORT/tcp" >/dev/null 2>&1 || true
    ok "ufw 已放行端口 $LNMS_HTTP_PORT"
else
    warn "未检测到 firewall-cmd/ufw，请自行放行 $LNMS_HTTP_PORT 端口"
fi

# 6.5 SELinux（尽力而为，Kylin 若为 Enforcing 需处理）
if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce)" == "Enforcing" ]]; then
    warn "SELinux 处于 Enforcing，尝试配置上下文（如无 policycoreutils 可能失败）"
    if command -v semanage >/dev/null 2>&1; then
        for d in logs rrd storage bootstrap/cache; do
            semanage fcontext -a -t httpd_sys_rw_content_t "$LNMS_DIR/$d(/.*)?" 2>/dev/null || true
        done
        restorecon -RF "$LNMS_DIR" 2>/dev/null || true
        setsebool -P httpd_can_sendmail 1 2>/dev/null || true
        setsebool -P httpd_execmem 1 2>/dev/null || true
    fi
fi

# ---------- 步骤 7: 启动服务并自检 ----------
step "步骤 7/7 启动服务"
for svc in "$FPM_SERVICE" nginx mariadb; do
    systemctl enable "$svc" >/dev/null 2>&1 || true
    systemctl restart "$svc" 2>/dev/null || warn "服务 $svc 启动异常"
done
systemctl daemon-reload >/dev/null 2>&1 || true

# 7.1 HTTP 探活重试（避免 php-fpm socket 启动慢导致 502/000）
step "步骤 7.1 HTTP 探活（重试 12 次，每次 2 秒）"
http_ok=0
for i in $(seq 1 12); do
    code=$(curl -sS -o /dev/null -w '%{http_code}' -m 5 http://127.0.0.1:$LNMS_HTTP_PORT/ 2>/dev/null || echo 000)
    if [[ "$code" == "200" || "$code" == "302" || "$code" == "301" ]]; then
        ok "Web 服务在第 $i 次探测时响应正常 (HTTP $code)"
        http_ok=1
        break
    fi
    info "第 $i 次探测: HTTP $code, 等待 2 秒后重试..."
    sleep 2
done

if [[ "$http_ok" -ne 1 ]]; then
    warn "HTTP 探活未成功（最后一次: $code）—— 启动诊断"
    echo "----- 服务状态 -----"
    for svc in "$FPM_SERVICE" nginx mariadb; do
        echo "[$svc] $(systemctl is-active "$svc" 2>/dev/null) | enabled=$(systemctl is-enabled "$svc" 2>/dev/null)"
    done
    echo "----- php-fpm 状态详情 -----"
    systemctl status "$FPM_SERVICE" --no-pager -l 2>&1 | tail -20
    echo "----- nginx 错误日志（最后 20 行） -----"
    tail -n 20 /var/log/nginx/error.log 2>/dev/null || echo "(无 nginx 错误日志)"
    echo "----- LibreNMS 最近日志（最后 20 行） -----"
    tail -n 20 "$LNMS_DIR/logs/librenms.log" 2>/dev/null || echo "(无 librenms.log)"
    echo "----- 端口监听检查 -----"
    ss -tlnp 2>/dev/null | grep -E ":$LNMS_HTTP_PORT|php-fpm" || true
    echo "----- FPM socket 权限 -----"
    ls -l "$FPM_SOCK_DIR" 2>/dev/null | head -5 || true

    # 常见修复建议
    if [[ "$code" == "502" ]]; then
        warn "502 修复建议: 检查 php-fpm 是否在监听 $FPM_SOCK；nginx 的 fastcgi_pass 是否指向同一 socket；socket 文件权限是否允许 $WEB_USER 读写"
    elif [[ "$code" == "000" ]]; then
        warn "000 修复建议: nginx 未监听 $LNMS_HTTP_PORT 端口，或 socket 启动失败。检查 nginx 配置中 listen $LNMS_HTTP_PORT；SELinux setsebool -P httpd_can_network_connect 1"
    fi
fi

echo
echo "=================== stage2 部署完成 ==================="
echo "finished : $(date '+%F %T')"
echo "访问地址 : $LNMS_APP_URL"
echo "管理员   : $LNMS_ADMIN_USER"
echo "密码     : $LNMS_ADMIN_PASS"
echo "数据库   : $LNMS_DB / 用户 $LNMS_DB_USER"
echo "请妥善保存以上密码！(日志中亦可查：$LOG_FILE)"
echo
echo "运行自检..."
bash "$SCRIPT_DIR/selfcheck.sh" "$LNMS_DIR" || true
