import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Registration/V2/Create.vue');
const row = read('resources/js/components/v2/V2PatientPickRow.vue');
const loose = (s) => s.replace(/\s+/g, '');

test('Register visit puts the rail at 1280 px with the bar below it and guards unsaved work', () => {
    assert.ok(page.includes('rail-from="xl"'));
    assert.ok(page.includes('<V2StickyBar>'));
    assert.ok(page.includes('<V2StickySummary'));
    assert.ok(
        page.includes('<V2LeaveGuard :dirty="dirty && !form.processing" />'),
    );
    // A chosen patient, a new patient being typed or any changed field is unsaved work.
    assert.ok(
        loose(page).includes(
            loose(
                "form.isDirty || !!selectedPatient.value || (quickMode.value && quick.value.full_name.trim() !== '')",
            ),
        ),
    );
});

test('Recent patients shows the six most recent and says how to find the others', () => {
    assert.ok(page.includes('const RECENT_SHOWN = 6;'));
    assert.ok(page.includes('props.recentPatients.slice(0, RECENT_SHOWN)'));
    assert.ok(
        loose(page).includes(loose('most recent. Search to find others.')),
    );
});

test('a pick row keeps the masked text visible and speaks only the last characters', () => {
    assert.ok(row.includes(':aria-label="spokenName"'));
    assert.ok(row.includes('identifier ending ${ending}'));
    assert.ok(loose(row).includes(loose('{{ maskedIdentifier }}')));
});
