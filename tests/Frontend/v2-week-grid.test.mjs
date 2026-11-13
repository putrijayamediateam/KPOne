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

test('the grid shows from 1280 px with a sticky first column, and day cards below 1280 px', () => {
    assert.match(
        template,
        /max-w-full overflow-x-auto rounded-\[14px\] border bg-card max-xl:hidden/,
    );
    assert.match(template, /<ul class="grid gap-3 md:grid-cols-2 xl:hidden">/);
    assert.equal(
        (template.match(/sticky left-0 z-\[1\] bg-card/g) ?? []).length,
        3,
    );
    assert.match(template, /table-fixed/);
    assert.match(template, /<col class="w-40" \/>/);
});

test('entries are text, notes are words, a warning has an icon and a word', () => {
    assert.match(template, /\{\{ entry\.label \}\}|entry\.label\s*\}\}/);
    assert.match(template, /row\.notes\[day\.key\]/);
    assert.match(template, /totals\[day\.key\]\?\.warning/);
    // The page gives the warning word ("Below minimum"); the block has none of its own.
    assert.doesNotMatch(template, /\bShort\b|Below minimum/);
    assert.match(
        template,
        /<svg[\s\S]*?aria-hidden="true"[\s\S]*?\{\{ totals\[day\.key\]\?\.warning \}\}/,
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
