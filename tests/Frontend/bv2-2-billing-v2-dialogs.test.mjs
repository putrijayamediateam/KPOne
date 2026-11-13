import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Billing/V2/Show.vue');

const script = (src) =>
    src.slice(
        src.indexOf('<script setup lang="ts">'),
        src.indexOf('</script>'),
    );
// The logic after the imports and the BV2-2 doc comment: whitespace and trailing commas do not count.
const body = (src) =>
    script(src)
        .replace(/^import[\s\S]*?;$/gm, '')
        .replace(/\/\*\*[\s\S]*?\*\//g, '')
        .replace(/<script setup lang="ts">/, '')
        .replace(/,(\s*[)\]}])/g, '$1')
        .replace(/\s+/g, '');

for (const [name, classicPath, v2Path] of [
    [
        'adjustment',
        'resources/js/components/billing/InvoiceAdjustmentDialog.vue',
        'resources/js/components/billing/v2/InvoiceAdjustmentDialogV2.vue',
    ],
    [
        'completion',
        'resources/js/components/billing/BillingCompletionDialog.vue',
        'resources/js/components/billing/v2/BillingCompletionDialogV2.vue',
    ],
]) {
    const classic = read(classicPath);
    const v2 = read(v2Path);

    test(`BV2-2 ${name} dialog: the Version 2 script is the Classic logic, unchanged`, () => {
        assert.equal(body(v2), body(classic));
        // Byte for byte from `const props` to the end of the script (Kumar): no normalisation at all.
        const logic = (src) =>
            script(src).slice(script(src).indexOf('const props'));
        assert.equal(logic(v2), logic(classic));
        // Every string and template literal in the logic, in order (no wording or URL drift).
        const strings = (src) =>
            [
                ...script(src)
                    .replace(/^import[\s\S]*?;$/gm, '')
                    .replace(/\/\*\*[\s\S]*?\*\//g, '')
                    .matchAll(/'[^'\n]*'|`[^`]*`/g),
            ].map((m) => m[0]);
        assert.deepEqual(strings(v2), strings(classic));
    });

    test(`BV2-2 ${name} dialog: Classic does not use the Version 2 copy`, () => {
        assert.doesNotMatch(classic, /V2Dialog|\/v2\//);
        assert.match(v2, /<V2Dialog\b/);
        assert.doesNotMatch(
            v2,
            /from '@\/components\/ui\/dialog'|OperationalConfirmDialog/,
        );
    });
}

test('the adjustment dialog keeps the Classic disabled and close rules', () => {
    const v2 = read(
        'resources/js/components/billing/v2/InvoiceAdjustmentDialogV2.vue',
    );
    // Classic's native "required" (amount when adding, reason always) is the disabled Confirm, plus the override rule.
    assert.match(
        v2,
        /:busy="\s*busy \|\|\s*!form\.reason \|\|\s*\(!reverse && !form\.value\) \|\|\s*\(above && \(!controls\?\.override \|\| !form\.confirmed\)\)\s*"/,
    );
    // Closing while saving is refused (the discard question is off while busy).
    assert.match(
        v2,
        /@update:open="!busy && \(\$event \? \(open = true\) : reset\(\)\)"/,
    );
    assert.match(v2, /:dirty="!busy && !!\(form\.value \|\| form\.reason\)"/);
    assert.match(v2, /@confirm="submit"/);
    // No native selects and no amber: 44 px choices and warning tokens.
    assert.doesNotMatch(v2, /<select|amber-/);
    assert.match(v2, /type="radio"/);
    assert.match(v2, /border-warning\/40 bg-warning\/10/);
    assert.match(v2, /<V2MoneyInput\b/);
    // Whenever the action is disabled, the line above the footer names what is missing (Daniel).
    assert.match(v2, /'Tick the box to use your override authority\.'/);
    assert.match(v2, /'Enter an amount\.'/);
    assert.match(v2, /'Enter a reason\.'/);
    assert.match(
        v2,
        /An authorised override holder is required for an adjustment above the branch limit\./,
    );
});

test('the completion dialog keeps the Classic post, guard and labels', () => {
    const v2 = read(
        'resources/js/components/billing/v2/BillingCompletionDialogV2.vue',
    );
    assert.match(v2, /@update:open="updateOpen"/);
    assert.match(v2, /@confirm="submit"/);
    assert.match(v2, /:busy="processing"/);
    assert.match(v2, /processing \? 'Completing…' : 'Complete Visitation'/);
    // Plain V2 wording for what completing does (behaviour unchanged).
    assert.match(
        v2,
        /After you complete the visit, it cannot be edited\. The invoice and the payments stay as they are\./,
    );
    // The visit number never breaks at its hyphen in the title (non-breaking hyphen, display only).
    assert.match(v2, /visitNumber\.replace\(\/-\/g, '\\u2011'\)/);
});

test('the Version 2 page uses only Version 2 dialogs', () => {
    assert.match(page, /<InvoiceAdjustmentDialogV2 :billing="billing" \/>/);
    assert.match(page, /<BillingCompletionDialogV2\b/);
    assert.doesNotMatch(
        page,
        /components\/billing\/(InvoiceAdjustmentDialog|BillingCompletionDialog)\.vue/,
    );
});
