Current account/security changes: [29 October defense verification](DEFENSE_TEST_REPORT.md). The report below describes the previous release.

# Release verification — 27 October 2026

The release was executed locally with PHP 8.3, MariaDB 10.11 (InnoDB), four PHP HTTP workers, and headless Chromium 138 through Playwright. All accounts, messages, invoices and events used for verification were synthetic. No production database was modified.

| Check | Result |
|---|---|
| HTTP/database integration (`tests/integration.py`) | 153 assertions passed |
| Real browser flows (`tests/browser.cjs`) | 67 assertions passed; no JavaScript or CSP console errors |
| Security boundary unit tests (`tests/security_unit.php`) | 34 assertions passed |
| Existing role matrix (`tests/role_access_test.php`) | Passed |
| Database migration (`tests/migration.php`) | Existing records preserved through two consecutive migrations; legacy roles mapped; creator plan backfilled; new tables present; the newer team redesign schema also migrated twice, preserving draft/cancelled states, original capacity/refill counts, categories, posters and social URLs |
| Fresh database import | All 12 application tables created |
| PHP syntax | 64 source/test PHP files passed |
| JavaScript syntax / SVG XML | Application scripts and browser suite passed; all 7 local SVG files parsed |
| Composer | Lockfile validation passed; audit reported no known vulnerability advisories for locked packages |
| Patch whitespace | `git diff --check` passed |

Composer's metadata validation still reports the repository's unspecified project licence. No project licence has been invented or changed; jsQR's bundled Apache-2.0 licence is included.

## Behaviour exercised

The HTTP suite checks public discovery, malformed inputs, sensitive-path denial, CSRF enforcement on 20 POST entry points, registration and role restrictions, email ownership, single-use reset/verification tokens, password-reset session revocation, cookie attributes, idle and absolute expiry, persistent rate limits, and immediate staff/account revocation.

Booking checks send concurrent requests from independent sessions for the last seat and verify there is exactly one booking. Cancellation promotes the oldest eligible waiting attendee. Tests cover duplicate requests, private booking/ticket access, waiting-list departure, the event-lifetime refill budget capped at 10% of original capacity, accurate booking audit references and refusal to reduce capacity below existing bookings.

Admission checks cover altered signatures, real elapsed expiry, a new code after the 30-second boundary, unassigned staff, disabled attendees, cancelled events and two concurrent attempts to redeem the same ticket. Exactly one check-in succeeds. Background ticket polling does not renew the idle deadline.

Creator/admin checks cover creation, approval, reapproval after a material edit, cancellation and notifications, society evidence retention, a waived society invoice, fixed HK$80 external invoices, idempotent invoice requests and authorised recording of offline payments. SVG uploads are rejected; an accepted raster cover is re-encoded to JPEG; traversal is rejected.

The developer explorer requires its own password check. Tests verify table/column allowlisting, hidden sensitive row values, numeric pagination and rejection of an injected table identifier. Ordinary users cannot open it; production and non-allowlisted access are checked in unit tests.

The browser suite exercises search/category controls, password reveal and confirmation feedback, creator registration fields, responsive menus, login/logout, account pages, creator forms, posters, invoices, scanner fallback and the SQL explorer. Low-data mode emits no artwork image elements; draft and social-link fields remain available. Discovery, login and registration fit 320px and 390px screens; ticket and admin layouts fit 390px. It decodes the **actual rendered admission QR** with the bundled jsQR decoder and waits for an automatic refresh to a different signed code. All displayed artwork loads. The generated print poster is one A4 page.

## Visual review

These screenshots were captured from the running application with demo data:

- [Desktop discovery](previews/discovery.jpg)
- [Login](previews/login.jpg)
- [Mobile admission ticket](previews/ticket-mobile.png) — the pictured test token has expired.

## Reproduce

Follow [README setup and test commands](../README.md#tests), using a separate development database whose name ends in `_test`. For normal developer runs, seed the documented role-specific demo passwords. For CI, the optional `EVENTLINK_DEMO_PASSWORD` override must match between seeder and test scripts. Enable the SQL explorer for the seeded admin's actual user ID in that test configuration. The HTTP suite additionally requires `EVENTLINK_TEST_ALLOW_MUTATION=1`.

`tests/migration.php` creates and drops its own randomly named test database. The HTTP fixture bridge is CLI-only and refuses a non-development or non-`_test` database. Browser startup uses the same guard. Tests require local PHP, Python requests/Pillow, and Playwright/Chromium; these are development dependencies, not website runtime dependencies. Use four PHP workers to exercise independent simultaneous HTTP requests.

## Limits of this verification

MySQL 8, Apache/Nginx hosting rules on a deployed host, external SMTP delivery, real phone camera permissions and online payment processing were not exercised. The local router's private-path rules, local mail-token flows, rendered QR decoder and offline payment ledger were exercised. The deployment configurations and operator steps are supplied for the target environment.

This is application testing, not an independent penetration test or a guarantee that no vulnerabilities remain. Rotating QR codes cannot prevent relay of a still-valid code. Production needs its own HTTPS, secret configuration, database privileges, mail transport and operational backups as described in [release notes](RELEASE.md).

Retained upstream policy, media and QR helper checks also passed. The retained SQLite-backed catalog/access/booking helper suites were not executed completely because PDO SQLite was unavailable; they are separate from the active MariaDB/HTTP regression suite above. No claim of passing those optional SQLite suites is made.
