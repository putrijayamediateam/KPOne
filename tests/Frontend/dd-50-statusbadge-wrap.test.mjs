import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const badge = readFileSync(
    new URL(
        '../../resources/js/components/ui/status/StatusBadge.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-50: a long status label wraps inside the badge instead of widening the page', () => {
    assert.match(badge, /max-w-full/);
    assert.match(badge, /break-words/);
    assert.match(badge, /rounded-\[10px\]/);
    assert.doesNotMatch(badge, /whitespace-nowrap/);
});
