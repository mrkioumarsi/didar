#!/usr/bin/env bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi
if [ -x /usr/local/sbin/didar-update ]; then
    exec /usr/local/sbin/didar-update reinstall
fi
HERE="$(cd "$(dirname "$0")" && pwd)"
if [ -f "$HERE/didar-update.sh" ]; then
    exec bash "$HERE/didar-update.sh" reinstall
fi
echo "didar-update was not found"
exit 1
