import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/scan.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module },
);
const lib = module.exports;
const scan = read('resources/js/components/inventory/ScanInput.vue');
const receiving = read(
    'resources/js/components/inventory/VerifiedReceivingForm.vue',
);

test('scanner control characters are trimmed but the code is never changed', () => {
    assert.equal(lib.cleanScannedCode(' 9556001234567\t\r\n'), '9556001234567');
    assert.equal(lib.cleanScannedCode('abc-123'), 'abc-123');
    assert.equal(lib.cleanScannedCode('\u0002A1\u0003'), 'A1');
    assert.equal(lib.cleanScannedCode('9'.repeat(150)).length, 100);
});

test('every lookup result has plain wording, and a shared code is never resolved', () => {
    const sku = {
        public_id: 'x',
        sku_code: 'PCM-500',
        item_name: 'Paracetamol',
        stock_unit: 'tablet',
        active: true,
    };
    assert.equal(
        lib.scanMessage({ status: 'found', code: '1', sku }, 'search'),
        'Found SKU PCM-500 · Paracetamol, showing its stock.',
    );
    assert.match(
        lib.scanMessage({ status: 'found', code: '1', sku }, 'receiving'),
        /Pick the batch by hand/,
    );
    assert.match(
        lib.scanMessage({ status: 'not_found', code: '9556' }, 'search'),
        /No SKU has the code 9556/,
    );
    assert.match(
        lib.scanMessage(
            { status: 'shared', code: '1', skus: ['A', 'B'] },
            'search',
        ),
        /more than one SKU\. Fix it on the Pilot readiness page/,
    );
    assert.match(
        lib.scanMessage(
            { status: 'found', code: '1', sku: { ...sku, active: false } },
            'search',
        ),
        /is inactive, so it has no stock to show here/,
    );
});

test('camera support uses only the built-in reader and needs a secure page', () => {
    const media = { mediaDevices: { getUserMedia() {} } };
    assert.equal(
        lib.cameraSupport({
            isSecureContext: true,
            BarcodeDetector: function Detector() {},
            navigator: media,
        }),
        'supported',
    );
    assert.equal(
        lib.cameraSupport({ isSecureContext: true, navigator: media }),
        'unsupported',
    );
    assert.equal(
        lib.cameraSupport({
            isSecureContext: false,
            BarcodeDetector: function Detector() {},
            navigator: media,
        }),
        'insecure',
    );
    assert.doesNotMatch(scan, /from '(zxing|quagga|html5-qrcode)/);
});

test('repeat reads within one second are ignored', () => {
    assert.equal(lib.isRepeat(null, 'A', 0), false);
    assert.equal(lib.isRepeat({ code: 'A', at: 0 }, 'A', 999), true);
    assert.equal(lib.isRepeat({ code: 'A', at: 0 }, 'A', 1000), false);
    assert.equal(lib.isRepeat({ code: 'A', at: 0 }, 'B', 10), false);
});

test('the scanner Enter never submits a surrounding form, and the camera always stops', () => {
    assert.match(scan, /@keydown\.enter\.prevent\.stop="lookup\(code\)"/);
    assert.doesNotMatch(scan, /type="submit"/);
    assert.match(scan, /track\.stop\(\)/);
    assert.match(scan, /onBeforeUnmount\(/);
    assert.match(scan, /visibilitychange/);
    assert.match(scan, /role="status" aria-live="polite"/);
    assert.match(scan, /role="alert"\s+aria-live="assertive"/);
    assert.match(
        scan,
        /if \(unmounted\) \{\s+media\.getTracks\(\)\.forEach\(\(track\) => track\.stop\(\)\)/,
    );
    assert.match(scan, /if \(starting\.value \|\| open\.value\)/);
    assert.doesNotMatch(scan, /setInterval/);
    assert.match(scan, /input\.value\?\.select\(\)/);
    assert.doesNotMatch(scan, /v-html/);
});

test('scanning is placed on the four Inventory screens and only finds', () => {
    for (const page of [
        'resources/js/pages/Inventory/GovernedStock.vue',
        'resources/js/pages/Inventory/SupplierReturns.vue',
        'resources/js/pages/Inventory/TreatmentUsage.vue',
    ]) {
        assert.match(read(page), /<ScanInput[\s\S]*?context="search"/, page);
    }

    assert.match(
        read('resources/js/pages/Inventory/TreatmentUsage.vue'),
        /stockQ\.value = sku\.sku_code/,
    );
    assert.match(receiving, /context="receiving"/);
    assert.match(
        receiving,
        /is not on this receiving order\. Nothing was changed\./,
    );
    assert.match(receiving, /line\.skuPublicId === sku\.public_id/);
    assert.match(receiving, />Scanned</);
    // A scan never moves the cursor into a receiving field, so the next scan's Enter cannot submit the receipt.
    assert.doesNotMatch(receiving, /\.focus\(\)/);
});

test('the Pilot readiness page lists shared barcodes', () => {
    const readiness = read('resources/js/pages/Inventory/Readiness.vue');
    assert.match(readiness, /Barcodes shared by more than one SKU/);
    assert.match(readiness, /No shared barcodes\./);
    assert.match(
        readiness,
        /\/inventory\/readiness\/barcode\/\$\{skuPublicId\}/,
    );
    assert.match(readiness, /v-if="readiness\.canFixBarcodes"/);
    assert.doesNotMatch(readiness, /Fix in Stock setup/);
});
