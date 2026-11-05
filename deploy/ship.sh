#!/usr/bin/env bash
#
# ship.sh — deploy from your MAC: grab the newest tarball Claude built (or a
# prebuilt tarball / project dir), upload it, trigger the server-side deploy.
# Installed at ~/bin/ship.sh.
#
#   ship.sh <app>                            # auto: newest <app>*.tar.gz Claude built
#   ship.sh <app> ~/path/to/app.tar.gz       # ship a specific tarball
#   ship.sh <app> ~/code/project_dir         # pack a specific project dir
#
# The common case is just `ship.sh svdp` (or `pa`, `journey`, …). With no file
# argument it finds the newest <app>*.tar.gz under OUTPUTS_ROOT, deletes the
# older ones so only the most recent remains, and ships that. You never copy
# anything to Downloads.
#
# Config (env or ~/.config/thunderstruck/ship.env):
#   SSH_HOST      default: thundrstruck.com   ← a HOSTNAME, so an ISP IP change never breaks deploys
#   SSH_USER      default: cruiserzen
#   SSH_PORT      default: 2525
#   OUTPUTS_ROOT  where to auto-find the newest <app>*.tar.gz. Defaults to Claude's
#                 cowork outputs folder on this Mac, so you normally don't set it.
#                 May list SEVERAL folders separated by ":" (like PATH) when your
#                 apps build in different places — e.g. cowork builds under the
#                 Claude app-support folder, chat builds you saved elsewhere:
#                   OUTPUTS_ROOT="/path/one:/path/two:/path three"
#                 Every listed folder is searched recursively; the globally newest
#                 <app>*.tar.gz (by modification time) wins. Paths may contain spaces.
#   PRUNE_OUTPUTS default: 1. After picking the newest <app>*.tar.gz, delete the
#                 older <app>*.tar.gz across the OUTPUTS_ROOT folders. Set 0 to keep.
#
# Source selection for `ship.sh <app> [arg]`:
#   • arg is a .tar.gz file  → ship that tarball
#   • arg is a directory     → pack that project dir
#   • no arg                 → newest <app>*.tar.gz under OUTPUTS_ROOT (default: Claude outputs)
# The result is uploaded to ~/<app>/release.tar.gz and ~/ops/deploy.sh <app> runs the
# release / health-check / rollback flow.

set -euo pipefail
die(){ echo "✗ $*" >&2; exit 1; }
log(){ echo "▶ $*"; }

CFG="$HOME/.config/thunderstruck/ship.env"
# shellcheck disable=SC1090
[ -f "$CFG" ] && . "$CFG"
SSH_HOST="${SSH_HOST:-thundrstruck.com}"
SSH_USER="${SSH_USER:-cruiserzen}"
SSH_PORT="${SSH_PORT:-2525}"
PRUNE_OUTPUTS="${PRUNE_OUTPUTS:-1}"

# Where Claude writes per-session tarballs on this Mac. Used when OUTPUTS_ROOT
# isn't set explicitly, so bare `ship.sh <app>` works with no configuration.
DEFAULT_OUTPUTS="$HOME/Library/Application Support/Claude/local-agent-mode-sessions"
OUTPUTS_ROOT="${OUTPUTS_ROOT:-$DEFAULT_OUTPUTS}"

APP="${1:-}"; [ -n "$APP" ] || die \
  "Usage: ship.sh <app> [prebuilt.tar.gz | project_dir]
  ship.sh svdp                             # newest svdp*.tar.gz Claude built
  ship.sh pa                               # newest pa*.tar.gz Claude built
  ship.sh re   ~/code/re-dashboard          # pack a specific project dir"
ARG2="${2:-}"

TARBALL="$(mktemp -d)/release.tar.gz"

# OUTPUTS_ROOT may list several folders separated by ":" (like PATH). Emit each
# non-empty one on its own line, splitting ONLY on ":" so paths may contain spaces.
split_roots() {
  local IFS=: r
  set -f                       # no glob expansion on the unquoted split
  for r in $OUTPUTS_ROOT; do [ -n "$r" ] && printf '%s\n' "$r"; done
  set +f
}

