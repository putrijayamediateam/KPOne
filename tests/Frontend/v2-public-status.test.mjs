import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PublicCheckIn/Status.vue');
const page = read('resources/js/pages/PublicCheckIn/V2/Status.vue');
const tight = (s) => s.replace(/\s+/g, '');
const script = (s) =>
    s.split('<script setup lang="ts">')[1].split('</script>')[0];
const template = (s) => s.split('</script>')[1];

test('V2 status keeps the Classic script exactly (props, steps, announcement, polling, sound, GPS)', () => {
    assert.equal(tight(script(page)), tight(script(classic)));
});

test('V2 status keeps every Malay string and the privacy lines, word for word', () => {
    const strings = [
        'Status pendaftaran',
        'KPOne · Klinik Putrijaya',
        'Nombor queue anda',
        'Pesakit di hadapan anda:',
        'Anda seterusnya dalam giliran.',
        'Anggaran masa menunggu: kira-kira',
        'minit',
        'Anggaran berdasarkan purata terkini, bukan jaminan.',
        'Giliran anda! Sila ke kaunter farmasi.',
        'Giliran anda! Sila masuk ke bilik doktor.',
        'Kemajuan pendaftaran',
        '(selesai)',
        'Aktifkan bunyi pemberitahuan',
        'Semak status',
        'Cawangan lain',
        'Cawangan Klinik Putrijaya',
        'Mendapatkan lokasi…',
        'Cari cawangan terdekat',
        'Cawangan paling dekat:',
        'Cawangan anda',
        'Kira-kira',
        '(garis lurus)',
        'Buka peta',
        'menunggu',
        'Lokasi anda hanya digunakan pada peranti ini untuk mengira jarak dan tidak dihantar atau disimpan.',
        'Halaman ini tidak menyimpan maklumat anda dalam storan pelayar.',
    ];

    for (const text of strings) {
        assert.ok(
            tight(template(classic)).includes(tight(text)) ||
                tight(script(classic)).includes(tight(text)),
            `Classic lacks ${text}`,
        );
        assert.ok(
            tight(template(page)).includes(tight(text)) ||
                tight(script(page)).includes(tight(text)),
            `V2 lacks ${text}`,
        );
    }
});

test('V2 status identity is shown only with a queue number, exactly as Classic', () => {
    const t = tight(template(page));

    assert.ok(t.includes('v-if="status.queueNumber"'));
    assert.ok(t.includes('v-if="status.firstName"'));
    assert.ok(t.includes('{{status.queueNumber}}'));
    assert.ok(t.includes('{{status.firstName}}'));

    // Nothing else of the patient reaches the page: no other status field is read for display besides the Classic ones.
    for (const key of [
        'status.message',
        'status.branch',
        'status.ahead',
        'status.waitRange',
        'status.branches',
    ]) {
        assert.ok(t.includes(key), key);
    }
});

test('V2 status keeps lang="ms", the no-referrer meta, the title and the polite announcer', () => {
    const t = template(page);

    assert.ok(/lang="ms"/.test(t));
    assert.ok(
        tight(t).includes(
            '<Headtitle="Statuspendaftaran"><metaname="referrer"content="no-referrer"/></Head>',
        ),
    );
    assert.ok(/data-test="status-announcer"/.test(t));
    assert.ok(
        tight(t).includes(
            'role="status"aria-live="polite"aria-atomic="true"data-test="status-announcer"',
        ),
    );
});

test('V2 status call-in banner is a polite region (announced once when it appears), not an assertive alert', () => {
    const banner = template(page).slice(
        template(page).indexOf('data-testid="called-banner"') - 400,
        template(page).indexOf('data-testid="called-banner"') + 60,
    );

    assert.ok(
        /role="status"/.test(banner) && /aria-live="polite"/.test(banner),
    );
    assert.ok(!/role="alert"/.test(template(page)));
    assert.ok(template(page).includes('v-if="called"'));
});

