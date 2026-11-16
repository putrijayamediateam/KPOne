import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-62 (Billing part): the money on Billing Approval Limits (the sen inputs
// and their "= RM x.xx" echo) uses tabular figures so amounts line up.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/BillingApprovalLimits/Index.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-62: approval limit inputs and their ringgit echo use tabular-nums', () => {
    assert.equal([...page.matchAll(/class="w-44 tabular-nums"/g)].length, 2);

    for (const kind of ['panel', 'deferment']) {
        assert.match(
            page,
            new RegExp(
                `:id="\`limit-echo-\\$\\{row\\.id\\}-${kind}\`"\\s+class="mt-1 text-xs text-muted-foreground tabular-nums"`,
            ),
            kind,
        );
    }
});
