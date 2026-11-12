import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Dispensary/ReturnsToCheck.vue');
const section = read(
    'resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
);

test('DSP-7b: the Returns to check list is read only and links each row to its case', () => {
    assert.match(page, /<PageHeader title="Returns to check"/);
    assert.match(
        page,
        /This list\s+does not check anything\. You cannot check a return you recorded\./,
    );
    assert.match(page, /`\$\{row\.caseUrl\}#medicine-returns`/);
    assert.match(
        page,
        /Open case for \$\{row\.medicine\}, \$\{row\.visitNumber\}/,
    );
    assert.match(page, /title="No returns waiting"/);
    assert.match(page, /<StatusBadge status="waiting_check" \/>/);
    assert.match(
        page,
        /You recorded this\. Another CA Supervisor must check it\./,
    );
    // Nothing on the page writes: no form, post or router call other than paging.
    assert.doesNotMatch(
        page,
        /useForm|router\.post|router\.put|fetch\(|requestJson/,
    );
    assert.match(section, /id="medicine-returns"/);
});

test('DSP-7b: a table from 1024 px and cards with a 44 px Open case button below it', () => {
    assert.match(page, /<div class="hidden lg:block">/);
    assert.match(
        page,
        /class="space-y-3 lg:hidden" aria-label="Returns to check"/,
    );
    assert.match(page, /class="min-h-11 w-full justify-center"/);
    assert.doesNotMatch(
        page,
        /row\.note|note_encrypted/,
        'the Other note never reaches the list',
    );
});
