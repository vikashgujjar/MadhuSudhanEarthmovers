# Putting the ERP live on Hostinger (Web / Cloud hosting)

This works on Hostinger's normal website hosting (the plan with **hPanel → File Manager**).
You do not need a VPS, SSH or any software on the server. It takes about 20 minutes.

**You need:**
- The file `me-erp-hostinger.zip` from the `laravel` folder on this computer.
- Your domain connected to your Hostinger hosting.

---

## Step 1: Create the database

1. Go to **hPanel → Websites → Manage → Databases → MySQL Databases**.
2. Create a new database. Hostinger shows three values; write them down. They look like this:
   - Database name: `u123456789_meerp`
   - Username: `u123456789_meerp`
   - Password: the one you chose

## Step 2: Check PHP and HTTPS

1. **hPanel → Advanced → PHP Configuration:** choose **PHP 8.2** or **8.3**.
   The required extensions (pdo_mysql, mbstring, gd, zip, fileinfo) are on by default.
2. **hPanel → Security → SSL:** make sure SSL is **active** for your domain.
   The ERP always uses `https://`.

## Step 3: Upload and extract

1. Open **hPanel → Files → File Manager** and go into `public_html`.
2. Delete Hostinger's placeholder files (`default.php`, `index.html`) and any leftovers from earlier
   uploads. If a website you still need is in this folder, use a **subdomain** for the ERP instead
   (e.g. `erp.yourdomain.com`; hPanel → Domains → Subdomains) and upload into its folder.
3. Upload `me-erp-hostinger.zip`, right-click it → **Extract**, and extract into the **current
   folder**. Afterwards `public_html` must directly contain `app`, `public`, `vendor`, `.env` and
   `.htaccess`, not a sub-folder.
4. Delete the `.zip` file.

> Hidden files: `.env` and `.htaccess` start with a dot. If you don't see them, turn on
> "Show hidden files" in the File Manager settings.

## Step 4: Fill in the `.env` file

Open `.env` (right-click → **Edit**) and change these lines:

```
APP_URL=https://yourdomain.com
DB_DATABASE=u123456789_meerp
DB_USERNAME=u123456789_meerp
DB_PASSWORD=your-database-password
```

Leave everything else as it is. **Never share this file**: it contains your secret keys.

## Step 5: Run the installer

1. Open `https://yourdomain.com/install` in your browser.
2. Every server check should say **OK**. If "Database connection" fails, re-check Step 4.
3. Fill in the form:
   - **Installation key:** copy the value after `ERP_INSTALL_KEY=` from the `.env` file.
   - Your business name, your name, and the **admin username and password** you want.
4. Click **Install**. You are signed in and taken to **Business Settings**: add your address,
   phone, GSTIN, logo and opening cash balance.

The installer switches itself off once an admin exists. You can also delete the
`ERP_INSTALL_KEY=` line from `.env` now.

## Step 6: Start using it

1. **Vehicles:** JCB Management / Tractor Management → add each machine.
2. **Drivers:** add them and assign each to a vehicle.
3. **Customers:** add them (with any opening balance they already owe).
4. **Users & Permissions:** create logins for staff (Entry User / Viewer).

---

## Backups (important)

- **hPanel → Files → Backups** keeps automatic backups (frequency depends on your plan).
- Monthly, also keep your own copy:
  - **Database:** hPanel → Databases → **phpMyAdmin** → select the database → **Export** → Go.
  - **Uploaded bills and logo:** in File Manager, download the folder `storage/app/private`.

## Installing a new version later

1. On this computer, run in PowerShell inside the `laravel` folder:
   ```powershell
   powershell -ExecutionPolicy Bypass -File deploy\make-hostinger-package.ps1 -Update
   ```
   This creates `me-erp-hostinger-update.zip`. It contains no `.env` and no `storage` folder, so
   your settings and uploaded files on the server are kept.
2. Take a backup (see above).
3. Upload the update zip to `public_html`, extract it into the current folder and allow it to
   overwrite files, then delete the zip.
4. Sign in as admin and open **Business Settings**. If the update includes database changes, a
   yellow box with **Apply database updates** appears; click it.

## If something goes wrong

| Problem | What to do |
|---|---|
| "500 / Something went wrong" | File Manager → `storage/logs/laravel.log` shows the reason. A wrong database password is the most common cause. |
| Page looks unstyled or icons are missing | The files were extracted into a sub-folder. Move everything up so it sits directly in `public_html`. |
| "Page expired" after login | The form was open too long. Reload the page and try again. |
| Forgot the admin password | Another admin can reset it in Users & Permissions. If there is no other admin, ask your developer to reset it in the database. |
| "Too many failed attempts" | Five wrong passwords lock that username for 15 minutes. |
