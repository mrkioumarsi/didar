#!/bin/bash
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
cat <<'EOF'

This removes Didar and everything the Ubuntu install script added on this machine.

It deletes:
- The Didar website, its files, and the didar database
- nginx, MariaDB, PHP, coturn, certbot, ffmpeg, and LiveKit
- The HTTPS certificate issued for the Didar domain

If any other website, database, or certificate is on this server, it will be removed too.
SSH stays. The firewall stays. This cannot be undone.

Type DELETE to continue.
EOF
read -r ans
if [ "$ans" != "DELETE" ]; then echo "Cancelled"; exit 1; fi

APP=""
if [ -f /etc/didar/appdir ]; then APP="$(tr -d '\r\n' < /etc/didar/appdir)"; fi
DOM=""
if [ -f /etc/didar/domain ]; then DOM="$(tr -d '\r\n' < /etc/didar/domain)"; fi

if command -v mysql >/dev/null 2>&1; then
 mysql --protocol=socket -e "DROP DATABASE IF EXISTS didar; DROP USER IF EXISTS 'didar'@'localhost'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || true
fi

if [ -n "$DOM" ] && printf '%s' "$DOM" | grep -Eq '^[A-Za-z0-9.-]{1,253}$'; then
 certbot delete --cert-name "$DOM" --non-interactive >/dev/null 2>&1 || true
fi

rm -f /etc/nginx/sites-enabled/didar /etc/nginx/sites-available/didar /etc/nginx/sites-available/didar.didar-bak
rm -f /etc/letsencrypt/renewal-hooks/deploy/didar.sh
rm -f /etc/sudoers.d/didar
rm -f /usr/local/sbin/didar-update /usr/local/sbin/didar-renew /usr/local/sbin/didar-ssl
rm -f /etc/sysctl.d/99-didar.conf /etc/mysql/mariadb.conf.d/99-didar.cnf /etc/livekit.yaml
rm -f /etc/systemd/system/livekit.service
rm -f /usr/local/bin/livekit-server
rm -f /etc/php/*/fpm/conf.d/99-didar.ini /etc/php/*/cli/conf.d/99-didar.ini

systemctl disable --now livekit >/dev/null 2>&1 || true
systemctl stop livekit >/dev/null 2>&1 || true
if id livekit >/dev/null 2>&1; then userdel livekit >/dev/null 2>&1 || true; fi
rm -rf /var/lib/livekit

safe_dir(){
 case "$1" in
  /|/usr|/etc|/var|/var/www|/home|/root|"") return 1;;
 esac
 printf '%s' "$1" | grep -Eq '^/[A-Za-z0-9._/-]+$' || return 1
 printf '%s' "$1" | grep -Fq 'didar' || return 1
 return 0
}
if safe_dir "$APP"; then rm -rf "$APP"; fi
if [ -d /var/www/didar ]; then rm -rf /var/www/didar; fi
rm -rf /etc/didar /etc/didar/ssl /etc/didar/ssl-prev /etc/coturn/certs

systemctl daemon-reload >/dev/null 2>&1 || true
export DEBIAN_FRONTEND=noninteractive
apt-get purge -y nginx nginx-common nginx-core mariadb-server mariadb-client php-fpm php-cli php-mysql php-gd php-mbstring php-xml php-curl php-zip coturn certbot python3-certbot-nginx ffmpeg >/dev/null 2>&1 || true
apt-get purge -y 'php8.*-fpm' 'php8.*-cli' 'php8.*-mysql' 'php8.*-gd' 'php8.*-mbstring' 'php8.*-xml' 'php8.*-curl' 'php8.*-zip' 'php8.*-common' >/dev/null 2>&1 || true
apt-get autoremove -y >/dev/null 2>&1 || true
echo "Didar and the packages added by its installer have been removed."
