import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D2 (owner: "start D2 on a branch"; GUIDE section 19): layout only. One summary card, one card per line,
// and no cards inside cards: the record form and the check panel are quiet fills, the history a hairline list.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (text) => text.replace(/\s+/g, ' ');
const completed = flat(read('resources/js/pages/Dispensary/Completed.vue'));
const section = flat(
    read('resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue'),
);
const panel = flat(
    read('resources/js/pages/Dispensary/Partials/ReturnCheckPanel.vue'),
);

test('the completed status and read-only note sit in one summary card', () => {
    assert.ok(completed.includes('rounded-[14px] border bg-card p-4'));
    assert.ok(completed.includes('data-testid="completed-summary"'));
    assert.equal((completed.match(/class="lg:h-9"/g) ?? []).length, 3);
});

test('each line is one 14 px card; the form, history and check panel are not cards inside it', () => {
    assert.ok(section.includes('rounded-[14px] border bg-card p-4'));
    assert.ok(
        section.includes('class="grid gap-4 rounded-[10px] bg-muted/50 p-4"'),
    );
    assert.ok(section.includes('<ol class="divide-y rounded-[10px] border">'));
    assert.equal(section.includes('rounded-md border bg-background'), false);
    assert.ok(
        panel.includes(
            'class="mt-2 grid gap-3 rounded-[10px] bg-muted/50 p-3"',
        ),
    );
});

test('section and card titles follow the type scale; no uppercase eyebrow', () => {
    assert.ok(
        section.includes(
            '<h2 id="returns-heading" class="text-xl font-semibold">',
        ),
    );
    assert.ok(
        section.includes(
            'class="text-[13px] font-semibold text-muted-foreground"',
        ),
    );
    assert.equal(section.includes('uppercase'), false);
});

test('tokens only: no palette colour or dark: override', () => {
    for (const file of [completed, section, panel]) {
        assert.equal(
            /\b(bg|text|border)-(slate|gray|zinc|amber|pink|red|emerald|blue)-\d/.test(
                file,
            ),
            false,
        );
        assert.equal(file.includes('dark:'), false);
    }
});
