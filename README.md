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
- `reference_source_2/` - additional local reference and original Excel forms; excluded from Git

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
APIs handle authentication without an authentication library.

Login uses `users.password_hash`, the active user status and employee status
when an employee is assigned. Successful login opens the protected dashboard.
The dashboard reads real product, warehouse and receipt summaries from MySQL.
Its menus reflect the reference app and new schema:
products, import/export receipts, warehouses, decisions, suppliers, employees,
accounts and audit logs. Receipt search and warehouse/type filters work locally;
unimplemented sidebar actions open an explanatory dialog. Sessions expire
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
fall back to sample data. Home and all five report pages also read real data.

Super admins can open **Kho hàng → Danh sách kho** at
`public/index.php?page=warehouses`. The page reads real warehouse codes, names,
addresses and active/inactive statuses, with employee and individual access counts.
Search, status filters and pagination show 10 warehouses per page. Create/edit and
activate/deactivate actions validate unique codes, reject stale edits and record
before/after audit history in the same transaction. Inactive warehouses deny access
and cannot receive new employee assignments or new grants; existing history and
grants are preserved for reactivation. Stock reports read confirmed receipt lines from the database.
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

## Personal account

The avatar at the right of the shared topbar opens a native account popover with personal information, change password and logout. Super admins, warehouse keepers and viewers can use it from any signed-in page. Both dialogs keep the heading and actions fixed while only the body scrolls; contact fields use two columns on desktop and one on mobile. Password fields support visibility toggles and are cleared when the dialog closes or a request finishes.

`index.php?page=profile` reads/updates only the session owner's information. Full name, email and phone belong to the linked employee record; username, employee code, warehouse, employee link, state and grants remain under administrator management. An unlinked account shows its existing identity and a read-only profile with an explanation; it can still change its password. No schema change, upload library or new dependency is required.

Self-service uses the same validation and write-lock order as management, requires CSRF and an active account/employee, rejects additional fields and detects stale profile forms. Changed fields and audit history commit together. Password changes verify the current password with the existing login rate limiter, reject reuse and confirmation mismatches, and hash the new password with PHP. Password audit records contain only a change flag, never secrets or hashes. No-op profile saves add no audit record.

Sessions bind to the account password hash fingerprint. A successful password change rotates the current session ID/CSRF, updates the tokens in forms on the page and preserves that session; other sessions expire on their next request. Sessions created before this feature need to sign in again once. Administrator password resets also invalidate existing sessions. The current session fingerprint comes from the committed new hash, rather than an unlocked follow-up read.

Checks: `php tests/management.php` covers real viewer/keeper HTTP edits in an isolated database, ownership, tampered fields, CSRF, stale data, validation, session rotation/invalidation, rate limiting, audit secrecy and rollback. `php tests/auth.php` covers authentication regressions. `node tests/home-ui.mjs` covers popover/dialogs, responsive layout, fixed actions, confirmation/visibility, clearing passwords, Escape and fixture-only previews without writes.

## Reports

`index.php?page=reports&kind=inventory` provides five live reports: inventory, materials, parts, tools and fixed assets. `loadReportData()` in `app/reports.php` reads the MySQL catalogue, warehouses, confirmed receipt headers/details, decisions and account roles in one consistent transaction. Access remains super-admin only; errors never fall back to sample data. Sample ledgers exist only in `tests/fixtures/reports.php`. No schema change or sample business inserts are required.

Date, warehouse, category, stock-state and product filters share the same ledger calculation. Opening stock is confirmed imports minus exports before the start date; closing stock adds imports and subtracts exports through the inclusive end date. Historical reports retain inactive warehouses/products. Each row is one product ID at one warehouse, with quantities summarized separately by unit. BCMath preserves six-decimal DECIMAL precision; values use stored line `amount_before_tax`, including receipt rounding and paired warehouse transfers. Unit price is the latest confirmed import price up to the end date, for reference only.

`tracking_mode=none` displays no tracked stock quantity. Confirmed tracked receipts with missing quantities or units that differ from the catalogue produce an unknown balance, rather than false zero stock. Known period flows and financial values remain visible; unknown balances are excluded from unit quantity totals and marked on screen, print and Excel. The unknown-stock filter finds these rows.

