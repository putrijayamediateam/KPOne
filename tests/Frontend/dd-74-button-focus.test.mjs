import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const button = readFileSync(
    new URL(
        '../../resources/js/components/ui/button/index.ts',
        import.meta.url,
    ),
    'utf8',
);
const variant = (name) =>
    button.match(new RegExp(`\\n\\s+${name}:\\s*\\n?\\s*"([^"]*)"`))?.[1] ?? '';
const OFFSET =
    'focus-visible:ring-offset-2 focus-visible:ring-offset-background';

test('DD-74: the shared keyboard focus ring is full strength, 2 px, focus-visible only', () => {
    assert.match(button, /focus-visible:ring-ring focus-visible:ring-2/);
    assert.doesNotMatch(
        button,
        /focus-visible:ring-ring\/\d|ring-destructive\/20 dark:focus-visible|ring-brand\/35/,
    );
});

test('DD-74 Daniel: filled buttons get a 2 px gap in the surface colour; outline and ghost do not', () => {
    for (const name of ['default', 'primary', 'accent', 'destructive']) {
        assert.ok(variant(name).includes(OFFSET), name);
    }

    for (const name of ['outline', 'secondary', 'ghost', 'link']) {
        assert.ok(!variant(name).includes('ring-offset'), name);
    }
});
