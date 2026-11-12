import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Inventory/OpeningImport.vue');

test('STK-35: the page says what it is and is not', () => {
    assert.match(page, /Synthetic data only\. Not a Yezza data migration\./);
    assert.match(page, /Cost\s+policy to be confirmed by the accountant\./);
    assert.match(
        page,
        /needs sign-off on Pilot readiness by someone other than you\./,
    );
    assert.match(page, /Nothing is recorded until\s+every\s+row is OK\./);
});

test('STK-35: check first, then record the same file with its hash and one key', () => {
    assert.match(page, /'\/inventory\/opening-import\/check'/);
    assert.match(page, /file_hash: checked\.value\.hash,/);
    assert.match(page, /idempotency_key: importKey\.value,/);
    assert.match(page, /forceFormData: true,/);
    // Recording is only possible once every row is OK.
    assert.match(
        page,
        /checked\.value !== null && checked\.value\.errors === 0/,
    );
    assert.match(page, /:disabled="recording \|\| !ready"/);
    // A new file gets a new key and must be checked again.
    assert.match(
        page,
        /checked\.value = null;\s*fileError\.value = undefined;\s*importKey\.value = crypto\.randomUUID\(\);/,
    );
});

test('STK-35: Pilot readiness links to the import for CA Supervisors only', () => {
    const readiness = read('resources/js/pages/Inventory/Readiness.vue');
    assert.match(readiness, /v-if="readiness\.canImportOpening"/);
    assert.match(readiness, /href="\/inventory\/opening-import"/);
});
