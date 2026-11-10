import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-11: screen-reader fixes found by scanning the QR status page, the QR form and the QR intake screens.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const status = read('resources/js/pages/PublicCheckIn/Status.vue');
const form = read('resources/js/pages/PublicCheckIn/Show.vue');
const table = read('resources/js/components/registration/QrIntakeTable.vue');
const picker = read('resources/js/components/visit/VisitReasonPicker.vue');

test('the Bahasa Melayu public check-in pages declare their language', () => {
    assert.match(status, /<main\s+class="[^"]+"\s+lang="ms"/);
    assert.match(form, /<main\s+class="[^"]+"\s+lang="ms"/);
});

test('the progress steps expose the current step and the finished ones', () => {
    assert.match(
        status,
        /:aria-current="i === currentStep \? 'step' : undefined"/,
    );
    assert.match(status, /v-if="i < currentStep" class="sr-only"/);
});

test('each branch map link names its branch and says it opens a new tab', () => {
    assert.match(
        status,
        /:aria-label="`Buka peta \$\{branch\.name\} \(tab baharu\)`"/,
    );
});

test('every Verify link on the QR intake list names the intake it opens', () => {
    const links = table.match(/>Verify<\/Link/g);

    assert.equal(links?.length, 4);
    assert.equal(table.match(/:aria-label="verifyLabel\(item\)"/g)?.length, 4);
    assert.match(
        table,
        /return `Verify \$\{item\.summary\?\.name \?\? 'intake'\}`/,
    );
});

test('the Visit Reason search points aria-controls at its list only while the list exists', () => {
    assert.match(
        picker,
        /:aria-controls="listboxVisible \? listboxId : undefined"/,
    );
    assert.match(picker, /v-if="listboxVisible"/);
    assert.doesNotMatch(picker, /:aria-controls="listboxId"/);
});
