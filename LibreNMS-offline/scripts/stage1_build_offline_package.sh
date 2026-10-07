#!/usr/bin/env bash
###############################################################################
#  stage1_build_offline_package.sh
#
#  LibreNMS 离线部署包 —— 阶段一：生成离线依赖仓库
#
#  【在哪里运行】
#    在一台【已联网】的麒麟 V10 SP1（Hygon x86_64）机器上、以 root 运行。
#    推荐在“目标机本身”尚未断开内网/公网时运行，这样产出的仓库与目标机
#    的 OS、内核、架构、源完全一致。
#
#  【做什么】
#    1. 自动识别包管理器（rpm/dnf 或 deb/apt），两种均可。
#    2. 校验并安装部署所需的系统软件包（联网）。
#    3. 把所需软件包及其全部依赖下载到本包的 osrepo/ 下，
#       并生成本地仓库元数据（repodata / Packages.gz）。
#    4. 用内置 composer.phar 在 src/lnms 内安装 PHP 依赖 vendor/（联网）。
#    5. 下载 LibreNMS 所需 Python 依赖到 osrepo/pip/（离线轮子仓库）。
#    6. 生成文件清单与 sha256 校验和。
#
#  【运行完后】
#    整个 LibreNMS-offline 目录即为自包含离线包，可拷贝到离线目标机，
#    再用 scripts/stage2_deploy_offline.sh 一键部署。
#
# 用法: sudo bash scripts/stage1_build_offline_package.sh
###############################################################################
set -euo pipefail

# ---------- 路径 ----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$SCRIPT_DIR/.." && pwd)"
SRC_DIR="$BUNDLE/src"
LNSM_DIR="$SRC_DIR/lnms"
OSREPO="$BUNDLE/osrepo"
LOG_DIR="$BUNDLE/logs"
LOG_FILE="$LOG_DIR/stage1.log"

mkdir -p "$OSREPO" "$LOG_DIR"

# ---------- 日志 ----------
exec > >(tee -a "$LOG_FILE") 2>&1
echo "=================== LibreNMS offline package builder (stage1) ==================="
echo "bundle  : $BUNDLE"
echo "time    : $(date '+%F %T')"

# ---------- 工具函数 ----------
die() { echo " [错误] $*" >&2; exit 1; }
info() { echo " [ .. ] $*"; }
ok()   { echo " [ OK ] $*"; }
warn() { echo " [警告] $*"; }

# 需要 root
if [[ "$EUID" -ne 0 ]]; then die "请以 root 运行: sudo bash $0"; fi

# ---------- 识别包管理器 ----------
PKGMGR=""
if command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then PKGMGR="rpm"; fi
if command -v apt-get >/dev/null 2>&1; then PKGMGR="deb"; fi
[[ -z "$PKGMGR" ]] && die "无法识别包管理器（需要 dnf/yum 或 apt-get）"
info "检测到包管理器: $PKGMGR"

# 架构检查
ARCH="$(uname -m)"
info "架构: $ARCH (预期 x86_64)"
[[ "$ARCH" != "x86_64" ]] && warn "架构非 x86_64，请确认目标机为 Hygon C86-3G 对应架构"

# ---------- 软件包清单 ----------
# rpm (麒麟服务器 V10 SP1 / RHEL8 系)
RPM_PKGS="
createrepo_c composer cronie fping git ImageMagick jwhois
mariadb-server mariadb mtr net-snmp net-snmp-utils nginx
php-fpm php-cli php-common php-curl php-gd php-mbstring php-process
php-snmp php-xml php-zip php-mysqlnd php-ldap
rrdtool python3 python3-pip
"

# deb (麒麟桌面/优麒麟 / Ubuntu 系)
DEB_PKGS="
composer cron fping git imagemagick whois
mariadb-server mariadb-client mtr snmp snmpd rrdtool nginx
php-fpm php-cli php-common php-curl php-gd php-mbstring php-mysql
php-snmp php-xml php-zip php-ldap php-intl php-bcmath php-memcached
python3 python3-pip dpkg-dev
"

# 从当前可用源中过滤出真正存在的包（容忍个别包名在当前源不存在）
filter_available() {
    local mgr="$1"; shift
    local out=""
    for p in "$@"; do
        if [[ "$mgr" == "rpm" ]]; then
            if dnf repoquery --quiet "$p" >/dev/null 2>&1; then out="$out $p"; else warn "包不存在于当前源，跳过: $p"; fi
        else
            if apt-cache show "$p" >/dev/null 2>&1; then out="$out $p"; else warn "包不存在于当前源，跳过: $p"; fi
        fi
    done
    echo "$out"
}

# ---------- 步骤 1: 安装部署所需软件包（联网） ----------
info "---- 步骤 1: 安装部署所需系统软件包 ----"
if [[ "$PKGMGR" == "rpm" ]]; then
    dnf -y install $RPM_PKGS || {
        # 部分包可能不在默认源，逐个尝试，失败仅警告
        for p in $RPM_PKGS; do
            dnf -y install "$p" >/dev/null 2>&1 || warn "无法安装(可能不在源): $p"
        done
    }
else
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    for p in $DEB_PKGS; do
        apt-get install -y "$p" >/dev/null 2>&1 || warn "无法安装(可能不在源): $p"
    done
fi
ok "系统软件包安装完成"

