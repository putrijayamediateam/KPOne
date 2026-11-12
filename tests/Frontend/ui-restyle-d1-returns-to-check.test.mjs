import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D1, Version 2 only (owner decision 2026-11-12: Classic keeps its original look; the new look shows only
// for people who switch on Version 2). Under V2: the same data, links and words, a table from 1024 px and label and
// value cards with a 44 px Open case button below it. Classic is pinned by dsp-7b-returns-to-check.test.mjs.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/Dispensary/ReturnsToCheck.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');
const classicCards = flat.slice(
    flat.indexOf('<template v-if="!isV2">'),
    flat.indexOf('<ul v-else'),
);
const v2Cards = flat.slice(flat.indexOf('<ul v-else'));

test('the page asks useUiVersion which look to show', () => {
    assert.ok(
        page.includes(
            "import { useUiVersion } from '@/composables/useUiVersion';",
        ),
    );
    assert.ok(page.includes('const { isV2 } = useUiVersion();'));
});

test('tokens only, and every D1 class change on a shared element is a v2: variant', () => {
    assert.equal(
        /\b(bg|text|border)-(slate|gray|zinc|amber|pink|red|emerald|blue)-\d/.test(
            page,
        ),
        false,
    );
    assert.equal(page.includes('dark:'), false);

    for (const classes of [
        'flex-col gap-4 px-4 py-6 md:px-6 v2:gap-5',
        'text-xs text-muted-foreground v2:-mt-2 v2:text-[13px]',
        'class="v2:align-top"',
        'whitespace-nowrap v2:tabular-nums',
        'mr-2 v2:font-medium',
        'flex flex-col items-end gap-1 v2:gap-1.5',
        'text-xs text-muted-foreground v2:max-w-56 v2:text-[13px]',
        'size="sm" class="v2:h-9 v2:px-3"',
    ]) {
        assert.ok(flat.includes(classes), classes);
    }
});

test('Classic: the table still switches at 768 px; V2: at 1024 px', () => {
    assert.ok(
        flat.includes(
            `<div :class="isV2 ? 'hidden lg:block' : 'contents'"> <div class="hidden md:block">`,
        ),
    );
    assert.ok(
        classicCards.includes(
            '<ul class="space-y-3 md:hidden" aria-label="Returns to check">',
        ),
    );
    assert.ok(
        v2Cards.startsWith(
            '<ul v-else class="space-y-3 lg:hidden" aria-label="Returns to check" >',
        ),
    );
});

test('Classic cards keep their original lines; V2 cards are label and value pairs in the table words', () => {
    assert.ok(
        classicCards.includes(
            'space-y-2 rounded-xl border bg-card p-4 text-sm',
        ),
    );
    assert.ok(
        classicCards.includes(
            'Qty returned {{ row.quantity }} {{ row.unit }} ·',
        ),
    );
    assert.ok(
        classicCards.includes(
            "{{ row.recordedAt }} · by {{ row.recordedByMe ? 'you' : row.recordedBy }} ·",
        ),
    );
    assert.equal(classicCards.includes('<dl'), false);

    assert.ok(v2Cards.includes('rounded-[14px] border bg-card p-4'));
    assert.ok(
        v2Cards.includes('<dl class="grid grid-cols-[auto_minmax(0,1fr)]'),
    );

    for (const label of [
        'Qty returned',
        'Patient / Visit',
        'Reason / Condition',
        'Recorded',
        'Recorded by',
    ]) {
        assert.ok(
            v2Cards.includes(
                `<dt class="text-[13px] text-muted-foreground"> ${label} </dt>`,
            ),
            label,
        );
    }

    assert.ok(v2Cards.includes('class="min-h-11 w-full justify-center"'));
});
