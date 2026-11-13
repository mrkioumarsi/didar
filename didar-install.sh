#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

LOG="/var/log/didar-install.log"
DOMAIN=""
EMAIL=""
APPDIR=""
ADMIN_USER="admin"
ADMIN_PASS=""
ADMIN_NAME="Admin"
CONFIRM_WIPE=0

mkdir -p /var/log
touch "$LOG"
chmod 600 "$LOG"
exec > >(tee -a "$LOG") 2>&1

trap 'rc=$?; echo; echo "ERROR: Installation failed at line ${LINENO}, exit code ${rc}."; echo "Log: '"$LOG"'"; exit "$rc"' ERR

step() {
    echo
    echo "============================================================"
    echo "$1"
    echo "============================================================"
}

fail() {
    echo "ERROR: $1"
    exit 1
}

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

while [ $# -gt 0 ]; do
    case "$1" in
        --domain)       [ $# -ge 2 ] || fail "Missing domain"; DOMAIN="$2"; shift 2 ;;
        --email)        [ $# -ge 2 ] || fail "Missing email"; EMAIL="$2"; shift 2 ;;
        --dir)          [ $# -ge 2 ] || fail "Missing directory"; APPDIR="$2"; shift 2 ;;
        --admin-user)   [ $# -ge 2 ] || fail "Missing admin username"; ADMIN_USER="$2"; shift 2 ;;
        --admin-pass)   [ $# -ge 2 ] || fail "Missing admin password"; ADMIN_PASS="$2"; shift 2 ;;
        --admin-name)   [ $# -ge 2 ] || fail "Missing admin name"; ADMIN_NAME="$2"; shift 2 ;;
        --confirm-wipe) CONFIRM_WIPE=1; shift ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

[ "$(id -u)" -eq 0 ] || fail "Run this installer as root"

[ -n "$DOMAIN" ] || fail "Domain is required"
[ -n "$EMAIL" ] || fail "Email is required"

[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || fail "Invalid domain"
[[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || fail "Invalid email"
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{3,50}$ ]] || fail "Invalid admin username"

case "$ADMIN_PASS" in
    *$'\n'*|*$'\r'*) fail "Invalid admin password" ;;
esac

if [ -n "$ADMIN_PASS" ] && [ "${#ADMIN_PASS}" -lt 8 ]; then
    fail "Admin password must contain at least 8 characters"
fi

if [ -z "$APPDIR" ]; then
    HERE="$(cd "$(dirname "$0")" && pwd)"

    if [ -f "$HERE/api.php" ]; then
        APPDIR="$HERE"
    elif [ -f "$HERE/../api.php" ]; then
        APPDIR="$(cd "$HERE/.." && pwd)"
    else
        fail "Application directory not found. Use --dir"
    fi
fi

APPDIR="$(realpath "$APPDIR")"

[ -f "$APPDIR/api.php" ] || fail "api.php not found in $APPDIR"
[ -f "$APPDIR/index.php" ] || fail "index.php not found in $APPDIR"
[ -f "$APPDIR/schema.sql" ] || fail "schema.sql not found"
[ -f "$APPDIR/didar-update.sh" ] || fail "didar-update.sh not found"
[ -f "$APPDIR/didar-uninstall.sh" ] || fail "didar-uninstall.sh not found"
[ -f "$APPDIR/didar-reinstall.sh" ] || fail "didar-reinstall.sh not found"
[ -f "$APPDIR/didar-renew.sh" ] || fail "didar-renew.sh not found"
[ -f "$APPDIR/didar-ssl.sh" ] || fail "didar-ssl.sh not found"

[[ "$APPDIR" != *" "* ]] || fail "Application path cannot contain spaces"

. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || fail "Ubuntu is required"

if [ "$CONFIRM_WIPE" -ne 1 ]; then
    echo
    echo "WARNING: THIS IS A DESTRUCTIVE FRESH INSTALL."
    echo "All MariaDB databases, users and privileges will be erased."
    echo "Existing Nginx websites and their configurations will be preserved."
    echo

    [ -t 0 ] || fail "Non-interactive installation requires --confirm-wipe"

    read -r -p "Type WIPE-DATABASES to continue: " CONFIRMATION
    [ "$CONFIRMATION" = "WIPE-DATABASES" ] || fail "Installation cancelled"
fi

if [ -z "$ADMIN_PASS" ] && [ -t 0 ]; then
    read -r -s -p "Admin password (Enter = generate): " ADMIN_PASS
    echo
