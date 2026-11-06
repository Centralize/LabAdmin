# LabAdmin

A small self-hosted dashboard for administering **Docker** and **LXC** containers on remote Linux servers over **SSH**.

- PHP 8.1+ backend, SQLite3 storage, vanilla ES-module JavaScript + UIkit 3 front-end (no build step)
- SSH via [phpseclib 3](https://phpseclib.com) – no PHP extensions or `ssh` binary needed
- Auto-detects per host: Docker, Proxmox VE (`pct`), Incus, LXD and classic LXC (`lxc-*`)

## Features

| Area | What you can do |
|---|---|
| Overview | One card per server with CPU, RAM, root-disk usage, load, uptime and running/total counts per engine |
| Containers | Unified Docker + LXC table: filter, start / stop / shutdown / restart / pause / kill / delete; Docker compose projects and Proxmox tags are grouped |
| Logs | Tail 50–5000 lines, follow mode, filter, copy (Docker logs; LXC: host + in-container journal) |
| Terminal | Full interactive terminal in the browser (xterm.js) on the host or inside any running container – vim, nano, htop, Tab completion, Ctrl+C, colours, resizing |
| Edit file | Built-in editor for config files on the host or in a container: backup before saving, keeps owner/permissions/line endings, warns if the file changed meanwhile |
| Run command | One-off commands with their output and exit code, with history (non-interactive) |
| Inspect | `docker inspect` / `pct config` / `incus config show` / LXC config |
| Images | List / pull / remove Docker images, Proxmox templates (with catalogue browser), Incus/LXD images |
| Create | Engine-specific forms: Docker (`run` with ports/env/volumes/limits), Proxmox `pct create`, Incus/LXD `launch`, classic `lxc-create -t download` |
| Audit log | Every action, command, success/failure and login – searchable and filterable by server |

## Install

```bash
cd LabAdmin
composer install --no-dev
```

Required PHP extensions: `pdo_sqlite`, `openssl` (and `mbstring`, recommended). On Windows enable them in `php.ini`:

```ini
extension=pdo_sqlite
extension=openssl
extension=mbstring
```

### Run it

**Linux / macOS** (built-in server with parallel workers – recommended for quick use):

```bash
./start.sh            # web UI on 0.0.0.0:8080, terminal service on 8081
```

**Windows**:

```bat
start.bat             REM opens the terminal service in its own (minimised) window
```

Both scripts start two processes:

| Process | Port | What it does |
|---|---|---|
| `php -S … -t public` | 8080 | the web UI and its JSON API |
| `php bin/terminal-server.php` | 8081 | interactive terminals (WebSocket ↔ SSH with a PTY) |

Open TCP **8081** in the firewall of the machine running LabAdmin (as well as 8080), otherwise the
Terminal button reports that it cannot reach the service. Everything else works without it.

> The PHP built-in server on Windows handles **one request at a time**. With several
> servers, or one that is offline, the UI will feel sluggish while it waits for SSH
> time-outs. For daily use put LabAdmin behind Apache (XAMPP/Laragon) or nginx + PHP-FPM.

**Apache / nginx**: point the document root at `public/`, and run `php bin/terminal-server.php` as a
service (systemd unit / NSSM on Windows). Behind a reverse proxy, proxy a path such as `/term` to
`127.0.0.1:8081` with WebSocket upgrade enabled and set `'terminal_url' => 'wss://your-host/term'` in
`config.local.php`. Nothing outside `public/` must be
web-reachable (`data/` holds the database and the encryption key). `.htaccess` files are
included as a safety net for Apache.

Open `http://<machine>:8080/`. The first visit asks you to create the admin account.

## Adding a server

*Servers → +*. Use a dedicated SSH key where possible (paste it or load the key file;
OpenSSH, PEM and PuTTY `.ppk` formats are accepted).

- **root** login works out of the box.
- **Non-root user**: tick *Run container tools with sudo -n* and give that user passwordless sudo
  for the tools, e.g. `/etc/sudoers.d/labadmin`:

  ```
  labadmin ALL=(root) NOPASSWD: /usr/bin/docker, /usr/sbin/pct, /usr/bin/pvesh, /usr/bin/pveam, /usr/sbin/pvesm, /usr/bin/incus, /usr/bin/lxc*, /usr/bin/journalctl
  ```

  (LabAdmin wraps commands in `sudo -n sh -c '…'`, so a full `NOPASSWD: ALL` rule is the
  simplest; restrict it if you prefer.) Alternatively add the user to the `docker` /
  `incus-admin` / `lxd` group and leave sudo off.

On *Save & test* LabAdmin connects, **pins the server's SSH host key** (trust-on-first-use) and
detects the available engines. If a host key later changes, connections are refused until you
press *Trust new host key* in the server settings.

## Security notes

- Single admin account; password hashed with `password_hash()`; 5 failed logins per IP per
  15 min → temporary lock-out.
- Session cookie is `HttpOnly` + `SameSite=Strict`; every state-changing call needs a CSRF token.
- Stored SSH passwords / private keys / passphrases are encrypted with AES-256-GCM. The key is
  `data/secret.key` – back it up together with `data/labadmin.sqlite`, and keep both private.
- Container names, images, ports etc. are validated and POSIX-quoted before being sent to the
  remote shell. The *exec* and *host shell* features intentionally run arbitrary commands –
  that is what they are for, so protect the login.
- Intended for a trusted LAN. If you ever expose it, put it behind HTTPS (reverse proxy) and
  ideally a VPN.

## Terminal and editor notes

- A terminal opens through a one-time token (valid 60 s) issued to your logged-in session, and the
  service checks that the browser's Origin matches the LabAdmin host. Opening and closing a terminal
  is written to the audit log; the keystrokes inside it are not.
- Container terminals start `bash` if the container has it, otherwise `sh`. Docker uses
  `docker exec -it`, Proxmox `pct exec`, LXD/Incus `lxc|incus exec`, classic LXC `lxc-attach`.
- Idle terminals (no typing) are closed after 4 hours (`terminal_idle_minutes`).
- Ctrl+W, Ctrl+T and Ctrl+N belong to the browser and close/open tabs; use the terminal's own
  alternatives (e.g. `:q` in vim) and the **Close** button.
- The editor handles UTF-8 text files up to 2 MB. Backups are written next to the file as
  `<name>.labadmin-YYYYmmdd-HHMMSS.bak` (switch off per save with the Backup checkbox). Files are
  rewritten in place, so owner, permissions and hard links are preserved.

## Configuration

Copy `config.local.sample.php` to `config.local.php` to override defaults (SSH timeouts,
session lifetime, data directory, …).

## Layout

```
public/            web root (index.php, api.php, assets/)
bin/               terminal-server.php (interactive terminal service)
src/               PHP classes (namespace LabAdmin)
  Drivers/         DockerDriver, ProxmoxDriver, IncusDriver (Incus + LXD), LxcClassicDriver
data/              SQLite DB + secret.key (created automatically, never web-served)
```

Adding another engine = one class extending `Drivers\ContainerDriver`, plus a detection line in
`Hosts::capabilities()`.

## Limitations

- Docker CPU/memory figures come from `docker stats --no-stream` (adds ~2 s to a refresh).
- Proxmox: one node per host entry (`pvesh /nodes/$(hostname)/lxc`); add each cluster node separately.
