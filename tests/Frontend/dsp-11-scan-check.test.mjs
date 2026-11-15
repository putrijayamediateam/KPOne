import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const load = (path) => {
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(read(path), {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, module },
    );

    return module.exports;
};
const lib = load('resources/js/lib/dispensary-scan.ts');
const show = read('resources/js/pages/Dispensary/Show.vue');
const scan = read('resources/js/components/inventory/ScanInput.vue');

const lines = [
    {
        publicId: 'a',
        name: 'Paracetamol',
        strength: '500 mg',
        skuPublicId: 'sku-pcm',
    },
    {
        publicId: 'b',
        name: 'Amoxicillin',
        strength: '250 mg',
        skuPublicId: 'sku-amx',
    },
    {
        publicId: 'c',
        name: 'Paracetamol',
        strength: '500 mg',
        skuPublicId: 'sku-pcm',
    },
    { publicId: 'd', name: 'Unmapped', strength: null, skuPublicId: null },
];
const removed = [
    {
        publicId: 'r',
        name: 'Ibuprofen',
        strength: '400 mg',
        skuPublicId: 'sku-ibu',
    },
];
const sku = (id, name = 'Product', code = 'SKU-X') => ({
    public_id: id,
    item_name: name,
    sku_code: code,
});

test('DSP-11: a scan matches only shown lines whose saved SKU is the scanned SKU', () => {
    assert.deepEqual(
        lib.matchingLines('sku-amx', lines).map((l) => l.publicId),
        ['b'],
    );
    assert.deepEqual(
        lib.matchingLines('sku-pcm', lines).map((l) => l.publicId),
        ['a', 'c'],
    );
    assert.deepEqual(lib.matchingLines('sku-other-strength', lines), []);
    assert.equal(
        lib.scannableCount(lines),
        3,
        'a line with no SKU is not counted',
    );
});

test('DSP-11: wording says the check is on this screen only and counts lines', () => {
    const one = lib.checkScan(sku('sku-amx'), lines, removed, {});
    assert.equal(one.kind, 'match');
    assert.equal(
        one.message,
        'Matches: Amoxicillin 250 mg. 1 of 3 lines checked by scan. Shown on this screen only.',
    );
    assert.deepEqual({ ...one.marks }, { b: 'sku-amx' });

    const two = lib.checkScan(sku('sku-pcm'), lines, removed, one.marks);
    assert.equal(
        two.message,
        'Matches 2 lines: Paracetamol 500 mg. 3 of 3 lines checked by scan. Shown on this screen only.',
    );

    const again = lib.checkScan(sku('sku-amx'), lines, removed, two.marks);
    assert.equal(again.kind, 'already');
    assert.equal(
        again.message,
        'Already checked: Amoxicillin 250 mg. 3 of 3 lines checked by scan. Shown on this screen only.',
    );

    assert.deepEqual(
        { ...lib.checkScan(sku('sku-ibu'), lines, removed, {}) },
        {
            kind: 'removed',
            message:
                'On this case but removed: Ibuprofen 400 mg. Check you picked the right medicine.',
        },
    );
    assert.deepEqual(
        {
            ...lib.checkScan(
                sku('sku-other', 'Amoxicillin', 'AMX-500'),
                lines,
                removed,
                {},
            ),
        },
        {
            kind: 'not_on_case',
            message:
                'Not on this case: Amoxicillin (AMX-500). Check you picked the right medicine or strength.',
        },
    );
});

test('DSP-11: a mark lasts only while the line is shown with the scanned SKU', () => {
    const marks = { a: 'sku-pcm', b: 'sku-amx' };
    assert.equal(lib.checkedCount(lines, marks), 2);
    // Line b changed to another SKU after a save, and line a was removed: both lose their mark.
    const after = [{ ...lines[1], skuPublicId: 'sku-amx-500' }, lines[2]];
    assert.deepEqual({ ...lib.keepCurrentMarks(after, marks) }, {});
    // Restoring line a later does not bring its mark back.
    const kept = lib.keepCurrentMarks(after, marks);
    assert.equal(lib.isChecked(lines[0], kept), false);
});