fi

if [ -z "$ADMIN_PASS" ]; then
    ADMIN_PASS="$(openssl rand -hex 16)"
fi

[ "${#ADMIN_PASS}" -ge 8 ] || fail "Admin password must contain at least 8 characters"

export DEBIAN_FRONTEND=noninteractive

step "1. Checking installed packages"

REQUIRED_PACKAGES=(
    nginx
    mariadb-server
    mariadb-client
    php-fpm
    php-cli
    php-mysql
    php-gd
    php-mbstring
    php-xml
    php-curl
    php-zip
    coturn
    certbot
    ffmpeg
    ufw
    curl
    ca-certificates
    openssl
)

MISSING_PACKAGES=()

for pkg in "${REQUIRED_PACKAGES[@]}"; do
    if ! dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q "install ok installed"; then
        MISSING_PACKAGES+=("$pkg")
    fi
done

if [ "${#MISSING_PACKAGES[@]}" -gt 0 ]; then
    apt-get update
    apt-get install -y "${MISSING_PACKAGES[@]}"
else
    echo "All required packages are already installed."
fi

if ! dpkg-query -W -f='${Status}' mariadb-server 2>/dev/null | grep -q "install ok installed"; then
    fail "MariaDB server package is not installed"
fi

if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    fail "MariaDB server binary was not found"
fi

PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

step "2. Preparing fresh MariaDB installation"

# Detect the configured MariaDB data directory.
DB_BIN="$(command -v mariadbd || command -v mysqld)"
DB_DATADIR="$("$DB_BIN" --print-defaults 2>/dev/null | tr ' ' '\n' | sed -n 's/^--datadir=//p' | tail -n 1)"

if [ -z "$DB_DATADIR" ]; then
    DB_DATADIR="/var/lib/mysql"
fi

DB_DATADIR="$(realpath -m "$DB_DATADIR")"

case "$DB_DATADIR" in
    /|/etc|/usr|/var|/home|/root)
        fail "Unsafe MariaDB data directory: $DB_DATADIR"
        ;;
esac

echo "MariaDB data directory: $DB_DATADIR"
if mountpoint -q "$DB_DATADIR"; then
    fail "MariaDB data directory is a mount point; refusing to wipe it: $DB_DATADIR"
fi

# Stop MariaDB before removing its data.
systemctl stop mariadb >/dev/null 2>&1 || true
systemctl stop mysql >/dev/null 2>&1 || true

# Remove every database, user, privilege and system table.
install -d -o mysql -g mysql -m 750 "$DB_DATADIR"

find "$DB_DATADIR" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +

chown mysql:mysql "$DB_DATADIR"
chmod 750 "$DB_DATADIR"

# Generate independent, strong credentials.
DBROOTPASS="$(openssl rand -hex 40)"
DBPASS="$(openssl rand -hex 32)"
TURNSEC="$(openssl rand -hex 32)"
LKKEY="$(openssl rand -hex 12)"
LKSEC="$(openssl rand -hex 32)"

# Remove stale root client credentials from previous installations.
rm -f /root/.my.cnf

# Initialize a completely new MariaDB data directory.
if command -v mariadb-install-db >/dev/null 2>&1; then
    mariadb-install-db \
        --user=mysql \
        --datadir="$DB_DATADIR" \
        --auth-root-authentication-method=socket \
        --skip-test-db
elif command -v mysql_install_db >/dev/null 2>&1; then
    mysql_install_db \
        --user=mysql \
        --datadir="$DB_DATADIR" \
        --auth-root-authentication-method=socket \
        --skip-test-db
else
    fail "mariadb-install-db was not found"
fi

systemctl enable mariadb
systemctl start mariadb

DB_READY=0

for i in $(seq 1 30); do
    if mariadb-admin --protocol=socket ping --silent >/dev/null 2>&1; then
        DB_READY=1
        break
    fi
    sleep 2
done

[ "$DB_READY" -eq 1 ] || fail "MariaDB did not start"

step "3. Setting MariaDB root password"

# Keep both password and Unix Socket authentication.
# The Unix Socket method keeps local Ubuntu maintenance tools working.

mariadb --protocol=socket --user=root <<SQL
ALTER USER 'root'@'localhost'
IDENTIFIED VIA mysql_native_password USING PASSWORD('${DBROOTPASS}')
OR unix_socket;

CREATE DATABASE didar
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

CREATE USER 'didar'@'localhost'
IDENTIFIED BY '${DBPASS}';

