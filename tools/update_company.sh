#!/usr/bin/env bash
# Workforce One: update the plugin (and the lockdown must-use plugin) on hosted company sites.
#
# Sites made by tools/new_company.sh cannot update plugins from wp-admin (DISALLOW_FILE_MODS) and
# their code is owned by root, so updates are done here, as root:
#
#   sudo bash tools/update_company.sh --plugin /root/workforce-one.zip --slug acme
#   sudo bash tools/update_company.sh --plugin /root/workforce-one.zip --all
#
# The new plugin folder is unpacked next to the old one and swapped in with a rename, then the site
# is loaded once so the plugin runs its database upgrade. The old folder is kept in
# /root/wfo-backups/<slug>/workforce-one.previous until the next update, for a quick rollback.
set -euo pipefail

PLUGIN='' SLUGS=() ALL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --plugin) PLUGIN=$2; shift 2;;
    --slug) SLUGS+=("$2"); shift 2;;
    --all) ALL=1; shift;;
    -h|--help) sed -n '2,13p' "$0"; exit 0;;
    *) echo "Unknown option: $1" >&2; exit 1;;
  esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root (sudo)."
[[ -f $PLUGIN ]] || die "plugin zip not found: ${PLUGIN:-missing --plugin}"
LOCKDOWN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lockdown/wfo-lockdown.php"
[[ -f $LOCKDOWN ]] || die "lockdown plugin not found: $LOCKDOWN"
if [[ $ALL -eq 1 ]]; then
  for f in /root/wfo-companies/*.txt; do [[ -e $f ]] && SLUGS+=("$(basename "$f" .txt)"); done
fi
[[ ${#SLUGS[@]} -gt 0 ]] || die "no company: use --slug <slug> (repeatable) or --all."

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
unzip -q "$PLUGIN" -d "$STAGE"
[[ -f $STAGE/workforce-one/employee-schedule-manager.php ]] || die "the zip has no workforce-one/employee-schedule-manager.php"
NEW_VERSION=$(grep -m1 -oP '^\s*\*\s*Version:\s*\K\S+' "$STAGE/workforce-one/employee-schedule-manager.php" || echo '?')

FAILED=0
for SLUG in "${SLUGS[@]}"; do
  SITE_USER="wfo-$SLUG"
  ROOT_DIR=$(find "/home/$SITE_USER/htdocs" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -1)
  if [[ -z $ROOT_DIR || ! -f $ROOT_DIR/wp-config.php ]]; then
    echo "[$SLUG] skipped: no site found for $SITE_USER"; FAILED=1; continue
  fi
  PLUGINS="$ROOT_DIR/wp-content/plugins"
  wpc() { sudo -u "$SITE_USER" -H -- wp --path="$ROOT_DIR" --quiet "$@"; }
  BEFORE=$(wpc option get ews_schema_version 2>/dev/null || echo '?')

  rm -rf "$PLUGINS/.workforce-one-new"
  cp -a "$STAGE/workforce-one" "$PLUGINS/.workforce-one-new"
  chown -R root:root "$PLUGINS/.workforce-one-new"
  find "$PLUGINS/.workforce-one-new" -type d -exec chmod 755 {} +
  find "$PLUGINS/.workforce-one-new" -type f -exec chmod 644 {} +
  BACKUP="/root/wfo-backups/$SLUG"; install -d -m 700 "$BACKUP"
  rm -rf "$BACKUP/workforce-one.previous"
  if [[ -d $PLUGINS/workforce-one ]]; then mv "$PLUGINS/workforce-one" "$BACKUP/workforce-one.previous"; fi
  mv "$PLUGINS/.workforce-one-new" "$PLUGINS/workforce-one"
  install -m 644 -o root -g root "$LOCKDOWN" "$ROOT_DIR/wp-content/mu-plugins/wfo-lockdown.php"

  if wpc eval 'wfo_lockdown_sync_role();' && AFTER=$(wpc option get ews_schema_version); then
    echo "[$SLUG] updated to $NEW_VERSION (database $BEFORE -> $AFTER)"
  else
    echo "[$SLUG] FAILED after the swap; rolling back"
    rm -rf "$PLUGINS/workforce-one"; mv "$BACKUP/workforce-one.previous" "$PLUGINS/workforce-one"
    FAILED=1
  fi
done
exit $FAILED
