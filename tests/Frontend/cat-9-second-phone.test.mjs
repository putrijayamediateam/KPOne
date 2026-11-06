import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-9 (owner decision 2026-11-06, "go with CAT-9"): an optional second clinic phone in Business details.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/BusinessDetails/Index.vue');
const flat = page.replace(/\s+/g, ' ');

test('the second phone box sits right after the first, optional and connected to its help and error', () => {
    const first = page.indexOf('id="phone"');
    const second = page.indexOf('id="phone_secondary"');
    assert.ok(first > 0 && second > first);
    assert.ok(second < page.indexOf('id="email"'));
    assert.match(
        flat,
        /<Label for="phone_secondary" >Second phone number \(optional\)<\/Label >/,
    );
    assert.match(
        flat,
        /aria-describedby="phone_secondary-help phone_secondary-error"/,
    );
    assert.match(flat, /Printed after the first number, as "Tel: A \/ B"\./);
    assert.match(
        flat,
        /<InputError id="phone_secondary-error" :message="form\.errors\.phone_secondary"/,
    );
});

test('the form loads, sends and clears the second phone', () => {
    assert.match(
        page,
        /phone_secondary: props\.letterhead\.details\.phone_secondary \?\? '',/,
    );
    assert.match(page, /'phone',\s+'phone_secondary',\s+'email',/);
});