GRANT ALL PRIVILEGES ON didar.* TO 'didar'@'localhost';

FLUSH PRIVILEGES;
SQL

# Save root credentials in a root-only client file.
cat > /root/.my.cnf <<EOF
[client]
user=root
password=${DBROOTPASS}
protocol=socket
EOF

chmod 600 /root/.my.cnf

# Store all application credentials separately.
install -d -m 700 /etc/didar

cat > /etc/didar/secrets.env <<EOF
DBROOTPASS=${DBROOTPASS}
DBPASS=${DBPASS}
TURNSEC=${TURNSEC}
LKKEY=${LKKEY}
LKSEC=${LKSEC}
EOF

chmod 600 /etc/didar/secrets.env

dbroot() {
    mariadb --defaults-extra-file=/root/.my.cnf "$@"
}

dbroot -Nse "SELECT 1" >/dev/null || fail "MariaDB root password authentication failed"

echo "MariaDB fresh installation completed."
echo "All previous MariaDB databases and accounts have been removed."

step "4. Configuring MariaDB"

RAMKB="$(awk '/MemTotal/{print $2}' /proc/meminfo)"
RAMMB=$((RAMKB / 1024))

POOLSZ=256M
if [ "$RAMMB" -lt 2048 ]; then
    POOLSZ=128M
fi

install -d /etc/mysql/mariadb.conf.d

cat > /etc/mysql/mariadb.conf.d/99-didar.cnf <<EOF
[mysqld]
max_connections=200
innodb_buffer_pool_size=${POOLSZ}
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
EOF

systemctl restart mariadb

dbroot -Nse "SELECT 1" >/dev/null || fail "MariaDB failed after configuration"

step "5. Checking DNS and public IP"

PUB="$(curl -4 -fsS --max-time 12 https://api.ipify.org || true)"
RESOLVED="$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}' || true)"

if [ -n "$PUB" ] && [ -n "$RESOLVED" ] && [ "$PUB" != "$RESOLVED" ]; then
    echo "WARNING: DNS does not point to this server."
    echo "Domain IP: $RESOLVED"
    echo "Server IP: $PUB"
fi

PRIV="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"

EXT=""
EXTLINE=""
RELAYLINE=""
LK_NODE=""

if [ -n "$PUB" ] && [ -n "$PRIV" ] && [ "$PUB" != "$PRIV" ]; then
    EXT="${PUB}/${PRIV}"
elif [ -n "$PUB" ]; then
    EXT="$PUB"
fi

if [ -n "$EXT" ]; then
    EXTLINE="external-ip=${EXT}"
fi

if [ -n "$PRIV" ]; then
    RELAYLINE="relay-ip=${PRIV}"
fi

LK_EXT="true"
if [ -n "$PUB" ]; then
    LK_EXT="false"
    LK_NODE="  node_ip: \"${PUB}\""
fi

step "6. Configuring UFW"

# Add rules only. Never reset, delete or rewrite existing UFW rules.

SSH_PORTS="$(ss -H -lntp 2>/dev/null | awk 'index($0,"sshd"){n=split($4,a,":"); p=a[n]; if(p ~ /^[0-9]+$/) print p}' | sort -u || true)"

if [ -z "$SSH_PORTS" ] && [ -x /usr/sbin/sshd ]; then
    SSH_PORTS="$(/usr/sbin/sshd -T 2>/dev/null | awk 'tolower($1)=="port" && $2 ~ /^[0-9]+$/ {print $2}' | sort -u || true)"
fi

if [ -z "$SSH_PORTS" ]; then
    SSH_PORTS=22
fi

for p in $SSH_PORTS; do
    case "$p" in
        ''|*[!0-9]*) continue ;;
    esac

    if [ "$p" -ge 1 ] && [ "$p" -le 65535 ]; then
        ufw allow "${p}/tcp"
    fi
done

ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 3478/tcp
ufw allow 3478/udp
ufw allow 5349/tcp
ufw allow 7881/tcp
ufw allow 49152:49999/udp
ufw allow 50000:60000/udp

if ! ufw status | grep -q '^Status: active'; then
    ufw --force enable
fi

step "7. Preparing Nginx"

# Nginx is not reinstalled if already present.
# No existing site, default server or Nginx configuration is removed.
# Didar gets its own configuration file in conf.d.

NGINX_MAIN="/etc/nginx/nginx.conf"
NGINX_CONF="/etc/nginx/conf.d/didar.conf"

