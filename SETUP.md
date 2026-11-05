# Setup Guide — Vincentian Resource Center

Deploy the shared Resource Center on your home **Ubuntu 20.04** server using **Apache2**, **PostgreSQL**, and **PHP**. Viewing is public; editing requires a shared password. Two people can edit asynchronously, and a version check prevents one from silently overwriting the other.

Follow the steps in order. Commands are meant to be run in a terminal on the server. Anything in `ALL_CAPS` is a value you choose or replace.

---

## 0. What you'll end up with

```
/var/www/svdp-app/
├── config.php            <- you create this (holds DB password + edit password); NOT web-accessible
├── config.example.php    <- template to copy
├── lib/                  <- PHP helpers (db, auth, servware stub); NOT web-accessible
├── sql/schema.sql        <- database tables
└── public/               <- the ONLY web-served folder (DocumentRoot)
    ├── index.html        <- the app
    ├── api.php           <- the JSON API
    └── .htaccess         <- security headers
```

The web server only ever serves the `public/` folder, so your config and database code stay private.

---

## 1. Install the software

```bash
sudo apt update
sudo apt install -y apache2 postgresql php libapache2-mod-php php-pgsql php-curl
```

On Ubuntu 20.04 this gives you PHP 7.4, which is fine for this app. Confirm the pieces are present:

```bash
apache2 -v
psql --version
php -v
php -m | grep -E 'pdo_pgsql|curl'   # should print pdo_pgsql and curl
```

Enable the Apache modules the app uses:

```bash
sudo a2enmod headers rewrite
sudo systemctl restart apache2
```

---

## 2. Create the PostgreSQL database and user

Switch to the `postgres` admin account and open a SQL prompt:

```bash
sudo -u postgres psql
```

At the `postgres=#` prompt, run these (choose a strong `DB_PASSWORD`):

```sql
CREATE DATABASE svdp;
CREATE USER svdp WITH PASSWORD 'DB_PASSWORD';
GRANT ALL PRIVILEGES ON DATABASE svdp TO svdp;
-- PostgreSQL 15+ locks down the public schema by default, so grant it explicitly:
\c svdp
GRANT ALL ON SCHEMA public TO svdp;
ALTER SCHEMA public OWNER TO svdp;
\q
```

> If you skip the schema grant on PostgreSQL 15 or newer, the next step fails with `permission denied for schema public`. The two `public` lines above prevent that.

Now create the tables. From the folder where you unpacked these files:

```bash
psql "host=127.0.0.1 dbname=svdp user=svdp password=DB_PASSWORD" -f sql/schema.sql
```

You should see `CREATE TABLE` messages. If you get a peer-authentication error instead, add `host=127.0.0.1` (as shown) so it connects over TCP with the password rather than the local socket.

---

## 3. Copy the app into place

```bash
sudo mkdir -p /var/www/svdp-app
sudo cp -r ./svdp-app/* /var/www/svdp-app/     # adjust source path to where you unpacked it
```

(You want the structure from section 0: `config.example.php`, `lib/`, `sql/`, and `public/` all directly under `/var/www/svdp-app/`.)

---

## 4. Create your config file

```bash
cd /var/www/svdp-app
sudo cp config.example.php config.php
```

First, generate a **hash** of the edit password you want your collaborator to use:

```bash
php -r "echo password_hash('YOUR_EDIT_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

Copy the output (it starts with `$2y$...`). Then edit the config:

```bash
sudo nano config.php
```

Set:
- `db.password` → your `DB_PASSWORD` from step 2
- `edit_password_hash` → the `$2y$...` string you just generated
- `secure_cookies` → leave `false` for now; set to `true` after HTTPS works (step 7)

Save and exit.

Lock the config down so only the web server can read it:

```bash
sudo chown -R www-data:www-data /var/www/svdp-app
sudo chmod 640 /var/www/svdp-app/config.php
```

---

## 5. Configure the Apache site

The provided vhost is already set to serve **`svdp.thundrstruck.com`** — no editing needed. Just copy it in and enable it:

```bash
sudo cp /var/www/svdp-app/apache/svdp.conf /etc/apache2/sites-available/svdp.conf
sudo a2ensite svdp
sudo a2dissite 000-default        # optional: stop serving the default Apache page
sudo apache2ctl configtest        # should say: Syntax OK
sudo systemctl reload apache2
```

Test locally on the server:

```bash
curl -s http://localhost/api.php?action=get
```

You should get JSON like `{"data":null,"version":0,...}`. You can also browse to the server's LAN IP (`http://192.168.x.x/`) to see the Resource Center before DNS is set up.

