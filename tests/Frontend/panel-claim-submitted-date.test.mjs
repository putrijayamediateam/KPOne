import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// The Classic Panel claim page showed "Submitted on —": the server sends `submittedOn` as already formatted text
// ("6 Nov 2026, 11:49 AM"), and `formatDate` cannot read that text, so it printed its dash. The page now shows the
// text as sent, the same way it shows `createdAt`.

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/PanelClaims/Show.vue');
const service = read(
    'app/Domain/Visit/Billing/Services/PanelClaimDirectoryService.php',
);

test('the Classic claim page shows the submitted date text as sent', () => {
    assert.ok(
        !/formatDate\(\s*claim\.submittedOn\s*\)/.test(show),
        'submittedOn is already formatted text: formatDate cannot read it',
    );
    assert.match(show, /Submitted on \{\{ claim\.submittedOn \}\}/);
});

test('the server sends submittedOn already formatted (the reason formatDate cannot be used)', () => {
    assert.match(
        service,
        /'submittedOn' => is_array\(\$submittedEvent\) \? \$submittedEvent\['occurredAt'\] : null/,
    );
    assert.match(service, /'occurredAt' => [^\n]*format\(/);
});
