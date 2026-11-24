# B3: Queue polling soak (30 screens and 3 TVs, 30 minutes)

Test and measurement only. No application code changed. Synthetic data on a disposable `kpone_b3_soak` database. Not a production SLA.

## Headline, in plain words

With one branch's day loaded (100 queue entries, 8 doctors in 8 rooms, 5 patients marked not ready), **30 staff screens and 3 TV screens polled for 30 minutes: 20,344 requests (about 11 a second), no error, no refusal (zero 429s), no lock wait, no deadlock.**
The busiest route, the Queue board refresh (`POST /queue/search`, 11,636 calls), took **136 ms typically and 224 ms at the 95th percentile** (306 ms at the 99th, 729 ms worst). The TV feed took 138 ms typically and 264 ms at the 95th percentile. The slowest route was the Registration board refresh: 314 ms typically, 483 ms at the 95th percentile, 895 ms worst. A request ran about **20 database queries** (402,077 queries for 20,344 requests); each query took well under a millisecond, so the time is PHP, not the database.
The database stayed idle: at most 8 connections open (average 3), at most 3 busy at once, at most 42% of one core, about 180 MB.

## What it ran on (a cloud container is not the pilot server)

- One container host: **4 vCPU, 16 GB RAM**; the load driver, the PHP server and PostgreSQL all shared it, so every figure is pessimistic against a dedicated server.
- PostgreSQL **18.6** in Docker, CI-equivalent settings (`max_locks_per_transaction = 1024`; defaults otherwise: `max_connections = 100`, `shared_buffers = 128 MB`) plus `pg_stat_statements`.
- PHP 8.4.27 `artisan serve` with **8 worker processes**, OPcache on (no timestamp checks), `config:cache` and `route:cache`, `APP_DEBUG=false`, file sessions. This stands in for php-fpm; the real server, its worker count and its session store are not known.
- Without OPcache and the config and route caches the same load was about three times slower (typical 300 to 500 ms), so the pilot server must run with them.

## What the load did

Seed: `docs/reports/b3-soak/seed.php` (7 extra doctors, 17 extra CAs, 8 rooms, 100 entries: 96 waiting, 4 serving, 5 not-ready flags). Driver: `soak.mjs`, intervals copied from the Vue pages.
- 8 doctors: own Queue board every 3 s, readiness and treatment alerts every 10 s, waiting counts every 15 s, one Call next try a minute.
- 8 CAs on the Queue board and 2 supervisors and the Director: board every 3 s, readiness alerts every 10 s.
- 10 CAs on the Registration board every 10 s.
- 3 TV accounts: feed every 5 s.
- 1 Finance Officer logged in and idle.

## Per route (milliseconds)

| Route | Calls | Typical | 95th | 99th | Worst |
|---|---|---|---|---|---|
| POST queue/search | 11,636 | 136 | 224 | 306 | 729 |
| GET queue/readiness-updates | 3,423 | 142 | 252 | 350 | 519 |
| GET queue/treatment-updates | 1,444 | 142 | 238 | 321 | 445 |
| GET queue-display/feed | 1,080 | 138 | 264 | 357 | 480 |
| GET queue/waiting-counts | 959 | 144 | 260 | 368 | 583 |
| POST registration/search | 1,802 | 314 | 483 | 640 | 895 |

All responses were 200. Raw numbers: `result.json`; five-second samples of connections, locks, CPU and memory: `monitor.csv`.

## Limits of this test

- **No Call next happened in the 30 minutes** (no doctor had a free room by then, because an aborted first attempt at the soak had already used the idle doctors' calls). Call next under contention is covered by the B2 races, not here.
- No registrations, completions or other writes ran during the soak; the load is read polling.
- Queries per request are the average over all routes, not measured per route.
- The Finance Officer account was idle; a real day adds Dispensary, Billing and other pages that were not polled.
- Sessions were file-based and the Node driver does not run the browser's JavaScript, so page weight and rendering are not measured.
- The first attempt at this soak was cut short when the cloud container's Docker daemon stopped; the run reported here is the complete second one.
- No heavy route needed a fix, so no follow-up PR is proposed. If the Registration board is slow on real hardware, it is the first one to look at.
