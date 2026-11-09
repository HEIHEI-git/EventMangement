# Fairboard / EventLink

A PHP + MySQL community event platform with a cinematic discovery interface, complete account flows, bookings, creator tools and staff admission scanning.

## Requirements

- PHP 8.3+ with PDO MySQL, mbstring, GD, fileinfo, and the extensions required by Composer.
- MySQL 8 or MariaDB 10.4+, using InnoDB. The release was executed against MariaDB 10.11.
- Composer. Java, Tomcat and a Node build are not needed to run the website.
- HTTPS for a deployed site and phone camera scanning. Localhost works for development.

## First run

1. Create an empty `eventlink` database and import `database.sql` using phpMyAdmin or the MySQL client. Do not import the fresh schema over an existing installation.
2. Copy `config.example.php` to the gitignored `config.php`. Set database credentials, the complete `base_url` (including a subdirectory if applicable) and timezone.
3. Generate a private application key with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and paste it into `app_key`. Keep it out of Git and preserve it across deployments.
4. Run `composer install --no-dev`.
5. For development, optionally run `php tools/seed_demo.php`. It creates sample events and the accounts listed below. Existing accounts are not overwritten unless `--reset-passwords` is supplied.
6. Start `php -S 127.0.0.1:8080 router.php` and set `base_url` to `http://127.0.0.1:8080`, or serve with Apache and the included `.htaccess`.

For an existing database, back up both the database and uploaded covers and any existing `uploads/` posters, install the code/dependencies, then run:

```sh
php tools/migrate.php
```

The migration adds missing columns/tables, maps legacy roles, and backfills creator plans without deleting existing users, events or bookings. It is safe to repeat. An optional first argument supplies a configuration file outside the web root for a separate migration account. Use a database account with schema privileges for migration; the runtime account only needs SELECT/INSERT/UPDATE/DELETE.

**Windows / XAMPP and Ubuntu instructions:** [Setup guide](docs/SETUP_JA.md).

For the personal fork's main branch, upstream sync, archived branches and recovery commands, see [repository maintenance](docs/REPOSITORY_MAINTENANCE_JA.md).

## Development demo accounts

With `app_env=development`, `php tools/seed_demo.php` creates these shared demo logins:

| Email | Password |
|---|---|
| `admin@fairboard.test` | `AdminP@ssw0rd` |
| `admin2@fairboard.test` | `AdminP@ssw0rd` |
| `admin3@fairboard.test` | `AdminP@ssw0rd` |
| `admin4@fairboard.test` | `AdminP@ssw0rd` |
| `admin5@fairboard.test` | `AdminP@ssw0rd` |
| `admin6@fairboard.test` | `AdminP@ssw0rd` |
| `staff@fairboard.test` | `StaffP@ssw0rd` |
| `creator@fairboard.test` | `CreatorP@ssw0rd` |
| `user@fairboard.test` | `UserP@ssw0rd` |

To apply these passwords to existing demo accounts after updating the code, run:

```sh
php tools/seed_demo.php --reset-passwords
```

This resets only the nine demo accounts, revokes their existing sessions and password-reset links, and preserves their roles, MFA, account settings and existing events. A mismatched demo role stops the operation without changing any accounts. Admin/staff still require authenticator enrollment or their existing MFA. The reset does not reactivate disabled accounts or clear a required password change.

A nonempty `EVENTLINK_DEMO_PASSWORD` overrides all nine passwords with one shared value for either command. Unset it to use the table above. These public credentials are for development demos only; the seeder refuses production mode.

## What works

| Area | Behaviour |
|---|---|
| Discovery | Featured event, category filters, escaped keyword search, today/weekend/date filters and low-data mode, popular sorting, pagination, original illustrated covers, desktop/mobile navigation |
| Accounts | Attendee/creator registration, organisation profile, confirmation field, show/hide password, login return path, email verification, password reset, password change, logout |
| Bookings | One place per user, event-row locks, waiting list, leave/cancel, FIFO promotion, in-app notifications, FIFO promotions capped at 10% of original capacity for the event’s lifetime |
| Admission | Private QR tickets rotate at 30-second server-time boundaries; assigned staff or owner can redeem once; camera scanner and pasted-code fallback |
| Creator / staff | Draft/submit creation, event website links, assigned editing, moderation after material changes, cover selection/upload, printable event posters, attendee lists |
| Admin | Event moderation, staff assignment/revocation, account disabling, verified school-society approval, offline invoice/payment records |
| Developer | `SQL DEV` navigation button for explicitly allowlisted development admins; password reauthentication; table schemas/counts/paginated safe rows |

## Account email and fees

In development, `mail_transport=log` writes verification/reset emails under private `storage/mail/`. Open the text file locally to use the link. Tokens are never displayed by the SQL explorer. These files are gitignored and blocked from HTTP.

For real delivery set `mail_transport=mail`, a valid `mail_from`, and configure PHP's mail transport/MTA (including SMTP relay and deliverability). Confirm delivery on the deployment host. This release does not provide an email-service account. A failed delivery never silently verifies an address; a user can request another verification from Account.

School domains are an exact configuration allowlist. Verifying a school email proves address ownership. An administrator separately approves the society and records its evidence reference before fee waiver status is granted.

