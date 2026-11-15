import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Registration/Edit.vue');
const edit = read('resources/js/pages/Registration/V2/Edit.vue');

test('Edit visit keeps the Classic request', () => {
    for (const piece of ['visit_type', 'coverage_type', 'lock_version']) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(edit.includes(piece), `V2 lacks ${piece}`);
    }
});

test('Edit visit puts the rail at 1280 px with the bar below it and guards unsaved changes', () => {
    assert.ok(edit.includes('rail-from="xl"'));
    assert.ok(edit.includes('<V2StickyBar>'));
    assert.ok(edit.includes('<V2StickySummary'));
    assert.ok(
        edit.includes(
            '<V2LeaveGuard :dirty="form.isDirty && !form.processing" />',
        ),
    );
});

test('the unsaved-changes guard covers visit type, doctor, reasons, coverage and urgency', () => {
    for (const key of [
        'visit_type: props.visit.visitType',
        'assigned_doctor_user_id: props.visit.doctor?.id',
        'visit_reason_public_ids: props.visit.visitReasons.structured',
        'coverage_type: props.visit.coverage.type',
        'priority: props.visit.priority',
    ]) {
        assert.ok(edit.includes(key), `the form lacks ${key}`);
    }

    // All five live in the same useForm that the guard and the Save button read (form.isDirty).
    assert.ok(edit.includes('const form = useForm({'));
    assert.ok(edit.includes('form.isDirty && !form.processing'));
});

test('the crumb is a two-item trail so the phone view never shows a lone ellipsis', () => {
    assert.ok(!/title: 'Registration', href: '\/registration'/.test(edit));
    assert.ok(edit.includes("{ title: 'Edit' }"));
});
