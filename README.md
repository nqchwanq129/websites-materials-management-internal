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
for login. Management now uses the existing foreign keys in `users`, `employees`,
`warehouses`, `user_warehouses` and `audit_logs`; no schema changes are required.

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
`public/index.php?page=accounts`. The page reads real accounts and warehouse
permissions from MySQL. Create/edit, password reset, employee linking and
lock/unlock actions save to the database. Passwords are hashed with native PHP
and never returned to the UI or audit history. Permissions are configured inside
the account form: global super admin, or keeper/viewer at individual warehouses.
`warehousePermission()` checks current user, employee and warehouse status;
future warehouse operations must use it to enforce their read/write permissions.
Management routes require a signed-in super admin. Dashboard pages share the
sidebar/header/footer templates under `resources/views/layouts/`.
Use `php tests/home.php --accounts` to render-check the account UI without a DB.
The browser check also covers account filters, forms and warehouse role selection.

Account lists show at most **10 accounts per page**. Searching or changing a
filter resets to the first page; pagination applies to the matching accounts.

Super admins can open **Danh mục → Nhân viên** at `public/index.php?page=employees`.
The employee UI reads real records with code, full name, email, phone,
required workplace warehouse and active/inactive status. It shows linked
accounts as information; account links and access roles are managed separately.
Search, status/warehouse/account-link filters and 10-row pagination work locally.
Create/edit and activate/deactivate actions save to MySQL. Deactivating an employee
also prevents associated accounts from signing in or accessing warehouses.
A real active warehouse must exist before adding an employee; the app does not
create placeholder warehouses. Run `php tests/home.php --employees` for a
database-free rendering check; `node tests/home-ui.mjs` also checks employee UI.

Super admins can open **Hệ thống → Nhật ký thao tác** at `public/index.php?page=audit`.
This read-only page queries `audit_logs`:
actor, warehouse/global scope, action, entity and before/after data. Search,
inclusive date-range filters, action/entity/warehouse filters and 10-row pagination
work locally. Details highlight changed fields and display system-generated
events without a user. Account/employee writes and their before/after audit data
commit in the same transaction; failures roll back both. Audit history cannot be
edited or deleted through the app. Existing database immutability triggers are
preserved. New events use Vietnam time (UTC+7).
Run `php tests/home.php --audit` for a database-free rendering check;
`node tests/home-ui.mjs` also checks audit filters, diffs and responsive dialogs.

Management POSTs require CSRF tokens and server-side validation. Duplicate names,
invalid references, stale edits and removing the current administrator's access
are rejected. Locking an account revokes its session at the next request.
Each list shows **10 rows per page**; filters and pagination currently operate
on the full result set in the browser. Move these to SQL when data grows.

Run `php tests/management.php` with XAMPP PHP 8.2 to check real HTTP management
writes, authorization, password hashing, grants/revocation, validation, stale
edits, rollback and audit consistency. The check clones the schema, including
foreign keys and triggers, into a randomly named test database and serves a
temporary app copy on localhost. It drops only that test database afterward;
the configured MySQL user needs CREATE/DROP DATABASE privileges. No application
configuration or production records are changed by this check.
UI fixtures live only in `tests/fixtures/`; production management pages never
fall back to sample data. The home dashboard remains a UI preview.

Super admins can open **Kho hàng → Danh sách kho** at
`public/index.php?page=warehouses`. The page reads real warehouse codes, names,
addresses and active/inactive statuses, with employee and individual access counts.
Search, status filters and pagination show 10 warehouses per page. Create/edit and
activate/deactivate actions validate unique codes, reject stale edits and record
before/after audit history in the same transaction. Inactive warehouses deny access
and cannot receive new employee assignments or new grants; existing history and
grants are preserved for reactivation. Stock reports currently use labeled sample data.
Run `php tests/home.php --warehouses` for fixture rendering and
`php tests/management.php` for isolated database checks; the browser check includes
warehouse forms, filters, pagination and fixed dialog headers/footers.

Warehouse and employee rows also offer **Xóa** with an explicit confirmation.
Only super admins can delete; CSRF and version checks still apply. Foreign keys
block deleting linked employees or warehouses referenced by employees, access
grants, receipts, decisions or audit history. Warehouses created through the app
already have audit history and should normally be deactivated instead of deleted.
Successful deletions retain their before snapshot in the immutable audit log;
if audit insertion fails, the deletion rolls back. Tests use the isolated DB.

Super admins can open **Danh mục → Nhà cung cấp** at
`public/index.php?page=suppliers`. This read-only page queries `suppliers` and
linked `import_bill` records, with contact/tax details, counts, latest receipt date,
search, status/tax-code/link filters and 10-row pagination. The detail dialog shows
linked receipt numbers, dates, warehouses, invoices and draft/confirmed/cancelled
statuses. POSTs are rejected; this tab does not create or edit supplier records.
Supplier entry belongs to the import-receipt workflow, primarily from invoice XML;
implement supplier synchronization/deduplication there when receipt creation is
built. That XML workflow is not implemented yet. Production reads never create
placeholder suppliers. Run `php tests/home.php --suppliers` for fixture rendering;
the management and browser checks cover real data and the read-only UI.

