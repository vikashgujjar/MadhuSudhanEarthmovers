> **Note:** the live version of this ERP is now the PHP/Laravel app in [laravel/](laravel/README.md), built for Hostinger shared hosting. This Django code is kept for reference.

# Madhusudhan Earthmover ERP

Web-based ERP for **Madhusudhan Earthmover** — JCB, tractor, earthmoving and equipment services.
Tracks JCB and tractor income and expenses, driver expenses, customer payments and outstanding balances,
vehicle-wise profitability and a cash book, with role-based access, an approval workflow, an audit trail
and PDF / Excel / CSV / print reports.

| Layer | Technology |
|---|---|
| Backend | Python 3.12, Django 5.2 |
| Database | PostgreSQL (16 tested) |
| Front end | Django templates, Tailwind CSS 3 (standalone CLI, no Node.js), vanilla JavaScript |
| Charts / icons | Chart.js 4.4.7, Lucide 0.469 (vendored in `static/vendor/`) |
| Reports | openpyxl (Excel), ReportLab (PDF), csv |
| Auth | Django authentication + server-enforced roles and capability flags |

---

## 1. Quick start (Windows, local)

```powershell
cd MadhuSudhan-Earthmover
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env          # then edit DB_* values (see section 2)
python manage.py migrate
python manage.py setup_erp --username admin --password "Choose-A-Strong-Pass1"
python manage.py runserver
```

Open http://127.0.0.1:8000 and sign in as `admin`.

Optional — load **demo** data to explore (never on a live database):

```powershell
python manage.py seed_demo --yes      # adds demo vehicles, drivers, customers, ~6 months of transactions
```

Demo users created: `entry` (Entry User) and `viewer` (Viewer), password `Demo@12345`.

## 2. PostgreSQL setup

### Option A — install PostgreSQL (recommended, required for production)

1. Download PostgreSQL 16 for Windows from https://www.postgresql.org/download/windows/ and install
   (remember the `postgres` password; keep port 5432).
2. Create the database:
   ```powershell
   & "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -c "CREATE DATABASE me_erp;"
   ```
3. In `.env`:
   ```
   DB_ENGINE=postgres
   DB_NAME=me_erp
   DB_USER=postgres
   DB_PASSWORD=<your password>
   DB_HOST=127.0.0.1
   DB_PORT=5432
   ```
4. `python manage.py migrate`

### Option B — embedded PostgreSQL for development/testing only

No system install needed; `pgserver` ships PostgreSQL 16 binaries inside the virtualenv.

```powershell
pip install -r requirements-dev.txt
# The Windows wheel lacks time-zone files; copy them in once:
python -c "import tzdata,pgserver,os,shutil;src=os.path.join(os.path.dirname(tzdata.__file__),'zoneinfo');dst=os.path.join(os.path.dirname(pgserver.__file__),'pginstall','share','postgresql','timezone');shutil.copytree(src,dst,dirs_exist_ok=True)"
# Start it (prints the URI with the port to put in .env as DB_PORT):
python -c "import pgserver,os;s=pgserver.get_server(os.path.join(os.environ['LOCALAPPDATA'],'me_erp_pgdata'),cleanup_mode=None);print(s.get_uri())"
python -c "import psycopg;psycopg.connect('postgresql://postgres:@127.0.0.1:<PORT>/postgres',autocommit=True).execute('CREATE DATABASE me_erp')"
```

(`DB_ENGINE=sqlite` also exists for throw-away experiments; it is not supported for real data.)

## 3. Running the tests

```powershell
python manage.py test tests
```

The suite (85 tests) runs against the configured database (PostgreSQL). It covers billing calculations,
the no-double-counting rule, partial payments and outstanding balances, cash book, approval workflow,
server-side permissions for all three roles, form validation, search/filter/pagination, CSV/Excel/PDF
exports, audit logging and immutability, branding/settings, and a crawler that opens every link visible to
each role (including all export links) and fails on any error or forbidden link.

Lint: `python -m pyflakes apps config tests`

## 4. Rebuilding the CSS

`static/css/app.css` is committed. After changing templates or `static/src/input.css`, rebuild with the
Tailwind standalone CLI (single executable, no Node.js):

```powershell
# one-time download into tools/ (git-ignored)
curl -L -o tools\tailwindcss.exe https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-windows-x64.exe
tools\tailwindcss.exe -c tailwind.config.js -i static\src\input.css -o static\css\app.css --minify
```

## 5. Deployment

**Hostinger VPS / any Ubuntu 24.04 server:** follow [deploy/DEPLOY.md](deploy/DEPLOY.md). The scripts in
`deploy/` set up PostgreSQL, Gunicorn, Nginx, HTTPS, a firewall and daily backups in one command, and
handle later updates.

Manual setup on other servers:

1. Server with Python 3.12 and PostgreSQL 16. Clone the project, create the virtualenv, `pip install -r requirements.txt`.
2. Production `.env`:
   ```
   DJANGO_DEBUG=false
   DJANGO_SECRET_KEY=<python -c "import secrets;print(secrets.token_urlsafe(50))">
   DJANGO_ALLOWED_HOSTS=erp.example.com
   DJANGO_CSRF_TRUSTED_ORIGINS=https://erp.example.com
   DJANGO_SSL_REDIRECT=true          # when served over HTTPS
   DJANGO_HSTS_SECONDS=31536000      # after HTTPS is confirmed working
   DB_* = production database
   DJANGO_MEDIA_ROOT=/var/lib/me-erp/media   # uploaded logos and bills; back this up
   ```
