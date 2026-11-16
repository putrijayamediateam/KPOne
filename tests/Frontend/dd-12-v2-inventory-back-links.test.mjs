import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';

const dir = new URL('../../resources/js/pages/Inventory/V2/', import.meta.url);
const phoneBack =
    'class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"';

test('DD-12: every V2 Inventory sub-page, Stock value included, has the same phone-only back link', () => {
    const pages = readdirSync(dir).filter(
        (name) => name.endsWith('.vue') && name !== 'Index.vue',
    );
    assert.ok(pages.includes('StockValue.vue'));

    for (const name of pages) {
        const source = readFileSync(new URL(name, dir), 'utf8');
        const flat = source.replace(/\s+/g, ' ');
        assert.match(flat, /data-action="back"/, name);
        assert.ok(flat.includes(phoneBack), name);
    }
});
