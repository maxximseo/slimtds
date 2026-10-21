# Exclude China traffic from analytics

Scope: all campaign click and pixel traffic, historical and future, including lists, visitor journeys, counters, charts, search/AI attribution, site rankings and recordings. Preserve raw history, campaign configuration and all conversion money records.

Implementation: reuse the existing multi-address country guard at click/pixel/record ingress; skip CN inbox rows before enrichment side effects; apply null-safe country predicates before aggregation and pagination; rebuild only clicks_hourly through a new reversible migration.

Risk: missing independent read paths or queued writes, stale hourly aggregate, changed conversion totals, production restart/config loss. Release through fork PR CI and merged-main CI, immutable tagged image, explicit current runtime compose overrides, rollback image and runtime readback.

Acceptance: cn/CN disappear, foreign and unknown rows stay, mixed visitor identities retain allowed-country visits, historical raw rows/conversions remain; forged analytics headers cannot bypass ingress; queues drain without CN inserts or notifications. Run isolated full Pest/PHPStan and live read-only report checks; production CN probes must add zero data.