3. ```
   python manage.py migrate
   python manage.py collectstatic --noinput
   python manage.py setup_erp --username admin --password "<strong password>"
   python manage.py check --deploy
   ```
   `check --deploy` leaves two optional warnings (`SECURE_HSTS_INCLUDE_SUBDOMAINS`, `SECURE_HSTS_PRELOAD`);
   enable them only if every subdomain is HTTPS-only.
4. Run the app server behind Nginx/IIS (HTTPS termination):
   - Linux: `gunicorn config.wsgi:application --bind 127.0.0.1:8000 --workers 3`
   - Windows: `waitress-serve --listen=127.0.0.1:8000 config.wsgi:application`
5. Static files are served by WhiteNoise. Uploaded files are served by the app through an access-checked
   view (`/media/…`): the logo is public, expense bills require a user allowed to see that expense.
6. Back up the PostgreSQL database (`pg_dump`) and the media folder daily.

## 6. Roles and permissions (enforced on the server)

| | Admin | Entry User | Viewer |
|---|---|---|---|
| Users, business settings, audit logs, approvals, deletes | ✔ | ✘ | ✘ |
| Add income / expenses / driver expenses / payments | ✔ | per flag | ✘ (never) |
| Add/edit vehicles, drivers, customers | ✔ | `manage_masters` flag | ✘ |
| Dashboard totals, reports, cash book, profitability | ✔ | `view_reports` flag | `view_reports` flag |
| Export PDF / Excel / CSV | ✔ | `export_reports` flag | `export_reports` flag |
| Sees transactions | all | **own only** | all (read-only) |
| Edit transactions | any | own, while Pending/Rejected | ✘ |

Login lockout: 5 failed attempts for a username within 15 minutes blocks that username for 15 minutes.

## 7. Financial rules

* **Only Approved** transactions count in dashboards, balances and reports. Admin entries are approved
  immediately; Entry User entries are Pending when *Business Settings → Require approval* is on.
* **Billed income ≠ money received.** Income entries are invoices (`Income.amount`). Payments are money
  received. Profit = billed income − expenses. The Cash Book uses money actually received and paid.
* **Hourly / daily** amounts = quantity × rate, computed on the server (rounded half-up to paise).
  Fixed contract / manual use the entered amount.
* **Category first:** income and vehicle-related expenses require JCB/Tractor, and the vehicle must belong to
  that category (validated in the model and the form; the browser only filters the list).
* **No double counting:** every rupee spent is exactly one `Expense` row. A driver expense is an Expense
  with category *Driver expense* plus a driver (and optionally the vehicle). It is counted once in total
  expenses, once in that vehicle's expenses and once in that driver's total — never added twice to one
  total. JCB expenses + Tractor expenses + General overheads = Total expenses (shown as a reconciliation).
* **Partial payments:** each instalment is a Payment against the invoice; a payment can never exceed the
  invoice's remaining balance (pending payments reserve their share; the invoice row is locked while saving).
  Payments without an invoice are advances.
* **Customer outstanding** = opening balance + approved invoices − approved payments. Customers holding an
  advance (negative balance) do not reduce the total owed by others.
* Money uses `DecimalField(14, 2)` everywhere; aggregates use subqueries so joins never multiply sums.

## 8. Project layout

```
config/            settings (env-driven), urls, wsgi
apps/core/         BusinessSettings (org name/logo), dashboard, permissions, periods, template tags, commands
apps/accounts/     custom User (role + capability flags), login, user management
apps/fleet/        Vehicle (JCB/Tractor), Driver, JCB & Tractor management screens, vehicle API
apps/customers/    Customer, account statement, outstanding balances
apps/finance/      Income, Expense (incl. driver expenses), Payment, approval workflow, calculation services
apps/reports/      Cash book, profitability, P&L, driver & category reports, CSV/Excel/PDF exporters
apps/audit/        Immutable AuditLog, login/logout signals, change diffs
templates/         All pages (Tailwind); static/ (css, js, vendor libraries, favicon)
tests/             Automated test suite
```

The organisation name, ERP title, tagline, logo, address, GSTIN, number prefixes, financial-year start,
opening cash balance and approval policy live in one `BusinessSettings` row (default "Madhusudhan
Earthmover", created by migration `core/0002`). Every page, PDF, Excel and CSV header reads from it;
change it at **Administration → Business Settings**.

## 9. Progress checklist

- [x] Phase 1 — Project setup, branding, authentication, PostgreSQL configuration
- [x] Phase 2 — Roles, permissions, admin / entry / viewer dashboards
- [x] Phase 3 — Vehicles (JCB & Tractor management), drivers, customers
- [x] Phase 4 — Income (hourly/daily/fixed/manual), expenses, driver expenses
- [x] Phase 5 — Payments, partial payments, outstanding balances, cash book
- [x] Phase 6 — Reports, PDF/Excel/CSV/print exports, approval workflow, audit logs
- [x] Phase 7 — Responsive checks (1440 px & 390 px), automated tests, security review, documentation

## 10. Known limitations

* No GST tax-invoice generation (amounts are recorded as billed; GSTIN is stored for headers only).
* Expenses are treated as paid when approved (no supplier credit / payables ledger).
* Approved transactions are corrected by an Admin edit (fully audited) rather than reversal vouchers.
* No password-reset-by-email (no mail server configured); administrators reset passwords.
* Login lockout is per username, not per IP.
* Google Fonts (Inter, Barlow Condensed) load from the internet; offline, the system font is used.
* PDF exports use the built-in Helvetica font, so amounts appear without the ₹ glyph (headers state INR).
# MadhuSudhanEarthmovers
