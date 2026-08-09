# Keitaro history import

## Scope

- Show both all-source EPC and search-only EPC per actual offer in the Telegram digest.
- Import the retained Keitaro click and conversion history into slimTDS with explicit `source = keitaro` attribution.
- Preserve current slimTDS traffic, postbacks, notifications, campaign routing, and existing identifiers.

## Material risks

- Duplicate historical rows or payouts on a repeated import.
- Wrong campaign/offer attribution when a Keitaro entity was renamed or removed.
- Multiple Keitaro conversions for one click conflicting with slimTDS live-postback idempotency.
- Missing monthly click partitions or retention cleanup deleting imported history.
- Partial import after a connection/process failure.

## Controls

- Export Keitaro read-only, pin row counts, and verify unique source event IDs.
- Use the existing immutable Keitaro-to-slimTDS campaign/offer/flow map; fail closed on unmapped IDs.
- Add source-aware idempotency without weakening one-conversion-per-click behavior for live slimTDS postbacks.
- Import in a database transaction after a production backup and create every required monthly partition first.
- Make the importer repeatable and require a second dry run to report zero pending rows.

## Acceptance

- Export totals match the source: clicks and conversions, earliest/latest timestamps, unique source IDs, statuses, and approved payout totals.
- Imported totals match by campaign, actual offer, status, and payout; unmapped rows equal zero or are explicitly reported and not guessed.
- Existing slimTDS row counts and current postback behavior remain unchanged.
- Babu88 digest displays all-source clicks/EPC separately from search clicks/EPC.
- CI is green for the release SHA; production health/TLS/log checks pass after deploy.
- A repeated import changes zero rows, and the pre-import backup plus previous container image remain available for rollback.
