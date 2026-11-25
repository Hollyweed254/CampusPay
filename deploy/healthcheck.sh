#!/usr/bin/env sh
# CampusPay health check.
#
#   ./deploy/healthcheck.sh https://pay.example.com
#
# Exits 0 when the app answers /api/v1/health with status "ok", 2 otherwise.
# Wire it into your uptime monitor, load balancer or a cron watchdog.

set -eu

base="${1:-https://pay.example.com}"
url="${base%/}/api/v1/health"

body=$(curl -fsS --max-time 10 "$url" 2>/dev/null) || {
    echo "UNHEALTHY: no response from $url"
    exit 2
}

case "$body" in
    *'"status":"ok"'*)
        echo "OK: $body"
        ;;
    *)
        echo "UNHEALTHY: unexpected payload from $url"
        echo "$body"
        exit 2
        ;;
esac
