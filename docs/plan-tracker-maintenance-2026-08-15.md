# Tracker maintenance plan — 2026-08-15

## Scope

- Disable the empty `CK4444` campaign after preserving its row.
- Give anonymous login/CSRF sessions a one-hour server-side lifetime, retain the
  existing 14-day authenticated lifetime, persist `admin_id`, and clean expired
  sessions hourly.
- Do not invoke or journal `demo:reset` when `DEMO_MODE` is disabled.
- Upload each validated daily PostgreSQL dump to Hetzner Storage Box after
  encrypting it with a public-only key on the tracker host. Keep the private
  decryption key on the separate panel host and verify decryption weekly.

## Acceptance

- CK4444 is inactive, its single imported historical click is preserved, no
  live fleet file targets it, and a root-only rollback record exists.
- A fresh anonymous session has a roughly one-hour TTL; a successful admin
  login stores its real `admin_id` and keeps the 14-day TTL.
- `sessions:cleanup` succeeds and no new production `demo:reset` run is logged.
- The newest local dump passes `pg_restore --list`, its encrypted copy and
  checksum exist on Storage Box, and a separate host can decrypt and fully
  restore it into a disposable PostgreSQL 18 instance.
- Application, cron, database, redirect, postback rejection, admin login and
  current traffic smokes remain healthy after deployment.

## Rollback

- Re-enable CK4444 from the saved row only if it is intentionally configured.
- Restore the previous release/image and remove the new env setting to return to
  the former session behavior.
- Disable the two Storage Box timers; local database backup remains unchanged.
