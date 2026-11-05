#!/usr/bin/env bash
#
# healthcheck.sh — one-glance health report for thunderstruck. READ-ONLY.
# Runs ON THE SERVER. Installed at ~/ops/healthcheck.sh.
#
#   ~/ops/healthcheck.sh
#
# Prints service status, disk/memory/load, failed systemd units, TLS cert expiry,
# per-app HTTP health (from ~/ops/apps/*.conf), Docker containers, and a firewall
# summary. Nothing is changed. Exit code is non-zero if anything looks wrong, so
# it can also be wired into cron with email-on-failure.
#
# TLS certs and the firewall are root-only. For the full report run it with sudo:
#     sudo ~/ops/healthcheck.sh
# (it resolves your real home so it still finds ~/ops/apps when run as root). Run
# as your normal user for everything except those two sections.

set -uo pipefail

# Find the ops dir whether run as cruiserzen, via sudo, or from a root cron.
# Order: explicit $OPS_DIR env → invoking user's home (under sudo too) → $HOME → cruiserzen.
if [ -n "${SUDO_USER:-}" ]; then REAL_HOME="$(getent passwd "$SUDO_USER" | cut -d: -f6)"; else REAL_HOME="$HOME"; fi
OPS_DIR=""
for cand in "${OPS_DIR:-}" "$REAL_HOME/ops" "$HOME/ops" /home/cruiserzen/ops; do
  [ -n "$cand" ] && [ -d "$cand/apps" ] && { OPS_DIR="$cand"; break; }
done
OPS_DIR="${OPS_DIR:-$REAL_HOME/ops}"
APPS_DIR="$OPS_DIR/apps"

# Can we read root-only things? (either we are root, or passwordless sudo works)
ROOT=""
if [ "$(id -u)" -eq 0 ]; then ROOT="root"
elif sudo -n true 2>/dev/null; then ROOT="sudo"; fi
# run a privileged read: `priv <cmd...>` — prefixes sudo -n when we're not root but can.
priv(){ if [ "$ROOT" = sudo ]; then sudo -n "$@"; else "$@"; fi; }

PROBLEMS=0
hr(){ printf '\n──────────────────────────────────────────────\n'; }
head(){ hr; echo "## $1"; }
warn(){ echo "  ⚠ $*"; PROBLEMS=$((PROBLEMS+1)); }
okln(){ echo "  ✓ $*"; }

echo "thunderstruck health — $(date '+%Y-%m-%d %H:%M:%S %Z')  $(uptime -p 2>/dev/null || echo up)"

head "Core services"
# Only checks units that actually exist on the box — `systemctl cat` fails for an
# absent/purged unit, so a removed service is skipped instead of flagged inactive.
# Edit this list as your stack changes.
for svc in apache2 postgresql mysql mongod docker re-dashboard wekan minio; do
  systemctl cat "$svc.service" >/dev/null 2>&1 || continue   # not installed — skip
  state=$(systemctl is-active "$svc" 2>/dev/null || true)
  if [ "$state" = active ]; then okln "$svc"; else warn "$svc is '$state'"; fi
done

head "Failed units"
failed=$(systemctl --failed --no-legend --plain 2>/dev/null | awk '{print $1}')
if [ -z "$failed" ]; then okln "none"; else echo "$failed" | sed 's/^/  ⚠ /'; PROBLEMS=$((PROBLEMS+1)); fi

head "Disk"
# Dedupe filesystems (/, /home may be the same device) and flag ≥90% use.
df -hP / "$REAL_HOME" 2>/dev/null | awk 'NR==1{print "  "$0; next} !seen[$1]++{print "  "$0; if($5+0>=90) print "  ⚠ "$6" at "$5}'
if df -hP / "$REAL_HOME" 2>/dev/null | awk 'NR>1 && !s[$1]++{if($5+0>=90) exit 1}'; then :; else warn "a filesystem is ≥90% full"; fi

head "Memory & load"
free -h | awk 'NR<=2{print "  "$0}'
echo "  load:$(cut -d' ' -f1-3 /proc/loadavg) (cores: $(nproc))"

head "TLS certificates (days remaining)"
# /etc/letsencrypt/{live,archive} are root-only. Need root or passwordless sudo.
if [ -z "$ROOT" ]; then
  echo "  (certs are root-only — re-run with: sudo ~/ops/healthcheck.sh)"
