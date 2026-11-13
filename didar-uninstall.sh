#!/bin/bash
# Didar uninstaller (Ubuntu)
#
#   --soft   Remove Didar only. nginx, PHP, MariaDB, certbot, ffmpeg and every
#            other database, website and certificate stay untouched.
#   --hard   Remove Didar and everything the installer added, including
#            nginx, PHP, MariaDB with ALL databases, certbot and ffmpeg.
#   --yes    Skip the confirmation prompt (a mode flag is required with it).
#
# SSH access and UFW itself are never removed.

export DEBIAN_FRONTEND=noninteractive

MODE=""
ASSUME_YES=0
APP=""
DOM=""

say()  { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
cat <<'EOF'
Usage: didar-uninstall.sh [--soft | --hard] [--yes]

  --soft   Remove Didar, its database and user, its nginx site, its PHP pool,
           LiveKit, coturn and its certificate. Keeps nginx, PHP, MariaDB and
           all other databases, sites and certificates.
  --hard   Remove everything the installer added: all of the above plus
           nginx, PHP, MariaDB (ALL databases), certbot, ffmpeg and the
           firewall rules the installer opened.
  --yes    Do not ask for confirmation.

Without a mode flag the script asks which mode to use.
EOF
}

# ---------- helpers ----------

db_cli() {
    if command -v mariadb >/dev/null 2>&1; then
        mariadb --protocol=socket "$@"
    elif command -v mysql >/dev/null 2>&1; then
        mysql --protocol=socket "$@"
    else
        return 127
    fi
}

db_up() { db_cli -Nse "SELECT 1" >/dev/null 2>&1; }

user_dbs() {
    db_cli -Nse "SHOW DATABASES" 2>/dev/null \
        | grep -Ev '^(information_schema|performance_schema|mysql|sys)$'
}

safe_dir() {
    case "$1" in
        /|/usr|/etc|/var|/var/www|/home|/root|"") return 1 ;;
    esac
    printf '%s' "$1" | grep -Eq '^/[A-Za-z0-9._/-]+$' || return 1
    printf '%s' "$1" | grep -Fq 'didar' || return 1
    return 0
}

stop_unit() { systemctl disable --now "$1" >/dev/null 2>&1 || true; }

# Prints installed (or config-left) packages that match the given patterns.
pkgs_matching() {
    dpkg-query -W -f='${Package} ${Status}\n' "$@" 2>/dev/null \
        | awk '$4=="installed" || $4=="config-files" {print $1}' | sort -u
}

purge_pkgs() {
    local list=("$@")
    [ "${#list[@]}" -gt 0 ] || return 0
    apt-get purge -y "${list[@]}" >/dev/null 2>&1 || warn "apt could not purge: ${list[*]}"
}

db_datadir() {
    local bin d=""
    bin="$(command -v mariadbd || command -v mysqld || true)"
    if [ -n "$bin" ]; then
        d="$("$bin" --print-defaults 2>/dev/null | tr ' ' '\n' | sed -n 's/^--datadir=//p' | tail -n 1)"
    fi
    [ -n "$d" ] || d="/var/lib/mysql"
    realpath -m "$d"
}

# ---------- removal steps shared by both modes ----------

remove_livekit() {
    say "- Removing LiveKit"
    stop_unit livekit
    rm -f /etc/systemd/system/livekit.service /usr/local/bin/livekit-server /etc/livekit.yaml
    if id livekit >/dev/null 2>&1; then userdel livekit >/dev/null 2>&1 || true; fi
    if getent group livekit >/dev/null 2>&1; then groupdel livekit >/dev/null 2>&1 || true; fi
    rm -rf /var/lib/livekit
}

remove_cert() {
    say "- Removing the HTTPS certificate for the Didar domain"
    rm -f /etc/letsencrypt/renewal-hooks/deploy/didar.sh
    if [ -n "$DOM" ] && printf '%s' "$DOM" | grep -Eq '^[A-Za-z0-9.-]{1,253}$' \
        && command -v certbot >/dev/null 2>&1 \
        && { [ -e "/etc/letsencrypt/renewal/${DOM}.conf" ] || [ -d "/etc/letsencrypt/live/${DOM}" ]; }; then
        certbot delete --cert-name "$DOM" --non-interactive >/dev/null 2>&1 \
            || warn "Could not delete the certificate for $DOM"
    fi
}

remove_nginx_site() {
    say "- Removing the Didar nginx site"
    rm -f /etc/nginx/conf.d/didar.conf /etc/nginx/conf.d/didar.conf.pre-didar.*
    # Legacy layout from older installers
    rm -f /etc/nginx/sites-enabled/didar /etc/nginx/sites-available/didar /etc/nginx/sites-available/didar.didar-bak
}

