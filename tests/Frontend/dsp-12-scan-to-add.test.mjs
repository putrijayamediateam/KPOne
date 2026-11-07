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
const scanLib = load('resources/js/lib/scan.ts');
const show = read('resources/js/pages/Dispensary/Show.vue');
const scan = read('resources/js/components/inventory/ScanInput.vue');
const sku = { public_id: 's', sku_code: 'PCM-500', item_name: 'Paracetamol' };
const med = (over = {}) => ({
    name: 'Panadol',
    strength: '500 mg',
    availability: [{}],
    allergyMatches: [],
    ...over,
});

test('DSP-12: one stocked medicine with no allergy match is chosen; anything else fills the list', () => {
    const one = lib.scanAddOutcome([med()], sku);
    assert.equal(one.kind, 'choose');
    assert.equal(
        one.message,
        'Found Panadol 500 mg. Set the quantity and batch, then press Add.',
    );

    const allergy = lib.scanAddOutcome([med({ allergyMatches: [{}] })], sku);
    assert.equal(allergy.kind, 'list');
    assert.equal(allergy.allergy, true);
    assert.equal(
        allergy.message,
        'Found Panadol 500 mg. Possible allergy match: check the allergy list before you choose it.',
    );

    const empty = lib.scanAddOutcome([med({ availability: [] })], sku);
    assert.equal(empty.kind, 'list');
    assert.equal(
        empty.message,
        'Found Panadol 500 mg, but there is no stock to dispense at this branch.',
    );

    assert.equal(
        lib.scanAddOutcome([med(), med()], sku).message,
        'Found 2 medicines for this product. Choose one.',
    );
    assert.equal(
        lib.scanAddOutcome([], sku).message,
        'PCM-500 · Paracetamol is not linked to a catalogue medicine, so it cannot be added by scan. Search by name, or ask a CA Supervisor to link it.',
    );
});

test('DSP-12: unknown, shared and inactive codes point back to the name search', () => {
    assert.equal(
        scanLib.scanMessage(
            { status: 'not_found', code: '1' },
            'dispensary-add',
        ),
        'No medicine has the code 1. Search by name instead.',
    );
    assert.match(
        scanLib.scanMessage(
            { status: 'shared', code: '1', skus: [] },
            'dispensary-add',
        ),
        /Search by name instead\.$/,
    );
    const inactive = {
        status: 'found',
        code: '1',
        sku: {
            public_id: 'x',
            sku_code: 'A',
            item_name: 'B',
            stock_unit: 'tab',
            active: false,
        },
    };
    assert.equal(
        scanLib.scanMessage(inactive, 'dispensary-add'),
        'SKU A · B is inactive. Search by name instead.',
    );
});

test('DSP-12: a Scan to add button opens the scan box; the scan only finds and never adds', () => {
    assert.match(show, /data-action="scan-to-add"/);
    assert.match(
        show,
        /:aria-expanded="scanAddOpen"\s+:aria-controls="\s*scanAddOpen \? 'scan-add-panel' : undefined\s*"/,
    );
    assert.match(
        show,
        /<ScanInput\s+context="dispensary-add"\s+refocus-key="dispensary-add"/,
    );
    assert.match(show, /\/medicines\/by-sku`/);
    const handler = show.slice(
        show.indexOf('const scanToAdd'),
        show.indexOf('const refreshChosen'),
    );
    assert.doesNotMatch(
        handler,
        /addMedicine\(|router\.post/,
        'a scan never presses Add',
    );
    assert.match(handler, /chooseMedicine\(outcome\.medicine\)/);
    assert.match(show, /data-testid="scan-add-allergy"/);
    assert.match(
        scan,
        /You still set the quantity, directions and batch, then press Add\./,
    );
    assert.match(
        scan,
        /alerting\.value && props\.context !== 'dispensary-add'/,
    );
});

test('DSP-12 review: only the newest scan counts, a scan never replaces typed directions or runs during a save', () => {
    const handler = show.slice(
        show.indexOf('const scanToAdd'),
        show.indexOf('const refreshChosen'),
    );
    assert.match(handler, /const generation = \+\+scanAddGeneration;/);
    assert.match(
        handler,
        /if \(generation !== scanAddGeneration \|\| busy\.value\) \{\s+return;/,
    );
    assert.match(handler, /Wait for the save to finish, then scan again\./);
    assert.match(
        handler,
        /Finish or cancel the medicine you are adding first, then scan again\./,
    );
    // A manual search or choosing another medicine clears an old allergy warning.
    assert.match(
        show,
        /const searchMedicines = async \(\) => \{\s+clearScanAdd\(\);/,
    );
    assert.match(show, /@click="pickMedicine\(medicine\)"/);
    // Closing the scan box returns focus to the button, and the success is announced once (by the page).
    assert.match(show, /@keydown\.esc="closeScanAdd"/);
    assert.match(show, /:describe="\(\) => ''"/);
});
