import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';
import { renderToString } from 'vue/server-renderer';

// Q-2 treatment progress (owner decisions 2026-11-05): board and consultation
// page only, controlled steps, attending-doctor alert, nothing on the TV.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const helper = {};
Function(
    'exports',
    transpile(read('resources/js/lib/treatment-progress.ts')),
)(helper);

const started = {
    id: 7,
    code: 'nebuliser',
    label: 'Nebuliser',
    state: 'started',
    startedAt: '2026-11-05T01:00:00Z',
    doneAt: null,
    lockVersion: 1,
    canManage: true,
};
const done = {
    ...started,
    state: 'done',
    doneAt: '2026-11-05T01:20:00Z',
    lockVersion: 2,
};

test('the step list and cancel reasons match the owner decision and the server', () => {
    assert.deepEqual(
        helper.TREATMENT_STEPS.map(([, label]) => label),
        [
            'Nebuliser',
            'Wound dressing',
            'Injection and observation',
            'IV drip',
            'Observation after medicine',
            'ECG',
            'Blood test (point-of-care)',
            'Other procedure',
        ],
    );
    const service = read('app/Domain/Queue/Treatment/TreatmentStepService.php');
    const migration = read(
        'database/migrations/2026_11_08_000100_create_queue_treatment_steps.php',
    );

    for (const [code, label] of helper.TREATMENT_STEPS) {
        assert.ok(service.includes(`'${code}' => '${label}'`), code);
        assert.ok(migration.includes(`'${code}'`), code);
    }

    for (const [code, label] of helper.TREATMENT_CANCEL_REASONS) {
        assert.ok(service.includes(`'${code}' => '${label}'`), code);
    }
});

test('minutes count from the start while running and from done once done', () => {
    const at = (iso) => Date.parse(iso);
    assert.equal(
        helper.treatmentMinutes(started, at('2026-11-05T01:12:30Z')),
        12,
    );
    assert.equal(helper.treatmentMinutes(done, at('2026-11-05T01:23:00Z')), 3);
    assert.equal(helper.treatmentMinutes(done, at('2026-11-05T01:19:00Z')), 0);
    assert.equal(helper.treatmentMinutes(null, Date.now()), null);
    assert.equal(
        helper.treatmentMinutes({ ...started, state: null }, Date.now()),
        null,
    );
});

test('badge, screen-reader and button wording', () => {
    assert.equal(
        helper.treatmentBadgeText(started, 12),
        'Nebuliser: started 12 min ago',
    );
    assert.equal(
        helper.treatmentBadgeText(started, 0),
        'Nebuliser: started just now',
    );
    assert.equal(
        helper.treatmentBadgeText(done, 3),
        'Nebuliser: done 3 min ago · Ready to resume',
    );
    assert.equal(
        helper.treatmentBadgeText({ ...started, state: null }, 1),
        null,
    );
    assert.equal(
        helper.treatmentAriaLabel(done, 1),
        'Treatment progress: Nebuliser done 1 minute ago, ready to resume',
    );
    assert.equal(
        helper.treatmentAriaLabel(started, 12),
        'Treatment progress: Nebuliser started 12 minutes ago',
    );
    assert.equal(
        helper.treatmentButtonLabel({ ...started, state: null }),
        'Start treatment step',
    );
    assert.equal(helper.treatmentButtonLabel(started), 'Mark done');
    assert.equal(helper.treatmentButtonLabel(done), 'Cancel step…');
});

const presentation = {};
Function(
    'exports',
    transpile(read('resources/js/lib/presentation.ts')),
)(presentation);

const renderBadge = async (props) => {
    const path = 'resources/js/components/queue/TreatmentProgressBadge.vue';
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: 'q-2-badge',
        inlineTemplate: true,
    }).content;
    const icon = Vue.defineComponent({ setup: () => () => Vue.h('i') });
    const module = { exports: {} };
    Function(
        'require',
        'module',
        'exports',
        transpile(compiled),
    )(
        (name) =>
            ({
                vue: Vue,
                '@lucide/vue': { Activity: icon, CheckCircle2: icon },
                '@/lib/treatment-progress': helper,
                '@/lib/presentation': presentation,
            })[name] ??
            (() => {
                throw new Error(`Unexpected import: ${name}`);
            })(),
        module,
        module.exports,
    );

    return renderToString(Vue.createSSRApp(module.exports.default, props));
};

