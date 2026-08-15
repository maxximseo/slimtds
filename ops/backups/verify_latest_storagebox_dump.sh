#!/usr/bin/env bash
set -euo pipefail

umask 077

REMOTE_PATH="${SLIMTDS_STORAGEBOX_REMOTE_PATH:-affscale/slimtds/affvibes.com/database}"
GPG_HOMEDIR="${SLIMTDS_GPG_HOMEDIR:-/root/.gnupg-slimtds-backup}"
SSH_KEY="${HETZNER_STORAGEBOX_SSH_KEY_PATH:-/root/.ssh/storagebox_affscale}"
SSH_PORT="${HETZNER_STORAGEBOX_PORT:-23}"
KNOWN_HOSTS="${HETZNER_STORAGEBOX_KNOWN_HOSTS:-/root/.ssh/known_hosts}"

die() {
  echo "$*" >&2
  exit 1
}

[[ -n "${HETZNER_STORAGEBOX_HOST:-}" ]] || die 'HETZNER_STORAGEBOX_HOST is required'
[[ -n "${HETZNER_STORAGEBOX_USER:-}" ]] || die 'HETZNER_STORAGEBOX_USER is required'
[[ -f "$SSH_KEY" ]] || die "Storage Box SSH key not found: $SSH_KEY"
[[ -f "$KNOWN_HOSTS" ]] || die "Storage Box known-hosts file not found: $KNOWN_HOSTS"
[[ -d "$GPG_HOMEDIR" ]] || die "GPG home not found: $GPG_HOMEDIR"
[[ "$REMOTE_PATH" =~ ^[A-Za-z0-9._/-]+$ ]] || die 'Unsafe Storage Box remote path'

for command_name in docker flock gpg rsync sftp sha256sum; do
  command -v "$command_name" >/dev/null || die "Required command is missing: $command_name"
done

exec 9>/run/lock/slimtds-storagebox-restore-test.lock
flock -n 9 || die 'Another slimTDS Storage Box restore test is running'

SSH_CMD="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS"
REMOTE="${HETZNER_STORAGEBOX_USER}@${HETZNER_STORAGEBOX_HOST}"
LATEST_NAME="$(
  rsync --list-only -e "$SSH_CMD" "$REMOTE:$REMOTE_PATH/" 2>/dev/null \
    | awk '{print $NF}' \
    | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.dump\.gpg$' \
    | sort -r | head -n 1
)"
[[ -n "$LATEST_NAME" ]] || die 'No encrypted slimTDS backup found on Storage Box'

TMP_DIR="$(mktemp -d /var/tmp/slimtds-storagebox-restore-test.XXXXXX)"
cleanup() {
  case "$TMP_DIR" in
    /var/tmp/slimtds-storagebox-restore-test.*) rm -rf -- "$TMP_DIR" ;;
  esac
}
trap cleanup EXIT

SFTP_BATCH="$TMP_DIR/download.sftp"
{
  printf 'get %s/%s %s/%s\n' "$REMOTE_PATH" "$LATEST_NAME" "$TMP_DIR" "$LATEST_NAME"
  printf 'get %s/%s.sha256 %s/%s.sha256\n' "$REMOTE_PATH" "$LATEST_NAME" "$TMP_DIR" "$LATEST_NAME"
} > "$SFTP_BATCH"
sftp -i "$SSH_KEY" -P "$SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$KNOWN_HOSTS" \
  -b "$SFTP_BATCH" "$REMOTE" >/dev/null

(cd "$TMP_DIR" && sha256sum -c "${LATEST_NAME}.sha256" >/dev/null)
DECRYPTED_PATH="$TMP_DIR/${LATEST_NAME%.gpg}"
gpg --homedir "$GPG_HOMEDIR" --batch --yes --output "$DECRYPTED_PATH" \
  --decrypt "$TMP_DIR/$LATEST_NAME" >/dev/null 2>&1
CONTAINER_NAME="slimtds-restore-test-$$"
cleanup_container() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}
trap 'cleanup_container; cleanup' EXIT

docker run -d --rm --name "$CONTAINER_NAME" \
  -e POSTGRES_DB=slimtds_restore_test \
  -e POSTGRES_USER=postgres \
  -e POSTGRES_PASSWORD=restore-test-only \
  -v "$TMP_DIR:/restore:ro" postgres:18-alpine >/dev/null

ready=0
for _ in $(seq 1 60); do
  if docker exec "$CONTAINER_NAME" pg_isready -U postgres -d slimtds_restore_test >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
(( ready == 1 )) || die 'Disposable PostgreSQL 18 did not become ready'

docker exec "$CONTAINER_NAME" pg_restore \
  -U postgres -d slimtds_restore_test --no-owner --no-privileges \
  "/restore/$(basename "$DECRYPTED_PATH")"

CAMPAIGNS="$(docker exec "$CONTAINER_NAME" psql -U postgres -d slimtds_restore_test -Atqc \
  'SELECT count(*) FROM core.campaigns')"
CLICKS="$(docker exec "$CONTAINER_NAME" psql -U postgres -d slimtds_restore_test -Atqc \
  'SELECT count(*) FROM stats.clicks')"
[[ "$CAMPAIGNS" =~ ^[1-9][0-9]*$ ]] || die 'Restored database has no campaigns'
[[ "$CLICKS" =~ ^[1-9][0-9]*$ ]] || die 'Restored database has no clicks'

printf 'restore verification ok: %s (campaigns=%s, clicks=%s)\n' \
  "$LATEST_NAME" "$CAMPAIGNS" "$CLICKS"
