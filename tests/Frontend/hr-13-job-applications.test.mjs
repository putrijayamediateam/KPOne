import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-13 job applications (HR-PLAN 15.2; the owner's "start the HR-13 build", 2026-11-07).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    apply: 'resources/js/pages/Careers/Apply.vue',
    thanks: 'resources/js/pages/Careers/Thanks.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the public form, the thank-you page and the HR page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the public form sends the signed token, the hidden field and a PDF, and keeps nothing in the browser', () => {
    assert.match(source.apply, /form_token: props\.formToken/);
    assert.match(source.apply, /forceFormData: true/);
    assert.match(source.apply, /form\.reset\('cv'\)/);
    assert.match(template('apply'), /accept="application\/pdf,\.pdf"/);
    // The shared picker, labelled in both languages (Daniel, HR-13).
    assert.match(template('apply'), /<FileChooseButton/);
    assert.match(template('apply'), /label="Choose PDF \/ Pilih PDF"/);
    // The honeypot is hidden from sight and from screen readers, and skipped by the keyboard.
    assert.match(template('apply'), /class="sr-only" aria-hidden="true"/);
    assert.match(template('apply'), /tabindex="-1"/);
    assert.match(
        template('apply'),
        /Notice version \/ Versi notis\s+\{\{ privacyNoticeVersion \}\}/,
    );
    assert.match(template('apply'), /never\s+used\s+for\s+marketing/);
    assert.doesNotMatch(source.apply, /localStorage|sessionStorage|indexedDB/);
    // The thank-you page shows nothing that was sent: its only value is the clinic name.
    assert.deepEqual(
        [...template('thanks').matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)].map(
            (m) => m[1],
        ),
        ['clinicName'],
    );
});

test('applicants see English and Bahasa Melayu side by side (owner, 2026-11-07)', () => {
    for (const ms of [
        'Mohon jawatan',
        'Nama penuh',
        'Nombor telefon',
        'Notis privasi',
        'Hantar permohonan',
    ]) {
        assert.match(source.apply, new RegExp(ms));
    }

    assert.match(template('apply'), /lang="ms"/);
    assert.match(template('thanks'), /Terima kasih kerana memohon/);
});

test('the layout serves the careers pages without the staff shell', () => {
    const app = read('resources/js/app.ts');
    assert.match(
        app,
        /case name\.startsWith\('PublicCheckIn\/'\):\s*case name\.startsWith\('Careers\/'\):\s*return PublicCheckInLayout;/,
    );
});

test('the HR page records each CV open on the server and asks before a closing step', () => {
    assert.match(template('hr'), /\/hr\/applications\/\$\{viewing\.id\}\/cv/);
    assert.match(template('hr'), /Each open of a CV is recorded\./);
    assert.match(
        source.hr,
        /\['offered', 'not_taken_forward', 'withdrawn'\]\.includes\(to\)/,
    );
    assert.match(template('hr'), /A closed application cannot be moved again/);
    assert.match(
        template('hr'),
        /KPOne\s+ships no number\. Leave it empty to keep applications\./,
    );
    assert.match(
        template('hr'),
        /or from when it was sent if nobody closed\s+it/,
    );
    assert.match(
        template('hr'),
        /If the offer is declined, you can still mark it Withdrawn\./,
    );
    assert.match(template('hr'), /The public form is <strong>off<\/strong>/);
    // Off until a retention period is saved, because the notice promises deletion (Daniel, HR-13).
    assert.match(
        template('hr'),
        /applications\.form\.state === 'needs_retention'/,
    );
    assert.match(
        template('hr'),
        /until you set how long\s+applications are kept/,
    );
});
