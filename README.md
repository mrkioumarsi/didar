# Didar

PHP meeting rooms in the browser. A small group connects directly. A larger group uses your own Ubuntu server for TURN and a media server.

[فارسی](README.fa.md)

## Install on Ubuntu

Point the domain at the server with a DNS A record. Copy this project onto the server, then run:

```bash
sudo bash install/ubuntu.sh --domain meet.example.com --email you@example.com
```

Optional admin flags: `--admin-user admin --admin-pass 'your-password'`. If you omit the password, the script generates one and prints it.

The script installs Nginx, PHP, MariaDB, a TLS certificate, Coturn for STUN and TURN, and LiveKit. It creates the database, the admin, and the connection settings, then removes the web installer. TURN uses port 3478. Encrypted TURN uses port 5349.

Open `https://meet.example.com/` and sign in with the admin printed at the end. In Settings, choose few people or many people. The addresses are already filled in.

Running the script again clears the previous Didar database and installs from the start. Other software on the server is left in place.

## Install on shared hosting

Create an empty MySQL database, upload the files, and open `install/install.php`. After a successful install, that page deletes itself. Leave the connection mode on few people. TURN and the media server need the Ubuntu script above.

## In the app

- Pick a language on the sign-in page. Change it later in the profile. Add a language by dropping a file in `lang/`.
- Dates follow the calendar and timezone chosen in Settings. The default calendar is Jalali.
- Password strength is also in Settings: low, medium, or high.
- Online count comes from the app. CPU, memory, and bandwidth appear when the server can read them.
- Settings can download a full database backup.
- Settings checks the GitHub release and, when you confirm, replaces the app files. The database, `config.local.php`, and uploaded files stay. On Ubuntu the install script allows the web user to apply that update. On shared hosting, PHP must be able to write the site files.

## Support

<p align="center">
  <a href="https://bscscan.com/address/0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD"><img alt="Donate USDT BEP20" src="https://img.shields.io/badge/Donate-USDT%20BEP20%20on%20BSC-f3ba2f?style=for-the-badge&logo=binance&logoColor=white"></a>
</p>

USDT on BNB Smart Chain (BEP20):

```
0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD
```
