import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const show = readFileSync('resources/js/pages/Clinical/Show.vue', 'utf8');
const treatment = readFileSync(
    'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
    'utf8',
);
const safety = readFileSync(
    'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
    'utf8',
);
test('Consultation uses two columns with persistent treatment/history tabs and treatment default', () => {
    assert.ok(show.includes('lg:grid-cols-[minmax(0,55fr)_minmax(0,45fr)]'));
    assert.match(show, /ref<'treatment' \| 'history'>\('treatment'\)/);
    assert.match(show, /v-show="workspaceTab === 'treatment'"/);
    assert.match(show, /v-show="workspaceTab === 'history'"/);
    const right = show.indexOf('data-testid="consultation-right"');
    assert.ok(show.indexOf('<TreatmentPlanPanel') > right);
    assert.ok(show.indexOf('<ClinicalHistoryPanel') > right);
    assert.ok(show.indexOf('ref="treatmentPlanPanelRef"') > right);
    assert.ok(show.indexOf('Clinical Note') < right);
    assert.ok(
        treatment.includes('data-testid="consultation-treatment-actions"'),
    );
    const actions = treatment.slice(
        treatment.indexOf('data-testid="consultation-treatment-actions"'),
        treatment.indexOf('<Dialog v-model:open="sendOpen">'),
    );
    assert.ok(actions.includes('Complete Consultation'));
    assert.ok(actions.includes('Save Treatment Plan'));
    assert.ok(treatment.includes('max-h-[calc(100dvh-15rem)]'));
    assert.doesNotMatch(show, /hidden lg:block/);
});
test('Compact safety and moved actions retain selectors, labels and existing guards', () => {
    for (const label of [
        'Choose preset',
        'Add diagnosis',
        'Save clinical record',
        'Panggil semula',
        'Ke bilik rawatan',
        'On Hold',
    ]) {
        assert.ok(
            label === 'Choose preset'
                ? show.includes('<DiagnosisPresetPicker')
                : show.includes(label),
        );
    }

    for (const id of ['recall-patient', 'call-treatment']) {
        assert.ok(show.includes(`data-testid="${id}"`));
    }

    for (const label of [
        'Underlying conditions',
        'Add problem',
        'Resolved problems',
        'Review for this consultation',
        'Declare no known allergies',
        'Record allergy',
    ]) {
        assert.ok(safety.includes(label));
    }

    assert.ok(show.includes('@save-and-hold="saveAndHold"'));
    assert.ok(show.includes('@hold-without-saving="holdWithoutSaving"'));
    assert.ok(show.includes('recordDrifted'));
    assert.ok(
        treatment.includes(
            'New or changed medicine instructions require a current Allergy',
        ),
    );
});

test('Compact orders and vitals retain mounted editors separate from draft state', () => {
    assert.match(
        treatment,
        /expandedOrders = ref<Record<string, boolean>>\(\{\}\)/,
    );
    assert.match(treatment, /item\.public_id \?\? item\.catalogue_public_id/);
    assert.equal(
        (treatment.match(/@click="toggleOrder\(item\)"/g) ?? []).length,
        2,
    );
    assert.equal(
        (
            treatment.match(
                /v-show="[\s\S]*?expandedOrders\[orderKey\(item\)\]/g,
            ) ?? []
        ).length,
        2,
    );
    assert.ok(
        treatment.includes('form.medicines.length + form.services.length'),
    );
    assert.ok(treatment.includes('item.administration_instruction'));
    assert.ok(show.includes('const vitalsExpanded = ref(false)'));
    assert.ok(show.includes('Record new'));
    assert.match(show, /Record\s+vital\s+signs/);
    assert.ok(show.includes('No vital signs recorded.'));
    assert.ok(show.includes('Vital signs'));
    assert.ok(show.includes('data-testid="vitals-summary"'));
    assert.ok(show.includes("key.startsWith('vitals.')"));
    assert.ok(safety.includes('Allergy details'));
    assert.ok(safety.includes('Condition details'));
});

