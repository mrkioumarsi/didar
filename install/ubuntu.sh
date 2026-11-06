#!/bin/bash
set -euo pipefail
DOMAIN=""
EMAIL=""
APPDIR=""
while [ $# -gt 0 ]; do
 case "$1" in
  --domain) DOMAIN="${2:-}"; shift 2;;
  --email) EMAIL="${2:-}"; shift 2;;
  --dir) APPDIR="${2:-}"; shift 2;;
  *) echo "sudo bash install/ubuntu.sh --domain meet.example.com --email you@example.com"; exit 1;;
 esac
done
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then echo "Domain and email are required"; exit 1; fi
if ! printf '%s' "$DOMAIN" | grep -Eq '^[A-Za-z0-9.-]+$'; then echo "Invalid domain"; exit 1; fi
if ! printf '%s' "$EMAIL" | grep -Eq '^[^@[:space:]]+@[^@[:space:]]+$'; then echo "Invalid email"; exit 1; fi
if [ -z "$APPDIR" ]; then APPDIR="$(cd "$(dirname "$0")/.." && pwd)"; fi
if [ ! -f "$APPDIR/api.php" ]; then echo "Application folder was not found"; exit 1; fi
if [ -f /etc/os-release ]; then . /etc/os-release; fi
if [ "${ID:-}" != "ubuntu" ]; then echo "This script is for Ubuntu"; exit 1; fi
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php-fpm php-cli php-mysql php-gd php-mbstring php-xml php-curl php-zip coturn certbot python3-certbot-nginx ufw curl ca-certificates openssl
systemctl disable --now apache2 >/dev/null 2>&1 || true
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
install -d -m 700 /etc/didar
SECFILE=/etc/didar/secrets.env
export DIDAR_APP="$APPDIR"
if [ -f "$SECFILE" ]; then
 set -a
 . "$SECFILE"
 set +a
else
 eval "$(php -r '
  $f=getenv("DIDAR_APP")."/config.local.php";
  $C=["db_pass"=>"","seed"=>[]];
  if(is_file($f)) include $f;
  $seed=isset($C["seed"])&&is_array($C["seed"])?$C["seed"]:[];
  $pairs=["DBPASS"=>$C["db_pass"]??"","TURNSEC"=>$seed["turn_secret"]??"","LKKEY"=>$seed["lk_key"]??"","LKSEC"=>$seed["lk_secret"]??""];
  foreach($pairs as $k=>$v) echo $k."=".escapeshellarg((string)$v)."\n";
 ')"
 if [ -z "${DBPASS:-}" ]; then DBPASS="$(openssl rand -hex 16)"; fi
 if [ -z "${TURNSEC:-}" ]; then TURNSEC="$(openssl rand -hex 24)"; fi
 if [ -z "${LKKEY:-}" ]; then LKKEY="$(openssl rand -hex 8)"; fi
 if [ -z "${LKSEC:-}" ]; then LKSEC="$(openssl rand -hex 24)"; fi
 umask 077
 printf 'DBPASS=%s\nTURNSEC=%s\nLKKEY=%s\nLKSEC=%s\n' "$DBPASS" "$TURNSEC" "$LKKEY" "$LKSEC" > "$SECFILE"
 chmod 600 "$SECFILE"
fi
export DIDAR_DBPASS="$DBPASS" DIDAR_TURNSEC="$TURNSEC" DIDAR_LKKEY="$LKKEY" DIDAR_LKSEC="$LKSEC" DIDAR_DOMAIN="$DOMAIN"
SQLF="$(mktemp)"
export DIDAR_SQLF="$SQLF"
php <<'PHP'
<?php
$e=str_replace("'","''",(string)getenv('DIDAR_DBPASS'));
$sql="CREATE DATABASE IF NOT EXISTS didar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
$sql.="CREATE USER IF NOT EXISTS 'didar'@'localhost' IDENTIFIED BY '{$e}';\n";
$sql.="ALTER USER 'didar'@'localhost' IDENTIFIED BY '{$e}';\n";
$sql.="GRANT ALL PRIVILEGES ON didar.* TO 'didar'@'localhost';\n";
$sql.="FLUSH PRIVILEGES;\n";
file_put_contents(getenv('DIDAR_SQLF'),$sql);
PHP
mysql < "$SQLF"
rm -f "$SQLF"
RAMKB="$(awk '/MemTotal/{print $2}' /proc/meminfo)"
RAMMB=$(( RAMKB / 1024 ))
CHILDREN=$(( RAMMB / 64 ))
if [ "$CHILDREN" -lt 8 ]; then CHILDREN=8; fi
if [ "$CHILDREN" -gt 80 ]; then CHILDREN=80; fi
POOLSZ=256M
if [ "$RAMMB" -lt 2048 ]; then POOLSZ=128M; fi
install -d /etc/mysql/mariadb.conf.d
cat > /etc/mysql/mariadb.conf.d/99-didar.cnf <<EOF
[mysqld]
max_connections=200
innodb_buffer_pool_size=${POOLSZ}
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
EOF
systemctl enable --now mariadb
systemctl restart mariadb
PUB="$(curl -4 -fsS --max-time 12 https://api.ipify.org || true)"
RESOLVED="$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}' || true)"
if [ -n "$PUB" ] && [ -n "$RESOLVED" ] && [ "$PUB" != "$RESOLVED" ]; then
 echo "Warning: ${DOMAIN} points to ${RESOLVED} and this server IP is ${PUB}"