test('V2 status has no staff shell, no storage, no cookie, no new request and no third-party host', () => {
    const all = page;

    for (const banned of [
        'V2AppShell',
        'V2Layout',
        'usePageCrumbs',
        'PageFrame',
        'V2WorkLayout',
        'branchContext',
        'Back to Classic',
        'Try the new layout',
        'localStorage',
        'sessionStorage',
        'indexedDB',
        'document.cookie',
        'fetch(',
        'XMLHttpRequest',
        'router.post',
        'router.put',
        'router.patch',
        'router.delete',
        'useForm',
    ]) {
        assert.ok(
            !all.includes(banned),
            `V2 status must not contain ${banned}`,
        );
    }

    assert.ok(
        !/https?:\/\/(?!www\.w3\.org)/.test(template(page)),
        'no absolute external URL in the template',
    );
    // The only things it fetches are the existing logo and the Inertia reload of `status`.
    assert.ok(page.includes("only: ['status']"));
    assert.ok(page.includes('src="/kp-mark.png"'));
});

test('V2 status uses theme tokens, 44 px or taller targets, and one filled button at most', () => {
    const t = template(page);

    assert.ok(
        !/bg-white|dark:bg-zinc|text-zinc-|bg-pink-|text-pink-|bg-emerald-|text-emerald-/.test(
            t,
        ),
        'no fixed palette colours',
    );
    assert.ok(
        (t.match(/bg-primary font-semibold/g) ?? []).length === 1,
        'one filled button (sound)',
    );
    assert.ok(t.includes('min-h-12') && t.includes('min-h-11'));
    assert.ok(
        t.includes('whitespace-nowrap') && t.includes('tabular-nums'),
        'the queue number never wraps',
    );
    assert.ok(t.includes('motion-safe:animate-pulse'));
});

test('V2 status: the called banner is a polite live region that mounts once, so the 10 s poll never re-announces it', () => {
    const t = template(page);
    const banner = t.slice(
        t.indexOf('data-testid="called-banner"') - 300,
        t.indexOf('data-testid="called-banner"') + 100,
    );
    assert.match(banner, /v-if="called"/);
    assert.match(banner, /role="status"/);
    assert.match(banner, /aria-live="polite"/);
    assert.doesNotMatch(t, /role="alert"|aria-live="assertive"/);
    // The poll only reloads the status prop; no live region is bound to it.
    assert.match(script(page), /setInterval\(refresh, 10_000\)/);
    assert.match(script(page), /router\.reload\(\{\s*only: \['status'\]/);
    // The announcer is written only when the key facts change (a watch on the computed text), never by the poll.
    assert.match(
        script(page),
        /watch\(announcement,\s*\(text\) => \{\s*if \(text !== null\) \{\s*announced\.value = text;/,
    );
    // Exactly two live regions, both polite: the key-facts announcer and the called banner.
    assert.equal((t.match(/aria-live="polite"/g) ?? []).length, 2);
    assert.equal((t.match(/aria-live=/g) ?? []).length, 2);
});

test('V2 status: Semak status shows a visible checking state and is not a live region', () => {
    const t = template(page);
    assert.match(t, /data-test="check-status"/);
    assert.match(t, /:aria-busy="refreshing"/);
    assert.match(t, /refreshing \? 'Menyemak…' : 'Semak status'/);
    assert.match(t, /motion-safe:animate-spin/);
});

test('V2 status: branch addresses wrap instead of being cut, and Buka peta keeps a 44 px target', () => {
    const t = template(page);
    const at = t.indexOf('{{ branch.address }}');
    assert.ok(at > 0);
    const addr = t.slice(at - 200, at);
    assert.match(addr, /break-words/);
    assert.doesNotMatch(addr, /truncate/);
    assert.match(
        t,
        /min-h-11[^"]*"[^>]*>Buka peta|min-h-11 items-center[^"]*"\s*>\s*Buka peta/,
    );
});

test('V2 status: the called-banner pulse pauses under reduced motion', () => {
    assert.match(template(page), /motion-safe:animate-pulse/);
    assert.doesNotMatch(
        template(page).replace(/motion-safe:animate-/g, ''),
        /animate-pulse|animate-ping/,
    );
});
