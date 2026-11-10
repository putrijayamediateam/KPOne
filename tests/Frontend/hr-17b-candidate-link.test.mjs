import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17b candidate private link, hiring messages and "Needs you" (HR-PLAN 19.5 and 19.9).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    status: 'resources/js/pages/Careers/Status.vue',
    thanks: 'resources/js/pages/Careers/Thanks.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the three pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the status page takes the token from the fragment once, clears it and keeps nothing in the browser', () => {
    const s = source.status;
    assert.match(s, /window\.location\.hash\.slice\(1\)/);
    assert.match(s, /\^\[A-Za-z0-9_-\]\{43\}\$/);
    // The address bar is cleared before the token is sent.
    assert.ok(
        s.indexOf('history.replaceState') <
            s.indexOf("'/careers/status/exchange'"),
    );
    assert.doesNotMatch(
        s,
        /localStorage|sessionStorage|indexedDB|document\.cookie/,
    );
    // Never the candidate's own values or HR's working notes.
    assert.doesNotMatch(
        template('status'),
        /rating|note|checklist|\.name\b|\.email\b|\.phone\b/,
    );
});

test('withdrawing asks first, in both languages', () => {
    const t = template('status');
    assert.match(t, /data-test="status-withdraw"/);
    assert.match(t, /data-test="status-withdraw-confirm"/);
    assert.match(t, /Withdraw your application\? This cannot be\s+undone\./);
    assert.match(t, /Tarik balik permohonan anda\?/);
    assert.match(t, /min-h-11/);
});

test('the thank-you page shows the link once with a copy button that falls back to selecting it', () => {
    const s = source.thanks;
    assert.match(s, /v-if="statusLink"/);
    assert.match(s, /navigator\.clipboard\.writeText/);
    assert.match(s, /linkBox\.value\?\.select\(\)/);
    assert.match(s, /It is shown\s+only now\./);
    assert.match(s, /Ia\s+ditunjukkan sekarang sahaja\./);
});

test('HR sees Needs you, each message with its outcome, and the auto-reject date', () => {
    const t = template('hr');

    for (const id of [
        'needs-you',
        'needs-you-no-working-days',
        'application-messages',
        'application-auto-reject',
        'hiring-email-state',
        'applications-focus',
    ]) {
        assert.match(t, new RegExp(`data-test="${id}"`), id);
    }

    assert.match(t, /m\.statusLabel/);
    assert.match(source.hr, /focus: row\.id/);
});

test('Daniel: the invalid link is not a dead end, and the withdraw confirm manages focus', () => {
    const t = template('status');
    assert.match(t, /data-test="status-apply"/);
    assert.match(t, /Apply for a job \/ Mohon jawatan/);
    assert.match(t, /@keydown\.esc="keepIt"/);
    assert.match(t, /tabindex="-1"/);
    assert.match(source.status, /confirmPanel\.value\?\.focus\(\)/);
    assert.match(source.status, /withdrawButton\.value\?\.focus\(\)/);
});

test('Daniel: Needs you lists new applications as one line, and messages show a chevron and a failed outcome in words', () => {
    const t = template('hr');
    assert.match(t, /data-test="needs-you-new-show"/);
    assert.match(t, /filter\(\{ status: 'new' \}\)/);
    assert.doesNotMatch(t, /lg:max-w-3xl"\s+data-test="needs-you"/);
    assert.match(t, /<ChevronRight/);
    assert.match(t, /group-open:rotate-90/);
    assert.match(t, /viewing\.autoRejectSoon/);
    assert.match(t, /m\.status === 'failed'/);
});
