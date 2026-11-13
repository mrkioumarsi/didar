# Didar

Didar is a meeting app that runs in the browser and is built with PHP. People join a room, talk, turn a camera on, share a screen, draw on the whiteboard, open images, video, and PDFs, and send chat. A small group connects directly from browser to browser. A larger group sends audio and video through a media server that this project installs on your own Ubuntu machine. There is no outside meeting service. Accounts, rooms, chat, recordings, and settings stay in your database.

The web app handles sign-in, languages, the calendar, and a simple view of who is online. Settings can download a full database backup and replace the app with a newer GitHub release.

[فارسی](README.fa.md)

## What you need

- Ubuntu with root access
- A domain whose A record points at the public IP of that server
- An email address for the HTTPS certificate
- These ports open on the public IP, and forwarded to the server if it sits behind NAT: 22, 80, 443, 3478 (TCP and UDP), 5349 (TCP), 7881 (TCP), UDP 49152–49999, and UDP 50000–60000
- Outbound HTTPS to Let's Encrypt (`acme-v02.api.letsencrypt.org`). If certificate issuance times out, wait and run the installer again.

## Install

On Ubuntu as root, download the release, then run `didar-install.sh`. `--confirm-wipe` is required when there is no prompt. It erases every MariaDB database on this machine. Existing Nginx sites stay. The admin password must be at least 8 characters. If you omit `--admin-pass`, the script creates one and prints it once at the end.

```bash
wget -qO didar.tar.gz "https://github.com/mrkioumarsi/didar/archive/refs/tags/v1.1.0.tar.gz?$(date +%s)"
tar -xzf didar.tar.gz
cd didar-1.1.0
sudo bash didar-install.sh --domain meet.example.com --email you@example.com --admin-user admin --admin-pass 'your-password' --confirm-wipe
```

Replace the domain, email, username, and password. The first installer line must be `Didar 1.1.0 installer 20261113-nat`. If that line is missing, the download is an old copy; delete `didar.tar.gz` and `didar-1.1.0` and run wget again. The installer puts Nginx, PHP, MariaDB, Coturn, LiveKit, the HTTPS certificate, and the media ports on this machine, then creates the admin account.

Open `https://meet.example.com/` and sign in. In Settings, choose few people or many people. The addresses are already filled in.

## Update

Keeps the database, `config.local.php`, rooms, recordings, and uploaded files. Replaces the app files with a newer GitHub release.

From Settings, check GitHub, then confirm Update. On the server:

```bash
sudo didar-update
```

If that command is missing:

```bash
sudo bash /var/www/didar/didar-update.sh
```

## Reinstall this version

Same as update, but downloads the current tag again (for a server already on 1.1.0). Database, config, and uploads stay. From Settings use «Reinstall this version», or:

```bash
sudo didar-reinstall
```

```bash
sudo bash /var/www/didar/didar-reinstall.sh
```

## Uninstall

Two modes. Without `--yes` the script waits until you type the confirm word.

`--soft` — type `REMOVE`. Deletes the Didar database and user, Didar files, the Didar Nginx site, the Didar PHP pool, LiveKit, Coturn, and the Didar certificate. Nginx, PHP, MariaDB, other databases, other sites, certbot, ffmpeg, SSH, and UFW stay.

```bash
sudo didar-uninstall --soft
```

`--hard` — type `DELETE`. Also removes Nginx, PHP, MariaDB with every database, certbot, ffmpeg, all certificates on this server, and the firewall rules the installer opened. SSH and UFW itself stay. This cannot be undone.

```bash
sudo didar-uninstall --hard
```

Non-interactive: add `--yes` next to `--soft` or `--hard`. If the command is missing:

```bash
sudo bash /var/www/didar/didar-uninstall.sh --soft
sudo bash /var/www/didar/didar-uninstall.sh --hard
```

## NAT and media

The A record must be the public IP. If the server is behind NAT, forward the ports listed above to the private IP. The installer writes Coturn `external-ip` as public/private and advertises the public IP to LiveKit.

Clients behind NAT use STUN on 3478 and TURN on 3478 UDP, 3478 TCP, and 5349 TLS. Those addresses are filled in Settings. If audio or video still fail, turn on ICE relay in Settings so the path uses TURN.

Hairpin NAT (a client on the same LAN as the server, using the public hostname) needs the router to loop that traffic back, or the client should use a hostname that resolves to the private IP.

## In the app

- Choose a language when you sign in. Change it later in your profile. A new language is a file in `lang/`.
- Dates follow the calendar and time zone in Settings.
- Password strength is in Settings: low, medium, or high.
- The online count comes from the app. CPU, memory, and bandwidth show up when the server can read them.
- A room manager can record the meeting. The file is listed on that room and plays from the room card.
- The Files tab holds images, video, and PDFs. Notes on an image or PDF are live for people in the room and are not saved. People in the room can download the files. The host can delete them, and can allow others to upload.
- Settings can keep the automatic certificate or take a private key, public key, certificate, and bundle. Saving restarts the web server once. If you return to automatic and that certificate is missing, the manual certificate stays.
- Settings can download a full database backup.
- Settings checks the GitHub release. When you confirm, it replaces the app files. The database, `config.local.php`, and uploaded files stay.

## Donate

Financial support is welcome. USDT on BNB Smart Chain (BEP20):

```
0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD
```
