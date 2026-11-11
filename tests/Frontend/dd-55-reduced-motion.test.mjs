import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

test('DD-55: a reduce-motion block stops transitions and animation but keeps the spinner turning', () => {
    const block = css.slice(
        css.indexOf('@media (prefers-reduced-motion: reduce)'),
    );
    assert.match(block, /animation-duration: 0\.01ms !important/);
    assert.match(block, /animation-iteration-count: 1 !important/);
    assert.match(block, /transition-duration: 0\.01ms !important/);
    assert.match(
        block,
        /\.animate-spin\s*\{[^}]*animation-iteration-count: infinite !important/,
    );
});
