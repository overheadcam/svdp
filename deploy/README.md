# Deploy & operations scripts

Helpers for running the app on the self-hosted **thunderstruck** server.

| File | Runs on | Purpose |
|------|---------|---------|
| `ship.sh` | your **Mac** (install at `~/bin/ship.sh`) | `ship.sh svdp` finds the newest build, uploads it, and triggers the server-side release. Searches one or more output folders listed in `OUTPUTS_ROOT` (colon-separated) and keeps only the newest tarball. |
| `healthcheck.sh` | the **server** (`~/ops/`) | Checks services, TLS expiry, disk, and per-app dependencies (reads `DEPENDS` / `PHP_EXT` from each `*.conf`). |
| `svdp.conf` | the **server** (`~/ops/`) | Deploy manifest for this app: target dir, dirs to sync, health URL, DB backup settings, and the runtime dependencies (`pdftotext`, `pdftoppm`, `libreoffice`, PHP `zip`). |

## Mac setup (one time)
```bash
cp deploy/ship.sh ~/bin/ship.sh && chmod +x ~/bin/ship.sh
mkdir -p ~/.config/thunderstruck
cat > ~/.config/thunderstruck/ship.env <<'EOF'
SSH_HOST=thundrstruck.com
SSH_USER=cruiserzen
SSH_PORT=2525
# Optional: extra build folders to search, colon-separated (quote paths with spaces)
# OUTPUTS_ROOT="/path/to/builds:/another/path"
EOF
```
Then deploy any time with:
```bash
ship.sh svdp               # auto-find newest build
ship.sh svdp path/to.tgz   # or a specific tarball
```

## Server setup (one time)
Copy `svdp.conf` to `~/ops/` so the deploy and health check know about this app. The server also needs `poppler-utils` and `libreoffice` installed (used for the Run of Show document rendering) — see `../SETUP.md`.

> These scripts assume the thunderstruck server's `~/ops/deploy.sh` release tooling. They are included here for reference and reproducibility.
