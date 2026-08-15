#!/usr/bin/env bash
set -euo pipefail

umask 077

SOURCE_DIR="${SLIMTDS_BACKUP_SOURCE_DIR:-/opt/slimtds/var/backups}"
REMOTE_PATH="${SLIMTDS_STORAGEBOX_REMOTE_PATH:-affscale/slimtds/affvibes.com/database}"
REMOTE_KEEP="${SLIMTDS_STORAGEBOX_KEEP:-30}"
GPG_HOMEDIR="${SLIMTDS_GPG_HOMEDIR:-/root/.gnupg-slimtds-backup}"
SSH_KEY="${HETZNER_STORAGEBOX_SSH_KEY_PATH:-/root/.ssh/storagebox_affscale}"
SSH_PORT="${HETZNER_STORAGEBOX_PORT:-23}"
KNOWN_HOSTS="${HETZNER_STORAGEBOX_KNOWN_HOSTS:-/root/.ssh/known_hosts}"

log() {
  printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

die() {
  echo "$*" >&2
  exit 1
}

[[ -n "${HETZNER_STORAGEBOX_HOST:-}" ]] || die 'HETZNER_STORAGEBOX_HOST is required'
[[ -n "${HETZNER_STORAGEBOX_USER:-}" ]] || die 'HETZNER_STORAGEBOX_USER is required'
[[ -n "${SLIMTDS_GPG_RECIPIENT:-}" ]] || die 'SLIMTDS_GPG_RECIPIENT is required'
[[ -f "$SSH_KEY" ]] || die "Storage Box SSH key not found: $SSH_KEY"
[[ -f "$KNOWN_HOSTS" ]] || die "Storage Box known-hosts file not found: $KNOWN_HOSTS"
[[ -d "$GPG_HOMEDIR" ]] || die "GPG home not found: $GPG_HOMEDIR"
[[ "$REMOTE_PATH" =~ ^[A-Za-z0-9._/-]+$ ]] || die 'Unsafe Storage Box remote path'
[[ "$REMOTE_KEEP" =~ ^[0-9]+$ ]] && (( REMOTE_KEEP >= 2 )) || die 'SLIMTDS_STORAGEBOX_KEEP must be at least 2'

for command_name in find flock gpg pg_restore rsync sftp sha256sum; do
  command -v "$command_name" >/dev/null || die "Required command is missing: $command_name"
done
gpg --homedir "$GPG_HOMEDIR" --batch --list-keys "$SLIMTDS_GPG_RECIPIENT" >/dev/null 2>&1 \
  || die 'Configured GPG recipient public key is unavailable'

exec 9>/run/lock/slimtds-storagebox-backup.lock
flock -n 9 || die 'Another slimTDS Storage Box backup is running'

LATEST_DUMP="$({
  find "$SOURCE_DIR" -maxdepth 1 -type f -name '*.dump' -mmin -2160 -printf '%T@ %p\n' 2>/dev/null || true
} | sort -nr | head -n 1 | cut -d' ' -f2-)"
[[ -n "$LATEST_DUMP" && -f "$LATEST_DUMP" ]] || die 'No fresh slimTDS dump found (maximum age: 36 hours)'

pg_restore --list "$LATEST_DUMP" >/dev/null

TMP_DIR="$(mktemp -d /var/tmp/slimtds-storagebox-backup.XXXXXX)"
cleanup() {
  case "$TMP_DIR" in
    /var/tmp/slimtds-storagebox-backup.*) rm -rf -- "$TMP_DIR" ;;
  esac
}
trap cleanup EXIT

DUMP_NAME="$(basename "$LATEST_DUMP")"
ENCRYPTED_NAME="${DUMP_NAME}.gpg"
ENCRYPTED_PATH="$TMP_DIR/$ENCRYPTED_NAME"
CHECKSUM_PATH="$TMP_DIR/${ENCRYPTED_NAME}.sha256"

log "Encrypting validated dump $DUMP_NAME"
gpg --homedir "$GPG_HOMEDIR" --batch --yes --trust-model always \
  --recipient "$SLIMTDS_GPG_RECIPIENT" \
  --output "$ENCRYPTED_PATH" --encrypt "$LATEST_DUMP"
