// B3 polling soak driver (Node 20+, no dependencies). Logs in 30 staff and 3 TV accounts over HTTP, then polls like the real
// screens do (intervals taken from the Vue pages) with a few actions a minute, and writes per-route latency to a JSON file.
//   BASE=http://127.0.0.1:8830 BRANCH_ID=1 DURATION_MIN=30 OUT=soak-result.json node soak.mjs [--probe]
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8830';
const BRANCH = Number(process.env.BRANCH_ID ?? 1);
const DURATION_MS = Number(process.env.DURATION_MIN ?? 30) * 60_000;
const OUT = process.env.OUT ?? 'soak-result.json';
const PASSWORD = 'KPOne-Presentation-Only-2026!';
const PROBE = process.argv.includes('--probe');

const users = [
    ...Array.from({ length: 8 }, (_, i) => ({
        email:
            i === 0
                ? 'demo.resident_doctor@kpone.test'
                : `soak.doctor${i}@kpone.test`,
        kind: 'doctor',
    })),
    ...Array.from({ length: 18 }, (_, i) => ({
        email: i === 0 ? 'demo.ca@kpone.test' : `soak.ca${i}@kpone.test`,
        kind: i < 10 ? 'ca-registration' : 'ca-queue',
    })),
    { email: 'demo.ca_supervisor@kpone.test', kind: 'supervisor' },
    { email: 'demo.ca_supervisor2@kpone.test', kind: 'supervisor' },
    { email: 'demo.director@kpone.test', kind: 'supervisor' },
    { email: 'demo.finance_officer@kpone.test', kind: 'idle' },
    ...[1, 2, 3].map((i) => ({ email: `soak.tv${i}@kpone.test`, kind: 'tv' })),
];

