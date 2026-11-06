#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
APPDIR=""
if [ -f /etc/didar/appdir ]; then APPDIR="$(tr -d '\r\n' < /etc/didar/appdir)"; fi
if ! printf '%s' "$APPDIR" | grep -Eq '^/[A-Za-z0-9._/-]+$'; then echo "Application folder was not found"; exit 1; fi
if [ ! -f "$APPDIR/config.php" ]; then echo "Application folder was not found"; exit 1; fi
export DIDAR_APP="$APPDIR"
export DIDAR_UPDATE=1
php -r 'require getenv("DIDAR_APP")."/config.php"; exit(update_apply_root()?0:1);'
php -r 'require getenv("DIDAR_APP")."/config.php"; try{ensure_schema();}catch(Throwable $e){fwrite(STDERR,"schema failed\n"); exit(1);}'
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if printf '%s' "$PHPVER" | grep -Eq '^[0-9]+\.[0-9]+$'; then
 nohup bash -c "sleep 2; command -v ffmpeg >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg >/dev/null 2>&1 || true; systemctl reload php${PHPVER}-fpm >/dev/null 2>&1 || true; nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true" >/dev/null 2>&1 &
fi
exit 0