test('DSP-11: the Dispensary page checks by scan on screen only and never saves or blocks', () => {
    assert.match(
        show,
        /<ScanInput[\s\S]*?v-if="dispensary\.can\.update && visibleItems\.length"[\s\S]*?context="dispensary"/,
    );
    assert.match(show, /:accept="acceptScan"/);
    assert.match(show, /@found="markScanned"/);
    assert.match(show, /label="Checked by scan"/);
    assert.match(show, /class="sr-only"\s*>, not saved</);
    assert.match(
        show,
        /\{\{ scanChecked \}\} of \{\{ scanTotal \}\} \(not saved\)/,
    );
    assert.match(show, /watch\(scanLines,/);
    const block = show.slice(
        show.indexOf('// DSP-11'),
        show.indexOf('const unsaved = computed'),
    );
    assert.doesNotMatch(
        block,
        /router\.|fetch\(|requestJson|post\(|completeCase|completeBlockedReason/,
    );
    assert.doesNotMatch(
        show.slice(
            show.indexOf('const completeBlockedReason'),
            show.indexOf('const completeCase'),
        ),
        /scan/i,
    );
});

test('DSP-11: the shared scan box keeps STK-9 behaviour elsewhere; only a wrong medicine interrupts here', () => {
    assert.match(scan, /'search' \| 'receiving' \| 'dispensary'/);
    assert.match(
        scan,
        /Scanning only finds the SKU; it does\s+not save anything\./,
    );
    assert.match(
        scan,
        /Scanning only checks\. It does not save or dispense anything\. It\s+doesn't check the quantity or the batch\./,
    );
    assert.match(scan, /border-destructive\/40 bg-destructive\/10/);
    assert.match(scan, /<TriangleAlertIcon[\s\S]*?aria-hidden="true"/);
    assert.match(scan, /props\.describe\?\.\(result\.sku\) \?\?/);
    assert.match(scan, /show\(refused, true, true\)/);
    assert.match(
        scan,
        /failed\.value && \(props\.context !== 'dispensary' \|\| warning\.value\)/,
    );
    // The warning panel and its icon are only ever in the dispensary context.
    assert.match(scan, /context === 'dispensary' && alerting\s*\?/);
    assert.match(scan, /v-if="context === 'dispensary' && alerting"/);
    // Only the search context remembers a message across a page reload.
    assert.match(scan, /if \(props\.context === 'search'\)/);
});

test('DSP-11: unknown, shared and inactive codes get Dispensary wording; Inventory wording is unchanged', () => {
    const scanLib = load('resources/js/lib/scan.ts');
    assert.equal(
        scanLib.scanMessage({ status: 'not_found', code: '123' }, 'dispensary'),
        'No medicine has the code 123. Check the code, or check the box by eye.',
    );
    assert.equal(
        scanLib.scanMessage(
            { status: 'shared', code: '123', skus: ['A', 'B'] },
            'dispensary',
        ),
        'This code is on more than one SKU, so it cannot be checked by scan. Check the box by eye.',
    );
    const inactive = {
        status: 'found',
        code: '1',
        sku: {
            public_id: 'x',
            sku_code: 'AMX',
            item_name: 'Amoxicillin',
            stock_unit: 'capsule',
            active: false,
        },
    };
    assert.equal(
        scanLib.scanMessage(inactive, 'dispensary'),
        'SKU AMX · Amoxicillin is inactive. Check the box by eye.',
    );
    assert.equal(
        scanLib.scanMessage(inactive, 'search'),
        'SKU AMX · Amoxicillin is inactive, so it has no stock to show here.',
    );
    assert.equal(
        scanLib.scanMessage({ status: 'not_found', code: '123' }, 'search'),
        'No SKU has the code 123. Check the code or search by name.',
    );
});
