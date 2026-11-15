import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Returns to check (Daniel's OK, 2026-11-15). Look only: Classic's props, route, links and words.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/ReturnsToCheck.vue');
const v2 = read('resources/js/pages/Dispensary/V2/ReturnsToCheck.vue');
const block = (source, start, end) =>
    source.slice(
        source.indexOf(start),
        source.indexOf(end, source.indexOf(start)),
    );
const props = (source) => block(source, 'type Row = {', '}>();');
const template = (source) => source.slice(source.indexOf('<template>'));

test('V2 Returns to check is registered, and Classic does not import anything from V2', () => {
    assert.match(read('config/v2-pages.php'), /'Dispensary\/ReturnsToCheck',/);
    assert.ok(!/\/V2\//.test(classic));
});

test("V2 takes exactly Classic's row type and props, and the same helpers", () => {
    assert.equal(props(v2), props(classic));

    for (const line of [
        'const caseLink = (row: Row) => `${row.caseUrl}#medicine-returns`;',
        "'/dispensary/returns-to-check',",
        'returnReasonLabels[row.reason] ?? row.reason',
        'returnConditionLabels[row.condition] ?? row.condition',
        "(row.recordedByMe ? 'You' : row.recordedBy)",
    ]) {
        assert.ok(classic.includes(line), `Classic ${line}`);
        assert.ok(v2.includes(line), `V2 ${line}`);
    }
});

test('frame: Pharmacy eyebrow, branch in the description, crumbs, and the read-only line', () => {
    assert.match(v2, /eyebrow="Pharmacy"/);
    assert.match(
        v2,
        /`Medicine returns waiting for a check at \$\{props\.returns\.branchName\}, oldest first\.`/,
    );
    assert.match(
        v2,
        /\{ title: 'Clinic management', href: '\/clinic-management' \}/,
    );
    assert.match(
        template(v2),
        /This list does not check anything\. You cannot check a return\s+you recorded\./,
    );
    assert.match(template(v2), /title="No returns waiting"/);
    // Read only: no filled button on the page.
    assert.ok(!/bg-primary/.test(v2));
});

test('a table from 1280 px and cards below it, each with a named Open case link', () => {
    assert.match(
        template(v2),
        /<div class="hidden xl:block">\s*<OperationalTable/,
    );
    assert.match(template(v2), /class="space-y-3 xl:hidden"/);
    assert.equal(
        (template(v2).match(/:aria-label="openLabel\(row\)"/g) ?? []).length,
        2,
    );
    assert.ok(
        v2.includes('`Open case for ${row.medicine}, ${row.visitNumber}`'),
    );
    // Cards: full width and 44 px; the table: 36 px from 1024.
    assert.match(template(v2), /:class="\[outlineButton, 'w-full'\]"/);
    assert.match(template(v2), /'lg:h-9 lg:px-3'/);
    assert.match(v2, /const outlineButton =\s*'inline-flex h-11 /);
});

test('the own-return note is visible text under the state, and on cards above Open case', () => {
    assert.match(
        v2,
        /const ownNote = 'You recorded this return, so someone else must check it\.';/,
    );
    assert.equal(
        (template(v2).match(/data-testid="return-own-note"/g) ?? []).length,
        2,
    );
    const card = block(
        template(v2),
        'data-testid="return-to-check-card"',
        '</li>',
    );
    assert.ok(card.indexOf('return-own-note') < card.indexOf('openLabel(row)'));
    assert.ok(!/title="\{\{|:title="ownNote/.test(v2), 'never only a tooltip');
});

test('Controlled and the state are dot-and-word chips; quantities read as unit words; codes unbroken', () => {
    assert.equal((template(v2).match(/label="Controlled"/g) ?? []).length, 2);
    assert.equal(
        (template(v2).match(/label="Waiting for check"/g) ?? []).length,
        2,
    );
    assert.match(v2, /quantityWords\(row\.quantity, row\.unit\)/);
    assert.equal(
        (template(v2).match(/font-mono text-\[13px\] whitespace-nowrap/g) ?? [])
            .length,
        2,
    );
});

test('V2Pager with the server page size of 25', () => {
    assert.match(template(v2), /<V2Pager/);
    assert.match(v2, /const perPage = 25;/);
    assert.match(
        read(
            'app/Domain/Clinical/Dispensary/Services/DispensaryReturnService.php',
        ),
        /->paginate\(25, \['\*'\], 'page'/,
    );
});
