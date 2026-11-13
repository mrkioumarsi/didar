# Didar

Didar is a meeting app that runs in the browser and is written in PHP. People join a room, talk, use a camera, share a screen, draw on the whiteboard, open images, video, and PDFs, and send chat. A small group connects from browser to browser. A larger group sends audio and video through a media server that this project installs on your own Ubuntu machine. There is no outside meeting service. Accounts, rooms, chat, recordings, and settings stay in your database.

[فارسی](README.fa.md)

## What you get

- Sign-in, languages, a calendar, and a count of who is online
- Rooms for a few people, or a media server for a larger group
- Microphone, camera, screen share, whiteboard, and chat
- A Files tab for images, video, and PDF, with live notes that are not saved
- Recordings that play from the room card
- A full database backup from Settings
- An update from the GitHub release, without touching the database or uploads

## What you need

- Ubuntu with root
- A domain whose A record points at the public IP of this server
- An email address for the HTTPS certificate
- Outbound HTTPS to Let's Encrypt (`acme-v02.api.letsencrypt.org`)
- These ports open on the public IP. In `--net nat` mode, forward them to the private address of the server:
  - SSH, on the port this machine already uses (22 unless you changed it)
  - 80 and 443 TCP
  - 3478 TCP and UDP
  - 5349 TCP
  - 7881 TCP
  - UDP 49152–49999 and UDP 50000–60000

## Choose the network mode

Pick one mode. The installer does not guess.

`--net nat` is the home or office server. The network card has a private address such as `192.168.x.x`, and the router forwards the ports above to that address. LiveKit announces the public IP. Coturn is set to `external-ip=PUBLIC/PRIVATE` and `relay-ip` on the private card. The public IP is the domain A record. If that record is not in place yet, pass `--public-ip`.

`--net direct` is a server whose own network card already has the public IP. LiveKit and Coturn both use that address. If the card is private, this mode stops and tells you to use `--net nat`.

Stop any VPN before you install, update, or reinstall (`vpn-stop`). A tunnel as the default route is refused, so the media address cannot become the VPN address. If Let's Encrypt cannot be reached, the installer runs `vpn-start` only while it requests the certificate, then runs `vpn-stop`. Leave the VPN stopped while a meeting is open. Test audio and video from mobile data. A phone on the same Wi-Fi as the server often cannot open the public name.

## Install

Run this on Ubuntu as root. `--confirm-wipe` is required when there is no prompt. It erases every MariaDB database on this machine. Other Nginx sites stay. The firewall only gains rules. It is not reset. The admin password must be at least 8 characters. If you omit `--admin-pass`, the script creates one and prints it once at the end.

Behind NAT:

```bash
wget -qO didar.tar.gz "https://github.com/mrkioumarsi/didar/archive/refs/tags/v1.1.0.tar.gz?$(date +%s)"
tar -xzf didar.tar.gz
cd didar-1.1.0
sudo bash didar-install.sh --net nat --domain meet.example.com --email you@example.com --admin-user admin --admin-pass 'your-password' --confirm-wipe
```

On a public network card, use the same commands with `--net direct` instead of `--net nat`.

Replace the domain, email, username, and password. The first installer line must be `Didar 1.1.0 installer 20261113-net`. If that line is missing, the download is an old copy. Delete `didar.tar.gz` and `didar-1.1.0`, then run wget again.

Optional flags: `--public-ip` (nat only), `--admin-name`, and `--dir` when the app is not next to the script. Without `--confirm-wipe`, the script waits until you type `WIPE-DATABASES`.

The installer puts Nginx, PHP, MariaDB, Coturn, LiveKit, the HTTPS certificate, and the media ports on this machine, then creates the admin account. The app is served from `/var/www/didar` when the download directory is not already readable by the web user.

At the end, `SUCCESS` prints the media mode, then the database host, name, user, and password, then the admin username and password. Copy them before you close the terminal. The database user is `didar`, the database name is `didar`, and the host is `localhost`. The MariaDB root password is not printed. It is stored in `/root/.my.cnf` and `/etc/didar/secrets.env`. The same file holds the app database password. The admin password is also in `/etc/didar/admin.txt`. The install log is `/var/log/didar-install.log`. The chosen mode is `/etc/didar/media-mode` and the public IP is `/etc/didar/pubip`.

