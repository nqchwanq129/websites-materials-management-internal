# Materials Management Internal

Internal materials and warehouse management application.

## Project layout

- `app/` - application code and shared helpers
- `config/` - environment and application configuration
- `database/` - database schema and migrations
- `public/` - web entry point and public assets
- `resources/` - templates and other source resources
- `storage/` - runtime files and uploads
- `reference_source/` - local reference only; excluded from Git

## Bootstrap

The login interface is available at
`http://localhost/qlvt-remake/public/` when Apache is running in XAMPP.
For a VirtualHost, point its DocumentRoot to `public/`.

`public/index.php` renders `resources/views/auth/login.php`. The page uses
local CSS and JavaScript, with no external asset requests. The design is
independently implemented with Metronic Tailwind Demo 6 as a visual reference.
The login page uses the existing VISHIPEL logo and its purple/orange palette,
with a simple illustration panel. The layout adapts to viewport height to
fit the login controls on one screen without horizontal or vertical scrolling.

Copy `.env.example` to `.env` and set the MySQL credentials. This local setup
connects to `qlvt-new` on `127.0.0.1:3306`. PDO and native PHP password/session
APIs handle authentication; no additional dependency is required yet.

Login uses `users.password_hash`, the active user status and employee status
when an employee is assigned. Successful login opens the protected dashboard.
The dashboard currently uses clearly labeled sample business data and makes no
business database queries. Its menus reflect the reference app and new schema:
products, import/export receipts, warehouses, decisions, suppliers, employees,
accounts and audit logs. Receipt search and warehouse/type filters work locally;
unimplemented actions open an explanatory dialog. Sessions expire
after 30 minutes of inactivity. CSRF tokens protect login/logout, and 10 failed
attempts from an IP address block further attempts for the current 5-minute window.

The database already has `users.id` as its primary key and a unique index on
`users.username`. No additional keys or changes to business tables were needed
for login. Authentication currently does not use foreign keys or warehouse roles
to grant access to business functions.

The root `.htaccess` blocks HTTP access to project files outside `public/` when
running under XAMPP. Keep Apache's `AllowOverride All` enabled, or point your
VirtualHost DocumentRoot at `public/`. `.env` and runtime login attempts are ignored
by Git. The page includes password visibility and a support dialog.

With XAMPP running, use `node tests/login-ui.mjs` to check the interface in
Chrome/Edge (Node 22+). Screenshots are saved to a temporary directory.
Use `php tests/auth.php` for backend checks. This check creates a temporary test
account and deletes it afterwards; it does not modify the existing admin account.

Use `php tests/home.php` to check dashboard rendering without a database, and
`node tests/home-ui.mjs` to check sample receipt filters, search, dialogs, mobile
navigation and responsive layouts. The browser check renders a test account
directly in its browser session; it does not create database records.

Super admins can open **Hệ thống → Tài khoản & phân quyền** at
`public/index.php?page=accounts`. This is a UI preview with sample accounts,
employee links, active/locked states, a global super-admin option and individual
keeper/viewer roles for each warehouse. Create/edit and lock/unlock dialogs do
not send or save account data. The account list displays directly; permissions
are configured inside the create/edit form. Business authorization will be
implemented separately. Account routes require
a signed-in super admin and reject POST requests. Dashboard pages share the
sidebar/header/footer templates under `resources/views/layouts/`.
Use `php tests/home.php --accounts` to render-check the account UI without a DB.
The browser check also covers account filters, forms and warehouse role selection.

Account lists show at most **10 accounts per page**. Searching or changing a
filter resets to the first page; pagination applies to the matching accounts.

Super admins can open **Danh mục → Nhân viên** at `public/index.php?page=employees`.
The employee UI uses 12 sample records with code, full name, email, phone,
required workplace warehouse and active/inactive status. It shows linked
accounts as information; account links and access roles are managed separately.
Search, status/warehouse/account-link filters and 10-row pagination work locally.
Create/edit and activate/deactivate dialogs are previews only and make no writes.
The route rejects POST requests. Run `php tests/home.php --employees` for a
database-free rendering check; `node tests/home-ui.mjs` also checks employee UI.

Form dialogs keep the title/description and bottom Cancel/Save actions fixed.
Only the main form body scrolls. Use this same layout for future create/edit
dialogs, including on short screens and mobile landscape.

The existing `reference_source/` directory is kept locally as a reference
and is not part of the project history.
