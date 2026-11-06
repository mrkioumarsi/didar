# Didar

Didar is a meeting app that runs in the browser and is built with PHP. People join a room, talk, turn a camera on, share a screen, draw on the whiteboard, and send chat. A small group connects directly from browser to browser. A larger group sends audio and video through a media server that this project installs on your own Ubuntu machine. There is no outside meeting service. Accounts, rooms, chat, recordings, and settings stay in your database.

The web app handles sign-in, languages, the calendar, and a simple view of who is online. Settings can download a full database backup and replace the app with a newer GitHub release.

[فارسی](README.fa.md)

## What you need

- Ubuntu with root access
- A domain whose A record points at that server
- An email address for the HTTPS certificate
- These ports open: 22, 80, 443, 3478 (TCP and UDP), 5349 (TCP), 7881 (TCP), UDP 49152–49999, and UDP 50000–60000

## Install

On the server, run this. It downloads the release, then installs:

```bash
wget -qO didar.tar.gz https://codeload.github.com/mrkioumarsi/didar/tar.gz/refs/tags/v1.0.3 && tar -xzf didar.tar.gz && cd didar-1.0.3 && sudo bash ubuntu.sh --domain meet.example.com --email you@example.com --admin-user admin --admin-pass 'your-password'
```

Put your own admin username and password in that command. The password must be at least 4 characters. If you omit `--admin-pass`, the script creates one and prints it once at the end.

The script installs the web server, PHP, the database, the certificate, and the audio and video service. It creates the admin account and then removes the installer from the web root.

Open `https://meet.example.com/` and sign in. In Settings, choose few people or many people. The addresses are already filled in.

Running the script again clears the Didar database and installs from the start. Other software on the server stays.

## In the app

- Choose a language when you sign in. Change it later in your profile. A new language is a file in `lang/`.
- Dates follow the calendar and time zone in Settings.
- Password strength is in Settings: low, medium, or high.
- The online count comes from the app. CPU, memory, and bandwidth show up when the server can read them.
- A room manager can record the meeting. The file is listed on that room and can be downloaded from the room card.
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
