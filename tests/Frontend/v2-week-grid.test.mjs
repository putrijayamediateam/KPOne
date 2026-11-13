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
        (template.match(/sticky (top-16 )?left-0 z-\[\d\] bg-card/g) ?? [])
            .length,
        3,
    );
    // The day row stays under the 64 px V2 top bar while the page scrolls.
    assert.equal((template.match(/sticky top-16/g) ?? []).length, 2);
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

test('keyboard: a roving cell, arrows, Home and End move; Enter or Space opens or adds', () => {
    assert.match(template, /role="grid"/);
    assert.match(template, /role="gridcell"/);
    assert.match(template, /:tabindex="isFocusCell\(r, c\) \? 0 : -1"/);

    for (const key of [
        'ArrowRight',
        'ArrowLeft',
        'ArrowDown',
        'ArrowUp',
        'Home',
        'End',
    ]) {
        assert.match(script, new RegExp(`${key}: \\[`));
    }

    assert.match(script, /event\.key === 'Enter' \|\| event\.key === ' '/);
    assert.match(script, /emit\('add', day\.key, row\.key\)/);
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