Creator invoices are HK$80/month for external groups, or waived for approved societies. A creator requests the current month's invoice; an administrator records an **already received offline payment** with a receipt reference. No credit card information is collected and no online payment processor is connected. Event booking does not charge money.

## QR codes

- `booked.php` shows the attendee's live admission ticket. Its signed code changes at each 30-second boundary and old codes have no grace window.
- `ticket_qr.php` requires an authenticated owner and CSRF-protected POST. Responses are private and not cached. Background refresh does not extend the session's idle timeout.
- A signed ticket identifies a booking and event, with no name or email. Cancellation, event status, account status, authorisation and existing check-in are rechecked on the server. Redemption locks the event and booking and validates expiry again after acquiring the lock.
- Staff open `scan.php`, allow camera access, review the scanned code and confirm admission. A camera failure still permits pasting the QR link/code or authorised manual lookup from the guest list.
- Default check-in window: two hours before to twelve hours after the start, configurable in seconds.
- The printed poster QR is a permanent event-discovery link, **not** an admission ticket. Paper cannot rotate every 30 seconds.
- A live QR remains a bearer credential within its short validity window: live relay/sharing cannot be fully prevented by rotation alone. Admission staff should confirm the attendee when appropriate.

## Developer SQL explorer

In `config.php`, set `app_env=development`, `enable_developer_tools=true`, and `developer_user_ids` to explicit existing **admin** IDs. Sign in as one of those admins and select `SQL DEV`. Confirm the password to unlock for ten minutes. Production mode always disables this page.

Only a literal table/column allowlist can be browsed. There is no arbitrary SQL execution, credential display, export, or database write control. Names, emails, password hashes, token values/hashes, IP hashes, notification bodies and receipt references are excluded from previews. Column metadata remains visible so a developer can inspect the schema.

## Deployment and security

See [release scope and deployment notes](docs/RELEASE.md) and [test report](docs/TEST_REPORT.md). Set production mode, HTTPS cookie/HSTS settings and real mail delivery; disable developer tools; configure a least-privilege runtime DB account. Apache needs `mod_rewrite` and `AllowOverride` for the provided rules. A sample Nginx configuration is in `docs/nginx.example.conf`. Never use the development server as a public production server.

Public registration cannot create staff/admin accounts. To bootstrap an administrator or staff member, first register an account, verify the person operationally, and assign its role directly through a trusted database administration tool. Administrator accounts cannot be disabled from the web account-control button.

The original unauthenticated third-party AI image demo has been replaced with an authenticated cover studio: ten local SVG illustrations or validated/re-encoded image upload. It does not send prompts or images to a third-party AI provider. Generated artwork from a service can be uploaded as a normal image with appropriate usage rights.

## Tests

GitHub Actions runs the security, demo-seeding, migration, cover-storage and HTTP regression suites on pushes to `main`, pull requests targeting `main`, and manual dispatch. An open PR provides CI for a working branch; branch pushes do not also start a duplicate run. Browser scripts receive syntax checks in CI; the Playwright browser suite below must be run separately.

The earlier `SECURITY_RELEASE.md`, `TESTING.md`, `security_test_results.json` and legacy test scripts are retained as historical records. Use the current suites below and [release test report](docs/TEST_REPORT.md).

```sh
composer test
php tests/migration.php
EVENTLINK_TEST_ALLOW_MUTATION=1 python3 tests/integration.py
node tests/browser.cjs
```

`EVENTLINK_TEST_ALLOW_MUTATION=1 php tests/demo_seed_test.php` checks default passwords, repeat seeding, explicit resets, account/MFA preservation, rollback and the shared override. Run it only against an empty, freshly imported `_test` database; it leaves the four default demo accounts ready for the HTTP tests.

Integration/migration tests refuse a non-development database or a database name not ending in `_test`. Create a dedicated test database, seed it, and run a local server first. The HTTP suite needs Python `requests` and `Pillow`; the browser suite needs Playwright and Chromium. Use `EVENTLINK_TEST_URL`, `EVENTLINK_DEMO_PASSWORD`, and optionally `CHROMIUM_EXECUTABLE`. On Linux use `PHP_CLI_SERVER_WORKERS=4` for genuine parallel HTTP checks. Never run these mutation tests against real user data. Test artefacts go to gitignored `test-results/`.

`assets/vendor/jsQR.js` is jsQR 1.4.0 (Apache-2.0; bundled licence). Server QR rendering uses the existing Composer-locked chillerlan/php-qrcode package. No CDN is required to display or scan tickets.

## Security and account update (2026-10-30)

See [39-threat coverage and Ubuntu rollout](docs/DEFENSE_39_JA.md) and [verification results](docs/DEFENSE_TEST_REPORT.md) before upgrading. Run the additive migration before serving requests. Admin/staff require authenticator enrollment; attendees and creators verify their email before booking or creating events. Admins directly create staff login email IDs and receive a temporary password once. Staff replace it on first sign-in and enroll MFA; a mailbox is not required. Admin password reissue invalidates sessions while retaining existing MFA. The developer table viewer displays business records but hides authentication secrets and remains development-only.

Form-check failures now explain possible causes and offer a safe return to a fresh form. See [form recovery design and troubleshooting](docs/FORM_RECOVERY_JA.md).
