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
when an employee is assigned. Successful login opens a protected confirmation
page; the warehouse dashboard will be implemented separately. Sessions expire
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

The existing `reference_source/` directory is kept locally as a reference
and is not part of the project history.
