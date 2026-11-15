import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-9 (Daniel, design debt): in the TV settings room rows, Edit and Deactivate/Activate are outline buttons
// (GUIDE 5: outline in table rows; destructive only for actions that void, remove, reverse or cancel).

const settings = readFileSync(
    new URL(
        '../../resources/js/pages/QueueDisplay/Settings.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the Activate and Deactivate button in a room row is an outline button, not solid red or solid pink', () => {
    const button = settings.match(
        /<Button\s+size="sm"\s+variant="outline"\s+@click="toggleRoom\(room\)"\s*>/,
    );
    assert.ok(button, 'the toggle button is an outline button');
    assert.doesNotMatch(
        settings,
        /:variant="\s*room\.isActive\s*\?\s*'destructive'/,
    );
});

test('removing a poster stays destructive, because it removes the poster', () => {
    assert.match(
        settings,
        /variant="destructive"\s+class="w-full"\s+@click="removePoster\(poster\.id\)"/,
    );
});
