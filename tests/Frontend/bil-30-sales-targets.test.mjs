import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-30: monthly sales targets (Daniel's look, 23 Nov 2026). The server works out every figure.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

test('BIL-30: the doctor card sits below the room picker on the consultation queue', () => {
    const page = read('resources/js/pages/Queue/Index.vue');
    const room = page.indexOf('data-testid="doctor-room-choice"');
    const card = page.indexOf('<MySalesTarget');
    assert.ok(room > 0 && card > room, 'card after the room picker');
});

test('BIL-30: the doctor card is one calm line with a thin brand bar and the rest under an outline Details button', () => {
    const card = read('resources/js/components/billing/MySalesTarget.vue');
    assert.match(card, /class="h-1\.5 [^"]*bg-muted"/);
    assert.match(card, /class="h-full bg-brand"/);
    assert.match(card, /data-testid="my-sales-target-toggle"/);
    assert.match(card, /:aria-expanded="open \? 'true' : 'false'"/);
    assert.match(card, /h-11 shrink-0 [^"]*border[^"]*lg:h-7/);
    assert.doesNotMatch(card, /branchTargetSen|doctors/);
});

test('BIL-30: needed per day keeps its days in the label and never wraps', () => {
    const page = read('resources/js/pages/Billing/SalesTargets.vue');
    assert.match(page, /`Needed per day · \$\{row\.daysLeft\}/);
    assert.match(
        page,
        /return `\$\{myr\(row\.neededPerDaySen \?\? 0\)\} a day`;/,
    );
    assert.match(
        page,
        /class="whitespace-nowrap tabular-nums"> \{\{ neededText\(/,
    );
});

test('BIL-30: branch and doctor rows share one grid so the columns line up', () => {
    const page = read('resources/js/pages/Billing/SalesTargets.vue');
    assert.equal((page.match(/:class="grid"/g) ?? []).length, 2);
    assert.match(page, /class="min-w-0 pl-4 font-medium break-words"/);
});

test('BIL-30: the dialog says what is missing directly under the Save button', () => {
    const page = read('resources/js/pages/Billing/SalesTargets.vue');
    assert.match(page, /'Write the target in RM\.'/);
    const save = page.indexOf('data-testid="target-save"');
    const hint = page.indexOf('data-testid="target-missing"');
    const footerEnd = page.indexOf('</DialogFooter>');
    assert.ok(
        save < hint && hint < footerEnd,
        'hint inside the footer, after Save',
    );
});