fi
PRIV="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"
EXT=""
EXTLINE=""
if [ -n "$PUB" ] && [ -n "$PRIV" ] && [ "$PUB" != "$PRIV" ]; then EXT="${PUB}/${PRIV}"; elif [ -n "$PUB" ]; then EXT="$PUB"; fi
if [ -n "$EXT" ]; then EXTLINE="external-ip=${EXT}"; fi
install -d /var/www/certbot /etc/nginx/sites-available /etc/nginx/sites-enabled
rm -f /etc/nginx/sites-enabled/default
if [ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
 cat > /etc/nginx/sites-available/didar <<EOF
server {
 listen 80;
 server_name ${DOMAIN};
 location /.well-known/acme-challenge/ { root /var/www/certbot; }
 location / { return 200 "ok"; }
}
EOF
 ln -sfn /etc/nginx/sites-available/didar /etc/nginx/sites-enabled/didar
 nginx -t
 systemctl enable --now nginx
 systemctl reload nginx
 certbot certonly --webroot -w /var/www/certbot -d "$DOMAIN" --email "$EMAIL" --agree-tos --non-interactive --keep-until-expiring
fi
install -d -o turnserver -g turnserver -m 750 /etc/coturn/certs
cp "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" /etc/coturn/certs/fullchain.pem
cp "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" /etc/coturn/certs/privkey.pem
chown turnserver:turnserver /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
chmod 640 /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
if [ -f /etc/default/coturn ]; then
 sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
else
 echo 'TURNSERVER_ENABLED=1' > /etc/default/coturn
fi
cat > /etc/turnserver.conf <<EOF
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=${TURNSEC}
realm=${DOMAIN}
server-name=${DOMAIN}
${EXTLINE}
min-port=49152
max-port=49999
no-multicast-peers
no-cli
cert=/etc/coturn/certs/fullchain.pem
pkey=/etc/coturn/certs/privkey.pem
EOF
install -d /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/didar.sh <<EOF
#!/bin/bash
set -e
cp /etc/letsencrypt/live/${DOMAIN}/fullchain.pem /etc/coturn/certs/fullchain.pem
cp /etc/letsencrypt/live/${DOMAIN}/privkey.pem /etc/coturn/certs/privkey.pem
chown turnserver:turnserver /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
chmod 640 /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
systemctl restart coturn
EOF
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/didar.sh
systemctl enable --now coturn
systemctl restart coturn
if ! id livekit >/dev/null 2>&1; then useradd --system --home /var/lib/livekit --shell /usr/sbin/nologin livekit; fi
install -d -o livekit -g livekit /var/lib/livekit
if ! { [ -x /usr/local/bin/livekit-server ] && /usr/local/bin/livekit-server --version 2>/dev/null | grep -q '1.13.11'; }; then
 TMP="$(mktemp)"
 WORK="$(mktemp -d)"
 case "$(uname -m)" in
  aarch64|arm64) LKARCH=arm64;;
  *) LKARCH=amd64;;
 esac
 curl -fL --retry 3 --retry-delay 2 -o "$TMP" "https://github.com/livekit/livekit/releases/download/v1.13.11/livekit_1.13.11_linux_${LKARCH}.tar.gz"
 tar -xzf "$TMP" -C "$WORK"
 BIN="$(find "$WORK" -type f -name livekit-server -print -quit)"
 install -m 755 "$BIN" /usr/local/bin/livekit-server
 rm -rf "$TMP" "$WORK"
fi
cat > /etc/livekit.yaml <<EOF
port: 7880
bind_addresses:
  - "127.0.0.1"
