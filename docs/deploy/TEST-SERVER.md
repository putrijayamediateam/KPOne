# KPOne test server (synthetic data only)

**Status:** plan, written 2026-11-19. **Not a production deployment.** Nothing here authorises real patient or
staff data. Production stays blocked until the gates in `docs/BASELINE.md` section 4 ("Production deployment and
go-live") and section 6 item 1, and `docs/pdpa/08-gaps-and-decisions.md` sections A and B, are closed.

Owner decisions (2026-11-19): a test server first, synthetic data only; a cloud server (VPS) in a Malaysian or
Singapore region; **Haziq** holds the server and database credentials.

## 1. What the server is for

- Staff rehearse the pilot on real devices (counter PC, doctor laptop, phone, waiting-room TV) over the internet.
- Prove a backup and a witnessed restore (`docs/pdpa/08-gaps-and-decisions.md` B2), which production needs anyway.
- Fictional `.test` identities only. Never type a real patient, staff member, IC number or phone number into it.

## 2. What KPOne needs on the server

Read from the repository on 2026-11-19 (`composer.json`, `.env.example`, `routes/console.php`):

| Need | Detail |
|---|---|
| PHP | 8.4.1 or later, with `pgsql`, `gd`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath` |
| Database | PostgreSQL 18 (the only supported database; SQLite is for local tests only) |
| Build | Node.js and npm, to run `npm ci` and `npm run build` once per release |
| Web | nginx + PHP-FPM, HTTPS with a free Let's Encrypt certificate |
| Scheduler | One cron line running `php artisan schedule:run` every minute (purges, intake clean-up, the 07:00 and 14:00 agent runs) |
| Queue worker | Not needed today: no queued jobs exist in `app/`. Re-check before each release |
| Files | Private uploads live in `storage/app/private`; this folder must be in the backup |

**Sizing (assumption, to confirm with the provider):** 2 vCPU, 4 GB RAM, 40 GB SSD, Ubuntu 24.04 LTS. Enough for a
synthetic rehearsal with a handful of users.

## 3. Rules for this server

1. **No proxy or CDN in front yet.** KPOne does not configure Laravel trusted proxies (only the public QR intake
   has its own list), so nginx on the VPS must terminate HTTPS itself. Putting Cloudflare's proxy in front is a
   separate, reviewed change.
2. **Restrict who can reach it.** Allow HTTPS only from the clinic's office IP addresses, plus Haziq's for admin
   (firewall rule). The demo seeders create accounts with a shared, documented presentation password
   (`README.md`, `docs/DEVELOPMENT.md`), so a public test server with those accounts is an open door.
3. **Production-style settings even with fake data**, so the rehearsal tests what production will use:
   `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`,
   `PUBLIC_PATIENT_INTAKE_ENABLED=false` (public QR is out of the pilot, BASELINE section 6 item 16).
4. **Secrets stay on the server.** The server's `.env` is created by Haziq on the server, readable only by the
   web user, never committed, never pasted into chat, email or WhatsApp. `APP_KEY` is generated on the server.
5. **SSH by key only**, password login and root login switched off.
6. **No real Google sign-in client** until the owner approves one; use password sign-in for synthetic accounts.
7. **Ask KPOne (`kpone:read`) stays off** on this server unless the `kpone_ai_reader` login is set up as in
   `docs/DEVELOPMENT.md`. With real data it needs the PDPA review (decision 10 in the PDPA pack).

## 4. Steps (Haziq runs them; Claude can run them with him)

Each step names who acts. "Claude" means Claude Code running in a terminal on the server, started by Haziq after
he signs in (see section 6).

1. **Haziq:** open the cloud account in the clinic's name, create the VPS in a Malaysian or Singapore region,
   add his SSH key, point a sub-domain (for example `kpone-test.<clinic domain>`) at its IP address.
2. **Haziq or Claude:** harden the server: create a deploy user, switch off password and root SSH, turn on the
   firewall (SSH and HTTPS from the allowed IPs only), turn on automatic security updates.
3. **Haziq or Claude:** install PHP 8.4 with the extensions above, Composer, Node.js LTS, nginx and PostgreSQL 18.
   Check each installed version and write it into the release record (section 7).
4. **Haziq:** create the database and its user (`kpone_test`), with a long random password he keeps in the
   clinic's password manager.
5. **Haziq or Claude:** clone `main` from `github.com/putrijayamediateam/KPOne` (read-only deploy key) into
   `/var/www/kpone`.
6. **Haziq:** create `/var/www/kpone/.env` from `.env.example` with the settings in section 3, the database
   details and `APP_URL`; then `php artisan key:generate`. Claude never opens or prints this file.
7. **Haziq or Claude:** build and migrate:
   `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`, `php artisan migrate --force`,
   `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, `php artisan storage:link`
   only if a page needs it.
8. **Haziq:** create the first Director with the controlled Artisan command `php artisan kpone:bootstrap-director` (BS-02;
   `docs/DEVELOPMENT.md`, "First Director bootstrap"), using a
   synthetic `.test` email. Do not run the development or presentation seeders unless the firewall rule in
   section 3 is in place, and change every demo password afterwards.
9. **Haziq or Claude:** nginx site for the sub-domain, Let's Encrypt certificate, HTTP redirected to HTTPS.
10. **Haziq or Claude:** cron line for the scheduler as the web user.
11. **Haziq or Claude:** nightly backup: `pg_dump` of the database plus `storage/app/private`, encrypted, copied off
    the server, kept 14 days (test server only; production retention is a PDPA decision).
12. **Haziq + one witness:** restore last night's backup onto a fresh database, sign in, open a patient, an
    invoice and an uploaded file. Write down the date, who did it, and how long it took. This is the evidence
    for `docs/pdpa/08-gaps-and-decisions.md` B2.

## 5. Updating to a new `main`

`git pull` on `main` only, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`,
`php artisan migrate --force`, the three `cache` commands, reload PHP-FPM, then reload each waiting-room TV.
Only a commit whose post-merge CI on GitHub is green goes on the server.

## 6. Using Claude on the server

Haziq signs in to the server, installs Claude Code under the deploy user, and runs it inside `/var/www/kpone`.
Claude can then run the install, build, migrate, nginx and backup steps and show each result, with Haziq
approving each command. Limits:

- Claude does not open, print or edit `.env`, and never handles the database or provider passwords.
- While the data is synthetic this is fine. **Before real data**, decide whether Claude may run on the production
  server at all: anything Claude reads there reaches Anthropic as part of the owner's Claude use (same question as
  Ask KPOne, PDPA pack decision 10).
- No `migrate:fresh`, `db:wipe` or seeder on a server that holds data someone wants to keep.

## 7. Release record (fill in per install)

| Date | `main` commit | PHP | PostgreSQL | Node | Done by | Backup restore tested |
|---|---|---|---|---|---|---|
| | | | | | | |

## 8. Still needed before production (not covered here)

Security test by an outsider, PDPA pack approved, retention periods, access list with named people, a second
Director or deputy, hosting provider named in the privacy notice, open dependency advisories closed, and the
owner's go-live decision. See `docs/pdpa/08-gaps-and-decisions.md` and `docs/BASELINE.md` section 6 item 16.