Reconciliation flags confirmed receipts with no details or header totals differing from exact line sums. Scope is the selected warehouse through the end date, including opening history, independent of category/search/stock filters. Reports use line amounts and never rewrite existing records. The expandable check list identifies affected receipts; Excel adds a `Doi soat` sheet when checks or unknown quantities exist. Account totals count each account once, including locked accounts, prioritizing admin then keeper then viewer, and do not apply the report period filter.

Compared with `reference_source/public/reports/`, the old materials/parts spreadsheets merged goods by name across warehouses and did not restrict receipt status. The new calculation uses confirmed product/warehouse ledgers instead. Old tool/asset exports relied on catalogue import dates and prices; the new reports use confirmed receipt history and show opening/period/closing values. Tools/assets do not calculate depreciation or accounting allocations absent from the schema. Receipt notes are available; the old serial field is absent from the new products schema. Adding a serial register or depreciation schedule later would require an explicit business model and schema change.

Each report supports 10-row pagination, movement details with fixed dialog header/footer, monthly value charts, printing all filtered rows and genuine XLSX export with report and movement worksheets. Exported movements retain historical product names, units and notes from receipt lines. Local SheetJS CE 0.20.3 (Apache-2.0) writes XLSX without runtime CDN requests. Numbers exceeding Excel precision remain text, and names remain strings rather than spreadsheet formulas. Excel layouts follow the new reports rather than the old fixed spreadsheet templates. Dates and quick period presets use Vietnam time. Invalid filters disable print/export.

Checks: `php tests/reports.php`, `php tests/home.php --reports`, `php tests/home.php --reports --live-report`, `php tests/auth.php`, `php tests/management.php` and `node tests/home-ui.mjs`. The management check clones the schema into an isolated database and compares all five HTTP reports against independent ledger SQL, including inactive history, unknown quantities, transfers, permissions and reconciliation. Browser checks verify five report layouts, source labels, XLSX downloads/round-trips, reconciliation sheets, pagination, print coverage and responsive dialogs.

## Product list

`index.php?page=products` reads the full real product catalogue for super admins. Five separate routes (`materials`, `spare-parts`, `tools`, `fixed-assets`, `other-products`) have their own views, descriptions, summaries and category-specific columns. Materials show import/export balances; spare parts show quantities and latest receipt; tools show warehouse records; fixed assets show related decisions; other products show tracking mode. The server restricts both reads and edits on each category page to that category. Common stock calculations, filters and dialogs are reused. Search, warehouse, stock-state and active/inactive filters reset pagination to page 1 (10 rows per page). Warehouse filters update displayed import/export/balance quantities to that warehouse. Details show per-warehouse stock and receipt/decision history, including draft/cancelled receipts for reference. Only confirmed receipts contribute to stock; BCMath preserves six-decimal precision and separate receipt queries avoid join multiplication. Tracking mode none displays no quantities. Missing quantities on confirmed tracked imports display an unknown balance rather than false zero stock.

Editing updates catalogue fields and audits the change atomically, with CSRF/admin checks and stale-version protection. Product unit and tracking mode cannot change after any receipt history exists. Stock quantities are never editable catalogue fields. No direct product creation or deletion is added in this list stage. UI samples are test fixtures only; no sample catalogue records are inserted into production.

Checks: `php tests/home.php --products`, `php tests/auth.php`, `php tests/management.php` (isolated database), and `node tests/home-ui.mjs`.

### Import receipt list

`index.php?page=imports` reads real `import_bill` and `import_bill_details` records for super admins. It supports accent-insensitive searches (including line items), warehouse/type/status filters, inclusive date ranges, date/number sorting and 10 receipts per page. Detail dialogs show invoice and supplier information, the creator, stored header totals and historical line-item snapshots with taxes, related decisions and attached invoice PDFs. Decimal values retain all six fractional digits. Only the dialog body scrolls; its heading and close button remain fixed. The list includes drafts and cancelled receipts for reference. POST supports line editing, deletion of drafts and cancellation of confirmed receipts, with CSRF/admin checks and version validation.