[ -f "$NGINX_MAIN" ] || fail "Nginx main configuration was not found"

if ! grep -Eq 'include[[:space:]]+/etc/nginx/conf.d/\*\.conf' "$NGINX_MAIN"; then
    fail "Nginx does not include conf.d/*.conf. Existing Nginx configuration was not modified."
fi

install -d /etc/nginx/conf.d
install -d /var/www/certbot

# Refuse to claim a domain already configured by another active site.
if [ -f "$NGINX_CONF" ]; then
    cp -a "$NGINX_CONF" "${NGINX_CONF}.pre-didar.$(date +%Y%m%d%H%M%S)"
fi

NGINX_DUMP="$(nginx -T 2>/dev/null || true)"
if printf '%s\n' "$NGINX_DUMP" | awk -v target="$NGINX_CONF" -v domain="$DOMAIN" '
    /^# configuration file / {
        source_file=$4
        sub(/:$/, "", source_file)
    }
    $1 == "server_name" {
        for (i=2; i<=NF; i++) {
            name=$i
            gsub(/[;{}]/, "", name)
            if (name == domain && source_file != target) found=1
        }
    }
    END { exit(found ? 0 : 1) }
'; then
    fail "Domain $DOMAIN is already configured in another Nginx file. Existing sites were not modified."
fi

cat > "$NGINX_CONF" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    location / {
        return 200 "Didar SSL setup";
    }
}
EOF

nginx -t

systemctl enable nginx
systemctl start nginx
systemctl reload nginx



step "8. Configuring Coturn"

install -d -o turnserver -g turnserver -m 750 /etc/coturn/certs

if [ -f /etc/turnserver.conf ]; then
    cp -a /etc/turnserver.conf "/etc/turnserver.conf.pre-didar.$(date +%Y%m%d%H%M%S)"
fi

if [ -f /etc/default/coturn ]; then
    if grep -q '^TURNSERVER_ENABLED=' /etc/default/coturn; then
        sed -i 's/^TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
    elif grep -q '^#TURNSERVER_ENABLED=' /etc/default/coturn; then
        sed -i 's/^#TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
    else
        echo 'TURNSERVER_ENABLED=1' >> /etc/default/coturn
    fi
else
    echo 'TURNSERVER_ENABLED=1' > /etc/default/coturn
fi

cat > /etc/turnserver.conf <<EOF
listening-port=3478
tls-listening-port=5349
listening-ip=0.0.0.0
fingerprint
use-auth-secret
static-auth-secret=${TURNSEC}
realm=${DOMAIN}
server-name=${DOMAIN}
${EXTLINE}
${RELAYLINE}
min-port=49152
max-port=49999
no-multicast-peers
no-cli
cert=/etc/coturn/certs/fullchain.pem
pkey=/etc/coturn/certs/privkey.pem
EOF

install -d /etc/letsencrypt/renewal-hooks/deploy

cat > /etc/letsencrypt/renewal-hooks/deploy/didar.sh <<'EOF'
#!/bin/bash
set -e

if [ -f /etc/didar/ssl-mode ] && grep -qx manual /etc/didar/ssl-mode; then
    exit 0
fi

DOMAIN="$(cat /etc/didar/domain)"

cp "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" /etc/coturn/certs/fullchain.pem
cp "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" /etc/coturn/certs/privkey.pem

chown turnserver:turnserver /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
chmod 640 /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem

nginx -t
systemctl reload nginx
systemctl restart coturn
EOF

chmod 755 /etc/letsencrypt/renewal-hooks/deploy/didar.sh

echo "$DOMAIN" > /etc/didar/domain
chmod 644 /etc/didar/domain

systemctl enable coturn

step "9. Installing LiveKit"

if ! id livekit >/dev/null 2>&1; then
    useradd --system --home /var/lib/livekit --shell /usr/sbin/nologin livekit
fi

install -d -o livekit -g livekit /var/lib/livekit

if [ ! -x /usr/local/bin/livekit-server ]; then
    TMP="$(mktemp)"
    WORK="$(mktemp -d)"

    case "$(uname -m)" in
        aarch64|arm64) LKARCH=arm64 ;;
        x86_64|amd64) LKARCH=amd64 ;;
        *) fail "Unsupported LiveKit architecture" ;;
    esac

    curl -fL --retry 3 --retry-delay 2 \
        -o "$TMP" \
        "https://github.com/livekit/livekit/releases/download/v1.13.11/livekit_1.13.11_linux_${LKARCH}.tar.gz"

    tar -xzf "$TMP" -C "$WORK"

    BIN="$(find "$WORK" -type f -name livekit-server -print -quit)"
    [ -n "$BIN" ] || fail "LiveKit binary not found"

    install -m 755 "$BIN" /usr/local/bin/livekit-server

    rm -rf "$TMP" "$WORK"
