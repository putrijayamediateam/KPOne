import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const scan = read('resources/js/components/inventory/ScanInput.vue');

test('ScanInput: an optional shorter help line; each context keeps its own text without it', () => {
    assert.match(scan, /help\?: string;/);
    assert.match(scan, /v-if="help"[\s\S]*?\{\{ help \}\}/);
    // The Classic texts are still there for pages that pass no help.
    assert.match(
        scan,
        /Scan the box you picked to check it against the lines below\./,
    );
    assert.match(
        scan,
        /Use a handheld scanner, type the code, or use the camera\./,
    );
});

test('ScanInput: hideUnavailableCamera hides "Use camera" and its paragraph only when the device cannot scan', () => {
    assert.match(scan, /hideUnavailableCamera\?: boolean;/);
    assert.match(
        scan,
        /cameraState\.value === 'unsupported' \|\|\s*cameraState\.value === 'insecure'/,
    );
    assert.match(scan, /v-if="!cameraHidden"/);
    assert.match(scan, /cameraState !== 'supported' && !cameraHidden/);
    // Off by default: the Classic pages pass nothing and keep the disabled button and its reason.
    assert.match(scan, /!!props\.hideUnavailableCamera &&/);

    for (const page of [
        'resources/js/pages/Inventory/GovernedStock.vue',
        'resources/js/pages/Inventory/SupplierReturns.vue',
        'resources/js/pages/Inventory/TreatmentUsage.vue',
        'resources/js/components/inventory/VerifiedReceivingForm.vue',
        'resources/js/pages/Dispensary/Show.vue',
    ]) {
        assert.ok(!read(page).includes('hide-unavailable-camera'), page);
    }
});