Drafts expose **Xóa**, with a confirmation that their lines and receipt-owned PDF/photos will be removed. Products, suppliers and immutable audit history are retained. Database deletion and its full before snapshot commit together; only then are validated private uploads cleaned up. Shared file paths still referenced by other attachment records are retained. Rollback never removes files; a filesystem cleanup failure is logged and reported in the success notice rather than pretending the committed deletion failed.

Confirmed receipts expose **Hủy phiếu**, requiring a reason. This stores `status=cancelled` and `cancel_reason`, removes the receipt from confirmed stock/report calculations, and retains receipt lines, files and audit history. Linked lines automatically clear `decision_id` and return to `decision_status=pending`; cancelled lines are excluded from decision link choices. The cancellation audit preserves original decision IDs and the unlink result. Line timestamps change so stale decision forms cannot restore removed links. Cancellation and unlinking share one transaction and roll back together if auditing fails. Any export reference (including drafts), transfer receipt type, paired incoming transfer or inactive warehouse blocks both actions and shows its reason in the list. Cancelled receipts remain read-only. The confirmation dialog keeps its header/footer fixed and scrolls only the body. No schema change is needed. Checks: `php tests/management.php` (isolated DB, stock, files, permissions, stale forms and rollback), `node tests/home-ui.mjs` (action states, required reasons, preview payloads and responsive dialogs).

Checks: `php tests/home.php --imports`, `php tests/auth.php`, `php tests/management.php`, `node tests/home-ui.mjs`.

### Create import receipts

`index.php?page=import-create` creates a draft or immediately confirmed receipt with 1–200 lines in an active warehouse. Choose active existing products/suppliers or create them with the receipt. Empty product codes receive generated codes; existing codes must be selected explicitly. Tax codes identify suppliers; unnamed-tax suppliers reuse an exact name match. Existing catalogue metadata is authoritative and receipt lines retain their snapshots. All new lines start with pending decisions, ready to attach after purchase. Confirmed receipts contribute to stock immediately; drafts do not. BCMath recalculates quantities, before-tax values, taxes and totals on the server; the browser uses BigInt for matching previews. Numbers accept a decimal dot or comma, up to 14 integer digits and six decimals, without grouping separators.

XML upload reads one VND `DLHDon` invoice, including namespaces, seller fields, invoice identity, `HHDVu` lines and totals. The preview endpoint performs no business writes. DTD/entities, malformed XML, multiple invoices, unsupported discount/adjustment lines and uploads above 2 MiB are rejected. Reviewed fields are submitted as ordinary validated form data; invoice totals must match line totals when supplied. This reads invoice data and does not verify digital signatures. Replace confirmation preserves a form already being edited until approved. Active products match by code, or unambiguous name/unit/tracking mode; suppliers match by tax code. A non-cancelled invoice identity cannot be imported twice; the database also enforces warehouse/year/receipt number uniqueness.

Optional PDF invoices use the existing `attachments.import_bill_id`, with the same extension/MIME/signature validation and download protection as decision PDFs. Files are saved outside public in `storage/uploads/imports/` and are excluded from commits. Receipt/product/supplier/audit writes are one transaction; a failure removes any newly uploaded PDF as well. No schema migration or sample business insert is needed. Keep database and uploaded documents together in backups.

Checks: `php tests/home.php --import-create`, `php tests/management.php` (isolated database), `php tests/auth.php`, `node tests/home-ui.mjs`.

### Export receipts

`index.php?page=exports` and `index.php?page=export-create` read and create real export receipts for super admins. The list supports searches by number, receiver, reason and line items, warehouse/type/status and date filters, sorting, 10-row pagination and detail dialogs with fixed headings/footers. Optional PDF documents use `attachments.export_bill_id`, private files in `storage/uploads/exports/` and the protected `export-file` endpoint.

Apply `database/2026-10-10-export-receiver.sql` once to an existing database before using these pages. This adds nullable `export_bill.receiver_name`; older receipts are preserved. It has already been applied to this local database.

