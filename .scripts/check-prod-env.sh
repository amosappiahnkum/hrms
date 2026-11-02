#!/bin/sh
# Refuse to build, start or deploy the production stack with development values or an exposed env file
# (default: .env.production).
# Usage: sh .scripts/check-prod-env.sh [env-file]

env_file="${1:-.env.production}"

if [ ! -f "$env_file" ]; then
    echo "✗ $env_file not found: run make env-prod, then fill in the production values."
    exit 1
fi

# The last value set for a key, without surrounding quotes or spaces
value() {
    grep -E "^$1=" "$env_file" | tail -n 1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

errors=0
fail() { echo "✗ $1"; errors=$((errors + 1)); }
warn() { echo "! $1"; }

# The secrets stay out of git and away from other users on the server
if git ls-files --error-unmatch "$env_file" >/dev/null 2>&1; then
    fail "$env_file is committed to git: git rm --cached $env_file, then rotate every secret in it."
fi
mode=$(stat -c %a "$env_file" 2>/dev/null || stat -f %Lp "$env_file")
case "$mode" in
    600|400) ;;
    *) fail "$env_file can be read by other users (mode $mode): chmod 600 $env_file" ;;
esac

# Set twice, Laravel reads the first value and Docker's env_file the last: they'd disagree
for key in APP_ENV APP_DEBUG APP_KEY APP_PORT REDIS_PASSWORD PAYMENT_FINGERPRINT_KEY SESSION_SECURE_COOKIE LOG_LEVEL; do
    count=$(grep -cE "^$key=" "$env_file")
    [ "$count" -gt 1 ] && fail "$key is set $count times: keep one."
done

[ "$(value APP_ENV)" = "production" ] || fail "APP_ENV must be production (is '$(value APP_ENV)')."
[ "$(value APP_DEBUG)" = "false" ] || fail "APP_DEBUG must be false (is '$(value APP_DEBUG)')."
[ -n "$(value APP_KEY)" ] || fail "APP_KEY is empty."
[ -n "$(value APP_PORT)" ] || fail "APP_PORT is empty (the port the host nginx proxies to)."

redis_password="$(value REDIS_PASSWORD)"
case "$redis_password" in
    ""|null) fail "REDIS_PASSWORD must be set (and not 'null'): Redis starts with it." ;;
esac

case "$(value REDIS_HOST)" in
    127.0.0.1|localhost|"") warn "REDIS_HOST is '$(value REDIS_HOST)': inside the containers Redis is hrms_redis." ;;
esac
[ "$(value DB_USERNAME)" = "root" ] && warn "DB_USERNAME is root: give the app its own database user with rights on its database only."
[ -n "$(value PAYMENT_FINGERPRINT_KEY)" ] || warn "PAYMENT_FINGERPRINT_KEY is empty: payment fingerprints follow APP_KEY."
[ "$(value SESSION_SECURE_COOKIE)" = "true" ] || warn "SESSION_SECURE_COOKIE isn't true: session cookies can be sent over plain http."
case "$(value LOG_LEVEL)" in
    debug) warn "LOG_LEVEL is debug: logs grow fast and may hold personal data." ;;
esac

if [ "$errors" -gt 0 ]; then
    echo "$env_file isn't ready for production ($errors problem(s) above)."
    exit 1
fi

echo "✓ $env_file has production values."
