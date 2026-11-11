import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const header = readFileSync(
    new URL(
        '../../resources/js/components/ui/page-header/PageHeader.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-63: both page titles balance their lines', () => {
    const titles = header.match(/<h1[^>]*class="([^"]+)"/g) ?? [];
    assert.equal(titles.length, 2);

    for (const title of titles) {
        assert.match(title, /text-balance/);
    }
});
