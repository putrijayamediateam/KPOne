import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/composables/useCallReadinessAlerts.ts');
const javascript = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const harness = (details) => {
    const responses = [];
    const calls = [];
    const messages = [];
    const stored = [];
    const module = { exports: {} };
    const cleanup = [];
    const require = (name) => ({
        vue: { ref: (value) => ({ value }), onBeforeUnmount: (fn) => cleanup.push(fn), onMounted: () => {} },
        'vue-sonner': { toast: { success: (message) => messages.push(message) } },
        '@/lib/json-client': { requestJson: async (url, init) => {
 calls.push({ url, init });

 return responses.shift();
} },
    })[name];
    Function('require', 'module', 'exports', 'localStorage', javascript)(require, module, module.exports, { getItem: () => 'false', setItem: (...args) => stored.push(args) });

    return { alerts: module.exports.useCallReadinessAlerts(details), responses, calls, messages, stored, cleanup };
};
test('consultation alert needs an explicit clear signal AND an aggregate drop; cancellation or Call In alone cannot alert', async () => {
    const h = harness(false); const controller = new AbortController();
    h.responses.push({ cursor: 3, ready: false });
    await h.alerts.poll(1, controller.signal, false);
    h.responses.push({ cursor: 3, ready: false });
    await h.alerts.poll(1, controller.signal, true);
    assert.deepEqual(h.messages, []);
    h.responses.push({ cursor: 4, ready: true });
    await h.alerts.poll(1, controller.signal, false);
    assert.deepEqual(h.messages, []);
    h.responses.push({ cursor: 4, ready: false });
    await h.alerts.poll(1, controller.signal, true);
    assert.deepEqual(h.messages, []);
    h.responses.push({ cursor: 5, ready: true });
    await h.alerts.poll(1, controller.signal, true);
    assert.deepEqual(h.messages, ['A patient is ready to be called']);
    assert.ok(h.calls.every(({ url }) => !url.includes('details=1')));
    assert.deepEqual(h.stored, []);
});
test('queue boards alone request details and show the cleared queue number; repeated polls do not repeat it', async () => {
    const h = harness(true); const controller = new AbortController();
    h.responses.push({ cursor: 1, ready: false, numbers: [] });
    await h.alerts.poll(1, controller.signal);
    h.responses.push({ cursor: 2, ready: true, numbers: ['A-007'] });
    await h.alerts.poll(1, controller.signal);
    h.responses.push({ cursor: 2, ready: false, numbers: [] });
    await h.alerts.poll(1, controller.signal);
    assert.deepEqual(h.messages, ['Ready to be called: A-007']);
    assert.ok(h.calls.every(({ url }) => url.includes('details=1')));
    assert.deepEqual(h.stored, []);
});
test('branch changes and disposed requests cannot notify from stale responses; no state other than boolean chime preference is stored', async () => {
    const h = harness(false); const controller = new AbortController(); controller.abort();
    h.responses.push({ cursor: 3, ready: true });
    await h.alerts.poll(1, controller.signal, true);
    assert.deepEqual(h.messages, []);
    h.alerts.reset();
    h.responses.push({ cursor: 0, ready: false });
    await h.alerts.poll(2, new AbortController().signal);
    assert.ok(!h.calls.at(-1).url.includes('cursor='));
    assert.match(source, /setItem\(preference, String\(chimeEnabled.value\)\)/);
    assert.equal((source.match(/setItem\(/g) ?? []).length, 1);
    assert.match(source, /audio\.state !== 'running'/);
});
test('dropdown remains count-only, contains exactly one additional aggregate and no Call In or next-patient control', () => {
    const dropdown = read('resources/js/components/clinical/WaitingPatientsButton.vue');
    assert.match(dropdown, /notReady: number/);
    const clearSample = dropdown.search(/readyAlerts\s*\.read/);
    assert.ok(clearSample >= 0);
    assert.ok(clearSample < dropdown.indexOf('requestJson<Counts>'));
    assert.match(dropdown, /readyAlerts.announce/);
    assert.match(dropdown, /include_readiness: '1'/);
    assert.match(dropdown, /not ready to be called/);
    assert.doesNotMatch(dropdown, /patientName|patientNumber|reason_code|callHold|override_reason|next.patient|\/queue\/call/);
    const board = read('resources/js/components/patient-board/PatientBoard.vue');
    assert.match(board, /data-testid="call-readiness-badge"/);
    assert.match(board, /:disabled="row.callHold\?\.active"/);
    assert.match(board, /Override\s+flag\s+and\s+call/);
    const caActions = read('resources/js/components/queue/CallReadinessActions.vue');
    assert.match(caActions, /:show-close-button="false"/);
    assert.match(caActions, /h-auto min-h-11/);
    const dialog = read('resources/js/components/queue/QueueCallDialog.vue');

    for (const reason of ['patient_is_urgent','issue_resolved_verbally','patient_at_door']) {
assert.ok(dialog.includes(reason));
}

    for (const page of ['Queue/Index.vue','Registration/Index.vue']) {
assert.ok(read(`resources/js/pages/${page}`).includes('callHold:'));
}
});

test('public TV and QR sources receive no readiness field or reason', () => {
    for (const path of ['resources/js/pages/PublicCheckIn/Status.vue', 'resources/js/pages/QueueDisplay/Screen.vue', 'app/Domain/Queue/Display/QueueDisplayFeedService.php', 'app/Domain/Patient/Services/PublicPatientIntakeService.php']) {
        assert.doesNotMatch(read(path), /callHold|call_hold|call-readiness|Not ready to call/);
    }
});

test('all readiness-only copy is English and the surrounding recall button is unchanged', () => {
    const service = read('app/Domain/Queue/Readiness/CallHoldService.php');
    const actions = read('resources/js/components/queue/CallReadinessActions.vue');

    for (const label of ['Awaiting Panel confirmation', 'Awaiting patient details', 'Awaiting payment or deposit', 'Other administrative']) {
        assert.ok(service.includes(label)); assert.ok(actions.includes(label));
    }

    const dialog = read('resources/js/components/queue/QueueCallDialog.vue');

    for (const label of ['Patient is urgent', 'Issue resolved verbally', 'Patient is at the door']) {
        assert.ok(service.includes(label)); assert.ok(dialog.includes(label));
    }

    const dropdown = read('resources/js/components/clinical/WaitingPatientsButton.vue');
    assert.ok(dropdown.includes('waiting, ${counts?.notReady ?? 0} not ready to be called'));

    for (const view of [dropdown, read('resources/js/pages/Queue/Index.vue')]) {
        assert.ok(view.includes('Notification sound:')); assert.ok(view.includes("? 'On' : 'Off'"));
    }

    assert.ok(read('resources/js/pages/Clinical/Show.vue').includes('Panggil semula'));

    for (const copy of [service, actions, dialog, source, read('app/Http/Controllers/QueueReadinessController.php')]) {
        assert.doesNotMatch(copy, /belum|sedia|menunggu|pengesahan|butiran|bayaran|pentadbiran|pesakit|penanda|giliran|pilih|sahkan|batal|diatasi|bunyi|tidak|cubaan|berubah/i);
    }
});
