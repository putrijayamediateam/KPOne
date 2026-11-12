import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D4b (owner: "start D4b on a branch, explain first"; GUIDE section 19): the Dispensary middle column and
// the 1024 px crush (Daniel). Two columns from 1024 to 1439 px, three from 1440 px (owner decision). Layout only.
const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);
const flat = show.replace(/\s+/g, ' ');
const main = flat.slice(
    flat.indexOf('data-testid="dispensary-main"'),
    flat.indexOf('data-testid="dispensary-right"'),
);

test('two columns from 1024 px, three from 1440 px, written in rem so lg cannot override them', () => {
    assert.ok(
        flat.includes(
            'grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,300px)] lg:grid-rows-[auto_1fr] min-[90rem]:grid-cols-[minmax(0,280px)_minmax(0,1fr)_minmax(0,300px)] min-[90rem]:grid-rows-none',
        ),
    );
    assert.ok(
        flat.includes('lg:col-start-2 lg:row-start-1 min-[90rem]:col-start-1'),
    );
    assert.ok(
        flat.includes(
            'lg:col-start-1 lg:row-span-2 lg:row-start-1 min-[90rem]:col-start-2 min-[90rem]:row-span-1',
        ),
    );
    assert.ok(
        flat.includes(
            'lg:col-start-2 lg:row-start-2 min-[90rem]:col-start-3 min-[90rem]:row-start-1',
        ),
    );
    // Tailwind sorts px breakpoints before rem ones, so a px breakpoint would lose to lg (64rem).
    assert.equal(/min-\[\d+px\]:/.test(show), false);
    assert.equal(
        (
            flat.match(
                /min-\[90rem\]:grid-cols-\[minmax\(220px,1fr\)_minmax\(0,3fr\)\]/g,
            ) ?? []
        ).length,
        2,
    );
});

test('label limits sit in a closed disclosure with the same words inside', () => {
    assert.ok(flat.includes('data-testid="label-limits"'));
    assert.ok(flat.includes('data-testid="label-limits-add"'));
    assert.equal((flat.match(/Label limits <\/summary>/g) ?? []).length, 2);
    assert.ok(
        flat.includes(
            'Label limits (characters; saved clinical text is unchanged)',
        ),
    );
    assert.ok(
        flat.includes(
            'flex min-h-11 w-fit cursor-pointer list-none items-center gap-1 font-medium lg:min-h-0',
        ),
    );
});

test('the middle column uses tokens and calm cards, with no palette colours or uppercase eyebrows', () => {
    assert.equal(
        /\b(bg|text|border|ring|accent)-(slate|gray|zinc|amber|pink|red|emerald|green|blue)-\d/.test(
            main,
        ),
        false,
    );
    assert.equal(main.includes('dark:'), false);
    assert.equal(main.includes('uppercase'), false);
    assert.equal(main.includes('text-brand'), false);
    assert.ok(
        main.includes(
            '<section class="divide-y rounded-[14px] border bg-card">',
        ),
    );
    assert.ok(
        main.includes('rounded-[10px] border border-warning/30 bg-warning/10'),
    );
});

test('the Label limits summary shows a chevron that turns when open (Hakim)', () => {
    assert.equal(
        (
            flat.match(
                /<ChevronRight class="size-3\.5 shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" \/>Label limits/g,
            ) ?? []
        ).length,
        2,
    );
    assert.ok(
        flat.includes('class="group text-muted-foreground sm:col-span-full"'),
    );
    assert.ok(flat.includes('[&::-webkit-details-marker]:hidden'));
});

test('field labels stay short, with the label budget as a hint under each box (Daniel)', () => {
    assert.equal(show.includes('(label max'), false);
    assert.equal(show.includes('Route (not printed on the label)'), false);
    assert.equal(
        (
            flat.match(
                /<span class="mt-1 block text-muted-foreground" ?>Label max \{\{ dispensary\.labelLimits\?\.\w+ \}\} characters<\/span/g,
            ) ?? []
        ).length,
        10,
    );
    assert.equal(
        (
            flat.match(
                /<span class="mt-1 block text-muted-foreground" ?>Not printed on the label<\/span/g,
            ) ?? []
        ).length,
        2,
    );
});

test('Print label on a line is an outline button, 36 px from 1024 px (Daniel)', () => {
    const print = flat.slice(
        flat.lastIndexOf('<Button', flat.indexOf('/label`"')),
        flat.indexOf('/label`"'),
    );
    assert.ok(print.includes('variant="outline"'));
    assert.ok(print.includes('class="mt-2 lg:h-9"'));
});
