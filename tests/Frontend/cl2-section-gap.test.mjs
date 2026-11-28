import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('resources/css/space-shell-layout.css', 'utf8');
const block = css.slice(css.indexOf('Homes are a flow of cards'));

test('a full-width block on a home brings its own gap above it', () => {
    assert.match(
        block,
        /\.space-main > \* \{\s*column-span: all;\s*margin-top: 16px;\s*margin-bottom: 16px;/,
    );
    assert.match(block, /\.space-main > :first-child \{\s*margin-top: 0;/);
});

test('cards stay level at the top of a column and the card before a full-width block has no bottom margin', () => {
    assert.match(
        block,
        /\.space-main \.space-card \{\s*column-span: none;\s*break-inside: avoid;\s*margin-top: 0;\s*margin-bottom: 16px;/,
    );
    assert.match(block, /\.space-card:has\(\+ \.space-recent\)/);
});
