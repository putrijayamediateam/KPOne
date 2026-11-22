// Classic only, CO-10c: the last V2 leftovers are gone and the Classic behaviour they sat beside is unchanged.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('Call next opens its confirm dialog directly', () => {
    const control = read('resources/js/components/queue/CallNextControl.vue');
    const held = read('resources/js/components/queue/HeldCallNext.vue');

    assert.match(
        control,
        /const start = \(\) => \{\s*if \(unavailable\.value \|\| processing\.value\) \{\s*return;\s*\}\s*openConfirm\(\);\s*\};/,
    );
    assert.doesNotMatch(control, /beforeStart/);
    assert.doesNotMatch(held, /beforeStart|before-start/);
});

test('the inventory panel always shows orders and requests', () => {
    const panel = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );

    assert.doesNotMatch(panel, /hideOrders|hideRequests/);
    assert.match(panel, /<div class="grid gap-4 xl:grid-cols-2">/);
    assert.match(panel, /Purchase Orders & Receiving/);
    assert.match(panel, /Stock Requests & Transfers/);
});

test('ScanInput keeps its per-context help and the disabled camera button with its reason', () => {
    const scan = read('resources/js/components/inventory/ScanInput.vue');

    assert.doesNotMatch(
        scan,
        /hideUnavailableCamera|cameraHidden|help\?: string/,
    );
    assert.match(
        scan,
        /class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto"/,
    );
    assert.match(
        scan,
        /:disabled="starting \|\| cameraState !== 'supported'"[\s\S]*?Use camera/,
    );
    assert.match(scan, /v-if="cameraState !== 'supported'"/);
    assert.match(scan, /\{\{ cameraMessages\[cameraState\] \}\}/);
    assert.match(scan, /v-if="context === 'dispensary-add'"/);
    assert.match(
        scan,
        /Scan the box you picked to check it against the lines below\./,
    );
    assert.match(
        scan,
        /Use a handheld scanner, type the code, or use the camera\./,
    );
});

test('WorkPermitWarnings keeps its corners and WhatsAppLink keeps its label', () => {
    const permits = read(
        'resources/js/components/hr/credentials/WorkPermitWarnings.vue',
    );
    const whatsapp = read('resources/js/components/patient/WhatsAppLink.vue');

    assert.doesNotMatch(permits, /v2/);
    assert.match(
        permits,
        /class="flex flex-col gap-2 rounded-xl border border-warning\/40 bg-warning\/10 p-4"/,
    );
    assert.doesNotMatch(whatsapp, /label\?: string/);
    assert.match(whatsapp, /aria-label="Open WhatsApp chat"/);
});

test('MaskedValue still sends the audited Show request', () => {
    assert.match(
        read('resources/js/components/hr/records/MaskedValue.vue'),
        /import \{ requestShowValue \} from '@\/lib\/hr-show-value';/,
    );
    assert.doesNotMatch(
        read('resources/js/lib/hr-show-value.ts'),
        /revealValue/,
    );
});

test('the Classic Leave line still drops stale day-count checks', () => {
    const leave = read('resources/js/composables/hr/useLeavePage.ts');

    assert.doesNotMatch(leave, /preview\.counting|counting: false/);
    assert.match(leave, /let previewPending = false;/);
    assert.match(
        leave,
        /if \(seq === previewSeq\) \{\s*previewPending = false;/,
    );
    assert.match(
        leave,
        /if \(previewPending\) \{\s*previewPending = false;\s*preview\.text = '';/,
    );
    assert.match(
        leave,
        /if \(previewPending && applyOpen\.value\) \{\s*void runPreview\(\);/,
    );
});

test('none of the removed names remain', () => {
    // beforeStart is checked on the Call next components above; Cash closing has its own unrelated `beforeStart`.
    const names = [
        'hideOrders',
        'hideRequests',
        'hideUnavailableCamera',
        'revealValue',
        'saveSettingsKeepingState',
        // The stock request field only; StockRequestService and pricing keep their own local variables of that name.
        'source.public_id as sourcePublicId',
        'sourcePublicId?:',
        'later_governed',
        "cannotDecideReason' =>",
        'catalogue-panels',
        'CataloguePanelController',
        'CataloguePanelStoreRequest',
        'PanelAdministrationService',
    ];

    const root = new URL('../../', import.meta.url);
    const files = ['resources/js', 'app', 'routes'].flatMap((dir) =>
        readdirSync(new URL(dir, root), { recursive: true })
            .filter((f) => /\.(vue|ts|php)$/.test(f))
            // Generated route helpers are not source.
            .filter((f) => !/^(actions|routes|wayfinder)\//.test(f))
            .map((f) => `${dir}/${f}`),
    );

    for (const name of names) {
        const found = files.filter((f) => read(f).includes(name));

        assert.deepEqual(found, [], `${name} still appears`);
    }
});
