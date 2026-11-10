import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-10: the Coverage and Urgency toggles on Register Visit are 44 px tall below 1024 px (36 px on desktop).
const source = readFileSync(
    new URL(
        '../../resources/js/pages/Registration/Create.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the four Coverage and Urgency toggle buttons grow to 44 px below 1024 px', () => {
    const buttons = source.match(/class="h-9 [^"]*rounded-sm[^"]*"/g);

    assert.equal(buttons?.length, 4);

    for (const button of buttons) {
        assert.match(button, /max-lg:h-11/);
    }
});
