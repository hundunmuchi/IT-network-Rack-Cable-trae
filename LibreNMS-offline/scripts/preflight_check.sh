#!/usr/bin/env bash
###############################################################################
#  preflight_check.sh  —— 离线部署前/后的系统预检
#
#  在【离线目标机】上、部署之前运行，检查环境是否满足要求：
#    - 是否 root
#    - 架构 / 内核
#    - 包管理器识别（rpm / deb）
#    - 离线包完整性（src/lnms、osrepo 仓库、composer.phar、pip 轮子）
#    - 网络状态提示（部署前应尽量让目标机保留可联网能力以进行 Stage A）
#
#  用法: sudo bash scripts/preflight_check.sh
#  退出码: 0 = 全部通过；1 = 存在硬性缺失，不建议继续部署
#
#  【日志】主日志: $BUNDLE/logs/preflight.log （终端同步显示，追加写入）
###############################################################################
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$BUNDLE/logs"
LOG_FILE="$LOG_DIR/preflight.log"
mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1
HARD_FAIL=0
warn_cnt=0

say()  { echo "[ .. ] $*"; }
good() { echo "[ OK ] $*"; }
warn() { echo "[警告] $*"; warn_cnt=$((warn_cnt+1)); }
bad()  { echo "[错误] $*"; HARD_FAIL=1; }

echo "=================== LibreNMS offline preflight ==================="
echo "bundle: $BUNDLE"
echo "log   : $LOG_FILE"
echo "time  : $(date '+%F %T')"

# 1. root
if [[ "$EUID" -eq 0 ]]; then good "以 root 运行"; else bad "请以 root 运行: sudo bash $0"; fi

# 2. 架构 / 内核
ARCH="$(uname -m 2>/dev/null || echo unknown)"
KERNEL="$(uname -r 2>/dev/null || echo unknown)"
say "架构=$ARCH  内核=$KERNEL"
[[ "$ARCH" == "x86_64" ]] && good "架构 x86_64" || bad "架构非 x86_64（当前 $ARCH），与 Hygon C86-3G 不符"

# 3. 包管理器
PKGMGR=""
command -v dnf >/dev/null 2>&1 && PKGMGR="rpm"
command -v yum  >/dev/null 2>&1 && PKGMGR="rpm"
command -v apt-get >/dev/null 2>&1 && PKGMGR="deb"
if [[ -n "$PKGMGR" ]]; then good "包管理器: $PKGMGR"; else bad "无法识别包管理器"; fi

# 4. 离线包完整性
say "检查离线包内容 ..."
[[ -d "$BUNDLE/src/lnms" ]] && good "LibreNMS 源码目录存在" || bad "缺少 src/lnms"
[[ -f "$BUNDLE/src/lnms/artisan" ]] && good "artisan 存在" || bad "缺少 artisan"
[[ -f "$BUNDLE/src/composer.phar" ]] && good "composer.phar 存在" || bad "缺少 src/composer.phar"

if [[ "$PKGMGR" == "rpm" ]]; then
    n=$(find "$BUNDLE/osrepo/rpms" -name '*.rpm' 2>/dev/null | wc -l)
    say "osrepo/rpms 下 rpm 数量: $n"
    if [[ "$n" -gt 0 ]]; then good "rpm 离线仓库非空"; else warn "rpm 离线仓库为空 —— 需先在有网机器上运行 stage1"; fi
    if [[ -d "$BUNDLE/osrepo/rpms/repodata" ]]; then good "repodata 存在（可用本地源）"; else warn "repodata 缺失 —— 部署脚本将尝试逐个 rpm 安装"; fi
else
    n=$(find "$BUNDLE/osrepo/debs" -name '*.deb' 2>/dev/null | wc -l)
    say "osrepo/debs 下 deb 数量: $n"
    if [[ "$n" -gt 0 ]]; then good "deb 离线仓库非空"; else warn "deb 离线仓库为空 —— 需先在有网机器上运行 stage1"; fi
    [[ -f "$BUNDLE/osrepo/debs/Packages.gz" ]] && good "Packages.gz 存在" || warn "Packages.gz 缺失"
fi

# 5. PHP 依赖 vendor
if [[ -d "$BUNDLE/src/lnms/vendor" ]]; then
    good "vendor/ 已存在（PHP 依赖已封装）"
else
    warn "vendor/ 缺失 —— 离线机上无法 composer install，必须在有网机 stage1 生成"
fi

# 6. Python 轮子
npip=$(find "$BUNDLE/osrepo/pip" -type f 2>/dev/null | wc -l)
say "osrepo/pip 下文件数量: $npip"
[[ "$npip" -gt 0 ]] && good "Python 轮子仓库非空" || warn "pip 轮子为空"

# 7. 网络提示
if command -v curl >/dev/null 2>&1; then
    if curl -sS -m 5 -o /dev/null https://github.com 2>/dev/null; then
        say "本机可访问外网。若是首次封装，可先用 stage1 生成离线仓库。"
    else
        warn "本机似无法访问外网 —— 将进入纯离线部署模式（依赖已有离线仓库）"
    fi
else
    warn "无 curl，跳过网络检测"
fi

echo
if [[ "$HARD_FAIL" -eq 0 ]]; then
    echo "=================== 预检结果: 通过（$warn_cnt 条警告） ==================="
    echo "可继续: sudo bash scripts/stage2_deploy_offline.sh"
else
    echo "=================== 预检结果: 存在硬性缺失，不建议部署 ==================="
    echo "请根据上方 [错误] 项修复后再执行。"
fi
exit $HARD_FAIL
