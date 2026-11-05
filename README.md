# Vincentian Resource Center

A self-hosted web app for the **St. Austin Conference, Society of St. Vincent de Paul** (Austin, TX). It gives Vincentians ("confreres") a single place to prepare for home visits, run meetings, coordinate dispatching, publish documents, and survey members. It is used only by known members of the conference — not by the Neighbors we serve.

**Viewing is public; editing is password-protected.** All content is shared through PostgreSQL, so everyone sees the same, current information.

Runs at **https://svdp.thundrstruck.com** on a self-hosted Ubuntu / Apache / PostgreSQL server.

## Features
- **Home Visits** — resource directory (food, utilities, rent, health, etc.), the spirituality of the visit, and the monthly assistance limits / Austin Energy Plus 1 allocation.
- **Meetings** — agendas per meeting (with numbered items and ●/○/– sub-bullets), report & material uploads, link materials to agenda items, clone a prior meeting into a draft, and a **Run of Show** presentation mode that renders every attached document/deck inline as page images, with a fixed meeting clock/timer.
- **Events** — Regional, National, and International events and official-calendar links on one page.
- **Documents** — typed or uploaded printable documents, with a jump-to index.
- **Dispatch** — intake, ZIP-based routing (in-area vs. sister-conference referral), and load-balanced auto-assignment of confrere pairs.
- **Dispatching analytics** — monthly dispatcher reports (Word `.docx`) parsed and charted over time.
- **Surveys** — build member surveys (yes/no, multiple choice, checkboxes/select-multiple, short text, number), serve open ones, and view tallied results. Responses are stored in their own database table.
- **Announcement banner** — seasonal, editor-configurable (text, colors via palette, uploaded graphic, optional click-through link).
- **Site Analytics** — lightweight per-section usage counts (editor-only).

## Stack
- **Apache2 + PHP (mod_php)** — no separate app server to manage.
- **PostgreSQL** — one shared content document (JSONB) with optimistic-concurrency versioning + an audit-history table; a separate `survey_responses` table for survey submissions.
- Plain **HTML/CSS/JS** frontend in a single file — no build step, no framework.
- **LibreOffice + poppler-utils** on the server, used only to convert attachments to page images for the Run of Show.

## Layout
| Path | Purpose |
|------|---------|
| `public/index.html` | The entire frontend (HTML/CSS/JS) |
| `public/api.php` | JSON API: get / login / logout / save, uploads, docx & dispatcher parsing, document rendering, surveys |
| `public/.htaccess` | Security headers |
| `public/uploads/.htaccess` | Serves uploads as static files only (never executed); member uploads themselves are **not** committed |
| `lib/db.php` | PDO PostgreSQL connection |
| `lib/auth.php` | Session-based edit login (bcrypt) |
| `lib/servware.php` | Placeholder seam for a future ServWare integration |
| `config.example.php` | Copy to `config.php`; holds DB credentials + edit-password hash (git-ignored) |
| `sql/schema.sql` | Database tables (also auto-created on first use) |
| `apache/svdp.conf` | Sample Apache virtual host |
| `deploy/` | Operations scripts (`ship.sh`, `healthcheck.sh`, `svdp.conf` manifest) — see `deploy/README.md` |
| `SETUP.md` | **Step-by-step install guide — start here** |

## Quick start
See **[SETUP.md](SETUP.md)**. In short: install packages (`apache2 postgresql php php-pgsql php-curl php-zip poppler-utils libreoffice`) → create the database → copy files → create `config.php` (DB password + a `password_hash()` of your edit password) → enable the Apache site → point the `svdp` DNS record at your home IP, forward ports 80/443 → add HTTPS with Let's Encrypt (`certbot --apache -d svdp.thundrstruck.com`).

## Deploying updates
From a Mac with the `deploy/ship.sh` helper installed, `ship.sh svdp` uploads the newest build and runs the server-side release (pre-deploy DB snapshot → copy-in-place → health check → auto-rollback). See `deploy/README.md`.

## Security notes
- `config.php` and `lib/` live outside the web root (`DocumentRoot` = `public/`), so they can't be downloaded. `config.php` is git-ignored.
- The edit password is stored as a bcrypt hash, verified server-side; a successful login sets an HttpOnly session cookie. Set `secure_cookies => true` once HTTPS is active.
- The server renders uploaded documents with LibreOffice in a throwaway profile; `public/uploads/` has an `.htaccess` that disables script execution.
- Member-uploaded files are intentionally **not** stored in git (see `.gitignore`).
