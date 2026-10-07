#!/usr/bin/env bash
#  fix_appkey.sh —— 修复 LibreNMS Web 500/502 的确定性方案（v3）
#  根因1: web 读不到 .env 的 APP_KEY -> MissingAppKey(500)  -> 用 systemd 给 php-fpm 注入 APP_KEY
#  根因2: 旧版向 php-fpm pool 注入 env[APP_KEY] 导致 status=78(FPM_EXIT_CONFIG 配置错误) -> 先回滚
#  适用: 麒麟桌面 V10 SP1（php7.4-fpm + nginx + mariadb），LibreNMS=/opt/librenms，端口默认 5656
#  用法: root 下直接运行  bash fix_appkey.sh   （幂等，可重复）
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$BUNDLE/logs"
LOG_FILE="$LOG_DIR/fix_appkey.log"
mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

LNMS_DIR="${LNMS_DIR:-/opt/librenms}"
LNMS_USER="${LNMS_USER:-librenms}"
LNMS_HTTP_PORT="${LNMS_HTTP_PORT:-5656}"

echo "=================== LibreNMS Web 500/502 修复（v3 systemd 方案） ==================="
echo "install dir : $LNMS_DIR      log: $LOG_FILE"
echo "started     : $(date '+%F %T')"

[[ "$EUID" -ne 0 ]] && { echo " [错误] 请以 root 运行"; exit 1; }
[[ -f "$LNMS_DIR/.env" ]] || { echo " [错误] 无 $LNMS_DIR/.env"; exit 1; }

# ---------- 0) 探测 php-fpm 服务名与二进制 ----------
FPM_SVC=""
for s in php7.4-fpm php-fpm8.1 php-fpm; do systemctl list-unit-files "$s.service" >/dev/null 2>&1 && FPM_SVC="$s" && break; done
[[ -z "$FPM_SVC" ]] && FPM_SVC="php7.4-fpm"
FPM_BIN=""
for b in /usr/sbin/php-fpm7.4 /usr/sbin/php-fpm /usr/bin/php-fpm7.4 /usr/bin/php-fpm; do
    [[ -x "$b" ]] && { FPM_BIN="$b"; break; }
done
command -v php-fpm7.4 >/dev/null 2>&1 && FPM_BIN="$(command -v php-fpm7.4)"
echo " [ .. ] FPM service=$FPM_SVC  binary=$FPM_BIN"

# ---------- 1) 回滚旧版坏注入（pool 里的 env[APP_KEY]，可能导致 status=78） ----------
echo " [ .. ] 回滚 pool 中残留的 env[APP_KEY] ..."
for c in /etc/php/7.4/fpm/pool.d/librenms.conf /etc/php/*/fpm/pool.d/librenms.conf; do
    [[ -f "$c" ]] && sed -i '/^env\[APP_KEY\] = /d' "$c"
done

# ---------- 2) 确保 .env 有 APP_KEY ----------
if ! grep -qE '^APP_KEY=.{20,}$' "$LNMS_DIR/.env"; then
    echo " [ .. ] 重新生成 APP_KEY ..."
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$LNMS_USER" -- php "$LNMS_DIR/artisan" key:generate --force 2>&1 || php "$LNMS_DIR/artisan" key:generate --force || true
    else
        php "$LNMS_DIR/artisan" key:generate --force 2>&1 || true
    fi
fi
chmod 644 "$LNMS_DIR/.env" "$LNMS_DIR/config.php" 2>/dev/null || true
APPKEY=$(grep -E '^APP_KEY=' "$LNMS_DIR/.env" | tail -1 | cut -d= -f2-)
echo " [ .. ] APP_KEY 长度: ${#APPKEY}  前缀: ${APPKEY:0:10}"
[[ -z "$APPKEY" ]] && { echo " [错误] APP_KEY 为空，请检查 .env"; exit 1; }

# ---------- 3) 用 systemd 给 php-fpm 注入 APP_KEY（不写 pool，安全） ----------
DROPIN="/etc/systemd/system/${FPM_SVC}.service.d/appkey.conf"
mkdir -p "/etc/systemd/system/${FPM_SVC}.service.d"
cat > "$DROPIN" <<EOF
[Service]
Environment=APP_KEY=$APPKEY
EOF
echo " [ .. ] 已写入 $DROPIN"
systemctl daemon-reload

# ---------- 4) pool 只加标准指令 clear_env=no（保留 systemd 注入的 APP_KEY，可重复追加安全） ----------
for c in /etc/php/7.4/fpm/pool.d/librenms.conf /etc/php/*/fpm/pool.d/librenms.conf; do
    [[ -f "$c" ]] && { grep -q '^clear_env = no' "$c" || echo 'clear_env = no' >> "$c"; }
done

# ---------- 5) 用正确二进制校验 php-fpm 配置 ----------
if [[ -n "$FPM_BIN" ]]; then
    if "$FPM_BIN" -t 2>&1 | tail -4; then
        echo " [ OK ] php-fpm 配置语法通过"
    else
        echo " [错误] php-fpm 配置校验失败，回滚 systemd 注入并退出"
        rm -f "$DROPIN"; systemctl daemon-reload; exit 1
    fi
else
    echo " [警告] 找不到 php-fpm 二进制，跳过配置校验"
fi

# ---------- 6) 确认 mariadb 运行 + socket ----------
systemctl enable mariadb >/dev/null 2>&1 || true
systemctl restart mariadb 2>/dev/null || true
sleep 3
systemctl is-active --quiet mariadb && echo " [ OK ] mariadb 运行中" || { echo " [警告] mariadb 未运行"; systemctl status mariadb --no-pager 2>&1 | tail -6; }

# ---------- 7) 重启 php-fpm / nginx ----------
systemctl restart "$FPM_SVC" 2>&1 | tail -3 || true
sleep 1
if systemctl is-active --quiet "$FPM_SVC"; then
    echo " [ OK ] $FPM_SVC 运行中"
else
    echo " [警告] $FPM_SVC 未运行，退出码如下："
    systemctl status "$FPM_SVC" --no-pager 2>&1 | tail -8
fi
systemctl restart nginx 2>&1 | tail -3 || true

# ---------- 8) 绕过代理验证 ----------
sleep 3
code=$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$LNMS_HTTP_PORT/" 2>/dev/null || echo 000)
echo " [ .. ] 本地验证 HTTP $code"
if [[ "$code" == "200" || "$code" == "301" || "$code" == "302" ]]; then
    echo " [ OK ] Web 正常 (HTTP $code)  访问 http://<主机名>:$LNMS_HTTP_PORT"
else
    echo " [警告] HTTP $code，请把本日志与 /var/log/nginx/error.log 尾部发回分析"
fi
echo "=================== 结束 ==================="