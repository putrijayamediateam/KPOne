import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// V2WeekGrid: people as rows and days as columns from 1280 px, day cards below, text entries, keyboard moves.
const source = readFileSync(
    new URL('../../resources/js/components/v2/V2WeekGrid.vue', import.meta.url),
    'utf8',
);
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;

test('V2WeekGrid compiles and is a V2 block with no page logic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2-week-grid',
            source: template,
            filename: 'V2WeekGrid.vue',
        }).errors,
        [],
    );
    assert.match(template, /data-slot="v2-week-grid"/);
    assert.doesNotMatch(
        script.replace(/\/\*[\s\S]*?\*\//g, ''),
        /usePage|router|permission|hasRole|axios|fetch\(/i,
    );
    assert.doesNotMatch(source, /from '@\/components\/ui\//);
    assert.match(
        script,
        /open: \[entryKey: string, rowKey: string \| number, dayKey: string\]/,
    );
    assert.match(
        script,
        /add: \[dayKey: string, rowKey: string \| number \| null\]/,
    );
});

test('the grid shows from 1280 px with a sticky first column and day row, and day cards below 1280 px', () => {
    assert.match(
        template,
        /class="max-w-full rounded-\[14px\] border bg-card max-xl:hidden"/,
    );
    assert.match(template, /<ul class="grid gap-3 md:grid-cols-2 xl:hidden">/);
    assert.equal(
        (
            template.match(
                /sticky (top-\[var\(--v2-topbar-h,4rem\)\] )?left-0 z-\[\d\] bg-card/g,
            ) ?? []
        ).length,
        3,
    );
    // The day row stays under the V2 top bar while the page scrolls; its height comes from the shell's variable,
    // with the 64 px bar as the fallback, so a change to the bar cannot silently hide the day row.
    assert.equal(
        (template.match(/sticky top-\[var\(--v2-topbar-h,4rem\)\]/g) ?? [])
            .length,
        2,
    );
    assert.doesNotMatch(template, /top-16/);
    assert.match(template, /table-fixed/);
    assert.match(template, /<col class="w-40" \/>/);
});

test('entries are text that never breaks inside a time, notes are words, warnings have an icon and words', () => {
    assert.match(script, /\.split\(' – '\)/);
    assert.match(script, /replace\(\/ \/g, '\\u00a0'\)/);
    assert.equal((template.match(/noBreak\(/g) ?? []).length, 3);
    assert.match(template, /row\.notes\[day\.key\]/);
    assert.match(template, /row\.warnings\[day\.key\]/);
    assert.match(template, /totals\[day\.key\]\?\.warning/);
    // The page gives the warning word ("Below minimum"); the block has none of its own.
    assert.doesNotMatch(template, /\bShort\b|Below minimum/);
    assert.ok((template.match(/<svg/g) ?? []).length >= 4);
});

test('an empty editable cell shows + Add on hover and focus; a read-only entry does not look like a button', () => {
    assert.match(
        template,
        /group-hover\/cell:opacity-100 group-focus-visible\/cell:opacity-100/,
    );
    assert.match(template, /\+ \{\{ addLabel \}\}/);
    assert.match(template, /v-else\s+class="flex flex-col px-2 py-1\.5"/);
});

test('each cell has a spoken name, and the focus ring is offset', () => {
    assert.match(template, /:aria-label="cellName\(row, day\)"/);
    assert.match(
        script,
        /`\$\{props\.addLabel\} for \$\{row\.title\} on \$\{day\.long\}`/,
    );
    assert.match(script, /\. Press Enter to edit\./);
    assert.match(script, /replace\(' – ', ' to '\)/);
    assert.match(
        template,
        /focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring/,
    );
});

test('keyboard: a roving cell whose index is clamped, and entries that are hidden until a cell is stepped into', () => {
    assert.match(template, /role="grid"/);
    assert.match(template, /role="gridcell"/);
    assert.match(template, /:tabindex="isFocusCell\(r, c\) \? 0 : -1"/);
    assert.match(script, /clampIndex\(focusRow\.value, props\.rows\.length\)/);
    assert.match(script, /clampIndex\(focusCol\.value, props\.days\.length\)/);
    // One announcement: the cell's name says it all; its entry buttons stay out of the accessibility tree and the tab
    // order until a keyboard user steps into a cell with several entries.
    assert.match(template, /:tabindex="isEntered\(r, c\) \? 0 : -1"/);
    assert.match(template, /isEntered\(r, c\) \? undefined : 'true'/);
    assert.match(template, /@focusout="onCellFocusOut\(\$event, r, c\)"/);
});

const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const lib = readFileSync(
        new URL('../../resources/js/lib/v2-week-grid.ts', import.meta.url),
        'utf8',
    );
    const js = ts.transpileModule(lib, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('the focus index is clamped, so one cell stays reachable after rows or days shrink', async () => {
    const { clampIndex } = await loadLib();
    assert.equal(clampIndex(5, 3), 2);
    assert.equal(clampIndex(-1, 3), 0);
    assert.equal(clampIndex(1, 3), 1);
    // No rows at all: index 0, never NaN or -1.
    assert.equal(clampIndex(4, 0), 0);
});

test('cell keys: arrows, Home and End move inside the grid; Enter opens one entry, steps into several, adds on empty', async () => {
    const { cellKeyAction } = await loadLib();
    const size = { rows: 3, cols: 7 };
    const at = { row: 1, col: 3 };
    const move = (key, from = at) => cellKeyAction(key, from, size, 1, true);

    assert.deepEqual(move('ArrowRight'), { kind: 'move', row: 1, col: 4 });
    assert.deepEqual(move('ArrowLeft'), { kind: 'move', row: 1, col: 2 });
    assert.deepEqual(move('ArrowDown'), { kind: 'move', row: 2, col: 3 });
    assert.deepEqual(move('ArrowUp'), { kind: 'move', row: 0, col: 3 });
    assert.deepEqual(move('Home'), { kind: 'move', row: 1, col: 0 });
    assert.deepEqual(move('End'), { kind: 'move', row: 1, col: 6 });
    // At the edges a move stays inside the grid.
    assert.deepEqual(move('ArrowUp', { row: 0, col: 0 }), {
        kind: 'move',
        row: 0,
        col: 0,
    });
    assert.deepEqual(move('ArrowRight', { row: 2, col: 6 }), {
        kind: 'move',
        row: 2,
        col: 6,
    });

    assert.deepEqual(cellKeyAction('Enter', at, size, 0, true), {
        kind: 'add',
    });
    assert.deepEqual(cellKeyAction(' ', at, size, 1, true), { kind: 'open' });
    assert.deepEqual(cellKeyAction('Enter', at, size, 2, true), {
        kind: 'enter',
    });
    // A read-only grid only moves.
    assert.deepEqual(cellKeyAction('Enter', at, size, 2, false), {
        kind: 'none',
    });
    assert.deepEqual(cellKeyAction('x', at, size, 1, true), { kind: 'none' });
});

test('a number and its unit never split across lines; other words still wrap', async () => {
    const { keepUnits } = await loadLib();
    assert.equal(keepUnits('Rest under 11 h'), 'Rest under 11\u00a0h');
    assert.equal(
        keepUnits('Planned 48 h this week'),
        'Planned 48\u00a0h this week',
    );
    assert.equal(keepUnits('5 on shift'), '5 on shift');
    assert.equal(keepUnits('On leave (half day)'), 'On leave (half day)');
    assert.ok((template.match(/keepUnits\(/g) ?? []).length >= 8);
});

test("entry keys: Tab and Shift+Tab move between a cell's entries, Escape goes back, and nothing traps focus", async () => {
    const { entryKeyAction } = await loadLib();

    assert.deepEqual(entryKeyAction('Tab', false, 0, 2), {
        kind: 'focus',
        index: 1,
    });
    assert.deepEqual(entryKeyAction('Tab', true, 1, 2), {
        kind: 'focus',
        index: 0,
    });
    // Shift+Tab on the first entry and Escape on any entry go back to the cell.
    assert.deepEqual(entryKeyAction('Tab', true, 0, 2), { kind: 'cell' });
    assert.deepEqual(entryKeyAction('Escape', false, 1, 2), { kind: 'cell' });
    // Tab on the last entry leaves the grid as usual.
    assert.deepEqual(entryKeyAction('Tab', false, 1, 2), { kind: 'leave' });
    assert.deepEqual(entryKeyAction('Enter', false, 0, 2), { kind: 'none' });
});

test('targets are 44 px below 1024 and 36 px from 1024; each Edit and Add has a spoken name', () => {
    assert.match(script, /h-11[^']*lg:h-9/);
    assert.match(script, /min-h-11[^']*lg:min-h-9/);
    assert.match(template, /:aria-label="item\.entry\.actionLabel"/);
    assert.match(
        template,
        /<span class="sr-only"> on \{\{ day\.long \}\}<\/span>/,
    );
});