Open `https://meet.example.com/` and sign in. In Settings, choose a small group or the media server. The addresses are already filled in.

## Update

Use this when GitHub has a newer release. The database, `config.local.php`, rooms, recordings, and uploaded files stay. The app files are replaced.

From Settings, check GitHub, then confirm Update. On the server, with the VPN stopped:

```bash
sudo didar-update
```

If that command is missing:

```bash
sudo bash /var/www/didar/didar-update.sh
```

## Reinstall this version

Use this when the server is already on 1.1.0 and you need the current files of that same release again. It downloads the tag once more. The database, `config.local.php`, and uploads stay. Stop the VPN first. From Settings, use «Reinstall this release», or:

```bash
sudo didar-reinstall
```

```bash
sudo bash /var/www/didar/didar-reinstall.sh
```

A failed first install is not repaired by reinstall. Download the release again and run `didar-install.sh`. That wipes MariaDB.

## Certificate

The installer asks Let's Encrypt for a certificate in the last step. If that service cannot be reached on IPv4, and `vpn-start` exists, the installer starts the VPN for that step only and stops it afterwards. If it still fails, run `vpn-start`, run the installer again, then run `vpn-stop`.

Later, Settings can keep the automatic certificate or take a private key, public key, certificate, and bundle. Saving restarts the web server once. If you return to automatic and that certificate is missing, the manual certificate stays. Renewal copies the automatic certificate to Coturn. `didar-renew` and `didar-ssl` are installed for that work.

## Uninstall

Two modes. Without `--yes`, the script waits until you type the confirm word.

`--soft` — type `REMOVE`. This deletes the Didar database and user, the Didar files, the Didar Nginx site, the Didar PHP pool, LiveKit, Coturn, and the Didar certificate. Nginx, PHP, MariaDB, other databases, other sites, certbot, ffmpeg, SSH, and UFW stay.

```bash
sudo didar-uninstall --soft
```

`--hard` — type `DELETE`. This also removes Nginx, PHP, MariaDB with every database, certbot, ffmpeg, all certificates on this server, and the firewall rules the installer opened. SSH and UFW itself stay. This cannot be undone.

```bash
sudo didar-uninstall --hard
```

Without a prompt, add `--yes` next to `--soft` or `--hard`. If the command is missing:

```bash
sudo bash /var/www/didar/didar-uninstall.sh --soft
sudo bash /var/www/didar/didar-uninstall.sh --hard
```

## If audio or video fails

LiveKit is pinned to a real address, with `use_external_ip` off. It does not discover an address through STUN, and it does not use the VPN address. In nat mode, Coturn advertises the public IP and relays from the private card. In direct mode, both sides of Coturn are the public card.

The browser uses the STUN and TURN addresses stored in Settings (port 3478, and 5349 for TLS). «Force TURN» stays off unless you turn it on in Settings. Turn it on only when direct media is unstable and TURN is already filled in.

Check that the router forwards the ports listed above, that `/etc/didar/pubip` is the real public IP, and that `vpn-stop` has been run. Then test from mobile data.

## In the app

- Choose a language when you sign in. Change it later in your profile. A new language is a file in `lang/`.
- Dates follow the calendar and time zone in Settings.
- Password strength is in Settings: low, medium, or high.
- The online count comes from the app. CPU, memory, and bandwidth show up when the server can read them.
- A room manager can record the meeting. The file stays on that room and plays from the room card.
- The Files tab holds images, video, and PDFs. Notes on an image or PDF are live for people in the room and are not saved. People in the room can download the files. The host deletes them and can allow others to upload.
- When the host opens the stage, the whiteboard, or Files, the room follows. A file the host opens is shown to the room.
- Settings can download a full database backup.

## Donate

Financial support is welcome. USDT on BNB Smart Chain (BEP20):

```
0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD
```
