import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Medicine Catalogue: look only. Same routes, same payloads, same rules as Classic; Classic is untouched.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Medicine/Index.vue');
const v2 = read('resources/js/pages/Medicine/V2/Index.vue');
const form = read('resources/js/components/medicine-v2/MedicineForm.vue');
const formLogic = read('resources/js/composables/medicine/useMedicineForm.ts');
const rowLogic = read(
    'resources/js/composables/medicine/useMedicineRowActions.ts',
);
const registry = read('config/v2-pages.php');
const normal = (s) => s.replace(/\s+/g, ' ');

test('Medicine/Index is registered for Version 2 and the V2 files exist', () => {
    assert.match(registry, /'Medicine\/Index'/);
    assert.ok(v2.includes('<V2WorkLayout'));
    assert.ok(v2.includes('<PageFrame'));
    assert.ok(form.includes('<V2FormCard'));
});

test('V2 uses the same routes as Classic', () => {
    for (const route of [
        "'/medicines'",
        '`/medicines/${row.publicId}/setup`',
        '`/medicines/${editing.value.publicId}`',
        "`/medicines/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`",
        '`/medicines/${row.publicId}/controlled`',
        '`/medicines/${row.publicId}/allergy-groups`',
        '`/medicines/${row.publicId}/allergy-aliases`',
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
        assert.ok(
            [v2, formLogic, rowLogic].some((s) => s.includes(route)),
            `v2 ${route}`,
        );
    }
});

test('the form state and the payload are a faithful copy of Classic', () => {
    const between = (s, a, b) => s.slice(s.indexOf(a), s.indexOf(b));
    const classicForm = normal(
        between(classic, 'const form = useForm({', 'const openCreate'),
    );
    const v2Form = normal(
        between(formLogic, 'const form = useForm({', 'const openCreate'),
    ).replaceAll('setup.', 'props.setup.');

    assert.equal(v2Form, classicForm);

    const squash = (text) =>
        text.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');
    const classicSubmit = squash(
        between(classic, 'const submit = () => {', 'const pendingToggle'),
    )
        .replaceAll('dialogOpen', 'formOpen')
        .replaceAll('props.setup', 'setup');
    const v2Submit = squash(
        between(formLogic, 'const submit = () => {', '// Unsaved-work check'),
    );

    assert.equal(v2Submit, classicSubmit);

    for (const key of [
        'self_pay_sen',
        'panel_default_sen',
        'amount_sen',
        'unit_cost_sen',
        'expected_branch_id',
        'opening_stock',
    ]) {
        assert.ok(classic.includes(key) && formLogic.includes(key), key);
    }
});

test('the row actions keep Classic payloads and stale-list handling', () => {
    for (const text of [
        'is_controlled: !row.isControlled',
        'expected_is_controlled: row.isControlled',
        'expected_allergy_groups: row.allergyGroups',
        'expected_allergy_aliases: row.allergyAliases',
        "message.startsWith('This medicine changed.')",
        'Could not change the controlled medicine mark. Reload and try again.',
    ]) {
        assert.ok(classic.includes(text) && rowLogic.includes(text), text);
    }
});

test('V2 keeps the Classic wording of the confirms and the allergy dialogs', () => {
    for (const text of [
        'will no longer be available to order on a Treatment Plan.',
        'Ubat Terkawal/Controlled Medicine',
        'The Dispensary allergy warning checks a patient',
        'They are never printed on drug labels and do not change the medicine name.',
        'Prints on drug labels: up to 72 characters.',
    ]) {
        const inClassic = normal(classic).includes(text);

        assert.ok(inClassic, `classic ${text}`);
        assert.ok(
            [v2, form, formLogic, rowLogic].some((s) =>
                normal(s).includes(text),
            ),
            `v2 ${text}`,
        );
    }
});

test('V2 form: shared pickers reused, required fields marked, optional dosage behind a bar, sticky save on phones', () => {
    for (const picker of [
        'CatalogueOptionPicker',
        'InventorySkuPicker',
        'LocationPicker',
        'PanelTariffEditor',
        'SupplierPicker',
    ]) {
        assert.ok(form.includes(picker), picker);
    }

    assert.match(form, /label="Catalogue code"\s+required/);
    assert.match(form, /label="Display name"\s+required/);
    assert.ok(form.includes('Dosage and instructions (optional)'));
    assert.ok(form.includes('data-test="medicine-form-actions"'));
    assert.ok(form.includes('v-if="setup.canManageInventory"'));
    assert.ok(form.includes('v-if="setup.canManagePrices"'));
    assert.ok(form.includes('v-if="setup.canReceiveStock"'));
    assert.ok(form.includes('v-if="setup.canPublishPrices"'));
});

test('V2 list: one filled page button, row menu with Danger last, no native select or file input, back link goes to the list', () => {
    assert.equal((v2.match(/filledButton,/g) ?? []).length, 0);
    assert.equal((v2.match(/:class="filledButton"/g) ?? []).length, 1);
    assert.doesNotMatch(v2 + form, /<select[\s>]/);
    assert.doesNotMatch(v2 + form, /type="file"/);
    assert.match(v2, /← Medicines/);
    assert.match(v2, /data-action="back-to-medicines"/);
    assert.ok(v2.indexOf("label: 'Status'") > v2.indexOf("label: 'Labels'"));
    assert.ok(v2.includes('data-action="open-filters"'));
    assert.match(v2, /max-xl:hidden!/);
});

test('Classic keeps its own page and does not import V2 pieces', () => {
    assert.doesNotMatch(
        classic,
        /components\/v2|medicine-v2|composables\/medicine/,
    );
});

test('prices wrap in cards (no sideways scroll at 320) and a missing price reads "No price"', () => {
    assert.ok(v2.includes("'no price'"));
    assert.match(v2, /Self-pay:/);
    assert.match(v2, /Default Panel:/);
    assert.doesNotMatch(v2, /class="block whitespace-nowrap"/);
    assert.match(v2, /xl:whitespace-nowrap/);
});

test("Daniel's changes: URL state, neutral Controlled chip, always-visible save bar, unsaved-work guard, Controlled words", () => {
    // a. real URL state, front end only
    assert.match(v2, /add: '1'/);
    assert.match(v2, /edit: row\.publicId/);
    assert.match(v2, /watch\(\(\) => page\.url, syncFromUrl/);
    assert.match(v2, /window\.history\.back\(\)/);
    // One shared question for every way out with unsaved work: a menu link or crumb (V2LeaveGuard's router rule) and the
    // same-path form address (← Medicines, Back to the list) through guard.ask(). Browser Back to ANOTHER page is the one
    // documented limit (no stash and no popstate handler any more); the browser's own warning covers reload and tab close.
    assert.ok(
        v2.includes('<V2LeaveGuard ref="guard" :dirty="c.isDirty.value" />'),
    );
    assert.match(
        v2,
        /import V2LeaveGuard from '@\/components\/v2\/V2LeaveGuard\.vue'/,
    );
    assert.match(v2, /guard\.value\?\.ask\(closeToList\)/);
    assert.ok(!/DiscardPrompt/.test(v2));
    assert.ok(!/popstate|stashUnsaved|takeStash|beforeunload/.test(v2));
    assert.ok(!/router\.on\('before'/.test(v2));
    assert.match(formLogic, /const isDirty = computed/);
    // b. neutral Controlled chip (the owner's CAT-1 decision), no amber
    assert.doesNotMatch(v2, /tone="warning"/);
    assert.match(v2, /tone="neutral"\s+label="Controlled"/);
    // d. one action bar at every width
    assert.doesNotMatch(form, /V2StickyBar/);
    assert.match(form, /sticky bottom-0/);
    // f. the Controlled confirm says what changes for the label and the Dispensary
    assert.ok(rowLogic.includes('At Dispensary, a returned'));
    assert.ok(rowLogic.includes('controlled register'));
    assert.ok(rowLogic.includes('below the label border'));
    assert.ok(rowLogic.includes('reprints of earlier labels'));
    assert.ok(rowLogic.includes('Ubat Terkawal/Controlled Medicine'));
});
