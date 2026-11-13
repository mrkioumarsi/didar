#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
if [ -f /etc/didar/ssl-mode ] && grep -qx manual /etc/didar/ssl-mode; then echo "manual"; exit 0; fi
DOM=""
if [ -f /etc/didar/domain ]; then DOM="$(tr -d '\r\n' < /etc/didar/domain)"; fi
if ! printf '%s' "$DOM" | grep -Eq '^[A-Za-z0-9.-]{1,253}$'; then echo "domain"; exit 1; fi
EMAIL=""
if [ -f /etc/didar/email ]; then EMAIL="$(tr -d '\r\n' < /etc/didar/email)"; fi

prefer_ipv4() {
    local f=/etc/gai.conf
    touch "$f"
    if grep -Eq '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96' "$f"; then
        return 0
    fi
    printf '\nprecedence ::ffff:0:0/96  100\n' >> "$f"
}

write_acme_py() {
    install -d -m 755 /etc/didar/py
    cat > /etc/didar/py/sitecustomize.py <<'PY'
import socket
_ga = socket.getaddrinfo
def getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
    if family == 0:
        try:
            return _ga(host, port, socket.AF_INET, type, proto, flags)
        except OSError:
            pass
    return _ga(host, port, family, type, proto, flags)
socket.getaddrinfo = getaddrinfo
try:
    from requests.sessions import Session as _S
    _orig = _S.request
    def _request(self, method, url, **kw):
        t = kw.get("timeout", 120)
        if t is None or (isinstance(t, (int, float)) and t < 120):
            kw["timeout"] = 120
        return _orig(self, method, url, **kw)
    _S.request = _request
except Exception:
    pass
PY
    chmod 644 /etc/didar/py/sitecustomize.py
}

run_certbot() {
    local n=1 max=5 wait=20
    umask 022
    while [ "$n" -le "$max" ]; do
        echo "Let's Encrypt attempt ${n}/${max}..."
        if PYTHONPATH="/etc/didar/py${PYTHONPATH:+:$PYTHONPATH}" certbot "$@"; then
            umask 077
            return 0
        fi
        echo "Let's Encrypt attempt ${n}/${max} failed."
        n=$((n + 1))
        if [ "$n" -le "$max" ]; then
            echo "Waiting ${wait}s (acme-v02.api.letsencrypt.org:443)..."
            sleep "$wait"
            wait=$((wait + 20))
        fi
    done
    umask 077
    return 1
}

prefer_ipv4
write_acme_py
LIVE="/etc/letsencrypt/live/${DOM}/fullchain.pem"
if [ -s "$LIVE" ]; then
    run_certbot renew --cert-name "$DOM" --non-interactive
else
    if ! printf '%s' "$EMAIL" | grep -Eq '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then echo "email"; exit 1; fi
    install -d -m 755 /var/www/certbot
    run_certbot certonly --webroot -w /var/www/certbot -d "$DOM" --email "$EMAIL" --agree-tos --non-interactive
fi
nohup bash -c "sleep 2; nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true" >/dev/null 2>&1 &
exit 0