test('the rendered badge uses the neutral token while running, the success token when done, and is empty without a step', async () => {
    const running = await renderBadge({ progress: started, minutes: 12 });
    assert.match(running, /data-treatment-state="started"/);
    assert.match(running, /border-border bg-muted text-foreground/);
    assert.match(running, /Nebuliser: started 12 min ago/);
    assert.match(
        running,
        /aria-label="Treatment progress: Nebuliser started 12 minutes ago"/,
    );
    const finished = await renderBadge({ progress: done, minutes: 3 });
    assert.match(finished, /border-success\/25 bg-success\/10 text-success/);
    assert.match(finished, /Ready to resume/);
    const empty = await renderBadge({
        progress: { ...started, id: null, state: null, label: null },
        minutes: null,
    });
    assert.doesNotMatch(empty, /treatment-progress-badge/);
    // Tokens only (DARK-1 lesson): no raw palette colours, and amber stays free.
    assert.doesNotMatch(running + finished, /amber|sky-|emerald-/);
    assert.equal(presentation.treatmentProgressTone('started'), 'neutral');
    assert.equal(presentation.treatmentProgressTone('done'), 'success');
});

const alertHarness = (details) => {
    const responses = [];
    const calls = [];
    const messages = [];
    const module = { exports: {} };
    const require = (name) =>
        ({
            vue: {
                ref: (value) => ({ value }),
                onBeforeUnmount: () => {},
                onMounted: () => {},
            },
            'vue-sonner': {
                toast: { success: (message) => messages.push(message) },
            },
            '@/lib/json-client': {
                requestJson: async (url) => {
                    calls.push(url);

                    return responses.shift();
                },
            },
        })[name];
    Function(
        'require',
        'module',
        'exports',
        'localStorage',
        transpile(read('resources/js/composables/useTreatmentDoneAlerts.ts')),
    )(require, module, module.exports, { getItem: () => 'false' });

    return {
        alerts: module.exports.useTreatmentDoneAlerts(details),
        responses,
        calls,
        messages,
    };
};

test('the doctor alert seeds its cursor first, shows queue number and step once, and never repeats on refresh', async () => {
    const h = alertHarness(true);
    const signal = new AbortController().signal;
    h.responses.push({ cursor: 4, ready: false, doneCount: 1, numbers: [] });
    await h.alerts.poll(1, signal);
    assert.ok(!h.calls[0].includes('cursor='), 'first read only seeds');
    h.responses.push({
        cursor: 5,
        ready: true,
        doneCount: 2,
        numbers: ['A-012 Nebuliser'],
    });
    await h.alerts.poll(1, signal);
    assert.ok(h.calls[1].includes('cursor=4'));
    h.responses.push({ cursor: 5, ready: false, doneCount: 2, numbers: [] });
    await h.alerts.poll(1, signal);
    assert.deepEqual(h.messages, [
        'Treatment done: A-012 Nebuliser. Ready to resume.',
    ]);
    assert.equal(h.alerts.doneCount.value, 2);
    assert.ok(
        h.calls.every((url) => url.startsWith('/queue/treatment-updates?')),
    );
    assert.ok(h.calls.every((url) => url.includes('details=1')));

    const widget = alertHarness(false);
    widget.responses.push({ cursor: 1, ready: false, doneCount: 0 });
    await widget.alerts.poll(1, signal);
    widget.responses.push({ cursor: 2, ready: true, doneCount: 1 });
    await widget.alerts.poll(1, signal);
    assert.deepEqual(widget.messages, [
        "A held patient's treatment is done. Ready to resume.",
    ]);
    assert.ok(widget.calls.every((url) => url.includes('details=0')));

    const aborted = alertHarness(true);
    const controller = new AbortController();
    controller.abort();
    aborted.responses.push({ cursor: 9, ready: true, doneCount: 1 });
    await aborted.alerts.poll(1, controller.signal);
    assert.deepEqual(aborted.messages, []);
});

test('the alert shares the existing sound switch and stores nothing else', () => {
    const source = read('resources/js/composables/useTreatmentDoneAlerts.ts');
    assert.match(source, /const preference = 'kpone\.call-readiness-chime';/);
    assert.doesNotMatch(source, /setItem/);
    assert.match(source, /beep\(660, 0\);\s*beep\(880, 0\.22\);/);
});