else
    echo "Existing LiveKit binary found. Download skipped."
fi

cat > /etc/livekit.yaml <<EOF
port: 7880
bind_addresses:
  - "0.0.0.0"
log_level: info

rtc:
  tcp_port: 7881
  port_range_start: 50000
  port_range_end: 60000
  use_external_ip: ${LK_EXT}
  advertise_internal_ip: true
  skip_external_ip_validation: true
  use_ice_lite: false
${LK_NODE}
  stun_servers:
    - stun.l.google.com:19302
    - "${DOMAIN}:3478"
  turn_servers:
    - host: ${DOMAIN}
      port: 3478
      protocol: udp
      secret: "${TURNSEC}"
    - host: ${DOMAIN}
      port: 3478
      protocol: tcp
      secret: "${TURNSEC}"
    - host: ${DOMAIN}
      port: 5349
      protocol: tls
      secret: "${TURNSEC}"

turn:
  enabled: false

room:
  auto_create: true

keys:
  ${LKKEY}: "${LKSEC}"
EOF

chown root:livekit /etc/livekit.yaml
chmod 640 /etc/livekit.yaml

cat > /etc/systemd/system/livekit.service <<'EOF'
[Unit]
Description=LiveKit
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/livekit-server --config /etc/livekit.yaml
User=livekit
Group=livekit
Restart=always
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable livekit
systemctl restart livekit

step "10. Preparing application directory"

if ! runuser -u www-data -- test -r "$APPDIR/index.php"; then
    TARGET="/var/www/didar"

    if [ -e "$TARGET" ] && [ "$(realpath "$TARGET")" != "$APPDIR" ]; then
        if [ -f "$TARGET/api.php" ]; then
            rm -rf "$TARGET"
        else
            fail "$TARGET exists and does not appear to be a Didar installation"
        fi
    fi

    install -d "$TARGET"
    cp -a "$APPDIR"/. "$TARGET"/
    APPDIR="$TARGET"
fi

APPDIR="$(realpath "$APPDIR")"
[ -r "$APPDIR/index.php" ] || fail "Application files are not readable"

export DIDAR_APP="$APPDIR"
export DIDAR_DOMAIN="$DOMAIN"
export DIDAR_DBPASS="$DBPASS"
export DIDAR_TURNSEC="$TURNSEC"
export DIDAR_LKKEY="$LKKEY"
export DIDAR_LKSEC="$LKSEC"

step "11. Creating a dedicated PHP-FPM pool"

FPM_POOL="/etc/php/${PHPVER}/fpm/pool.d/didar.conf"

RAM_CHILDREN=$((RAMMB / 128))

if [ "$RAM_CHILDREN" -lt 4 ]; then
    RAM_CHILDREN=4
fi

if [ "$RAM_CHILDREN" -gt 40 ]; then
    RAM_CHILDREN=40
fi

cat > "$FPM_POOL" <<EOF
[didar]

user = www-data
group = www-data

listen = /run/php/didar.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = ${RAM_CHILDREN}
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

clear_env = yes
security.limit_extensions = .php

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_value[max_execution_time] = 300
php_admin_flag[expose_php] = off
EOF

systemctl enable "php${PHPVER}-fpm"
systemctl restart "php${PHPVER}-fpm"

for i in $(seq 1 20); do
    [ -S /run/php/didar.sock ] && break
    sleep 1
done

[ -S /run/php/didar.sock ] || fail "Didar PHP-FPM socket was not created"

step "12. Writing application configuration"

php <<'PHP'
<?php

$app = getenv('DIDAR_APP');
$file = $app . '/config.local.php';

$C = [
    'db_host' => 'localhost',
    'db_name' => 'didar',
    'db_user' => 'didar',
    'db_pass' => getenv('DIDAR_DBPASS'),
    'turn' => [],
    'seed' => []
];

$domain = getenv('DIDAR_DOMAIN');