else
  names=$(priv ls /etc/letsencrypt/live 2>/dev/null | grep -v '^README$' || true)
  if [ -z "$names" ]; then echo "  (no letsencrypt certs found)"; fi
  for name in $names; do
    end=$(priv openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$name/cert.pem" 2>/dev/null | cut -d= -f2)
    [ -n "$end" ] || { echo "  ? $name (couldn't read)"; continue; }
    ends=$(date -d "$end" +%s 2>/dev/null || echo 0)
    days=$(( (ends - $(date +%s)) / 86400 ))
    if   [ "$days" -lt 0 ];  then warn "$name EXPIRED ${days#-} days ago"
    elif [ "$days" -lt 14 ]; then warn "$name expires in $days days"
    else okln "$name ($days d)"; fi
  done
fi

head "App HTTP health"
shopt -s nullglob
for m in "$APPS_DIR"/*.conf; do
  ( HEALTH_URL=""; HEALTH_EXPECT="200"; DOMAIN=""; . "$m"
    app=$(basename "$m" .conf)
    [ -n "$HEALTH_URL" ] || { echo "  – $app: no HEALTH_URL"; exit 0; }
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 8 "$HEALTH_URL" || echo 000)
    case ",$HEALTH_EXPECT," in *",$code,"*) echo "  ✓ $app → $code";;
      *) echo "  ⚠ $app → $code (want $HEALTH_EXPECT)  $HEALTH_URL"; exit 3;; esac
  ) || PROBLEMS=$((PROBLEMS+1))
done

head "App dependencies"
# Per-app required tools/extensions, declared in each manifest as:
#   DEPENDS="cmd1 cmd2"   (commands that must be on PATH)
#   PHP_EXT="zip gd"      (PHP extensions that must be loaded)
# e.g. svdp needs pdftotext (poppler-utils) for PDF->text in its Run of Show,
# and the php zip extension for reading .docx files.
any_dep=0
for m in "$APPS_DIR"/*.conf; do
  ( DEPENDS=""; PHP_EXT=""; . "$m"; app=$(basename "$m" .conf)
    [ -z "$DEPENDS$PHP_EXT" ] && exit 0
    miss=0
    for cmd in $DEPENDS; do
      if command -v "$cmd" >/dev/null 2>&1; then echo "  ✓ $app: $cmd"; else echo "  ⚠ $app: '$cmd' not found on PATH"; miss=1; fi
    done
    if [ -n "$PHP_EXT" ] && command -v php >/dev/null 2>&1; then
      loaded=$(php -m 2>/dev/null | tr 'A-Z' 'a-z')
      for ext in $PHP_EXT; do
        le=$(echo "$ext" | tr 'A-Z' 'a-z')
        if printf '%s
' "$loaded" | grep -qx "$le"; then echo "  ✓ $app: php-$ext"; else echo "  ⚠ $app: PHP extension '$ext' not loaded"; miss=1; fi
      done
    fi
    exit $miss
  ) || PROBLEMS=$((PROBLEMS+1))
  any_dep=1
done
[ "$any_dep" -eq 0 ] && echo "  (no app declares dependencies)"

head "Docker containers"
if command -v docker >/dev/null 2>&1; then
  docker ps --format '  {{.Names}}\t{{.Status}}' 2>/dev/null || warn "docker ps failed"
  # Exited containers are usually harmless leftovers (one-off runs), so report them
  # as info, not a problem. Prune with: docker container prune
  down=$(docker ps -a --filter 'status=exited' --format '{{.Names}}' 2>/dev/null)
  [ -n "$down" ] && echo "  info: exited (harmless; 'docker container prune' to clear): $(echo "$down" | paste -sd, -)"
else echo "  (docker not installed)"; fi

head "Firewall"
if command -v ufw >/dev/null 2>&1; then
  if [ -n "$ROOT" ]; then priv ufw status | sed 's/^/  /'
  else echo "  (root-only — re-run with: sudo ~/ops/healthcheck.sh, or try 'sudo ~/ops/firewall-audit.sh')"; fi
fi

hr
if [ "$PROBLEMS" -eq 0 ]; then echo "RESULT: all clear ✓"; exit 0
else echo "RESULT: $PROBLEMS thing(s) need attention ⚠"; exit 1; fi
