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
    // The only difference is the import of the template-level leave warning (no logic is added to the script).
    const withoutImport = script(page).replace(
        /import PublicLeaveWarning from '[^']+';/,
        '',
    );
    assert.equal(tight(withoutImport), tight(script(classic)));
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

const steps = () => {
    const t = template(page);
    const at = (needle) => t.indexOf(needle);

    return {
        t,
        bars: [...t.matchAll(/data-test="step-bar"/g)].length,
        at,
    };
};

test('V2 show (a): every step has one sticky bar with exactly one filled button; Kembali is outline', () => {
    const { t, bars } = steps();
    assert.equal(bars, 5);
    const sections = t.split('data-test="step-bar"').slice(1);

    for (const chunk of sections) {
        const bar = chunk.split('</div>')[0] + chunk.split('</div>')[1];
        assert.equal((bar.match(/bg-primary /g) ?? []).length, 1);
    }

    assert.match(t, /Seterusnya/);
    assert.match(t, /Hantar Maklumat/);
});

test('V2 show (b): the bar is sticky, safe-area aware, and the page uses dynamic viewport height; no ancestor creates a scroll box', () => {
    const t = template(page);
    assert.match(t, /sticky bottom-0/);
    assert.match(t, /env\(safe-area-inset-bottom\)/);
    assert.match(t, /min-h-dvh/);
    assert.doesNotMatch(t, /min-h-svh/);
    // overflow-hidden on the card would stop the bar sticking; overflow-clip does not.
    assert.match(t, /overflow-clip rounded-3xl/);
    assert.doesNotMatch(t, /overflow-hidden rounded-3xl/);
});

test('V2 show (c): the countdown is a silent timer; only the 3-minute warning and the lapse are live regions', () => {
    const t = template(page);
    const timer = t.slice(
        t.indexOf('role="timer"') - 200,
        t.indexOf('role="timer"') + 300,
    );
    assert.doesNotMatch(timer, /aria-live/);
    assert.match(
        t,
        /v-if="sessionWarning"[^>]*\s+role="status"|v-if="sessionWarning"\s+class="[^"]*"\s+role="status"/,
    );
    assert.match(t, /v-if="sessionLapsed"\s+class="[^"]*"\s+role="status"/);
    assert.equal((t.match(/role="timer"/g) ?? []).length, 1);
});

test('V2 show (d): Back keeps typed values (the script only changes the step) and leaving with typed answers asks the browser', () => {
    const s = script(page);
    assert.match(
        s,
        /const back = \(\) => \{\s*errors\.value = \{\};\s*step\.value = Math\.max\(1, step\.value - 1\);\s*\};/,
    );
    assert.doesNotMatch(s, /Object\.assign\(form|reset\(/);
    assert.match(template(page), /<PublicLeaveWarning/);
    const guard = read(
        'resources/js/components/public-v2/PublicLeaveWarning.vue',
    );
    assert.match(guard, /beforeunload/);
    assert.match(guard, /removeEventListener\('beforeunload'/);
    assert.doesNotMatch(guard, /localStorage|sessionStorage|fetch\(/);
});

test('V2 show (e): Hantar is busy and disabled while sending, cannot double submit, and a refused send shows its error beside the button', () => {
    const t = template(page);
    assert.match(
        t,
        /type="submit"[\s\S]*?:disabled="submitting"[\s\S]*?:aria-busy="submitting"/,
    );
    assert.match(t, /submitting \? 'Menghantar…' : 'Hantar Maklumat'/);
    assert.match(
        script(page),
        /if \(!session\.value \|\| submitting\.value\) \{\s*return;/,
    );
    assert.match(t, /data-test="submit-error"/);
    assert.match(t, /aria-describedby/);
    // The error beside the button is plain text; the page-top alert is the only live region for it.
    const near = t.slice(
        t.indexOf('data-test="submit-error"') - 150,
        t.indexOf('data-test="submit-error"') + 250,
    );
    assert.doesNotMatch(near, /role="alert"|aria-live/);
});

test('V2 show: at 320 the identity type sits above the number so all 12 digits show', () => {
    assert.match(
        template(page),
        /grid-cols-1 gap-2 min-\[400px\]:grid-cols-\[7rem_minmax\(0,1fr\)\]/,
    );
});

test('V2 show: every icon-only back button is named "Kembali" on the button itself, with the arrow hidden from screen readers', () => {
    const t = template(page);
    const backs =
        t.match(/<button[^>]*?@click="back"[\s\S]*?<\/button\s*>/g) ?? [];
    assert.equal(backs.length, 4);

    for (const b of backs) {
        assert.match(b, /aria-label="Kembali"/);
        assert.match(b, /<ArrowLeft[^>]*aria-hidden="true"/);
        assert.match(b, /min-h-12 min-w-12/);
    }
});

test('V2 show: the step announcer is polite, the error summary is a single alert, and the countdown stays silent', () => {
    const t = template(page);
    assert.match(
        t,
        /role="status"\s+aria-live="polite"\s+data-test="step-announcer"/,
    );
    const summary = t.slice(
        t.indexOf('v-if="generalError || validationSummary.length"'),
    );
    assert.match(summary.slice(0, 260), /role="alert"/);
    assert.equal(
        (
            t.match(
                /role="alert"\s+aria-live="assertive"|aria-live="assertive"/g,
            ) ?? []
        ).length,
        1,
    );
    assert.match(t, /sessionWarning/);
});

test('V2 show: focused fields clear the sticky bar (scroll margin) and the bar uses dvh and the safe area', () => {
    const t = template(page);
    assert.ok((t.match(/scroll-mb-32/g) ?? []).length >= 10);
    assert.match(t, /min-h-dvh/);
    assert.match(t, /pb-\[max\(1rem,env\(safe-area-inset-bottom\)\)\]/);
});

test('V2 show: both consent rows are whole-row labels at least 44 px tall, so the label text is clickable', () => {
    const t = template(page);
    const rows = [
        ...t.matchAll(
            /<label\s+class="flex min-h-12 items-start gap-3[^"]*"\s*>\s*<input\s+v-model="form\.(guardian_attestation|consent_confirmed)"\s+type="checkbox"/g,
        ),
    ];
    assert.equal(rows.length, 2);
});