$C['seed'] = [
    'brand' => 'دیدار',
    'domain' => 'https://' . $domain,
    'stun' => 'stun:' . $domain . ':3478',
    'turn_url' => 'turn:' . $domain . ':3478,turn:' . $domain . ':3478?transport=tcp,turns:' . $domain . ':5349?transport=tcp',
    'turn_secret' => getenv('DIDAR_TURNSEC'),
    'lk_url' => 'wss://' . $domain,
    'lk_key' => getenv('DIDAR_LKKEY'),
    'lk_secret' => getenv('DIDAR_LKSEC'),
    'link_mode' => 'sfu',
    'calendar' => 'jalali',
    'timezone' => 'Asia/Tehran',
    'default_lang' => 'fa',
    'pass_policy' => 'low',
    'quality' => 'mid',
    'ice_relay' => '0'
];

$content = "<?php\n\$C=" . var_export($C, true) . ";\n";

if (file_put_contents($file, $content) === false) {
    throw new RuntimeException('Cannot write config.local.php');
}

PHP

chown root:www-data "$APPDIR/config.local.php"
chmod 640 "$APPDIR/config.local.php"

step "13. Resetting Didar application files"

install -d "$APPDIR/storage"
install -d "$APPDIR/assets/avatars"

# Remove previous Didar application data.
find "$APPDIR/storage" -mindepth 1 ! -name '.gitkeep' -delete
find "$APPDIR/assets/avatars" -type f ! -name 'index.html' -delete

# Save schema outside the public web root.
install -m 600 "$APPDIR/schema.sql" /etc/didar/schema.sql

step "14. Creating Didar database schema"

dbroot <<'SQL'
DROP DATABASE IF EXISTS didar;

CREATE DATABASE didar
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON didar.* TO 'didar'@'localhost';

FLUSH PRIVILEGES;
SQL

step "15. Creating tables and administrator"

export DIDAR_ADMIN_USER="$ADMIN_USER"
export DIDAR_ADMIN_PASS="$ADMIN_PASS"
export DIDAR_ADMIN_NAME="$ADMIN_NAME"

php <<'PHP'
<?php

