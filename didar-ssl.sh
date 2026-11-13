#!/bin/bash
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then echo "root"; exit 1; fi
umask 077
MODE="${1:-}"
SITE=""
if [ -f /etc/nginx/conf.d/didar.conf ]; then
 SITE=/etc/nginx/conf.d/didar.conf
elif [ -f /etc/nginx/sites-available/didar ]; then
 SITE=/etc/nginx/sites-available/didar
fi
AUTO=/etc/didar/ssl-auto.conf
MODEFILE=/etc/didar/ssl-mode
if [ -z "$SITE" ] || [ ! -f "$SITE" ]; then echo "site"; exit 1; fi

write_hook(){
 local d=/etc/letsencrypt/renewal-hooks/deploy
 if [ ! -d "$d" ]; then return 0; fi
 cat > "$d/didar.sh" <<'EOF'
#!/bin/bash
set -e
if [ -f /etc/didar/ssl-mode ] && grep -qx manual /etc/didar/ssl-mode; then
 exit 0
fi
DOM="$(tr -d '\r\n' < /etc/didar/domain)"
cp "/etc/letsencrypt/live/${DOM}/fullchain.pem" /etc/coturn/certs/fullchain.pem
cp "/etc/letsencrypt/live/${DOM}/privkey.pem" /etc/coturn/certs/privkey.pem
chown turnserver:turnserver /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
chmod 640 /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem
systemctl restart coturn
EOF
 chmod 755 "$d/didar.sh"
}

cert_line(){ awk '/^[[:space:]]*ssl_certificate[[:space:]]/{print $2; exit}' "$SITE" | tr -d ';'; }
key_line(){ awk '/^[[:space:]]*ssl_certificate_key[[:space:]]/{print $2; exit}' "$SITE" | tr -d ';'; }