---

## 6. Open the firewall and forward ports

On the server:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'      # opens ports 80 and 443
sudo ufw enable
sudo ufw status
```

On your **home router**, forward external ports **80** and **443** to your server's LAN IP.

**Point `svdp.thundrstruck.com` at your home connection.** In your DNS provider for `thundrstruck.com`, add a record for the `svdp` host:

- If your home IP is static: an **A record** — host `svdp`, value = your home's public IPv4 address.
- If your home IP changes (typical for residential): use **dynamic DNS** so the name always tracks your current IP. Either (a) point `svdp` at a DDNS hostname with a **CNAME** and run that provider's updater client on the server, or (b) if your DNS provider has an API (Cloudflare, Namecheap, etc.), run a DDNS updater that keeps the `svdp` A record current.

Verify it resolves before moving on:

```bash
dig +short svdp.thundrstruck.com     # should return your home public IP
```

> Tip: give the server a static LAN IP (DHCP reservation in your router) so the port-forward doesn't break on reboot. Also confirm your ISP doesn't block inbound port 80/443 — some residential plans do, which would stop both the site and Let's Encrypt's HTTP validation.

---

## 7. Turn on HTTPS (required — a remote collaborator will send the edit password over the internet)

Once `svdp.thundrstruck.com` resolves to your home IP (step 6) and ports 80/443 are forwarded, install a free Let's Encrypt certificate:

```bash
sudo apt install -y snapd
sudo snap install core && sudo snap refresh core
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/bin/certbot
sudo certbot --apache -d svdp.thundrstruck.com
```

Choose the option to **redirect HTTP to HTTPS** when prompted. Certbot auto-renews via a systemd timer; verify with `sudo certbot renew --dry-run`.

Now harden the login cookie:

```bash
sudo nano /var/www/svdp-app/config.php   # set 'secure_cookies' => true
```

(The `apt install certbot python3-certbot-apache` package also works if you prefer not to use snap.)

---

## 8. First run

1. Visit `https://svdp.thundrstruck.com/` — you'll see the default content.
2. Click **✎ Edit** (bottom-right), enter the edit password. Edit mode turns on.
3. Fill in the two assistance limits, add a resource link, etc. Each change saves to the server; you'll see a brief "Saved" and a "Last updated … v#" line.
4. Open the site in another browser (or have your collaborator load it) — the changes are there.
5. **⇩ Export backup** downloads a JSON copy anytime; **⇧ Import** restores one.

Your collaborator just needs the URL and the edit password.

---

## File uploads (meeting materials)

The Meetings section lets logged-in members upload reports (PDF, Word, Excel, PowerPoint, images, etc.). Uploaded files are stored in `public/uploads/` and served as static downloads. Two one-time setup items:

**1. Make the uploads folder writable by the web server.** The `chown -R www-data` in step 4 already covers this; the folder ships with a `.htaccess` that blocks any script from executing there. Confirm it exists and is owned by `www-data`:

```bash
sudo mkdir -p /var/www/svdp-app/public/uploads
sudo chown -R www-data:www-data /var/www/svdp-app/public/uploads
```

