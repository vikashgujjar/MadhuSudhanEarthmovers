# Madhusudhan Earthmover ERP (Laravel)

Web-based ERP for **Madhusudhan Earthmover**: JCB, tractor, earthmoving and equipment services.
It tracks JCB and tractor income and expenses, driver expenses, customer payments and outstanding
balances, vehicle-wise profitability and a cash book. It has role-based access, an approval
workflow, an audit trail, and PDF / Excel / CSV / print reports.

This is the PHP/Laravel version, built to run on ordinary PHP + MySQL shared hosting such as
Hostinger Web/Cloud hosting. For going live, see **[deploy/HOSTINGER.md](deploy/HOSTINGER.md)**.

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| Database | MySQL 8 / MariaDB 10.4+ |
| Front end | Blade templates, Tailwind CSS 3 (standalone CLI, no Node.js), vanilla JavaScript |
| Charts / icons | Chart.js 4.4.7, Lucide 0.469 (in `public/vendor/`) |
| Reports | PhpSpreadsheet (Excel), dompdf (PDF, with the ₹ glyph), CSV |
| Auth | Laravel session auth, server-enforced roles, capability gates |

## Run it on this computer (XAMPP)

```powershell
# Start MySQL from the XAMPP control panel (or C:\xampp\mysql\bin\mysqld.exe)
cd laravel
composer install
copy .env.example .env          # set DB_DATABASE=me_erp, DB_USERNAME=root, APP_DEBUG=true
php artisan key:generate
php artisan migrate
php artisan erp:create-admin admin "Choose-A-Strong-Pass1" --name="Owner"
php artisan serve
```

Open http://127.0.0.1:8000.

## Tests

```powershell
# needs an empty MySQL database named me_erp_test (see phpunit.xml)
php artisan test
```

The 95 feature tests cover:
- billing calculations, the no-double-counting rule, partial payments and outstanding balances,
  and the cash book;
- the approval workflow and server-side permissions for all three roles;
- form validation, search, filters and pagination;
- CSV, Excel and PDF exports, audit logging and immutability;
- branding and settings, protected uploads, and the web installer;
- a crawler that opens every link (including every export link) visible to each role and fails
  on any error or forbidden link.

## Rebuilding the CSS

`public/css/app.css` is committed. After changing templates or `resources/css/input.css`:

```powershell
..\tools\tailwindcss.exe -c tailwind.config.js -i resources\css\input.css -o public\css\app.css --minify
```

## Packaging for hosting

```powershell
powershell -ExecutionPolicy Bypass -File deploy\make-hostinger-package.ps1          # first install
powershell -ExecutionPolicy Bypass -File deploy\make-hostinger-package.ps1 -Update  # later updates
```

The first-install zip includes:
- the production `vendor/` folder;
- a root `.htaccess` that forces HTTPS, routes everything into `public/`, and blocks `.env` and
  the code;
- a fresh `.env` with a new `APP_KEY` and a one-time `ERP_INSTALL_KEY` for the web installer
  at `/install`.

Database changes in later versions are applied from **Business Settings → Apply database
updates**, so no terminal is needed.

## Roles and permissions (enforced on the server)

| | Admin | Entry User | Viewer |
|---|---|---|---|
| Users, business settings, audit logs, approvals, deletes | ✔ | ✘ | ✘ |
| Add income / expenses / driver expenses / payments | ✔ | per flag | ✘ (never) |
| Add/edit vehicles, drivers, customers | ✔ | `manage_masters` flag | ✘ |
| Dashboard totals, reports, cash book, profitability | ✔ | `view_reports` flag | `view_reports` flag |
| Export PDF / Excel / CSV | ✔ | `export_reports` flag | `export_reports` flag |
| Sees transactions | all | all (edits own only) | all (read-only) |
| Money Lent (loans given, repayments, reminders) | ✔ | ✘ | ✘ |
| Edit transactions | any | own, while Pending/Rejected | ✘ |

Login lockout: 5 failed attempts for a username within 15 minutes block that username for
15 minutes.

## Financial rules

* **Only Approved** transactions count in dashboards, balances and reports. Admin entries are
  approved immediately. Entry User entries are Pending while *Require approval* is on.
* **Billed income ≠ money received.** Profit = billed income − expenses. The Cash Book uses money
  actually received and paid.
* **Hourly / daily** amount = quantity × rate, computed on the server in integer paise (half-up).
* **Category first:** the vehicle must belong to the chosen JCB/Tractor category (checked on the
  server; the browser only filters the list).
* **No double counting:** every rupee spent is exactly one expense row. A driver expense is counted
  once in total expenses, once in its vehicle and once in its driver.
  JCB + Tractor + General = total expenses.
* **Partial payments** can never exceed the invoice's remaining balance. The invoice row is locked
  while saving. Payments without an invoice are advances.
* **Customer outstanding** = opening balance + approved invoices − approved payments. Advances held
  by one customer never reduce what others owe.
* Money is `DECIMAL(14,2)` in MySQL and exact integer-paise arithmetic in PHP (`App\Support\Money`).
  Aggregates use correlated subqueries, so joins never multiply sums.

## Project layout

```
app/Models/          User, BusinessSetting, Vehicle, Driver, Customer, Income, Expense, Payment, AuditLog
app/Services/        Finance (all totals), Workflow (approve/reject), Audit (immutable trail)
app/Reports/         ReportTable + Exporter (CSV / Excel / PDF)
app/Http/Controllers one controller per module; Controller.php holds shared helpers
app/Support/         Money (exact arithmetic, Indian formatting), Period (financial year), PasswordRules
resources/views/     Blade pages; components/field.blade.php renders every form field
deploy/              Hostinger guide, package builder, root .htaccess
tests/Feature/       Automated tests
```

## Known limitations

* No GST tax-invoice generation (GSTIN is stored for headers only).
* Expenses are treated as paid when approved (no supplier payables ledger).
* Approved transactions are corrected by an admin edit (fully audited), not reversal vouchers.
* No password reset by email; administrators reset passwords.
* Google Fonts load from the internet; offline, the system font is used.
