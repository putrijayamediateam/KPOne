import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-13 (design debt DD-28): the Panel statement results use the shared table
// on wide screens; the phone cards are unchanged.

const helper = readFileSync(
    new URL(
        '../../resources/js/components/panel/PanelStatementHelper.vue',
        import.meta.url,
    ),
    'utf8',
);

test('Wide screens use the shared OperationalTable with seven columns', () => {
    assert.match(
        helper,
        /import \{ OperationalTable \} from '@\/components\/ui\/table';/,
    );
    assert.match(
        helper,
        /<OperationalTable\s+class="hidden lg:block"\s+label="Statement rows"\s+:columns="7"/,
    );
    assert.doesNotMatch(helper, /<table/);
    assert.doesNotMatch(helper, /overflow-x-auto rounded-lg border md:block/);

    for (const heading of [
        'Row',
        'Statement reference',
        'Amount',
        'Suggested claim',
        'Unpaid',
        'Result',
    ]) {
        assert.ok(helper.includes(`>${heading}</th>`), heading);
    }
});

test('Each table tick box keeps its rules and gets a 44 px target', () => {
    const table = helper.slice(helper.indexOf('<OperationalTable'));
    assert.match(
        table,
        /<label\s+class="-m-2 inline-flex min-h-11 min-w-11 items-center justify-center"\s*>\s*<input\s+type="checkbox"/,
    );
    assert.match(table, /:disabled="row\.result !== 'matched'"/);
    assert.match(
        table,
        /:aria-describedby="`statement-result-\$\{row\.line\}`"/,
    );
    assert.match(table, /:id="`statement-result-\$\{row\.line\}`"/);
});

test('Phones and tablets get the cards below lg, where the seven columns do not fit', () => {
    assert.match(
        helper,
        /<ul\s+class="divide-y rounded-lg border lg:hidden"\s+aria-label="Statement rows"/,
    );
});
