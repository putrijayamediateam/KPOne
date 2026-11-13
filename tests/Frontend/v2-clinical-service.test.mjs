import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Clinical Service Catalogue: look only. Same routes, payloads and rules as Classic; Classic is untouched.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ClinicalService/Index.vue');
const v2 = read('resources/js/pages/ClinicalService/V2/Index.vue');
const form = read(
    'resources/js/components/clinical-service-v2/ServiceForm.vue',
);
const logic = read(
    'resources/js/composables/clinical-service/useServiceForm.ts',
);
const registry = read('config/v2-pages.php');
const normal = (s) => s.replace(/\s+/g, ' ');
const squash = (s) => s.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');
const between = (s, a, b) => s.slice(s.indexOf(a), s.indexOf(b));

test('ClinicalService/Index is registered for Version 2 and the V2 files exist', () => {
    assert.match(registry, /'ClinicalService\/Index'/);
    assert.ok(v2.includes('<V2WorkLayout'));
    assert.ok(v2.includes('<PageFrame'));
    assert.ok(form.includes('<V2FormCard'));
});

test('V2 uses the same routes as Classic', () => {
    for (const route of [
        "'/clinical-services'",
        '`/clinical-services/${row.publicId}/setup`',
        '`/clinical-services/${editing.value.publicId}`',
        "`/clinical-services/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`",
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
        assert.ok(
            [v2, logic].some((s) => s.includes(route)),
            `v2 ${route}`,
        );
    }
});

test('the form state, the category rule and the submit are a faithful copy of Classic', () => {
    assert.equal(
        squash(
            between(logic, 'const form = useForm({', 'const openCreate'),
        ).replaceAll('setup.', 'props.setup.'),
        squash(between(classic, 'const form = useForm({', 'const openCreate')),
    );
    assert.equal(
        squash(between(logic, 'const submit = () => {', 'const baseline')),
        squash(
            between(classic, 'const submit = () => {', 'const pendingToggle'),
        )
            .replaceAll('dialogOpen', 'formOpen')
            .replaceAll('props.setup', 'setup'),
    );

    for (const key of [
        'self_pay_sen',
        'panel_default_sen',
        'amount_sen',
        'expected_branch_id',
        '(saved before the fixed list)',
    ]) {
        assert.ok(classic.includes(key) && logic.includes(key), key);
    }
});

test('V2 keeps the Classic toggle wording and the Consultation tariff panel', () => {
    for (const text of [
        'will no longer be available to order on a Treatment Plan.',
        'will become available to order on a Treatment Plan again.',
    ]) {
        assert.ok(normal(classic).includes(text) && normal(v2).includes(text));
    }

    assert.ok(v2.includes('<ConsultationTariffPanel'));
    assert.ok(classic.includes('<ConsultationTariffPanel'));
});

test('V2 form: shared picker reused, required fields marked, pricing gated by permission, one action bar', () => {
    assert.ok(form.includes('CatalogueOptionPicker'));
    assert.ok(form.includes('PanelTariffEditor'));
    assert.match(form, /label="Catalogue code"\s+required/);
    assert.match(form, /label="Display name"\s+required/);
    assert.ok(form.includes('v-if="setup.canManagePrices"'));
    assert.ok(form.includes('v-if="setup.canPublishPrices"'));
    assert.ok(form.includes('data-test="service-form-actions"'));
    assert.doesNotMatch(form, /V2StickyBar/);
});

test('V2 list: one filled page button, row menu, Back-safe address state, no native select, "no price" in words', () => {
    assert.equal((v2.match(/:class="filledButton"/g) ?? []).length, 1);
    assert.doesNotMatch(v2 + form, /<select[\s>]/);
    assert.doesNotMatch(v2 + form, /type="file"/);
    assert.match(v2, /add: '1'/);
    assert.match(v2, /edit: row\.publicId/);
    assert.match(v2, /takeStash\(\)/);
    assert.match(v2, /c\.stashUnsaved\(\)/);
    assert.match(v2, /beforeunload/);
    assert.match(v2, /← Clinical services/);
    assert.ok(v2.includes('<DiscardPrompt'));
    assert.ok(
        v2.includes(
            "import DiscardPrompt from '@/components/catalogue-v2/DiscardPrompt.vue'",
        ),
    );
    assert.ok(v2.includes("'no price'"));
    assert.match(v2, /max-xl:hidden!/);
    assert.match(v2, /data-action="open-filters"/);
    assert.doesNotMatch(v2, /\/medicines/);
});

test('Classic keeps its own page and does not import V2 pieces', () => {
    assert.doesNotMatch(
        classic,
        /components\/v2|clinical-service-v2|composables\/clinical-service/,
    );
});
