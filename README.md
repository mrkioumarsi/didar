# Didar

Meeting rooms in the browser, built with PHP. A small group connects directly. A larger group uses your own Ubuntu server for audio and video.

[فارسی](README.fa.md)

## What you need

**Ubuntu server**

- Ubuntu with root access
- A domain whose A record points at that server
- An email address for the HTTPS certificate
- These ports open: 22, 80, 443, 3478 (TCP and UDP), 5349 (TCP), 7881 (TCP), UDP 49152–49999, and UDP 50000–60000

**Shared hosting**

- PHP 8 and MySQL
- HTTPS
- Enough for a small group only. Audio and video go directly between browsers

## Install on Ubuntu

Copy the project onto the server, open that folder, and run:

```bash
sudo bash ubuntu.sh --domain meet.example.com --email you@example.com
```

The admin login is optional: `--admin-user admin --admin-pass 'your-password'`. If you leave the password out, the script makes one and prints it at the end.

The script installs the web server, PHP, the database, the certificate, and the audio and video service. It creates the admin account and then removes the web installer.

Open `https://meet.example.com/` and sign in. In Settings, choose few people or many people. The addresses are already filled in.

Running the script again clears the Didar database and installs from the start. Other software on the server stays.

## Install on shared hosting

Create an empty MySQL database, upload the files, and open:

```text
https://your-domain/install.php
```

The installer opens in English until you pick another language. After a successful install it deletes itself and the database file. Leave the connection mode on few people.

## In the app

- Choose a language when you sign in. Change it later in your profile. A new language is a file in `lang/`.
- Dates follow the calendar and time zone in Settings.
- Password strength is in Settings: low, medium, or high.
- The online count comes from the app. CPU, memory, and bandwidth show up when the server can read them.
- Settings can download a full database backup.
- Settings checks the GitHub release. When you confirm, it replaces the app files. The database, `config.local.php`, and uploaded files stay.

## Support

<p align="center">
  <a href="https://bscscan.com/address/0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD"><img alt="Donate USDT BEP20" src="https://img.shields.io/badge/Donate-USDT%20BEP20%20on%20BSC-f3ba2f?style=for-the-badge&logo=binance&logoColor=white"></a>
</p>

USDT on BNB Smart Chain (BEP20):

```
0xAec9F6Ad80940a50e35F453d3354CA8379d0CFaD
```