Select 1–200 lines from confirmed import lots in an active warehouse, with active products tracking a known quantity. The server checks lot versions, dates and current availability again under the existing management write lock. Repeated lines share the same lot balance. Stored lot values are allocated with six-decimal precision; the last withdrawal takes the exact remaining value to preserve invoice rounding. Prices and product snapshots come from the source lot and cannot be overridden. New lines start with pending decisions. Untracked services and imports missing quantities cannot be exported through this quantity-based schema.

Drafts do not reserve stock. Confirmed exports reduce stock; a confirmed warehouse transfer also creates a linked confirmed import in its active destination warehouse. Both receipts, line items, attachment metadata and audit events commit together; failures roll back and remove any new PDF. Draft editing, confirmation of existing drafts and cancellation are not implemented in this list/create stage. Uploaded files remain excluded from Git; back up the database and uploads together.

Checks: `php tests/home.php --exports`, `php tests/home.php --export-create`, `php tests/management.php`, `php tests/auth.php`, `node tests/home-ui.mjs`. Database writes in the management check use an isolated test database, including rounding, overdraw prevention, paired transfers and rollback scenarios.

### Optional receipt-line photos

Each row in **Tạo phiếu nhập** has an optional **Thêm ảnh** control. Multiple selections accumulate, previews can be enlarged or removed, and removing/replacing a row releases its image previews. XML replacement asks for confirmation before discarding selected photos. File groups are indexed from the final row order at submission, including when rows are removed or the same product appears twice.

The existing `attachments.import_bill_detail_id` foreign key and single-owner constraint link each photo to its exact new receipt line; no schema change is required. Files have random names in private `storage/uploads/import-items/`. Receipt, metadata, PDFs, images and audit writes commit together; errors roll back database writes and remove new files. Photos are optional for both drafts and confirmed receipts.

JPG/PNG/WebP uploads are verified by file extension, server MIME, image dimensions and actual GD decoding. Limits: 5 MiB per image, five per row, sixteen per receipt, 32 MiB total and 20 million pixels per image. Upload counts and the completion marker reject truncated or incorrectly indexed requests. These totals fit this XAMPP's existing 40 MiB POST/20-file limits together with the optional PDF.

Receipt details have a separate **Hình ảnh** column with a thumbnail and count for multiple photos. Click to open images in a native dialog with previous/next, arrow keys and Escape. **Xem PDF** opens the protected invoice PDF in the same dialog using the locally bundled Mozilla PDF.js 6.4.299 HTML viewer, with page navigation, zoom, search and an explicit download option. Preview fetches the linked PDF as JSON/base64 and passes bytes to PDF.js, without a PDF response MIME type or download disposition for IDM to intercept. The viewer opens the linked receipt PDF automatically; the external file picker is hidden. Pending requests are cancelled on close/switch, and failures show an error rather than a blank picker. Headers/actions remain fixed; only the body scrolls. `index.php?page=import-image&id=...` serves validated receipt-line images; `import-file&id=...&preview=1` returns authenticated JSON containing the linked invoice file name and base64 data; the URL without `preview=1` still downloads the PDF explicitly. Both require the same active super-admin permission as the receipt list. Direct storage access remains blocked. Back up the database and `storage/uploads/` together; uploads remain excluded from Git. Receipt Excel templates stay unchanged and contain no embedded photos.

PDF.js runtime files and licenses are under `public/assets/vendor/pdfjs/`; source maps and the example PDF are omitted. The integration selects Vietnamese, disables PDF scripting, annotation editing, persisted viewer preferences and optional remote model downloads. `node tests/home-ui.mjs` forces Chrome to download native PDFs, verifies painted PDF content and page navigation, and asserts no download starts on preview. For a private local document, set `RECEIPT_PDF_PATH` when running the same check; its contents are used only in the temporary browser, never added to fixtures or uploaded to an external service.

