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

    for (const name of ['w-full', 'min-w-0', 'flex-1']) {
        assert.ok(classes.includes(name), `tel input has ${name}`);
    }
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
        /class="grid grid-cols-1 gap-3 rounded-md border p-4 md:grid-cols-3"/,
    );
});
