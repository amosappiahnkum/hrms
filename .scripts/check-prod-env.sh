#!/bin/sh
# Refuse to build or start the production stack with development values in the env file (default: .env).
# Usage: sh .scripts/check-prod-env.sh [env-file]

env_file="${1:-.env}"

if [ ! -f "$env_file" ]; then
    echo "✗ $env_file not found: copy .env.example and fill in the production values."
    exit 1
fi

# The last value set for a key, without surrounding quotes or spaces
value() {
    grep -E "^$1=" "$env_file" | tail -n 1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

errors=0
fail() { echo "✗ $1"; errors=$((errors + 1)); }
warn() { echo "! $1"; }

[ "$(value APP_ENV)" = "production" ] || fail "APP_ENV must be production (is '$(value APP_ENV)')."
[ "$(value APP_DEBUG)" = "false" ] || fail "APP_DEBUG must be false (is '$(value APP_DEBUG)')."
[ -n "$(value APP_KEY)" ] || fail "APP_KEY is empty."
[ -n "$(value APP_PORT)" ] || fail "APP_PORT is empty (the port the host nginx proxies to)."

redis_password="$(value REDIS_PASSWORD)"
case "$redis_password" in
    ""|null) fail "REDIS_PASSWORD must be set (and not 'null'): Redis starts with it." ;;
esac

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