snapshot(){
 if [ -f "$AUTO" ]; then return 0; fi
 local c k
 c="$(cert_line || true)"
 k="$(key_line || true)"
 case "$c" in
  /etc/didar/ssl/*|"") return 0;;
 esac
 if [ ! -f "$c" ] || [ ! -f "$k" ]; then return 0; fi
 openssl x509 -in "$c" -noout >/dev/null 2>&1 || return 0
 openssl pkey -in "$k" -noout >/dev/null 2>&1 || return 0
 printf 'cert=%s\nkey=%s\n' "$c" "$k" > "$AUTO"
 chmod 644 "$AUTO"
}

point_nginx(){
 local cert="$1" key="$2"
 cp -f "$SITE" "$SITE.didar-bak"
 sed -i -E "s#^([[:space:]]*)ssl_certificate_key[[:space:]]+[^;]+;#\1ssl_certificate_key ${key};#" "$SITE"
 sed -i -E "s#^([[:space:]]*)ssl_certificate[[:space:]]+[^;]+;#\1ssl_certificate ${cert};#" "$SITE"
}

restore_site(){
 if [ -f "$SITE.didar-bak" ]; then cp -f "$SITE.didar-bak" "$SITE"; rm -f "$SITE.didar-bak"; fi
}

apply_turn(){
 local cert="$1" key="$2"
 if [ ! -d /etc/coturn/certs ]; then return 0; fi
 cp -f "$cert" /etc/coturn/certs/fullchain.pem
 cp -f "$key" /etc/coturn/certs/privkey.pem
 chown turnserver:turnserver /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem 2>/dev/null || true
 chmod 640 /etc/coturn/certs/fullchain.pem /etc/coturn/certs/privkey.pem || true
 systemctl restart coturn >/dev/null 2>&1 || true
}

restart_web(){
 nohup bash -c "sleep 2; systemctl restart nginx" >/dev/null 2>&1 &
}

pub_of_key(){ openssl pkey -in "$1" -pubout 2>/dev/null | tr -d '\r' | sed '/^$/d' || true; }
pub_of_cert(){ openssl x509 -in "$1" -pubkey -noout 2>/dev/null | tr -d '\r' | sed '/^$/d' || true; }
pub_of_file(){ openssl pkey -pubin -in "$1" -pubout 2>/dev/null | tr -d '\r' | sed '/^$/d' || true; }

if [ "$MODE" = "manual" ]; then
 SRC="${2:-}"
 SRC="$(readlink -f "$SRC" 2>/dev/null || true)"
 bn="$(basename "$SRC")"
 printf '%s' "$bn" | grep -Eq '^[a-f0-9]{16}$' || { echo "stage"; exit 1; }
 ok=0
 APP=""
 if [ -f /etc/didar/appdir ]; then APP="$(tr -d '\r\n' < /etc/didar/appdir)"; fi
 for base in "$APP" /var/www/didar; do
  [ -n "$base" ] || continue
  stage="$(readlink -f "$base/storage/ssl-stage" 2>/dev/null || true)"
  [ -n "$stage" ] || continue
  if [ "$(dirname "$SRC")" = "$stage" ]; then ok=1; fi
 done
 [ "$ok" = 1 ] || { echo "stage"; exit 1; }
 for f in privkey.pem cert.pem pubkey.pem; do
  [ -f "$SRC/$f" ] || { echo "file"; exit 1; }
 done
 openssl pkey -in "$SRC/privkey.pem" -noout >/dev/null 2>&1 || { echo "key"; exit 1; }
 openssl x509 -in "$SRC/cert.pem" -noout >/dev/null 2>&1 || { echo "cert"; exit 1; }
 A="$(pub_of_key "$SRC/privkey.pem")"
 B="$(pub_of_cert "$SRC/cert.pem")"
 C="$(pub_of_file "$SRC/pubkey.pem")"
 [ -n "$A" ] && [ "$A" = "$B" ] && [ "$A" = "$C" ] || { echo "match"; exit 1; }
 FULL="$(mktemp)"
 cat "$SRC/cert.pem" > "$FULL"
 if [ -s "$SRC/bundle.pem" ] && grep -q 'BEGIN CERTIFICATE' "$SRC/bundle.pem"; then
  tmpd="$(mktemp -d)"
  awk -v d="$tmpd" 'BEGIN{n=0} /BEGIN CERTIFICATE/{n++; fn=sprintf("%s/%d.pem",d,n)} n{print > fn} /END CERTIFICATE/{close(fn)}' "$SRC/bundle.pem"
  found=0
  for c in "$tmpd"/*.pem; do
   [ -f "$c" ] || continue
   found=1
   openssl x509 -in "$c" -noout >/dev/null 2>&1 || { rm -rf "$tmpd" "$FULL"; echo "bundle"; exit 1; }
  done
  rm -rf "$tmpd"
  [ "$found" = 1 ] || { rm -f "$FULL"; echo "bundle"; exit 1; }
  cat "$SRC/bundle.pem" >> "$FULL"
 fi
 snapshot
 prev=0
 if [ -d /etc/didar/ssl ]; then rm -rf /etc/didar/ssl-prev; cp -a /etc/didar/ssl /etc/didar/ssl-prev; prev=1; fi
 install -d -o root -g www-data -m 750 /etc/didar/ssl
 install -m 640 -o root -g www-data "$FULL" /etc/didar/ssl/fullchain.pem
 install -m 640 -o root -g www-data "$SRC/privkey.pem" /etc/didar/ssl/privkey.pem
 rm -f "$FULL"
 point_nginx /etc/didar/ssl/fullchain.pem /etc/didar/ssl/privkey.pem
 if ! nginx -t >/dev/null 2>&1; then
  restore_site
  rm -rf /etc/didar/ssl
  if [ "$prev" = 1 ]; then mv /etc/didar/ssl-prev /etc/didar/ssl; fi
  echo "nginx"
  exit 1
 fi
 rm -rf /etc/didar/ssl-prev
 rm -f "$SITE.didar-bak"
 printf 'manual\n' > "$MODEFILE"
 chmod 644 "$MODEFILE"
 apply_turn /etc/didar/ssl/fullchain.pem /etc/didar/ssl/privkey.pem
 write_hook
 restart_web
 exit 0
fi

if [ "$MODE" = "auto" ]; then
 c=""
 k=""
 if [ -f "$AUTO" ]; then
  c="$(sed -n 's/^cert=//p' "$AUTO" | head -1 | tr -d '\r')"
  k="$(sed -n 's/^key=//p' "$AUTO" | head -1 | tr -d '\r')"
 fi
 if [ ! -f "${c:-}" ] || [ ! -f "${k:-}" ]; then
  DOM=""
  if [ -f /etc/didar/domain ]; then DOM="$(tr -d '\r\n' < /etc/didar/domain)"; fi
  c="/etc/letsencrypt/live/${DOM}/fullchain.pem"
  k="/etc/letsencrypt/live/${DOM}/privkey.pem"
 fi
 if [ ! -f "$c" ] || [ ! -f "$k" ]; then echo "keep"; exit 2; fi
 openssl x509 -in "$c" -noout >/dev/null 2>&1 || { echo "keep"; exit 2; }
 openssl pkey -in "$k" -noout >/dev/null 2>&1 || { echo "keep"; exit 2; }
 point_nginx "$c" "$k"
 if ! nginx -t >/dev/null 2>&1; then restore_site; echo "nginx"; exit 1; fi
 rm -f "$SITE.didar-bak"
 printf 'auto\n' > "$MODEFILE"
 chmod 644 "$MODEFILE"
 apply_turn "$c" "$k"
 case "$c" in
  /etc/didar/ssl/*) ;;
  *) rm -f /etc/didar/ssl/fullchain.pem /etc/didar/ssl/privkey.pem; rmdir /etc/didar/ssl 2>/dev/null || true;;
 esac
 write_hook
 restart_web
 exit 0
fi
echo "mode"
exit 1
