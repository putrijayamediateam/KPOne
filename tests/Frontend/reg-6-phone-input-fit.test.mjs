import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-6: the phone number box must be able to shrink. Without a percentage width its built-in width (about 244 px)
// sets the minimum of the form's single mobile column, and /patients/create and /edit were 433 px wide on a phone.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const phone = read('resources/js/components/patient/PhoneInput.vue');

test('the phone number box can shrink inside a narrow column', () => {
    const input = phone.match(/<input[\s\S]*?type="tel"[\s\S]*?\/>/)?.[0] ?? '';
    assert.ok(input, 'the tel input exists');
    const classes = input.match(/class="([^"]+)"/)?.[1].split(/\s+/) ?? [];

    for (const name of ['w-full', 'min-w-0', 'sm:flex-1']) {
        assert.ok(classes.includes(name), `tel input has ${name}`);
    }

    // A bare flex-1 in the stacked (column) row sets the box's height basis to 0 and collapses it.
    assert.ok(!classes.includes('flex-1'), 'tel input has no bare flex-1');
});

test('below sm the country list stacks above the number box', () => {
    assert.match(phone, /<div class="flex flex-col gap-2 sm:flex-row">/);
    const select = phone.match(/<select[\s\S]*?>/)?.[0] ?? '';
    const classes = select.match(/class="([^"]+)"/)?.[1].split(/\s+/) ?? [];

    for (const name of ['w-full', 'sm:w-36', 'min-w-0']) {
        assert.ok(classes.includes(name), `country list has ${name}`);
    }
});

test('each identifier box on Patient create is a shrinkable one-column grid on phones', () => {
    const create = read('resources/js/pages/Patient/Create.vue');
    assert.match(
        create,
        /class="grid grid-cols-1 gap-3 rounded-xl border p-4 md:grid-cols-3"/,
    );
    assert.match(
        create,
        /class="h-9 w-full min-w-0 rounded-\[10px\] border bg-background px-3 text-sm uppercase max-lg:h-11"/,
    );
    assert.match(
        create,
        /autocomplete="off"\s+class="h-9 w-full min-w-0 rounded-\[10px\] border bg-background px-3 text-sm max-lg:h-11"\s+placeholder="Identifier value"/,
    );
});

test('the Quick create identity row stacks below sm and joins from sm up', () => {
    const quick = read('resources/js/pages/Registration/Create.vue');
    assert.match(
        quick,
        /<div class="flex flex-col gap-2 sm:flex-row sm:gap-0">/,
    );
    assert.match(
        quick,
        /trigger-class="h-10 w-full sm:w-40 sm:rounded-r-none"/,
    );
    assert.match(
        quick,
        /class="h-10 w-full min-w-0 rounded-md border bg-background px-3 text-sm sm:flex-1 sm:rounded-l-none sm:border-l-0"/,
    );
});