const samples = []; // {route, ms, status}
const failures = [];
class Session {
    constructor(user) {
        this.user = user;
        this.cookies = new Map();
    }
    cookieHeader() {
        return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    }
    store(res) {
        for (const line of res.headers.getSetCookie?.() ?? []) {
            const [pair] = line.split(';');
            const i = pair.indexOf('=');
            this.cookies.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
        }
    }
    xsrf() {
        return decodeURIComponent(this.cookies.get('XSRF-TOKEN') ?? '');
    }
    async req(route, method, path, body, record = true) {
        const headers = {
            Accept: 'application/json',
            Cookie: this.cookieHeader(),
        };

        if (method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            headers['X-XSRF-TOKEN'] = this.xsrf();
            headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        const t0 = performance.now();
        let res;
        let status = 0;
        let text = '';

        try {
            res = await fetch(BASE + path, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
                redirect: 'manual',
            });
            status = res.status;
            this.store(res);
            text = await res.text();
        } catch (e) {
            status = -1;
            text = String(e);
        }

        const ms = performance.now() - t0;

        if (record) {
            samples.push({ route, ms, status, t: Date.now() });
        }

        let json = null;

        try {
            json = JSON.parse(text);
        } catch {
            /* html or empty */
        }

        return { status, json, text, ms };
    }
    async login() {
        const page = await this.req(
            'login-page',
            'GET',
            '/login',
            undefined,
            false,
        );

        if (page.status !== 200) {
            throw new Error(`login page ${page.status}`);
        }

        const r = await this.req(
            'login',
            'POST',
            '/login',
            {
                email: this.user.email,
                password: PASSWORD,
                remember: this.user.kind === 'tv',
            },
            false,
        );

        if (![200, 204, 302, 303].includes(r.status)) {
            throw new Error(
                `login ${this.user.email} -> ${r.status} ${r.text.slice(0, 120)}`,
            );
        }
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jitter = (ms) => ms * (0.85 + Math.random() * 0.3);
const loop = async (until, everyMs, fn) => {
    await sleep(Math.random() * everyMs);

    while (Date.now() < until) {
        const t = Date.now();

        try {
            await fn();
        } catch (e) {
            failures.push(String(e));
        }

        await sleep(Math.max(50, jitter(everyMs) - (Date.now() - t)));
    }
};
const q = (o) => new URLSearchParams(o).toString();

const sessions = [];

for (const user of users) {
    const s = new Session(user);
    await s.login();
    sessions.push(s);
}

console.log(`logged in ${sessions.length} sessions`);

if (PROBE) {
    const doc = sessions[0];
    const search = await doc.req('probe', 'POST', '/queue/search', {});
    console.log(
        'queue/search',
        search.status,
        Object.keys(search.json ?? {}),
        JSON.stringify(search.json?.callNext)?.slice(0, 300),
    );
    console.log(
        'row keys',
        Object.keys((search.json?.rows ?? search.json?.waiting ?? [])[0] ?? {}),
    );

    for (const [name, path] of [
        ['call-next', `/queue/call-next?expected_branch_id=${BRANCH}`],
        [
            'readiness',
            `/queue/readiness-updates?${q({ expected_branch_id: BRANCH, details: 0 })}`,
        ],
        [
            'treatment',
            `/queue/treatment-updates?${q({ expected_branch_id: BRANCH, details: 0 })}`,
        ],
        [
            'counts',
            `/queue/waiting-counts?${q({ expected_branch_id: BRANCH, include_readiness: 1, scope: 'own' })}`,
        ],
    ]) {
        const r = await doc.req(name, 'GET', path);
        console.log(name, r.status, r.text.slice(0, 160));
    }

    const ca = sessions[8];
    const reg = await ca.req('probe', 'POST', '/registration/search', {
        page: 1,
    });
    console.log('registration/search', reg.status, Object.keys(reg.json ?? {}));
    const tv = sessions[sessions.length - 1];
    const feed = await tv.req('probe', 'GET', '/queue-display/feed');
    console.log('feed', feed.status, feed.text.slice(0, 200));
    process.exit(0);
}

const until = Date.now() + DURATION_MS;
const doctors = sessions.filter((s) => s.user.kind === 'doctor');
const tasks = [];

for (const s of sessions) {
    switch (s.user.kind) {
        case 'doctor': // doctor's own queue board (3 s), readiness and treatment alerts (10 s), waiting counts (15 s), Call next block (10 s)
            tasks.push(
                loop(until, 3000, () =>
                    s.req('POST queue/search', 'POST', '/queue/search', {}),
                ),
            );
            tasks.push(
                loop(until, 10000, () =>
                    s.req(
                        'GET queue/readiness-updates',
                        'GET',
                        `/queue/readiness-updates?${q({ expected_branch_id: BRANCH, details: 0 })}`,
                    ),
                ),
            );
            tasks.push(
                loop(until, 10000, () =>
                    s.req(
                        'GET queue/treatment-updates',
                        'GET',
                        `/queue/treatment-updates?${q({ expected_branch_id: BRANCH, details: 0 })}`,
                    ),
                ),
            );
            tasks.push(
                loop(until, 15000, () =>
                    s.req(
                        'GET queue/waiting-counts',
                        'GET',
                        `/queue/waiting-counts?${q({ expected_branch_id: BRANCH, include_readiness: 1, scope: 'own' })}`,
                    ),
                ),
            );
            break;
        case 'ca-registration':
            tasks.push(
                loop(until, 10000, () =>
                    s.req(
                        'POST registration/search',
                        'POST',
                        '/registration/search',
                        { page: 1 },
                    ),
                ),
            );
            break;
        case 'ca-queue':
        case 'supervisor':
            tasks.push(
                loop(until, 3000, () =>
                    s.req('POST queue/search', 'POST', '/queue/search', {}),
                ),
            );
            tasks.push(
                loop(until, 10000, () =>
                    s.req(
                        'GET queue/readiness-updates',
                        'GET',
                        `/queue/readiness-updates?${q({ expected_branch_id: BRANCH, details: 0 })}`,
                    ),
                ),
            );
            break;
        case 'tv':
            tasks.push(
                loop(until, 5000, () =>
                    s.req(
                        'GET queue-display/feed',
                        'GET',
                        '/queue-display/feed',
                    ),
                ),
            );
            break;
    }
}

// Actions: every doctor tries Call next about once a minute (the confirmed candidate comes from their own board).
for (const s of doctors) {
    tasks.push(
        loop(until, 60000, async () => {
            const board = await s.req(
                'POST queue/search',
                'POST',
                '/queue/search',
                {},
            );
            const c = board.json?.callNext?.candidate;

            if (!c) {
                return;
            }

            await s.req('POST queue/call-next', 'POST', '/queue/call-next', {
                expected_branch_id: BRANCH,
                doctor_user_id: board.json.callNext.doctorUserId,
                visit_number: c.visitNumber,
                visit_lock_version: c.visitLockVersion,
                queue_lock_version: c.queueLockVersion,
            });
        }),
    );
}

await Promise.all(tasks);

const by = {};

for (const s of samples) {
    (by[s.route] ??= []).push(s);
}

const pct = (a, p) => {
    const b = [...a].sort((x, y) => x - y);

    return b[Math.min(b.length - 1, Math.floor((p / 100) * b.length))];
};
const summary = Object.fromEntries(
    Object.entries(by).map(([route, a]) => {
        const ms = a.map((x) => x.ms);
        const statuses = {};

        for (const x of a) {
            statuses[x.status] = (statuses[x.status] ?? 0) + 1;
        }

        return [
            route,
            {
                count: a.length,
                p50: pct(ms, 50),
                p95: pct(ms, 95),
                p99: pct(ms, 99),
                max: Math.max(...ms),
                statuses,
            },
        ];
    }),
);
writeFileSync(
    OUT,
    JSON.stringify(
        {
            startedAt: new Date(until - DURATION_MS).toISOString(),
            durationMin: DURATION_MS / 60000,
            sessions: sessions.length,
            totalRequests: samples.length,
            summary,
            failures: failures.slice(0, 20),
        },
        null,
        2,
    ),
);
console.log(JSON.stringify(summary, null, 1));
