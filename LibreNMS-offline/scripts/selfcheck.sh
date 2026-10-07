#!/usr/bin/env bash
###############################################################################
#  selfcheck.sh —— 部署后自检
#  用法: sudo bash scripts/selfcheck.sh [/opt/librenms]
#  退出码: 0 = 全部关键项通过；1 = 存在关键失败
#
#  【日志】主日志: $BUNDLE/logs/selfcheck.log （终端同步显示，追加写入）
###############################################################################
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$BUNDLE/logs"
LOG_FILE="$LOG_DIR/selfcheck.log"
mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

LNMS_DIR="${1:-/opt/librenms}"
LNMS_HTTP_PORT="${LNMS_HTTP_PORT:-5656}"
FAIL=0; WARN=0

good() { echo " [ OK ] $*"; }
warn() { echo " [警告] $*"; WARN=$((WARN+1)); }
bad()  { echo " [错误] $*"; FAIL=$((FAIL+1)); }

echo "=================== LibreNMS selfcheck ==================="
echo "install dir: $LNMS_DIR"
echo "log        : $LOG_FILE"
echo "time       : $(date '+%F %T')"

[[ -d "$LNMS_DIR" ]] && good "安装目录存在" || { bad "安装目录不存在: $LNMS_DIR"; exit 1; }

# 1. PHP
if command -v php >/dev/null 2>&1; then
    PVER="$(php -r 'echo PHP_VERSION;' 2>/dev/null)"
    good "PHP $PVER"
    if [[ "$(printf '%s\n' "7.3" "$PVER" | sort -V | head -1)" != "7.3" ]]; then bad "PHP 版本过低（需 >= 7.3，LibreNMS 22.10）"; else good "PHP 版本满足要求"; fi
else
    bad "php 命令不存在"
fi

# 2. PHP 模块
REQ_MODS="mysqlnd mbstring pcre curl xml gd sockets dom json simplexml session pdo zlib"
MISS=""
for m in $REQ_MODS; do
    php -m 2>/dev/null | grep -qi "^$m$" || MISS="$MISS $m"
done
[[ -z "$MISS" ]] && good "必需 PHP 模块齐全" || bad "缺少 PHP 模块:$MISS"

# 3. 外部程序
REQ_BINS="fping rrdtool snmpwalk snmpget snmpgetnext snmpbulkwalk mtr"
MISSB=""
for b in $REQ_BINS; do command -v "$b" >/dev/null 2>&1 || MISSB="$MISSB $b"; done
[[ -z "$MISSB" ]] && good "必需程序齐全" || bad "缺少程序:$MISSB"

# 4. vendor / autoload
[[ -f "$LNMS_DIR/vendor/autoload.php" ]] && good "vendor/autoload.php 存在" || bad "vendor/autoload.php 缺失（未生成 PHP 依赖）"

# 5. .env / config.php / artisan
[[ -f "$LNMS_DIR/.env" ]] && good ".env 存在" || bad ".env 缺失"
[[ -f "$LNMS_DIR/config.php" ]] && good "config.php 存在" || bad "config.php 缺失"
[[ -f "$LNMS_DIR/artisan" ]] && good "artisan 存在" || bad "artisan 缺失"

# 6. 数据库连通与迁移状态
if command -v mysql >/dev/null 2>&1; then
    if mysql -uroot -e "SHOW DATABASES LIKE 'librenms'" 2>/dev/null | grep -q librenms; then
        good "数据库 librenms 存在"
    else
        bad "数据库 librenms 不存在"
    fi
fi
if [[ -f "$LNMS_DIR/artisan" ]] && command -v php >/dev/null 2>&1; then
    if (cd "$LNMS_DIR" && sudo -u librenms php artisan migrate:status >/dev/null 2>&1); then
        good "数据库迁移可执行（migrate:status 通过）"
    else
        warn "migrate:status 执行异常（可能是权限/DB 未就绪），请查看 stage2 日志"
    fi
fi

# 7. 服务状态
SVC_PHP="php-fpm"
PV="$(ls /etc/php 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -1)"
[[ -z "$PV" ]] || SVC_PHP="php${PV}-fpm"
for svc in "$SVC_PHP" nginx mariadb; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then good "服务 $svc 运行中"; else bad "服务 $svc 未运行"; fi
done

# 8. Web 响应 - 区分 502/连接拒绝/超时
if command -v curl >/dev/null 2>&1; then
    http_response=$(curl -sS -o /dev/null -w '%{http_code} %{time_total}' -m 10 http://127.0.0.1:$LNMS_HTTP_PORT/ 2>&1)
    code=$(echo "$http_response" | awk '{print $1}')
    time_total=$(echo "$http_response" | awk '{print $2}')
    if [[ "$code" == "200" || "$code" == "302" || "$code" == "301" ]]; then
        good "Web 服务响应正常 (HTTP $code, ${time_total}s)"
    elif [[ "$code" == "000" ]]; then
        # 000 表示 curl 未能获取 HTTP 响应。区分错误子类型。
        # 用 curl 的 exit code 判断根因。
        curl -sS -o /dev/null -m 10 http://127.0.0.1:$LNMS_HTTP_PORT/ >/dev/null 2>/tmp/curl_err
        curl_exit=$?
        case "$curl_exit" in
            7)  bad "Web 服务无响应: 连接被拒绝 (port $LNMS_HTTP_PORT 未监听或 php-fpm 没启动)" ;;
            28) bad "Web 服务无响应: 连接超时 (>10s) - 服务可能挂起或防火墙拦截" ;;
            6)  bad "Web 服务无响应: DNS 解析失败" ;;
            5)  bad "Web 服务无响应: 无法建立代理连接" ;;
            *)  bad "Web 服务无响应 (curl exit=$curl_exit); stderr: $(cat /tmp/curl_err 2>/dev/null)" ;;
        esac
    elif [[ "$code" == "502" ]]; then
        bad "Web 服务响应 502 Bad Gateway - nginx 在转发但 php-fpm 没运行或崩溃"
    elif [[ "$code" == "503" ]]; then
        bad "Web 服务响应 503 Service Unavailable - 服务过载或维护中"
    elif [[ "$code" == "504" ]]; then
        bad "Web 服务响应 504 Gateway Timeout - nginx 转发但后端超时"
    else
        bad "Web 服务响应异常 (HTTP $code)"
    fi
else
    warn "无 curl，跳过 Web 响应检查"
fi

echo
if [[ "$FAIL" -eq 0 ]]; then
    echo "=================== 自检结果: 通过（$WARN 条警告） ==================="
    exit 0
else
    echo "=================== 自检结果: $FAIL 项错误，$WARN 条警告 ==================="
    echo "请根据上方 [错误] 项排查（日志见 logs/stage2.log）"
    exit 1
fi