$pdo = new PDO(
    'mysql:host=localhost;dbname=didar;charset=utf8mb4',
    'didar',
    getenv('DIDAR_DBPASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$sql = file_get_contents('/etc/didar/schema.sql');

if ($sql === false) {
    throw new RuntimeException('Cannot read schema.sql');
}

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

$settings = [
    'brand' => 'دیدار',
    'domain' => 'https://' . getenv('DIDAR_DOMAIN'),
    'stun' => 'stun:' . getenv('DIDAR_DOMAIN') . ':3478',
    'turn_url' => 'turn:' . getenv('DIDAR_DOMAIN') . ':3478,turn:' . getenv('DIDAR_DOMAIN') . ':3478?transport=tcp,turns:' . getenv('DIDAR_DOMAIN') . ':5349?transport=tcp',
    'turn_secret' => getenv('DIDAR_TURNSEC'),
    'turn_user' => '',
    'turn_pass' => '',
    'ice_relay' => '0',
    'quality' => 'mid',
    'link_mode' => 'sfu',
    'lk_url' => 'wss://' . getenv('DIDAR_DOMAIN'),
    'lk_key' => getenv('DIDAR_LKKEY'),
    'lk_secret' => getenv('DIDAR_LKSEC'),
    'calendar' => 'jalali',
    'timezone' => 'Asia/Tehran',
    'pass_policy' => 'low',
    'default_lang' => 'fa'
];

$stmt = $pdo->prepare('REPLACE INTO settings(k,v) VALUES(?,?)');

foreach ($settings as $key => $value) {
    $stmt->execute([$key, (string)$value]);
}

$username = getenv('DIDAR_ADMIN_USER');
$password = getenv('DIDAR_ADMIN_PASS');
$name = getenv('DIDAR_ADMIN_NAME');

if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    throw new RuntimeException('Invalid administrator username');
}

if (strlen($password) < 8) {
    throw new RuntimeException('Administrator password is too short');
}

if ($name === '') {
    $name = 'Admin';
}

if (function_exists('mb_substr')) {
    $name = mb_substr($name, 0, 100);
} else {
    $name = substr($name, 0, 100);
}

$stmt = $pdo->prepare(
    "INSERT INTO users(username,pass,name,role,lang) VALUES(?,?,?,'admin','fa')"
);

$stmt->execute([
    $username,
    password_hash($password, PASSWORD_DEFAULT),
    $name
]);

$app = getenv('DIDAR_APP');

file_put_contents($app . '/storage/schema.ok', '5');
file_put_contents($app . '/storage/seed.ok', '1');
file_put_contents($app . '/storage/installed.lock', '1');

file_put_contents(
    '/etc/didar/admin.txt',
    "username=" . $username . "\npassword=" . $password . "\n"
);

chmod('/etc/didar/admin.txt', 0600);

PHP

step "16. Setting application permissions"

chown -R root:www-data "$APPDIR"

find "$APPDIR" -type d -exec chmod 755 {} \;
find "$APPDIR" -type f -exec chmod 644 {} \;

chown -R www-data:www-data \
    "$APPDIR/storage" \
    "$APPDIR/assets/avatars"

chmod 750 "$APPDIR/storage"
chmod 755 "$APPDIR/assets/avatars"

install -d -o www-data -g www-data -m 750 "$APPDIR/storage/sessions"

for ic in "$APPDIR/assets/icon-192.png" "$APPDIR/assets/icon-512.png"; do
    if [ -f "$ic" ]; then
        chown www-data:www-data "$ic"
        chmod 664 "$ic"
    fi
done

chown root:www-data "$APPDIR/config.local.php"
chmod 640 "$APPDIR/config.local.php"

step "17. Installing Didar management commands"

install -m 755 "$APPDIR/didar-update.sh" /usr/local/sbin/didar-update
install -m 755 "$APPDIR/didar-reinstall.sh" /usr/local/sbin/didar-reinstall
install -m 755 "$APPDIR/didar-uninstall.sh" /usr/local/sbin/didar-uninstall
install -m 755 "$APPDIR/didar-renew.sh" /usr/local/sbin/didar-renew
install -m 755 "$APPDIR/didar-ssl.sh" /usr/local/sbin/didar-ssl

echo "$APPDIR" > /etc/didar/appdir
echo "$DOMAIN" > /etc/didar/domain
if [ -n "$PUB" ]; then
    echo "$PUB" > /etc/didar/pubip
    chmod 644 /etc/didar/pubip
fi

chmod 644 /etc/didar/appdir /etc/didar/domain

cat > /etc/sudoers.d/didar <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/didar-update, /usr/local/sbin/didar-renew, /usr/local/sbin/didar-ssl
EOF

chmod 440 /etc/sudoers.d/didar
visudo -cf /etc/sudoers.d/didar >/dev/null

step "18. Preparing Didar HTTP site"

# Keep the site on HTTP until the final SSL stage.
# The ACME webroot must remain reachable on port 80.
cat > "$NGINX_CONF" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    root ${APPDIR};
    index index.php;
    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ^~ /rtc {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 3600s;
    }

    location ^~ /twirp {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 60s;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/didar.sock;
        fastcgi_read_timeout 600s;
    }

    location = /schema.sql { deny all; }
    location = /didar-install.sh { deny all; }
    location = /didar-update.sh { deny all; }
    location = /didar-reinstall.sh { deny all; }
    location = /didar-renew.sh { deny all; }
    location = /didar-ssl.sh { deny all; }
    location = /didar-uninstall.sh { deny all; }
    location = /install.php { deny all; }

    location ~ ^/(storage|lib)/ { deny all; }
    location ~ /(config\.php|config\.local\.php)\$ {
        deny all;
    }
    location ~ /\. {
        deny all;
    }
}
EOF

nginx -t
systemctl reload nginx

step "19. Pre-SSL verification"

systemctl restart mariadb
systemctl restart "php${PHPVER}-fpm"
systemctl restart livekit

nginx -t
systemctl reload nginx

dbroot -Nse "SELECT 1" >/dev/null || fail "MariaDB root access check failed"
mariadb --protocol=socket -u didar -p"${DBPASS}" didar -Nse "SELECT 1" >/dev/null || fail "Didar database access check failed"

systemctl is-active --quiet mariadb || fail "MariaDB is not active"
systemctl is-active --quiet nginx || fail "Nginx is not active"
systemctl is-active --quiet "php${PHPVER}-fpm" || fail "PHP-FPM is not active"
systemctl is-active --quiet livekit || fail "LiveKit is not active"

step "20. Final stage: SSL, HTTPS and Coturn certificates"

# Obtain or renew the certificate while the HTTP ACME challenge is reachable.
install -d -m 755 /var/www/certbot
chmod 755 /var/www/certbot
printf '%s\n' "$EMAIL" > /etc/didar/email
chmod 644 /etc/didar/email
prefer_ipv4
write_acme_py
echo "Checking Let's Encrypt API..."
if ! curl -4 -fsS -m 90 -o /dev/null "https://acme-v02.api.letsencrypt.org/directory"; then
    echo "Let's Encrypt API is slow or unreachable on IPv4. certbot will retry."
