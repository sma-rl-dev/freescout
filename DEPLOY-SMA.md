# FreeScout — tester-env Deployment Guide

## Quick Start

```bash
./tester-env deploy    # Build image, start container, create admin user
./tester-env seed      # Populate with deterministic Northwind Outdoor Gear helpdesk data
./tester-env verify    # Check seeded database counts and rendered mailbox page
./tester-env reset     # Stop containers, remove volumes, clean slate
./tester-env status    # Show whether app is running and on what port
./tester-env stop      # Stop containers (preserves data)
./tester-env logs      # Tail container logs
./tester-env help      # Show usage
```

## Credentials

| Field    | Value                    |
|----------|--------------------------|
| URL      | http://localhost:8042    |
| Admin    | admin@tester-env.local   |
| Password | TesterEnv123!            |
| Agents   | TesterEnv123!            |

## Baseline

- **Upstream:** https://github.com/freescout-help-desk/freescout
- **Fork:** https://github.com/Smartesting/freescout
- **Tag:** 1.8.225 (dist branch)
- **Commit:** 74fa4b7d4f8288f8d3fb1d343308d3289c4d72e2
- **Branch:** `tester-env-baseline`

## Architecture

- **Stack:** PHP 8.2 + Apache + MariaDB 10.11
- **Image:** `php:8.2-apache-bookworm`
- **PHP extensions:** pdo_mysql, mbstring, xml, zip, gd, bcmath
- **Volumes:** `freescout-db-data` (MariaDB), `freescout-storage` (app storage)
- **Port:** 8042
- **Build time (cold):** ~95s (PHP extensions compiled from source)
- **Deploy time (warm):** ~35s (DB init + migrations)

## Source Fixes Applied on Baseline

1. **Whoops 2.14.5 override fix:** Upstream dist tag ships patched Whoops files in `overrides/filp/whoops/src/Whoops/` but excludes unmodified files (`RunInterface.php`, `Exception/`, `Handler/`, `Util/`). These were added from the full Whoops 2.14.5 source.
2. **APP_KEY generation:** `key:generate --show | sed` workaround because `key:generate` sometimes fails to write to `.env` during entrypoint.
3. **Permission fix:** `chown -R www-data:www-data` at end of entrypoint (before starting Apache) because `php artisan` commands run as root and create cache files unreadable by Apache.
4. **Browser-facing APP_URL:** Set to `http://host.docker.internal:8042` so browser MCP containers can reach the app with working session cookies and CSP headers. Host-side curl at `localhost:8042` also works.
5. **Trusted hosts:** `APP_TRUSTED_HOSTS=localhost,host.docker.internal` in entrypoint so browser MCP can access via `host.docker.internal`.
6. **Storage symlink:** Entrypoint fixes stale `public/storage` and `storage/app/public` symlinks before running `storage:link`.

## Seed Data — Northwind Outdoor Gear

| Entity          | Count | Details                                                  |
|-----------------|-------|----------------------------------------------------------|
| Mailboxes       | 3     | Northwind Support, Northwind Billing, Northwind Returns |
| Agents          | 3     | Mia Chen, Noah Patel, Olivia Reed                        |
| Customers       | 6     | Avery Stone, Jamie Ortiz, Taylor Nguyen, Priya Shah, Marco Diaz, Sophia Klein |
| Conversations   | 8     | Numbers 7001–7008; 3 active, 2 pending, 2 closed, 1 spam |
| Timestamps      | Fixed | 2026 dates                                               |

## Browser Smoke Evidence

- Login at `http://host.docker.internal:8042` with admin credentials.
- Dashboard rendered with mailbox cards visible.
- Created mailbox "QA Support Browser Smoke" via Manage Mailboxes → New Mailbox UI.
- Deleted mailbox "QA Support Browser Smoke"; deletion returned list to empty state.

## Mutation Smoke Evidence

- Mutation: Changed Login button text from "Login" to "MUTATION-TEST-Login" in `resources/views/auth/login.blade.php`.
- Clear view cache with `php artisan view:clear`.
- Mutation visible: `<button>MUTATION-TEST-Login</button>` rendered on login page.
- Reverted and verified restoration.

## Seed Browser Verification

- 3 mailboxes (Northwind Billing/Returns/Support) visible in Manage Mailboxes page.
- Seeded conversations with subjects like "Expedite replacement tent poles before Friday" visible in conversation list.
- Customer names (Avery Stone, Jamie Ortiz, etc.) present in seeded data.
- Agent accounts (Mia Chen, Noah Patel, Olivia Reed) active and listed.

## Reset Path

```bash
./tester-env reset    # docker compose down -v removes containers, DB volume, storage volume, .env
./tester-env deploy   # Full fresh initialization
./tester-env seed     # Re-populate
./tester-env verify   # Assert state
```

Deterministic: full cycle verified twice from clean volumes with identical counts and rendered content.