# Newest <app>*.tar.gz across every OUTPUTS_ROOT folder (by mtime). macOS `stat -f` (BSD).
# awk (not head) picks line 1 so `sort` isn't SIGPIPE'd under `set -o pipefail`.
find_latest_output() {
  [ -n "${OUTPUTS_ROOT:-}" ] || return 0
  local root
  while IFS= read -r root; do
    [ -d "$root" ] || continue
    find "$root" -type f -name "$APP"'*.tar.gz' -exec stat -f '%m %N' {} \; 2>/dev/null
  done < <(split_roots) | sort -nr | awk 'NR==1{ sub(/^[0-9]+ /,""); print }'
}

# Delete every <app>*.tar.gz across the OUTPUTS_ROOT folders except the one we're
# keeping, so only the most recent build is ever left lying around.
prune_old_outputs() {
  [ "$PRUNE_OUTPUTS" = 1 ] || return 0
  [ -n "${OUTPUTS_ROOT:-}" ] || return 0
  local keep="$1" n=0 root f
  while IFS= read -r root; do
    [ -d "$root" ] || continue
    while IFS= read -r f; do
      [ "$f" = "$keep" ] && continue
      rm -f "$f" && n=$((n+1))
    done < <(find "$root" -type f -name "$APP"'*.tar.gz' 2>/dev/null)
  done < <(split_roots)
  [ "$n" -gt 0 ] && log "Removed $n older $APP tarball(s); keeping only the newest."
  return 0
}

# --- resolve the source tarball or project dir -------------------------------
SRC=""; AUTO=0
if [ -n "$ARG2" ]; then
  [ -f "$ARG2" ] && SRC="$ARG2"        # explicit file → tarball mode; a dir falls through
else
  AUTO=1
  SRC="$(find_latest_output || true)"
  [ -n "$SRC" ] && { log "Newest $APP tarball in outputs:"; log "  $SRC"; }
fi

if [ -n "$SRC" ]; then
  # Prebuilt-tarball mode.
  case "$SRC" in
    *.tar.gz|*.tgz) : ;;
    *) die "That doesn't look like a .tar.gz: $SRC" ;;
  esac
  # Soft guard: warn if the tarball name doesn't mention the target app.
  case "$(basename "$SRC")" in
    *"$APP"*) : ;;
    *) echo "⚠ heads up: '$(basename "$SRC")' doesn't mention '$APP'. Make sure you're"
       echo "  shipping the right app to the right target (Ctrl-C now if not)." >&2
       sleep 4 ;;
  esac
  log "Shipping tarball $SRC"
  cp "$SRC" "$TARBALL"
  [ "$AUTO" = 1 ] && prune_old_outputs "$SRC"
else
  # Project-dir mode — pack a source tree (explicit dir, or the current dir).
  PROJ="${ARG2:-$PWD}"
  [ -d "$PROJ" ] || die "No $APP tarball found under:
    $OUTPUTS_ROOT
  and '$PROJ' isn't a directory. Make sure Claude has built an $APP*.tar.gz,
  or pass a path: ship.sh $APP /path/to/tarball-or-project."
  cd "$PROJ"
  if [ -x scripts/pack.sh ]; then
    log "Packing via project scripts/pack.sh…"
    bash scripts/pack.sh
    src="$(ls -t ./*app*.tar.gz ./*.tar.gz 2>/dev/null | head -1)" || true
    [ -n "${src:-}" ] || die "pack.sh ran but I can't find the tarball it produced."
    cp "$src" "$TARBALL"
  elif [ -d .git ]; then
    log "Packing tracked files via git archive…"
    git archive --format=tar.gz -o "$TARBALL" HEAD
  else
    log "Packing directory (excluding node_modules/.git/.next)…"
    tar czf "$TARBALL" --exclude=node_modules --exclude=.git --exclude=.next .
  fi
fi
log "Tarball: $(du -h "$TARBALL" | cut -f1)"

log "Uploading to $SSH_USER@$SSH_HOST:~/$APP/release.tar.gz (port $SSH_PORT)…"
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "mkdir -p ~/$APP"
scp -P "$SSH_PORT" "$TARBALL" "$SSH_USER@$SSH_HOST:~/$APP/release.tar.gz"

log "Running server deploy…"
# -t allocates a terminal so the server-side deploy can prompt for your sudo password
# when it needs root (e.g. writing into /var/www for PHP apps). sudo caches it, so you
# type it at most once per deploy. Node apps that only NOPASSWD-restart won't prompt.
ssh -t -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "~/ops/deploy.sh $APP"

rm -f "$TARBALL"; rmdir "$(dirname "$TARBALL")" 2>/dev/null || true
echo "✓ ship complete: $APP"
