// Classic only, CO-9: the Classic half of v2-checkin-links, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PublicCheckInLinks/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const between = (s, from, to) => s.slice(s.indexOf(from), s.indexOf(to));

test('Classic QR check-in links keeps its request, create, confirm, copy, download and print functions', () => {
    for (const [from, to] of [
        ['const request = async (', 'const create = async'],
        ['const create = async', 'const confirmAction = async'],
        ['const confirmAction = async', 'const copyLink = async'],
        ['const copyLink = async', 'const downloadQr ='],
        ['const downloadQr =', 'const printQr ='],
        ['const printQr =', '</script>'],
    ]) {
        // Both markers must exist, in this order, with real code between them.
        assert.ok(classic.indexOf(from) >= 0, `classic lacks ${from}`);
        assert.ok(
            classic.indexOf(to) > classic.indexOf(from),
            `classic lacks ${to} after ${from}`,
        );
        assert.ok(
            tight(between(classic, from, to)).length > 40,
            `classic chunk ${from}`,
        );
    }
});

test('Classic QR check-in links sends its requests: URLs, methods, body, partial reload and CSRF header', () => {
    for (const piece of [
        "'/public-checkin-links','POST',form.data()",
        '`/public-checkin-links/${pending.value.link.publicId}`',
        "type==='rotate'?`${target}/rotate`:target",
        "type==='rotate'?'POST':'DELETE'",
        "only:['links','branches']",
        "'X-CSRF-TOKEN':csrfToken()",
    ]) {
        assert.ok(tight(classic).includes(piece), `Classic lacks ${piece}`);
    }
});

test('Classic QR check-in links keeps every wording string', () => {
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
    }
});
