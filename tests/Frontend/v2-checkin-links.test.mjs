import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PublicCheckInLinks/Index.vue');
const page = read('resources/js/pages/PublicCheckInLinks/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');
const between = (s, from, to) => s.slice(s.indexOf(from), s.indexOf(to));

test('V2 keeps the Classic request, create, confirm, copy, download and print logic exactly', () => {
    for (const [from, to] of [
        ['const request = async (', 'const create = async'],
        ['const create = async', 'const confirmAction = async'],
        ['const confirmAction = async', 'const copyLink = async'],
        ['const copyLink = async', 'const downloadQr ='],
        ['const downloadQr =', 'const printQr ='],
        ['const printQr =', '</script>'],
    ]) {
        const a = tight(between(classic, from, to));
        assert.ok(a.length > 40, `classic chunk ${from}`);
        assert.ok(
            tight(page).includes(a),
            `V2 differs from Classic in: ${from}`,
        );
    }
});

test('V2 sends the same requests: same URLs, methods and body, branch id stays a number', () => {
    const p = tight(page);

    for (const piece of [
        "'/public-checkin-links','POST',form.data()",
        '`/public-checkin-links/${pending.value.link.publicId}`',
        "type==='rotate'?`${target}/rotate`:target",
        "type==='rotate'?'POST':'DELETE'",
        "only:['links','branches']",
        "'X-CSRF-TOKEN':csrfToken()",
    ]) {
        assert.ok(tight(classic).includes(piece), `Classic lacks ${piece}`);
        assert.ok(p.includes(piece), `V2 lacks ${piece}`);
    }

    assert.ok(p.includes('set:(value:string)=>(form.branch_id=Number(value))'));
    assert.ok(!/router\.(post|put|patch|delete)/.test(page));
});

test('V2 keeps every Classic wording string', () => {
    for (const text of [
        'QR check-in links',
        'Issue and revoke branch-bound public landing links. No Patient information is staged until staff review and confirmation.',
        'Active QR ready',
        'The link is encrypted at rest and remains available to authorised staff after refresh.',
        'Branch public patient intake QR code',
        'Generated locally. No URL or token is sent to an external QR service.',
        'Create branch link',
        'Each branch can have one active link. Use Rotate to replace an existing link.',
        'Create link',
        'Current active QR',
        'Viewing, copying, downloading, or printing does not rotate this link.',
        'This older QR cannot be shown again because it was created before secure storage was added. Rotate it to make a new QR. The old QR stops working, so print the new one.',
        'Historical link retained for audit. It cannot be used.',
        'No public check-in links have been issued.',
        'Rotate this check-in link?',
        'Revoke this check-in link?',
        'The current public URL will stop working immediately. The replacement QR will remain securely available after refresh.',
        'The public URL will stop working immediately. This action does not delete its audit history.',
        'Rotate link',
        'Revoke link',
        'The link could not be updated. Please try again.',
        'Copy link',
        'Download',
        'Print',
        'Active',
        'Expired',
        'Revoked',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 confirm sheet: revoke is the only danger tone, the failure message shows inside it and clears on the next one', () => {
    const p = flat(page);

    assert.ok(
        p.includes(
            ":tone=\"pending?.type === 'revoke' ? 'danger' : 'default'\"",
        ),
    );
    assert.ok(p.includes(':busy="actionProcessing"'));
    assert.ok(p.includes('v-if="actionError"') && p.includes('role="alert"'));
    assert.ok(
        tight(page).includes("watch(pending,()=>(actionError.value=''))"),
    );
});

test('V2 actions are 44 px targets that stack on phones; the QR image stays white; one filled button', () => {
    const t = tight(page);

    assert.ok(t.includes('h-11'));
    assert.ok(t.includes('max-sm:flex-col'));
    assert.ok(/bg-white/.test(page));
    assert.equal((page.match(/:class="primaryButton"/g) ?? []).length, 1);
});
