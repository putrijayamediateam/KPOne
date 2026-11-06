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

test('DSP-11: a scan matches only lines whose saved SKU is the scanned SKU', () => {
    assert.deepEqual(
        lib.matchingLines('sku-amx', lines).map((line) => line.publicId),
        ['b'],
    );
    assert.deepEqual(
        lib.matchingLines('sku-pcm', lines).map((line) => line.publicId),
        ['a', 'c'],
    );
    assert.deepEqual(lib.matchingLines('sku-other-strength', lines), []);
});

test('DSP-11: plain wording for a match, a repeat, several lines and a wrong medicine', () => {
    assert.equal(
        lib.matchMessage([lines[1]], false, 1, 3),
        'Matches: Amoxicillin 250 mg. Marked as checked. 1 of 3 medicines checked.',
    );
    assert.equal(
        lib.matchMessage([lines[0], lines[2]], false, 3, 3),
        'Matches 2 lines: Paracetamol 500 mg. Both marked as checked. 3 of 3 medicines checked.',
    );
    assert.equal(
        lib.matchMessage([lines[1]], true, 1, 1),
        'Already checked: Amoxicillin 250 mg. 1 of 1 medicine checked.',
    );
    assert.equal(
        lib.notOnCaseMessage('Ibuprofen', 'IBU-400'),
        'Not on this case: Ibuprofen (IBU-400). Check you picked the right medicine or strength.',
    );
});

test('DSP-11: the Dispensary page checks by scan on screen only and never saves or blocks', () => {
    assert.match(
        show,
        /<ScanInput[\s\S]*?v-if="dispensary\.can\.update && visibleItems\.length"[\s\S]*?context="dispensary"/,
    );
    assert.match(show, /:accept="acceptScan"/);
    assert.match(show, /@found="markScanned"/);
    assert.match(show, /label="Checked by scan"/);
    assert.match(show, /tone="success"/);
    // A line stays checked only while its saved SKU is the one that was scanned.
    assert.match(
        show,
        /scanChecked\.value\[item\.publicId\] === item\.sku\.publicId/,
    );
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

test('DSP-11: the shared scan box keeps STK-9 wording elsewhere and shows the warning as a panel here', () => {
    assert.match(scan, /'search' \| 'receiving' \| 'dispensary'/);
    assert.match(
        scan,
        /Scanning only finds the SKU; it does\s+not save anything\./,
    );
    assert.match(
        scan,
        /Scan the box you picked to check it against the lines below\.\s+Scanning only checks\. It does not save or dispense anything\./,
    );
    assert.match(scan, /border-destructive\/40 bg-destructive\/10/);
    assert.match(scan, /<TriangleAlertIcon[\s\S]*?aria-hidden="true"/);
    assert.match(scan, /props\.describe\?\.\(result\.sku\) \?\?/);
    // Only the search context remembers a message across a page reload.
    assert.match(scan, /if \(props\.context === 'search'\)/);
});
