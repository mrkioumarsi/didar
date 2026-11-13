#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
if [ "$#" -gt 1 ]; then exit 1; fi
if [ "${1:-}" = "reinstall" ]; then
 export DIDAR_REINSTALL=1
elif [ -n "${1:-}" ]; then
 exit 1
fi
APPDIR=""
if [ -f /etc/didar/appdir ]; then APPDIR="$(tr -d '\r\n' < /etc/didar/appdir)"; fi
if ! printf '%s' "$APPDIR" | grep -Eq '^/[A-Za-z0-9._/-]+$'; then echo "Application folder was not found"; exit 1; fi
if [ ! -f "$APPDIR/config.php" ]; then echo "Application folder was not found"; exit 1; fi
export DIDAR_APP="$APPDIR"
export DIDAR_UPDATE=1
DEV="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}' || true)"
case "$DEV" in
 sing-box*|tun*|tap*|wg*|tailscale*|docker*|warp*)
  echo "Stop the VPN first (vpn-stop). Media must not use the VPN address."
  exit 1
  ;;
esac
if [ -f /etc/livekit.yaml ]; then
 if grep -q '127.0.0.1' /etc/livekit.yaml; then
  sed -i 's/^  - "127.0.0.1"/  - "0.0.0.0"/' /etc/livekit.yaml
 fi
fi
php -r 'require getenv("DIDAR_APP")."/config.php"; exit(update_apply_root()?0:1);'
php -r 'require getenv("DIDAR_APP")."/config.php"; try{ensure_schema();}catch(Throwable $e){fwrite(STDERR,"schema failed\n"); exit(1);}'
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if printf '%s' "$PHPVER" | grep -Eq '^[0-9]+\.[0-9]+$'; then
 nohup bash -c "sleep 2; command -v ffmpeg >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg >/dev/null 2>&1 || true; systemctl reload php${PHPVER}-fpm >/dev/null 2>&1 || true; nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true" >/dev/null 2>&1 &
fi
exit 0
