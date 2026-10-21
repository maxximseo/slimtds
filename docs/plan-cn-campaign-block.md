# Global China campaign block — 2026-10-21

Operator instruction: every current and future campaign must return a black page
for China, without any onward navigation. Campaign/flow/offer settings stay intact.

- Run GeoIP and the global CN guard before campaign lookup, tracking, flow matching,
  trash fallback or response schema. Check the effective analytics IP as well as
  X-Real-IP and CF-Connecting-IP independently; tracker nginx overwrites the latter,
  and the lander proxy must overwrite X-Real-IP with its resolved visitor address.
- Return a black HTML page with no navigation or external resources and no-store.
  Do not record blocked requests as successful campaign clicks.
- Scope: campaign routes reaching this tracker. Existing redirects before the
  tracker, direct links outside it, VPNs exiting outside CN and unknown GeoIP
  locations are not proven blocked. Do not block other/unknown countries.
- Verify deterministic fixture tests for normal flow, fallback, new/inactive/missing
  campaign, forged higher-priority headers, and non-CN control; run PHPStan and CI.
- Release from the merged, green, tagged SHA; preserve DB volume and current runtime
  credentials. Back up image and local configuration; do not recreate the DB.
- Production acceptance: matching app/cron image revision, clean tracked source,
  healthy public endpoint, CN IPv4/IPv6 black response with no Location, non-CN
  routing intact, no new errors. Replace the temporary localhost socket bridge with
  the normal explicit server compose port and verify that database identity stays
  unchanged. Record limitations in the final delivery.
