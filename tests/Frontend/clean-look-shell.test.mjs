import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const rail = read('resources/js/components/shell/WorkspaceRail.vue');
const header = read('resources/js/components/ui/page-header/PageHeader.vue');
const sheet = read('resources/js/components/ui/sheet/SheetContent.vue');

test('the rail always shows the active branch: a 3-letter chip with the full name as its accessible name and tooltip', () => {
    assert.match(rail, /data-test="rail-branch"/);
    assert.match(rail, /Active branch: \$\{branch\.name\}/);
    assert.match(rail, /branch\.code\.slice\(0, 3\)/);
});

test('every rail icon has a visible tooltip and the selected one uses the blue primary', () => {
    assert.match(
        rail,
        /<TooltipContent side="right" class="text-\[12\.5px\]">/,
    );
    assert.match(rail, /bg-primary\/10 text-primary/);
});

test('Escape closes an unpinned panel and returns focus to the rail button without reopening it', () => {
    assert.match(rail, /event\.key === 'Escape'/);
    assert.match(rail, /\[data-area="\$\{key\}"\]/);
    assert.match(rail, /ignoreFocusOpen/);
    assert.match(rail, /@focus="onRailFocus\(area\.key\)"/);
});

test('the page header shows the branch name as a quiet subline', () => {
    assert.match(header, /data-test="page-branch"/);
    assert.match(header, /branchContext\?\.active\?\.name/);
});

test('the drawer close button is a 44 px target', () => {
    assert.match(sheet, /flex size-11/);
});