# ---------- 步骤 2: 下载软件包及其依赖到 osrepo/，并生成仓库元数据 ----------
info "---- 步骤 2: 下载依赖包并生成离线仓库 ----"
if [[ "$PKGMGR" == "rpm" ]]; then
    PKG_DIR="$OSREPO/rpms"
    mkdir -p "$PKG_DIR"
    AVAIL=$(filter_available rpm $RPM_PKGS)
    info "下载 rpm 包及其依赖 ..."
    if command -v dnf >/dev/null 2>&1; then
        dnf download --destdir="$PKG_DIR" --resolve --alldeps $AVAIL || \
            { info "dnf download 不可用，改用 yum --downloadonly"; yum install -y --downloadonly --downloaddir="$PKG_DIR" $AVAIL; }
    else
        yum install -y --downloadonly --downloaddir="$PKG_DIR" $AVAIL
    fi
    if command -v createrepo_c >/dev/null 2>&1; then
        createrepo_c "$PKG_DIR"
    elif command -v createrepo >/dev/null 2>&1; then
        createrepo "$PKG_DIR"
    else
        warn "未找到 createrepo，跳过 repodata 生成（需在离线机上单独处理）"
    fi
else
    PKG_DIR="$OSREPO/debs"
    mkdir -p "$PKG_DIR"
    AVAIL=$(filter_available deb $DEB_PKGS)
    info "下载 deb 包及其依赖 ..."
    export DEBIAN_FRONTEND=noninteractive
    # 用临时缓存目录下载，随后移动 *.deb
    TMPCACHE="$OSREPO/.aptcache"
    mkdir -p "$TMPCACHE"
    apt-get install -y --download-only -o Dir::Cache::archives="$TMPCACHE" $AVAIL || \
        { info "整体下载失败，逐个下载"; for p in $AVAIL; do apt-get download "$p" 2>/dev/null; done; }
    # 移动缓存内的 deb（注意子目录）
    find "$TMPCACHE" -name '*.deb' -exec mv -f {} "$PKG_DIR/" \; 2>/dev/null || true
    rm -rf "$TMPCACHE"
    if command -v dpkg-scanpackages >/dev/null 2>&1; then
        ( cd "$PKG_DIR" && dpkg-scanpackages . /dev/null | gzip -9c > Packages.gz )
        info "已生成 Packages.gz"
    else
        warn "未找到 dpkg-scanpackages，跳过 deb 仓库元数据（需离线机上用 dpkg -i 逐个安装）"
    fi
fi
ok "离线软件仓库已生成: $PKG_DIR"

# ---------- 步骤 3: 生成 LibreNMS PHP 依赖 (vendor/) ----------
info "---- 步骤 3: 生成 PHP 依赖 vendor/ ----"
[[ -d "$LNSM_DIR" ]] || die "未找到 LibreNMS 源码目录: $LNSM_DIR"
command -v php >/dev/null 2>&1 || die "当前机器缺少 php 命令，无法生成 vendor/。请确认步骤1已安装 php-cli。"
cd "$LNSM_DIR"
if [[ ! -d vendor ]]; then
    PHPVER="$(php -r 'echo PHP_VERSION;')"
    info "本机 PHP 版本: $PHPVER（LibreNMS 22.10.0 需 PHP 7.3+ / 8.0，建议 7.4）"
    if [[ ! -f "$SRC_DIR/composer.phar" ]]; then
        warn "未找到内置 composer.phar，尝试使用系统 composer"
        composer install --no-dev --optimize-autoloader --no-interaction
    else
        php "$SRC_DIR/composer.phar" install --no-dev --optimize-autoloader --no-interaction
    fi
else
    info "vendor/ 已存在，跳过 composer install"
fi
[[ -f "$LNSM_DIR/vendor/autoload.php" ]] || die "vendor/autoload.php 未生成，vendor 封装失败"
ok "PHP 依赖 vendor/ 已生成"

# ---------- 步骤 4: 下载 Python 依赖到 osrepo/pip/ ----------
info "---- 步骤 4: 下载 Python 依赖（离线轮子） ----"
PIP_DIR="$OSREPO/pip"
mkdir -p "$PIP_DIR"
REQ="$LNSM_DIR/requirements.txt"
if [[ -f "$REQ" ]]; then
    python3 -m pip download -r "$REQ" -d "$PIP_DIR" || warn "pip download 部分失败，请检查网络"
    ok "Python 依赖已下载到 $PIP_DIR"
else
    warn "未找到 requirements.txt"
fi

# ---------- 步骤 5: 生成文件清单与校验和 ----------
info "---- 步骤 5: 生成清单与 sha256 ----"
cd "$BUNDLE"
find . -type f -not -path './logs/*' | sort > "$LOG_DIR/MANIFEST.files" 2>/dev/null || true
if command -v sha256sum >/dev/null 2>&1; then
    find . -type f -not -path './logs/*' -exec sha256sum {} \; > "$LOG_DIR/MANIFEST.sha256" 2>/dev/null || true
fi
ok "清单已生成: $LOG_DIR/MANIFEST.files / MANIFEST.sha256"

echo
echo "=================== stage1 完成 ==================="
echo "离线包根目录: $BUNDLE"
echo "请检查 osrepo/ 下是否已有足够多的 .rpm/.deb 与 repodata/Packages.gz。"
echo "确认后，将整个目录拷贝到离线目标机，执行:"
echo "    sudo bash scripts/preflight_check.sh"
echo "    sudo bash scripts/stage2_deploy_offline.sh"
echo "日志: $LOG_FILE"
