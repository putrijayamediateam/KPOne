import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PublicCheckIn/Show.vue');
const page = read('resources/js/pages/PublicCheckIn/V2/Show.vue');
const tight = (s) => s.replace(/\s+/g, '');
const script = (s) =>
    s.split('<script setup lang="ts">')[1].split('</script>')[0];
const template = (s) => s.split('</script>')[1];

test('V2 show keeps the Classic script exactly (token exchange, steps, checks, submit, countdown)', () => {
    assert.equal(tight(script(page)), tight(script(classic)));
});

test('V2 show keeps every Malay string of the Classic template', () => {
    const strings = [
        'Daftar untuk lawatan anda',
        'Mula Daftar',
        'Baca notis privasi',
        'Jika anda mengalami sesak nafas teruk',
        'Siapa yang mengisi borang?',
        'Saya pesakit',
        'Saya penjaga',
        'Maklumat pesakit',
        'Nama penuh *',
        'Tarikh lahir *',
        'Jenis bayaran *',
        'Semak persetujuan',
        'Semak maklumat anda',
        'Persetujuan & hantar',
        'Hantar Maklumat',
        'Menghantar…',
        'Notis privasi — draf UAT',
        'Sesi pendaftaran akan tamat tidak lama lagi.',
        'Masa sesi mungkin telah tamat.',
        'Beli ubat sahaja',
        'Jumpa doktor',
        'Saya mengesahkan bahawa saya diberi kuasa',
        'Kembali',
        'Ubah',
    ];
    const flat = (s) => tight(template(s));

    for (const text of strings) {
        assert.ok(flat(classic).includes(tight(text)), `Classic lacks ${text}`);
        assert.ok(flat(page).includes(tight(text)), `V2 lacks ${text}`);
    }
});

test('V2 show uses theme tokens only: no palette colours', () => {
    assert.doesNotMatch(
        template(page),
        /\b(?:bg|text|border|ring|divide)-(?:pink|zinc|red|amber|emerald|slate|gray)-\d/,
    );
    assert.doesNotMatch(template(page), /\bdark:/);
});

test('V2 show page shell: lang, no-referrer, language and step announcer', () => {
    const t = template(page);
    assert.match(t, /lang="ms"/);
    assert.match(t, /name="referrer" content="no-referrer"/);
    assert.match(t, /data-test="step-announcer"/);
    assert.match(t, /role="status"/);
});

test('V2 show has no staff shell, storage, cookies or external URLs', () => {
    assert.doesNotMatch(page, /localStorage|sessionStorage|document\.cookie/);
    assert.doesNotMatch(page, /https?:\/\//);
    assert.doesNotMatch(page, /V2Layout|useAppearance|usePage\(/);
});

test('V2 show keeps 44 px controls and one filled button per step', () => {
    const t = template(page);
    assert.ok(
        !/min-h-10\b(?!.*Ubah)/.test(t.replace(/min-h-10 shrink-0/g, '')),
    );
    assert.match(t, /aria-label="Kembali"/);
    assert.match(t, /bg-primary/);
});