log_level: info
rtc:
  tcp_port: 7881
  port_range_start: 50000
  port_range_end: 60000
  use_external_ip: true
  stun_servers:
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
systemctl enable --now livekit
systemctl restart livekit
PHPSOCK="/run/php/php${PHPVER}-fpm.sock"
NGXVER="$(nginx -v 2>&1 | awk -F/ '{print $2; exit}')"
HTTP2=' listen 443 ssl http2;'
LOW="$(printf '%s\n%s\n' "$NGXVER" "1.25.1" | sort -V | awk 'NR==1{print; exit}')"
if [ "$LOW" = "1.25.1" ]; then HTTP2=$' listen 443 ssl;\n http2 on;'; fi
cat > /etc/nginx/sites-available/didar <<EOF
server {
 listen 80;
 server_name ${DOMAIN};
 location /.well-known/acme-challenge/ { root /var/www/certbot; }
 location / { return 301 https://\$host\$request_uri; }
}
server {
${HTTP2}
 server_name ${DOMAIN};
 server_tokens off;
 ssl_certificate /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
 ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
 root ${APPDIR};
 index index.php;
 client_max_body_size 64m;
 location /rtc {
  proxy_pass http://127.0.0.1:7880;
  proxy_http_version 1.1;
  proxy_set_header Upgrade \$http_upgrade;
  proxy_set_header Connection "upgrade";
  proxy_set_header Host \$host;
  proxy_read_timeout 3600s;
 }
 location /twirp {
  proxy_pass http://127.0.0.1:7880;
  proxy_http_version 1.1;
  proxy_set_header Host \$host;
  proxy_read_timeout 60s;
 }
 location ~ \.php\$ {
  include snippets/fastcgi-php.conf;
  fastcgi_pass unix:${PHPSOCK};
 }
 location ~ ^/(storage|lib)/ { deny all; }
 location ~ ^/install/.+\.(sh|sql)\$ { deny all; }
 location ~ /(config\.php|config\.local\.php)\$ { deny all; }
 location ~ /\. { deny all; }
}
EOF
ln -sfn /etc/nginx/sites-available/didar /etc/nginx/sites-enabled/didar
nginx -t
install -d "/etc/php/${PHPVER}/fpm/conf.d" "/etc/php/${PHPVER}/cli/conf.d"
printf 'memory_limit=256M\nupload_max_filesize=64M\npost_max_size=64M\n' > "/etc/php/${PHPVER}/fpm/conf.d/99-didar.ini"
cp "/etc/php/${PHPVER}/fpm/conf.d/99-didar.ini" "/etc/php/${PHPVER}/cli/conf.d/99-didar.ini"
POOL="/etc/php/${PHPVER}/fpm/pool.d/www.conf"
if [ -f "$POOL" ]; then
 if grep -q '^pm.max_children' "$POOL"; then sed -i "s/^pm.max_children.*/pm.max_children = ${CHILDREN}/" "$POOL"; else echo "pm.max_children = ${CHILDREN}" >> "$POOL"; fi
fi
systemctl enable --now "php${PHPVER}-fpm"
systemctl restart "php${PHPVER}-fpm"
systemctl reload nginx
cat > /etc/sysctl.d/99-didar.conf <<'EOF'
net.core.rmem_max=16777216
net.core.wmem_max=16777216
net.ipv4.udp_rmem_min=16384
net.ipv4.udp_wmem_min=16384
EOF
sysctl --system >/dev/null
mkdir -p "$APPDIR/storage" "$APPDIR/assets/avatars"
chown -R root:www-data "$APPDIR"
find "$APPDIR" -type d -exec chmod 755 {} \;
find "$APPDIR" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$APPDIR/storage" "$APPDIR/assets/avatars"
chmod 750 "$APPDIR/storage"
chmod 755 "$APPDIR/assets/avatars"
for ic in "$APPDIR/assets/icon-192.png" "$APPDIR/assets/icon-512.png"; do
 if [ -f "$ic" ]; then chown www-data:www-data "$ic"; chmod 664 "$ic"; fi
done
php -r '
$f=getenv("DIDAR_APP")."/config.local.php";
$C=["db_host"=>"localhost","db_name"=>"didar","db_user"=>"root","db_pass"=>"","turn"=>[]];
$exist=is_file($f);
if($exist) include $f;
$oldUser=(string)($C["db_user"]??"");
$oldPass=(string)($C["db_pass"]??"");
$C["db_host"]="localhost";
$C["db_name"]="didar";
$C["db_user"]="didar";
if(!$exist || $oldUser!=="didar" || $oldPass==="") $C["db_pass"]=getenv("DIDAR_DBPASS");
else $C["db_pass"]=$oldPass;
if(!isset($C["turn"])||!is_array($C["turn"])) $C["turn"]=[];
$d=getenv("DIDAR_DOMAIN");
$seed=["domain"=>$d,"stun"=>"stun:".$d.":3478","turn_url"=>"turn:".$d.":3478,turn:".$d.":3478?transport=tcp,turns:".$d.":5349?transport=tcp","turn_secret"=>getenv("DIDAR_TURNSEC"),"lk_url"=>"wss://".$d,"lk_key"=>getenv("DIDAR_LKKEY"),"lk_secret"=>getenv("DIDAR_LKSEC"),"link_mode"=>"sfu"];
if(!isset($C["seed"])||!is_array($C["seed"])) $C["seed"]=[];
foreach($seed as $k=>$v) $C["seed"][$k]=$v;
file_put_contents($f,"<?php\n\$C=".var_export($C,true).";\n");
'
chown root:www-data "$APPDIR/config.local.php"
chmod 640 "$APPDIR/config.local.php"
ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 3478/tcp
ufw allow 3478/udp
ufw allow 5349/tcp
ufw allow 7881/tcp
ufw allow 49152:49999/udp
ufw allow 50000:60000/udp
ufw --force enable
echo "Server is ready. Open https://${DOMAIN}/install/install.php and create the admin."
echo "Secrets stay in /etc/didar/secrets.env and config.local.php."