fi
if ! run_certbot certonly \
    --webroot \
    -w /var/www/certbot \
    -d "$DOMAIN" \
    --email "$EMAIL" \
    --agree-tos \
    --non-interactive \
    --keep-until-expiring; then
    fail "Let's Encrypt timed out on acme-v02.api.letsencrypt.org:443. Open outbound HTTPS to Let's Encrypt, wait, then re-run the installer."
fi

[ -s "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ] || fail "SSL certificate was not created"
[ -s "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" ] || fail "SSL private key was not created"

NGXVER="$(nginx -v 2>&1 | awk -F/ '{print $2; exit}')"
HTTP2_LINE="    listen 443 ssl http2;"

if [ "$(printf '%s\n%s\n' "$NGXVER" "1.25.1" | sort -V | head -n 1)" = "1.25.1" ]; then
    HTTP2_LINE=$'    listen 443 ssl;\n    http2 on;'
fi

cat > "$NGINX_CONF" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
${HTTP2_LINE}
    server_name ${DOMAIN};

    server_tokens off;

    ssl_certificate /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;

    root ${APPDIR};
    index index.php;

    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ^~ /rtc {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 3600s;
    }

    location ^~ /twirp {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 60s;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/didar.sock;
        fastcgi_read_timeout 600s;
    }

    location = /schema.sql { deny all; }
    location = /didar-install.sh { deny all; }
    location = /didar-update.sh { deny all; }
    location = /didar-reinstall.sh { deny all; }
    location = /didar-renew.sh { deny all; }
    location = /didar-ssl.sh { deny all; }
    location = /didar-uninstall.sh { deny all; }
    location = /install.php { deny all; }

    location ~ ^/(storage|lib)/ { deny all; }
    location ~ /(config\.php|config\.local\.php)\$ {
        deny all;
    }
    location ~ /\. {
        deny all;
    }
}
EOF

nginx -t
systemctl reload nginx

# Install the certificate for Coturn only after SSL issuance.
cp "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" /etc/coturn/certs/fullchain.pem
cp "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" /etc/coturn/certs/privkey.pem

chown turnserver:turnserver \
    /etc/coturn/certs/fullchain.pem \
    /etc/coturn/certs/privkey.pem

chmod 640 \
    /etc/coturn/certs/fullchain.pem \
    /etc/coturn/certs/privkey.pem

systemctl enable coturn
systemctl restart coturn
systemctl restart livekit

systemctl is-active --quiet coturn || fail "Coturn is not active after SSL setup"
systemctl is-active --quiet livekit || fail "LiveKit is not active after SSL setup"

HOME_OK=0

if curl -kfsSL \
    --max-time 25 \
    --resolve "${DOMAIN}:443:127.0.0.1" \
    "https://${DOMAIN}/" \
    -o /tmp/didar-check.html; then

    if grep -q 'id="login"' /tmp/didar-check.html; then
        HOME_OK=1
    fi
fi

rm -f /tmp/didar-check.html

echo
echo "============================================================"
echo "DIDAR INSTALLATION RESULT"
echo "============================================================"

echo "URL: https://${DOMAIN}/"
echo "Application: $APPDIR"
echo "Database: didar"
echo "Database user: didar"
echo "Database credentials: /etc/didar/secrets.env"
echo "MariaDB root credentials: /etc/didar/secrets.env"
echo "MariaDB root client file: /root/.my.cnf"
echo "Admin credentials: /etc/didar/admin.txt"
echo "Installation log: $LOG"

echo
echo "SERVICE STATUS"

systemctl is-active mariadb || true
systemctl is-active nginx || true
systemctl is-active "php${PHPVER}-fpm" || true
systemctl is-active coturn || true
systemctl is-active livekit || true

echo
echo "UFW STATUS"
ufw status

if [ "$HOME_OK" -ne 1 ]; then
    echo
    echo "WARNING: HTTPS homepage verification failed."
    echo "Check DNS, NAT port forwarding, Nginx, PHP-FPM and application logs."
    exit 1
fi

echo
echo "SUCCESS: Didar is installed."
echo "URL: https://${DOMAIN}/"
echo "Admin username: ${ADMIN_USER}"
echo "Admin password: ${ADMIN_PASS}"

unset DBROOTPASS DBPASS TURNSEC LKKEY LKSEC ADMIN_PASS

exit 0