**Chỉnh sửa** in receipt details edits saved line names, quantities/units, prices, before-tax amounts, taxes, decision links and notes. Quantity/price changes calculate amounts and tax with six-decimal arithmetic; explicit amounts can be corrected independently. Saving updates line snapshots and header totals, retaining all attachments and catalogue records. Tracked unit corrections must match the catalogue unit. Cancelled receipts are read-only; lots referenced by any export or received through a paired transfer retain their quantity and valuation, while their decision/notes can be edited. Only confirmed receipts can link a decision from the same warehouse; selecting no decision requires a reason in the note. CSRF, row ownership, version checks, the shared write lock and an atomic audit record prevent stale or partial writes. No schema change is required.

### Receipt Excel downloads

The specifications feature has been removed from goods, receipt creation/details, searches, reports and Excel output. Existing nullable database columns and historical values are retained for compatibility; new products/receipt lines do not populate them and catalogue edits do not overwrite them. The original receipt template files remain unchanged.

Both receipt lists and their fixed dialog footers provide **Excel / Xuất Excel**. `index.php?page=import-excel&id=...` and `index.php?page=export-excel&id=...` generate XLSX files from real database records, using the same super-admin authorization as the receipt pages. GET only; invalid/missing IDs return 400/404. Downloads make no business or audit writes and use a consistent database read.

The two original `reference_source_2/mau_phieu_*_kho.xlsx` files are copied unchanged to `resources/templates/receipts/`, outside `public/` and included in Git. PhpSpreadsheet loads them, inserts additional item rows, and retains fonts, borders, column widths, merged headings, footer signatures and print settings. Header dates come from the receipt; sender/receiver and creator signature names come from the saved document and creator. Other fixed template text remains unchanged. Receipt number, draft/cancelled state and any discrepancy between header and line totals appear in the spare row below the title.

Goods use saved receipt names, units and notes; product codes fill the existing code column. Serial remains blank because the new schema has no serial register. Export original-document numbers come from source import invoices, falling back to source receipt numbers. The table and total show stored **before-tax line amounts** (including exact export-lot allocations), rather than multiplying quantity by price again or applying a fixed VAT rate. BCMath preserves six decimal places; formatted amounts and untrusted text are written as Excel strings to prevent precision loss and formulas. Unknown quantities stay blank. Amounts in words support six decimal places and amounts of a billion or more.

Install the locked PHP dependencies with `composer install --no-dev` (PhpSpreadsheet 5.0.0 from the reference application's lock file). PHP 8.2+, BCMath, ZIP and GD are required together with the standard XML/mbstring extensions. ZIP/GD have been enabled in this local XAMPP configuration and Apache reloaded. The feature does not depend on either ignored reference directory at runtime and requires no database migration.

Checks: `php tests/receipt-excel.php` round-trips empty/single/multiple-line XLSX files and compares template styles, merges, widths, signatures and print settings; `php tests/management.php` verifies actual downloads against an isolated database, authorization, IDs, headers, historical snapshots, exact allocations and unchanged stock; `node tests/home-ui.mjs` checks both download controls within responsive dialogs. Sample preview buttons are disabled.

### Live dashboard

`index.php?page=home` reads consistent database summaries: distinct catalogue products and category shares, active warehouses, current-month import/export counts with confirmed counts, a six-month receipt-count chart and the ten most recent receipts by business date. Months and update times use Vietnam time, including across year boundaries. Counts include drafts and cancelled receipts as labelled; they do not represent stock quantities. Receipt values display the stored before-tax amounts at full DECIMAL precision. Empty data produces zero counts and empty charts/lists.

Super admins see the whole system, including receipt history in inactive warehouses. Other active users see only active warehouses currently granted to them; products are counted once from receipt history in those warehouses. No grants expose no business records. Warehouse filtering and search apply to the ten displayed recent receipts. Admin shortcuts open existing pages, and receipt links open the requested detail dialog. Reports remain explicitly labelled previews. The dashboard is read-only and requires no schema change or sample records.

Checks: `php tests/home.php`, `php tests/home.php --empty`, `php tests/management.php` (isolated database counts, dates, scope and revocation), `php tests/auth.php`, `node tests/home-ui.mjs` (charts, filters, receipt links and responsive layouts).
