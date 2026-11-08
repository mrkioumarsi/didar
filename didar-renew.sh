#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
if [ -f /etc/didar/ssl-mode ] && grep -qx manual /etc/didar/ssl-mode; then echo "manual"; exit 0; fi
DOM=""
if [ -f /etc/didar/domain ]; then DOM="$(tr -d '\r\n' < /etc/didar/domain)"; fi
if ! printf '%s' "$DOM" | grep -Eq '^[A-Za-z0-9.-]{1,253}$'; then echo "domain"; exit 1; fi
certbot renew --cert-name "$DOM" --non-interactive
nohup bash -c "sleep 2; nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true" >/dev/null 2>&1 &
exit 0