**2. Raise PHP's upload limits.** PHP defaults to about 2 MB, which is far smaller than the app's 100 MB limit. Edit your PHP ini for Apache (match your PHP version — `php -v` shows it; on Ubuntu 20.04 it's 7.4):

```bash
sudo nano /etc/php/7.4/apache2/php.ini
```

Set these two values, then restart Apache:

```
upload_max_filesize = 100M
post_max_size = 105M
```

```bash
sudo systemctl restart apache2
```

Uploaded files are referenced from the shared content, so they appear for every member. Deleting a material entry in the app removes the link but leaves the file on disk; clean up `public/uploads/` manually if you ever need to reclaim space.

---

## Run of Show — inline document rendering

The **Run of Show** turns a meeting into one continuous, scrolling presentation: the agenda is the home page, and every linked report, deck, or document is shown **inline as page images** so the coordinator never has to switch tabs. The server converts each attachment once and caches the result under `public/uploads/_render/`.

This needs two command-line tools on the server:

```bash
sudo apt install -y poppler-utils libreoffice
```

- **poppler-utils** provides `pdftoppm` (PDF → page images) and `pdftotext` (already used elsewhere).
- **libreoffice** converts Word (`.doc`/`.docx`), PowerPoint (`.ppt`/`.pptx`), and Excel (`.xls`/`.xlsx`) to PDF behind the scenes. The headless engine is included; no desktop is required.

> LibreOffice is a large package (~1 GB installed). If you want the smallest footprint, `libreoffice-writer libreoffice-impress libreoffice-calc libreoffice-core` covers every format the app converts.

The web server (`www-data`) must be able to run these and write to `public/uploads/`. The `chown -R www-data` from step 4 already covers the folder; the first conversion of each file takes a few seconds (LibreOffice cold start), and every open after that is instant from the cache. `~/ops/healthcheck.sh` reports these under **App dependencies** (declared in `svdp.conf` as `DEPENDS="pdftotext pdftoppm libreoffice"`).

Videos are shown as links (they can't be embedded reliably), and external web pages open in a tab; everything that lives in `uploads/` renders inline.

---

## Updating an existing install

When you drop in a new version of these files (like this one, which adds the Meetings section), re-copy everything **except** your `config.php`, then reload Apache:

```bash
# from the folder where you unpacked the new version
sudo cp -r public lib apache sql /var/www/svdp-app/     # NOT config.php
sudo chown -R www-data:www-data /var/www/svdp-app
sudo systemctl reload apache2
```

No database change is needed — the Meetings data lives in the same JSON content row, and the app fills in the new section automatically for content saved before this update.

---

## How editing conflicts are handled

Every save carries the version number you loaded. If your collaborator saved in between, the server rejects your save (HTTP 409), loads their latest version into your page, and asks you to re-apply your change and save again. Nothing is silently lost. For two people working async this is simple and safe; the audit trail in the `content_history` table keeps every past version if you ever need to roll back.

---

## Backups

The whole state is one row in PostgreSQL. A nightly dump is plenty:

```bash
# put in root's crontab: sudo crontab -e
0 2 * * *  pg_dump "host=127.0.0.1 dbname=svdp user=svdp password=DB_PASSWORD" -Fc -f /var/backups/svdp-$(date +\%F).dump
```

The in-app **Export backup** button is a convenient manual second copy.

---

## Troubleshooting

- **Blank page / 500 error:** check `sudo tail -f /var/log/apache2/svdp_error.log`. Most often config.php is missing or has the wrong DB password.
- **"Database connection failed":** confirm PostgreSQL is running (`systemctl status postgresql`), the password matches, and you can connect manually with the `psql "host=127.0.0.1 ..."` command from step 2.
- **Login always fails:** regenerate the hash (step 4) — the value in config must be the full `$2y$...` string, quoted, with no stray spaces.
- **Edits don't persist:** open the browser's dev console (F12) → Network tab, click an edit, and check the `api.php?action=save` response. 401 = not logged in; 409 = version conflict (reload); 500 = see the Apache error log.
- **`can_edit` resets on refresh:** that's expected — logging in is per browser session. Just click Edit and re-enter the password.

---

## Adding ServWare later

`lib/servware.php` is a ready-made seam. When you have ServWare API access, fill in the `servware` block in `config.php`, set `enabled => true`, and implement the calls in that file using PHP cURL (already installed via `php-curl`). Nothing else in the app needs to change to start pulling or pushing ServWare data.
