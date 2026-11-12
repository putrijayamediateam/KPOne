import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('Staff Show and Index: Active is success and Inactive is neutral, not red', () => {
    for (const file of [
        'resources/js/pages/Staff/Show.vue',
        'resources/js/pages/Staff/Index.vue',
    ]) {
        const source = read(file);
        assert.match(source, /<StatusBadge/);
        assert.match(source, /'success'\s*:\s*'neutral'/);
        assert.doesNotMatch(source, /'secondary' : 'destructive'/);
        assert.doesNotMatch(source, /'secondary'\s*:\s*'destructive'/);
    }
});