test('Patient card and small type use existing patient permission, with clinical save inside the note card', () => {
    assert.ok(show.includes('consultation-workspace'));
    assert.ok(show.includes('font-size: 12.5px'));
    assert.ok(
        show.includes(
            "page.props.auth.permissions.includes('patients.view.organisation')",
        ),
    );
    assert.ok(show.includes('View profile'));
    const left = show.indexOf('data-testid="consultation-left"');
    assert.ok(show.indexOf('{{ clinical.patient.name }}') > left);
    assert.ok(show.indexOf('<AllergyProblemPanel') > left);
    assert.ok(
        show.indexOf('Save clinical record') <
            show.search(/>\s*Diagnoses\s*<\/h2>/),
    );
});

test('Payment badge is read-only, Panel note is conditional, and visit metadata has a slim card', () => {
    assert.ok(show.includes('{{ clinical.visit.paymentMethodLabel }}'));
    assert.ok(show.includes('v-if="clinical.visit.isPanel && panelNote"'));
    assert.ok(show.includes('data-testid="consultation-payment-method"'));
    assert.ok(show.includes('data-testid="consultation-panel-note"'));
    assert.ok(show.includes('data-testid="consultation-visit-bar"'));
    assert.ok(show.includes('height: 30px'));
    assert.ok(show.includes(':deep(button[class])'));
    assert.ok(show.includes('font-size: 12px'));
    assert.equal(
        (treatment.match(/space-y-1 border-b border-border\/60 py-2/g) ?? [])
            .length,
        2,
    );
    assert.doesNotMatch(
        safety,
        />No active structured conditions recorded\.<\/span/,
    );
});

test('Yezza grid aligns patient halves and groups the full record into one card', () => {
    assert.ok(show.includes('data-testid="consultation-patient-grid"'));
    assert.ok(show.includes('sm:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]'));
    assert.ok(safety.includes('sm:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]'));
    assert.ok(safety.includes('sm:items-stretch'));
    assert.ok(show.includes('sm:border-l'));
    assert.ok(show.includes('Queue No:'));
    const card = show.slice(
        show.indexOf('data-testid="consultation-record-card"'),
        show.indexOf('</form>'),
    );

    for (const label of [
        'Visit note',
        'Vital signs',
        'Clinical Note',
        'Diagnoses',
    ]) {
        assert.ok(card.includes(label));
    }

    assert.ok(card.includes('Save clinical record'));
    assert.doesNotMatch(card, />\s*Priority\s*</);
    assert.ok(
        show.includes('title="Structured codes are optional in Phase 2A."'),
    );
    assert.ok(treatment.includes('title="Clinical orders only.'));
});

test('Focus mode retains branch/account access and a one-line name while hiding an empty Panel note', () => {
    const layout = readFileSync(
        'resources/js/layouts/ClinicLayout.vue',
        'utf8',
    );
    assert.ok(layout.includes('focusMode: false'));
    assert.ok(layout.includes('v-if="!focusMode"'));
    assert.ok(show.includes('focusMode: true'));
    assert.ok(show.includes('<WorkspaceBranchSwitcher'));
    assert.ok(show.includes('<UserMenuContent :user="user"'));
    assert.ok(show.includes('aria-label="Open user menu"'));
    assert.ok(show.includes('class="truncate text-[14px] font-semibold"'));
    assert.ok(show.includes(':title="clinical.patient.name"'));
    assert.ok(show.includes('const panelNote = computed(() => props.clinical.visit.panelNote)'));
    assert.ok(show.includes('data-testid="consultation-vitals-bar"'));
    assert.ok(show.includes('title="Record vital signs"'));
});

test('Safety cells use matching title, summary and action rows with separated allergy controls', () => {
    assert.ok(safety.includes('data-testid="underlying-conditions-summary"'));
    const underlying = safety.slice(
        safety.indexOf('data-testid="underlying-conditions-summary"'),
        safety.indexOf('<details v-if="problems.active.length"'),
    );
    assert.ok(
        underlying.indexOf('Underlying conditions') <
            underlying.indexOf('problems.active.length'),
    );
    assert.ok(
        underlying.indexOf('problems.resolved.length') <
            underlying.indexOf('Add problem'),
    );
    assert.ok(safety.includes('class="flex flex-wrap items-center gap-2"'));
});
