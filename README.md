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
| Exec | Run one-off commands inside a container (`docker exec`, `pct exec`, `incus exec`, `lxc-attach`) or on the host itself, with history |
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
./start.sh            # listens on 0.0.0.0:8080
```

**Windows**:

```bat
start.bat
```

> The PHP built-in server on Windows handles **one request at a time**. With several
> servers, or one that is offline, the UI will feel sluggish while it waits for SSH
> time-outs. For daily use put LabAdmin behind Apache (XAMPP/Laragon) or nginx + PHP-FPM.

**Apache / nginx**: point the document root at `public/`. Nothing outside `public/` must be
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

## Configuration

Copy `config.local.sample.php` to `config.local.php` to override defaults (SSH timeouts,
session lifetime, data directory, …).

## Layout

```
public/            web root (index.php, api.php, assets/)
src/               PHP classes (namespace LabAdmin)
  Drivers/         DockerDriver, ProxmoxDriver, IncusDriver (Incus + LXD), LxcClassicDriver
data/              SQLite DB + secret.key (created automatically, never web-served)
```

Adding another engine = one class extending `Drivers\ContainerDriver`, plus a detection line in
`Hosts::capabilities()`.

## Limitations

- Exec is non-interactive (`sh -c`, no TTY) – fine for `df -h`, `apt list --upgradable`,
  `cat /etc/...`; use a real SSH session for `top`, editors or interactive installers.
- Docker CPU/memory figures come from `docker stats --no-stream` (adds ~2 s to a refresh).
- Proxmox: one node per host entry (`pvesh /nodes/$(hostname)/lxc`); add each cluster node separately.