(cd "$TMP_DIR" && sha256sum "$ENCRYPTED_NAME" > "${ENCRYPTED_NAME}.sha256")

SFTP_BATCH="$TMP_DIR/upload.sftp"
build_mkdirs() {
  local path="${1#/}"
  local current=''
  local part
  IFS='/' read -r -a parts <<< "$path"
  for part in "${parts[@]}"; do
    [[ -n "$part" ]] || continue
    current="${current:+$current/}$part"
    printf -- '-mkdir %s\n' "$current"
  done
}

{
  build_mkdirs "$REMOTE_PATH"
  printf -- '-rm %s/%s.part\n' "$REMOTE_PATH" "$ENCRYPTED_NAME"
  printf 'put %s %s/%s.part\n' "$ENCRYPTED_PATH" "$REMOTE_PATH" "$ENCRYPTED_NAME"
  printf -- '-rm %s/%s\n' "$REMOTE_PATH" "$ENCRYPTED_NAME"
  printf 'rename %s/%s.part %s/%s\n' "$REMOTE_PATH" "$ENCRYPTED_NAME" "$REMOTE_PATH" "$ENCRYPTED_NAME"
  printf -- '-rm %s/%s.sha256\n' "$REMOTE_PATH" "$ENCRYPTED_NAME"
  printf 'put %s %s/%s.sha256\n' "$CHECKSUM_PATH" "$REMOTE_PATH" "$ENCRYPTED_NAME"
} > "$SFTP_BATCH"

SFTP_OPTS=(
  -i "$SSH_KEY"
  -P "$SSH_PORT"
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$KNOWN_HOSTS"
)
SSH_CMD="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS"
REMOTE="${HETZNER_STORAGEBOX_USER}@${HETZNER_STORAGEBOX_HOST}"

log "Uploading encrypted dump to Storage Box"
sftp "${SFTP_OPTS[@]}" -b "$SFTP_BATCH" "$REMOTE" >/dev/null

LOCAL_SIZE="$(stat -c %s "$ENCRYPTED_PATH")"
REMOTE_SIZE="$(rsync --list-only -e "$SSH_CMD" "$REMOTE:$REMOTE_PATH/$ENCRYPTED_NAME" 2>/dev/null | awk 'NF >= 5 {print $2; exit}')"
[[ "$REMOTE_SIZE" = "$LOCAL_SIZE" ]] || die "Remote size mismatch for $ENCRYPTED_NAME"

CHECKSUM_DIFF="$(rsync -cni --out-format='%i %n' -e "$SSH_CMD" \
  "$ENCRYPTED_PATH" "$REMOTE:$REMOTE_PATH/$ENCRYPTED_NAME")"
[[ -z "$CHECKSUM_DIFF" ]] || die "Remote checksum mismatch for $ENCRYPTED_NAME"

mapfile -t REMOTE_DUMPS < <(
  rsync --list-only -e "$SSH_CMD" "$REMOTE:$REMOTE_PATH/" 2>/dev/null \
    | awk '{print $NF}' \
    | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.dump\.gpg$' \
    | sort -r || true
)

if (( ${#REMOTE_DUMPS[@]} > REMOTE_KEEP )); then
  RETENTION_BATCH="$TMP_DIR/retention.sftp"
  : > "$RETENTION_BATCH"
  for old_name in "${REMOTE_DUMPS[@]:REMOTE_KEEP}"; do
    printf -- '-rm %s/%s\n' "$REMOTE_PATH" "$old_name" >> "$RETENTION_BATCH"
    printf -- '-rm %s/%s.sha256\n' "$REMOTE_PATH" "$old_name" >> "$RETENTION_BATCH"
  done
  sftp "${SFTP_OPTS[@]}" -b "$RETENTION_BATCH" "$REMOTE" >/dev/null
fi

log "Storage Box backup verified: $REMOTE_PATH/$ENCRYPTED_NAME ($LOCAL_SIZE bytes)"