Form dialogs keep the title/description and bottom Cancel/Save actions fixed.
Dashboard notifications use Simple Notify 1.0.6 (MIT), vendored under
`public/assets/vendor/simple-notify/`; no CDN is needed at runtime. Successful
management writes display a toast after the existing redirect. Validation warnings
and save errors remain visible in the form and also display above its dialog.
Success/info messages close after 4.5 seconds; errors/warnings require dismissal.
Notification content is escaped, close controls support keyboard access and motion
respects the user's reduced-motion preference. The design uses the VISHIPEL palette.
Only the main form body scrolls. Use this same layout for future create/edit
dialogs, including on short screens and mobile landscape.

The existing `reference_source/` directory is kept locally as a reference
and is not part of the project history.

Decision list: `index.php?page=decisions` reads real database records (super admin only), with search, type/warehouse/link filters and 10 rows per page. Details show linked import and export lines; quantities retain DECIMAL precision. Only the list remains in the Decisions sidebar group. Create/edit decisions and select existing receipt lines in the same dialog. New links require confirmed receipts, a matching active warehouse, and pending lines without another decision. Removing a selection returns that line to pending. Concurrent decision/line changes reject stale forms; changes and attachments are audited atomically.

Decision PDF attachments use the existing `attachments` table (`decision_id`, `original_name`, `storage_path`, `mime_type`, `created_by`); no schema change is needed. Upload one PDF per save (maximum 2 MiB), with server-side extension, MIME and signature validation. Files are appended to existing attachments and stored with random names in `storage/uploads/decisions/`, outside the public directory and excluded from Git. Download through `index.php?page=decision-file&id=...` requires an active super-admin session. Back up both the database and `storage/uploads/`; a source-code commit does not contain uploaded documents. Failed database/audit writes roll back metadata and remove the newly uploaded file.

## Reports preview

`index.php?page=reports&kind=inventory` provides five reports: inventory, materials, parts, tools and fixed assets. All business data comes from `resources/data/reports-preview.php`, never from MySQL; authentication still uses the existing login. Report pages and exported workbooks visibly identify sample data.

Date, warehouse, category, stock-state and product filters run against the same ledger calculation in `app/reports.php`; only confirmed receipts count. Opening stock is imports minus exports before the start date; closing stock adds period imports and subtracts period exports. Rows are grouped by product ID and warehouse rather than names, quantities remain separate by unit, and tracking_mode=none displays no quantity. BCMath preserves DECIMAL precision. Value balances use amount_before_tax from receipt lines; tools/assets do not imply depreciation or accounting allocations absent from the new schema. Product specifications are shown; the old serial field is not exposed because it is absent from products in the new database.

Each report supports 10-row pagination, movement details with fixed dialog header/footer, a monthly value chart, printing all filtered rows and genuine XLSX export with report and movement worksheets. Local SheetJS CE 0.20.3 (Apache-2.0) writes XLSX without runtime CDN requests. Large numbers exceeding Excel precision remain text. Excel layouts follow the new reports and do not reproduce the old fixed spreadsheet templates.

Checks: `php tests/reports.php`, `php tests/home.php --reports`, `php tests/home.php --reports --report-kind=assets`, `php tests/auth.php`, and `node tests/home-ui.mjs`. The browser check verifies all five pages, genuine XLSX download/round-trip, pagination, print coverage and responsive dialogs. Replace the preview data source with validated, authorized DB queries when the report UI and business rules are approved; no database migration or sample business inserts have been made.

## Product list

`index.php?page=products` reads the full real product catalogue for super admins. Five separate routes (`materials`, `spare-parts`, `tools`, `fixed-assets`, `other-products`) have their own views, descriptions, summaries and category-specific columns. Materials show import/export balances; spare parts show specifications and latest receipt; tools show warehouse records; fixed assets show related decisions; other products show tracking mode. The server restricts both reads and edits on each category page to that category. Common stock calculations, filters and dialogs are reused. Search, warehouse, stock-state and active/inactive filters reset pagination to page 1 (10 rows per page). Warehouse filters update displayed import/export/balance quantities to that warehouse. Details show per-warehouse stock and receipt/decision history, including draft/cancelled receipts for reference. Only confirmed receipts contribute to stock; BCMath preserves six-decimal precision and separate receipt queries avoid join multiplication. Tracking mode none displays no quantities. Missing quantities on confirmed tracked imports display an unknown balance rather than false zero stock.

Editing updates catalogue fields and audits the change atomically, with CSRF/admin checks and stale-version protection. Product unit and tracking mode cannot change after any receipt history exists. Stock quantities are never editable catalogue fields. No direct product creation or deletion is added in this list stage. UI samples are test fixtures only; no sample catalogue records are inserted into production.

Checks: `php tests/home.php --products`, `php tests/auth.php`, `php tests/management.php` (isolated database), and `node tests/home-ui.mjs`.
