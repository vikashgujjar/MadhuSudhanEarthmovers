# Putting the ERP live on a Hostinger VPS

This guide takes the ERP from this computer to a live website such as
`https://erp.yourdomain.com`. It takes about 30–45 minutes the first time.

**You need:**
- A **Hostinger VPS** plan (KVM 1 or larger). Hostinger's *Web / Cloud hosting* plans
  cannot run this app: they have no PostgreSQL database and cannot keep a Python app running.
- A domain name (it can be the one you already have with Hostinger).
- This Windows computer with the project folder.

---

## Step 1: Prepare the VPS (in Hostinger hPanel)

1. Go to **hPanel → VPS → Manage**.
2. Under **OS & Panel → Operating System**, choose **Ubuntu 24.04** as a *plain OS*. Do not pick
   one with a control panel. This erases the VPS, so only do it on a new or empty server.
3. Set a **root password** when asked, or later under **Settings**. Note it down.
4. Note the server's **IP address**, shown on the VPS overview page, e.g. `82.25.10.20`.
5. If the VPS **Firewall** feature in hPanel is turned on, allow ports **22, 80 and 443**.

## Step 2: Point the domain at the server

In **hPanel → Domains → (your domain) → DNS / Nameservers → DNS records**, add:

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `erp` | *your VPS IP* | default |

This makes `erp.yourdomain.com` open the ERP. To use the main domain itself, use name `@` instead.
DNS changes usually take 5–30 minutes. Check by running `ping erp.yourdomain.com` in PowerShell. It
should show your VPS IP.

## Step 3: Build the upload package (on this computer)

Open PowerShell in the project folder and run:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\make_package.ps1
```

This creates `me-erp.tar.gz`. It contains the application only, not your local `.env`, the
virtualenv or any local data.

## Step 4: Upload and install

Still in PowerShell (replace the IP with yours):

```powershell
scp me-erp.tar.gz deploy\setup_server.sh root@82.25.10.20:/root/
ssh root@82.25.10.20
```

Type `yes` the first time, then enter the root password. You are now on the server. Run
(replace the domain and email with yours):

```bash
bash /root/setup_server.sh erp.yourdomain.com you@example.com
```

The email is only used by Let's Encrypt for HTTPS certificate expiry notices.

The script installs everything automatically:
- PostgreSQL, Nginx and HTTPS;
- a private database with a random password;
- a production `.env` with a new secret key;
- the app as a service that starts on boot;
- a firewall;
- daily backups.

Near the end it asks you to **choose the admin username and password**. Use a strong password.

When it prints `Done. Open https://erp.yourdomain.com`, open that address and sign in.

> If it says the HTTPS certificate could not be issued, the domain is not pointing at the server
> yet (Step 2). Wait a little, then run the `certbot …` command it prints.

## Step 5: First things to do in the ERP

1. **Administration → Business Settings:** check the name and logo, and add your address, phone and
   GSTIN. Set the **opening cash balance**.
2. **Users & Permissions:** create logins for staff (Entry User / Viewer).
3. Add vehicles, then drivers, then customers, then start entering transactions.

---

## Updating to a new version later

After changes are made on this computer:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\make_package.ps1
scp me-erp.tar.gz root@82.25.10.20:/root/
ssh root@82.25.10.20 "bash /srv/me-erp/app/deploy/update.sh /root/me-erp.tar.gz"
```

The update script:
- backs up the database first;
- keeps your settings and uploaded bills;
- applies database changes;
- restarts the app and checks that it responds.

## Backups

- The server backs up the database and uploaded bills **every night at 02:30** to
  `/var/backups/me-erp/` and keeps 14 days.
- These backups live on the same server. **Copy them to this computer regularly** (for example
  weekly):
  ```powershell
  scp -r root@82.25.10.20:/var/backups/me-erp C:\ME-ERP-Backups
  ```
- Hostinger's own weekly VPS backup (hPanel → VPS → Backups) is a useful extra layer.
- To take a backup right now, run `bash /srv/me-erp/app/deploy/backup.sh` on the server.

**Restoring a backup** (on the server; replace the file names with the ones you want):

```bash
systemctl stop me-erp
cd /tmp
runuser -u postgres -- pg_restore --clean --if-exists -d me_erp /var/backups/me-erp/db_2026-11-01_0230.dump
tar -xzf /var/backups/me-erp/media_2026-11-01_0230.tar.gz -C /var/lib/me-erp
systemctl start me-erp
```

## Useful server commands

| Task | Command |
|---|---|
| Is the app running? | `systemctl status me-erp` |
| App error log (last 100 lines) | `journalctl -u me-erp -n 100 --no-pager` |
| Restart the app | `systemctl restart me-erp` |
| Production settings | `nano /srv/me-erp/.env`, then `systemctl restart me-erp` |
| Reset a forgotten admin password | `cd /srv/me-erp/app && runuser -u meerp -- /srv/me-erp/venv/bin/python manage.py changepassword admin` |
| Install Ubuntu security updates | `apt update && apt upgrade -y` (monthly) |

## Where things are on the server

| What | Location |
|---|---|
| Application code | `/srv/me-erp/app` (previous version: `/srv/me-erp/app.old`) |
| Production settings (secret key, DB password) | `/srv/me-erp/.env` |
| Uploaded logo and bills | `/var/lib/me-erp/media` |
| Backups | `/var/backups/me-erp` |
| Web server config | `/etc/nginx/sites-available/me-erp` |

## Optional hardening once HTTPS works

After a few days of the site working over HTTPS, set `DJANGO_HSTS_SECONDS=31536000` in
`/srv/me-erp/.env` and run `systemctl restart me-erp`. Browsers will then refuse plain HTTP for
your domain. Do this only once HTTPS is reliable.
