import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-103: below lg each approver is a card with the two limits stacked and
// 44 px Save and Clear. DD-104: Save is off with "Enter a limit to continue."
// while the limit box is blank.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/BillingApprovalLimits/Index.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');

test('DD-103: the table turns into one card per approver below lg', () => {
    assert.match(
        flat,
        /<table class="w-full text-left text-sm max-lg:block lg:min-w-\[920px\]"/,
    );
    assert.match(flat, /<thead class="max-lg:hidden">/);
    assert.match(flat, /<tbody class="max-lg:grid max-lg:gap-3">/);
    assert.match(
        flat,
        /max-lg:block max-lg:rounded-lg max-lg:border max-lg:p-3/,
    );

    for (const label of [
        'Panel approval limit (sen)',
        'Pay later approval limit (sen)',
    ]) {
        assert.ok(
            flat.includes(
                `<p class="mb-1 text-xs text-muted-foreground lg:hidden" aria-hidden="true" > ${label} </p>`,
            ),
            label,
        );
    }

    assert.equal([...page.matchAll(/class="max-lg:min-h-11"/g)].length, 4);
    assert.doesNotMatch(page, /min-w-\[920px\]"[^>]*overflow-x-auto/);
});

test('DD-104: Save is off with a hint until a limit is typed', () => {
    assert.match(
        page,
        /const isBlank = \(row: ApproverRow, capability: 'panel' \| 'deferment'\) =>/,
    );

    for (const kind of ['panel', 'deferment']) {
        assert.match(
            flat,
            new RegExp(`:disabled=" ?isBlank\\(row, '${kind}'\\) ?"`),
            kind,
        );
        assert.ok(flat.includes(`v-if="isBlank(row, '${kind}')"`), kind);
    }

    assert.equal([...page.matchAll(/Enter a limit to continue\./g)].length, 2);
    assert.match(flat, /if \(isBlank\(row, capability\)\) \{ return; \}/);
});
