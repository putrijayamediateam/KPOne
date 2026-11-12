import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const pager = readFileSync(
    'resources/js/components/ui/pagination/CompactPagination.vue',
    'utf8',
);

test('DD-66: Previous and Next are 44 px tall below 1024 px and keep the small size from lg', () => {
    assert.equal(
        (pager.match(/class="max-lg:h-11 max-lg:px-4"/g) ?? []).length,
        2,
    );
    assert.match(pager, /size="sm"/);
});

test('DD-47: one record is singular', () => {
    const helper = readFileSync('resources/js/lib/pager.ts', 'utf8');
    assert.match(helper, /total === 1 \? 'record' : 'records'/);
    assert.match(pager, /recordsLabel\(total\)/);
});

test('DD-47: the shared recordsLabel reads 1 record, N records and 0 records', async () => {
    const { recordsLabel } = await import('../../resources/js/lib/pager.ts');
    assert.equal(recordsLabel(1), '1 record');
    assert.equal(recordsLabel(5), '5 records');
    assert.equal(recordsLabel(0), '0 records');
});
