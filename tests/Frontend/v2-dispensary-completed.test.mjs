import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Completed Dispensary page (owner, 2026-11-15). Look only: same props and the Classic returns section.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Completed.vue');
const v2 = read('resources/js/pages/Dispensary/V2/Completed.vue');
const props = (source) =>
    source.slice(
        source.indexOf('defineProps<{'),
        source.indexOf('}>();', source.indexOf('defineProps<{')),
    );
const template = (source) => source.slice(source.indexOf('<template>'));

test('V2 Completed is registered and Classic does not import anything from V2', () => {
    assert.match(read('config/v2-pages.php'), /'Dispensary\/Completed',/);
    assert.ok(!/\/V2\//.test(classic));
});

test("V2 takes exactly Classic's props, and keeps the same links and the returns section", () => {
    assert.equal(props(v2), props(classic));

    for (const marker of [
        'href="/registration"',
        ':href="dispensary.billingUrl"',
        ':href="dispensary.labelUrl"',
        'target="_blank"',
        '<MedicineReturnsSection',
        'v-if="returns && returns.lines.length"',
    ]) {
        assert.ok(template(classic).includes(marker), `Classic ${marker}`);
        assert.ok(template(v2).includes(marker), `V2 ${marker}`);
    }

    assert.match(
        v2,
        /from '@\/pages\/Dispensary\/Partials\/MedicineReturnsSection\.vue'/,
    );
});

test('one filled button: Continue to Billing; Print current labels and Return to Registration are outline', () => {
    assert.equal(
        (template(v2).match(/:class="filledButton"/g) ?? []).length,
        1,
    );
    assert.match(
        template(v2),
        /:href="dispensary\.billingUrl"\s+:class="filledButton"/,
    );
    assert.equal(
        (template(v2).match(/:class="outlineButton"/g) ?? []).length,
        2,
    );
});

test('the page says it is read only, and shows the patient and when it was completed', () => {
    assert.match(template(v2), /This case is read only\./);
    assert.match(template(v2), /data-testid="completed-patient"/);
    assert.match(template(v2), /Completed \{\{ dispensary\.completedAt \}\}/);
    assert.match(v2, /\{ title: 'Dispensary completed' \}/);
});