remove_fpm_pool() {
    say "- Removing the Didar PHP-FPM pool"
    local f v
    for f in /etc/php/*/fpm/pool.d/didar.conf; do
        [ -e "$f" ] || continue
        rm -f "$f"
        v="$(printf '%s' "$f" | cut -d/ -f4)"
        systemctl reload "php${v}-fpm" >/dev/null 2>&1 || true
    done
    rm -f /run/php/didar.sock
}

remove_didar_files() {
    say "- Removing Didar files, scripts and settings"
    rm -f /etc/sudoers.d/didar
    rm -f /usr/local/sbin/didar-update /usr/local/sbin/didar-reinstall /usr/local/sbin/didar-uninstall /usr/local/sbin/didar-install /usr/local/sbin/didar-renew /usr/local/sbin/didar-ssl
    # Legacy files from older installers
    rm -f /etc/sysctl.d/99-didar.conf
    rm -f /etc/php/*/fpm/conf.d/99-didar.ini /etc/php/*/cli/conf.d/99-didar.ini
    if safe_dir "$APP"; then rm -rf "$APP"; fi
    if [ -d /var/www/didar ]; then rm -rf /var/www/didar; fi
    rm -rf /etc/didar /etc/coturn/certs
    rm -f /var/log/didar-install.log
}

remove_coturn() {
    say "- Removing coturn"
    stop_unit coturn
    purge_pkgs coturn
    rm -rf /etc/coturn
    rm -f /etc/turnserver.conf /etc/turnserver.conf.pre-didar.* /etc/default/coturn
}

# ---------- soft mode ----------

do_soft() {
    say
    say "Soft uninstall started."

    say "- Dropping the didar database and user"
    if db_up; then
        db_cli -e "DROP DATABASE IF EXISTS didar; DROP USER IF EXISTS 'didar'@'localhost'; FLUSH PRIVILEGES;" >/dev/null 2>&1 \
            || warn "Could not drop the didar database or user"
    else
        warn "MariaDB is not reachable. The didar database and user were NOT dropped."
    fi

    remove_nginx_site
    if command -v nginx >/dev/null 2>&1; then
        if nginx -t >/dev/null 2>&1; then
            systemctl reload nginx >/dev/null 2>&1 || true
        else
            warn "nginx -t failed after removing the Didar site. nginx was not reloaded; check your nginx configuration."
        fi
    fi

    remove_fpm_pool
    remove_livekit
    remove_coturn
    remove_cert

    say "- Removing Didar MariaDB tuning"
    if [ -f /etc/mysql/mariadb.conf.d/99-didar.cnf ]; then
        rm -f /etc/mysql/mariadb.conf.d/99-didar.cnf
        systemctl restart mariadb >/dev/null 2>&1 || warn "MariaDB restart failed; restart it manually."
    fi

    remove_didar_files

    rm -rf /var/www/certbot/.well-known 2>/dev/null
    rmdir /var/www/certbot 2>/dev/null || true

    systemctl daemon-reload >/dev/null 2>&1 || true

    say
    say "Soft uninstall finished."
    say "Kept: nginx, PHP, MariaDB (all other databases), certbot, ffmpeg, UFW and SSH."
    say "Firewall rules opened for Didar were left in place. Remove them yourself if unused:"
    say "  3478/tcp, 3478/udp, 5349/tcp, 7881/tcp, 49152:49999/udp, 50000:60000/udp"
}

# ---------- hard mode ----------

do_hard() {
    say
    say "Hard uninstall started."

    local datadir
    datadir="$(db_datadir)"

    remove_cert

    say "- Stopping services"
    local u
    for u in livekit coturn nginx mariadb mysql; do stop_unit "$u"; done
    for u in $(systemctl list-units --all --plain --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
        stop_unit "$u"
    done

    remove_livekit
    remove_nginx_site
    remove_fpm_pool
    remove_didar_files
    rm -f /etc/mysql/mariadb.conf.d/99-didar.cnf

    say "- Purging packages (this can take a minute)"
    local pkgs=()
    mapfile -t pkgs < <(pkgs_matching 'nginx' 'nginx-*' 'libnginx-mod-*' \
        'mariadb-server*' 'mariadb-client*' 'mariadb-plugin-*' 'mariadb-backup' \
        'php*' 'coturn' 'certbot' 'python3-certbot*' 'ffmpeg')
    purge_pkgs "${pkgs[@]}"
    apt-get autoremove -y --purge >/dev/null 2>&1 || true

    say "- Removing leftover data and configuration"
    rm -rf /etc/nginx /var/log/nginx /var/lib/nginx /var/www/certbot
    rm -rf /etc/php /var/lib/php
    rm -rf /etc/coturn /var/lib/turn
    rm -f /etc/turnserver.conf /etc/turnserver.conf.pre-didar.* /etc/default/coturn
    rm -rf /etc/letsencrypt /var/lib/letsencrypt /var/log/letsencrypt
    rm -rf /etc/mysql /var/log/mysql
    rm -f /root/.my.cnf

    case "$datadir" in
        /|/etc|/usr|/var|/home|/root)
            warn "Unsafe MariaDB data directory ($datadir). It was not removed."
            ;;
        *)
            if [ -d "$datadir" ]; then
                if mountpoint -q "$datadir"; then
                    warn "MariaDB data directory is a mount point. It was not removed: $datadir"
                else
                    rm -rf "$datadir"
                fi
            fi
            ;;
    esac

    if command -v ufw >/dev/null 2>&1; then
        say "- Closing firewall ports opened by the installer (SSH is untouched)"
        local r p
        for r in 3478/tcp 3478/udp 5349/tcp 7881/tcp 49152:49999/udp 50000:60000/udp; do
            ufw --force delete allow "$r" >/dev/null 2>&1 || true
        done
        for p in 80 443; do
            if ! ss -H -lnt "sport = :$p" 2>/dev/null | grep -q .; then
                ufw --force delete allow "${p}/tcp" >/dev/null 2>&1 || true
            else
                warn "Port $p is still in use by another service; its firewall rule was kept."
            fi
        done
    fi

    systemctl daemon-reload >/dev/null 2>&1 || true

    say
    say "Hard uninstall finished."
    say "Removed: Didar, nginx, PHP, MariaDB and all databases, coturn, LiveKit, certbot, ffmpeg."
    say "Kept: SSH, UFW itself, curl, ca-certificates and openssl."
}

# ---------- main ----------

main() {
    cd / || exit 1

    local a c
    for a in "$@"; do
        case "$a" in
            --soft)
                [ -z "$MODE" ] || [ "$MODE" = "soft" ] || fail "Choose either --soft or --hard, not both"
                MODE="soft" ;;
            --hard)
                [ -z "$MODE" ] || [ "$MODE" = "hard" ] || fail "Choose either --soft or --hard, not both"
                MODE="hard" ;;
            --yes|-y) ASSUME_YES=1 ;;
            -h|--help) usage; exit 0 ;;
            *) usage; fail "Unknown argument: $a" ;;
        esac
    done

    if [ "$(id -u)" -ne 0 ]; then echo "Run this script as root"; exit 1; fi

    if [ -z "$MODE" ]; then
        [ -t 0 ] || fail "Choose a mode: --soft or --hard"
        cat <<'EOF'

Choose an uninstall mode:

  1) Soft - remove Didar only. Keeps nginx, PHP, MariaDB and all other databases.
  2) Hard - remove Didar and every package the installer added, including ALL databases.

EOF
        read -r -p "Mode [1/2]: " c
        case "$c" in
            1) MODE="soft" ;;
            2) MODE="hard" ;;
            *) echo "Cancelled"; exit 1 ;;
        esac
    fi

    if [ -f /etc/didar/appdir ]; then APP="$(tr -d '\r\n' < /etc/didar/appdir)"; fi
    if [ -f /etc/didar/domain ]; then DOM="$(tr -d '\r\n' < /etc/didar/domain)"; fi

    local word dbs
    if [ "$MODE" = "soft" ]; then
        word="REMOVE"
        cat <<'EOF'

SOFT UNINSTALL

This removes Didar only.

It deletes:
- The Didar website files, the didar database and the didar database user
- The Didar nginx site and the Didar PHP-FPM pool
- LiveKit and coturn
- The HTTPS certificate issued for the Didar domain

It keeps:
- nginx, PHP, MariaDB, certbot and ffmpeg
- Every other database, website and certificate
- SSH and the firewall

EOF
        dbs="$(user_dbs | grep -vx 'didar' | tr '\n' ' ')"
        if [ -n "$dbs" ]; then say "Other databases that will be kept: $dbs"; say; fi
    else
        word="DELETE"
        cat <<'EOF'

HARD UNINSTALL

This removes Didar and everything the Ubuntu install script added.

It deletes:
- The Didar website, its files and settings
- nginx, PHP, MariaDB, coturn, certbot, ffmpeg and LiveKit
- ALL MariaDB databases and users, not only the didar database
- ALL Let's Encrypt certificates on this server
- The firewall rules the installer opened

If any other website, database or certificate is on this server, it will be removed too.
SSH stays. UFW itself stays. This cannot be undone.

EOF
        dbs="$(user_dbs | tr '\n' ' ')"
        if [ -n "$dbs" ]; then say "Databases that will be destroyed: $dbs"; say; fi
    fi

    if [ "$ASSUME_YES" -ne 1 ]; then
        [ -t 0 ] || fail "Non-interactive use requires --yes"
        read -r -p "Type $word to continue: " c
        if [ "$c" != "$word" ]; then echo "Cancelled"; exit 1; fi
    fi

    if [ "$MODE" = "soft" ]; then do_soft; else do_hard; fi
}

# Everything lives in main() so the script can safely delete its own directory.
main "$@"; exit $?