test('the board shows the badge and button in the Status cell, and only Serving rows carry progress', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );
    const status = board.slice(
        board.indexOf('data-label="Status"'),
        board.indexOf('data-label="Actions"'),
    );
    assert.match(status, /data-testid="call-readiness-badge"/);
    assert.match(
        status,
        /<TreatmentProgressBadge\s+:progress="row\.treatmentProgress"\s+:minutes="row\.treatmentMinutes"/,
    );
    assert.match(
        status,
        /<TreatmentProgressActions\s+v-if="branchId && row\.treatmentProgress"[\s\S]*?from="queue"\s+inline="running"/,
    );
    // Daniel's review: no button on every Serving row; the row menu opens the dialog.
    assert.match(
        board,
        /data-testid="treatment-progress-menu"\s+@select="openTreatmentDialog\(row\.key\)"\s+>Treatment progress…</,
    );
    const actionsSource = read(
        'resources/js/components/queue/TreatmentProgressActions.vue',
    );
    assert.match(
        actionsSource,
        /progress\?\.canManage &&\s+\(inline === 'always' \|\| progress\.state === 'started'\)/,
    );
    assert.match(
        board,
        /td\[data-label='Status'\]\[data-treatment-row\] \{\s+grid-column: 1 \/ -1;/,
    );
    const queue = read('resources/js/pages/Queue/Index.vue');
    assert.match(queue, /treatmentProgress: row\.treatmentProgress \?\? null/);
    assert.match(
        queue,
        /if \(live\.value\.scope === 'own'\) \{\s*await treatmentAlerts/,
    );
    const registration = read('resources/js/pages/Registration/Index.vue');
    assert.doesNotMatch(registration, /treatmentProgress|treatment-progress/);
});

test('the dialog posts controlled values with a retry key and keeps consultation work on the page', () => {
    const actions = read(
        'resources/js/components/queue/TreatmentProgressActions.vue',
    );
    assert.match(actions, /\/queue\/treatment-steps`/);
    assert.match(actions, /step_code: stepCode\.value/);
    assert.match(actions, /reason_code: cancelReason\.value/);
    assert.match(actions, /key \|\|= crypto\.randomUUID\(\)/);
    assert.match(actions, /preserveState: props\.from === 'consultation'/);
    assert.match(actions, /not a clinical record and is not shown on the TV/);
    assert.match(actions, /Cancel this step \(entered in error\)/);
    assert.match(actions, /'h-auto min-h-11 w-full py-2/);
    assert.doesNotMatch(actions, /<textarea|type="text"/);
    const page = read('resources/js/pages/Clinical/Show.vue');
    assert.match(
        page,
        /<TreatmentProgressBadge\s+:progress="treatmentProgress"\s+live/,
    );
    assert.match(
        page,
        /<TreatmentProgressActions[\s\S]*?from="consultation"\s+inline="always"\s+compact/,
    );
    assert.match(actions, /dark:scheme-dark/);

    // Yasmin's checklist pass: busy labels, field-level errors, required markers, named record.
    for (const label of ['Starting…', 'Marking done…', 'Cancelling…']) {
        assert.ok(actions.includes(label), label);
    }

    assert.match(actions, /:aria-invalid="!!error && !stepCode"/);
    assert.match(actions, /:aria-invalid="!!error && !cancelReason"/);
    assert.match(actions, />Treatment step \*/);
    assert.match(actions, />Reason \*/);
    assert.match(actions, /data-testid="treatment-progress-subject"/);
    assert.match(actions, /variant="link"\s+class="[^"]*text-destructive/);
    assert.doesNotMatch(
        read('resources/js/components/queue/TreatmentProgressBadge.vue') +
            actions,
        /sky-|emerald-/,
    );
    const dropdown = read(
        'resources/js/components/clinical/WaitingPatientsButton.vue',
    );
    assert.match(dropdown, /done, ready to resume/);
    assert.doesNotMatch(
        dropdown,
        /patientName|patientNumber|stepCode|step_code/,
    );
});

test('the TV and public QR pages never mention treatment progress', () => {
    for (const path of [
        'resources/js/pages/QueueDisplay/Screen.vue',
        'resources/js/pages/PublicCheckIn/Status.vue',
        'app/Domain/Queue/Display/QueueDisplayFeedService.php',
    ]) {
        assert.doesNotMatch(
            read(path),
            /treatmentProgress|treatment_step|TreatmentStep|treatment-progress/,
        );
    }
});
