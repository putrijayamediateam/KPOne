import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D1 (owner: "go with your plan, start D1 now"; GUIDE section 19): layout only. The same data, links and
// words, a 1280 px frame, a table from 1024 px and label and value cards with a 44 px Open case button below it.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/Dispensary/ReturnsToCheck.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');

test('one frame up to 1280 px, tokens only', () => {
    assert.ok(flat.includes('mx-auto flex w-full max-w-7xl min-w-0'));
    assert.equal(
        /\b(bg|text|border)-(slate|gray|zinc|amber|pink|red|emerald|blue)-\d/.test(
            page,
        ),
        false,
    );
    assert.equal(page.includes('dark:'), false);
});

test('below 1024 px each row is a card with label and value pairs, in the table words', () => {
    assert.ok(flat.includes('rounded-[14px] border bg-card p-4'));
    assert.ok(flat.includes('<dl class="grid grid-cols-[auto_minmax(0,1fr)]'));

    for (const label of [
        'Qty returned',
        'Patient / Visit',
        'Reason / Condition',
        'Recorded',
        'Recorded by',
    ]) {
        assert.ok(
            flat.includes(
                `<dt class="text-[13px] text-muted-foreground"> ${label} </dt>`,
            ),
            label,
        );
    }
});

test('the table Open case button is 36 px from 1024 px', () => {
    assert.ok(flat.includes('size="sm" class="h-9 px-3"'));
});
