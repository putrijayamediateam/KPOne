import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Clinical/Show.vue');
const button = read('resources/js/components/clinical/WaitingPatientsButton.vue');
const service = read('app/Domain/Queue/Services/QueueWaitingCountService.php');
const routes = read('routes/web.php');

test('waiting counts sit beside recall while the existing consultation controls remain', () => {
    assert.match(page, /<WaitingPatientsButton[\s\S]*?:branch-id="clinical.branch.id"/);
    assert.ok(page.indexOf('<WaitingPatientsButton') < page.indexOf('data-testid="recall-patient"'));

    for (const label of ['Panggil semula', 'Ke bilik rawatan', 'On Hold', 'Save clinical record']) {
        assert.ok(page.includes(label));
    }

    assert.match(page, /<DiagnosisPresetPicker/);
    assert.match(read('resources/js/components/clinical/DiagnosisPresetPicker.vue'), /Choose preset/);
    assert.match(page, /:can-view-branch="[\s\S]*?queue.view.branch/);
});

test('dropdown exposes count scopes and an existing queue link without patient rows', () => {
    assert.match(button, /label: 'Your patient'/);
    assert.match(button, /label: 'All in clinic', disabled: !props.canViewBranch/);
    assert.match(button, /<OperationalTabs[\s\S]*?label="Waiting patient scope"/);
    assert.match(button, /href="\/queue"[\s\S]*?View all waiting patients/);
    assert.match(button, /Waiting counts unavailable. Try again./);
    assert.doesNotMatch(button, /router\.|useForm|patientName|patientNumber|doctorName/);
    assert.match(service, /'branch' => \$actor->can\('queue.view.branch'\) \? \$query->count\(\) : null/);
    assert.doesNotMatch(service, /->get\(|->paginate\(|->with\(|->rows\(|join\('patients'/);
});

test('polling does not reload forms and rejects responses after context changes or disposal', () => {
    assert.match(button, /requestJson<Counts>/);
    assert.match(button, /expected_branch_id: String\(branchId\)/);
    assert.match(button, /result.branchId !== branchId \|\| props.branchId !== branchId/);
    assert.match(button, /lifecycle.accepts\(generation\)/);
    assert.match(button, /controller\?\.abort\(\)/);
    assert.match(button, /15_000/);
    assert.match(button, /document.hidden/);
    assert.match(button, /watch\(open,[\s\S]*?if \(value\)\s*\{\s*void refresh\(\)/);
    assert.match(button, /onBeforeUnmount\([\s\S]*?lifecycle.dispose\(\)[\s\S]*?removeEventListener/);
    assert.match(button, /props.branchId, props.canViewBranch[\s\S]*?counts.value = null[\s\S]*?selected.value = 'own'/);
});

test('counts endpoint has existing permissions, throttling and no-store protection', () => {
    assert.match(routes, /Route::get\('queue\/waiting-counts',[\s\S]*?queue.view.own,queue.view.branch[\s\S]*?sensitive.no-store[\s\S]*?throttle:60,1/);
    assert.match(service, /Gate::forUser\(\$actor\)->authorize\('viewAny', QueueEntry::class\)/);
    assert.match(service, /\$branch->id !== \$expectedBranchId/);
    assert.match(service, /where\('queue_entries.status', QueueEntry::STATUS_WAITING\)/);
    assert.match(service, /where\('visits.visit_type', 'consultation'\)/);
    assert.match(service, /whereDate\('queue_entries.operational_date', '<='/);
});
