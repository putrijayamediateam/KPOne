import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/components/queue/QueueWorkspaceFrame.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'calls-today',
    inlineTemplate: true,
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const module = { exports: {} };
runInNewContext(js, { exports: module.exports, require });
const render = (props = {}) =>
    renderToString(
        createSSRApp(module.exports.default, {
            tabs: [
                { value: 'waiting', label: 'Waiting', count: 27 },
                { value: 'serving', label: 'Serving', count: 0 },
            ],
            activeTab: 'waiting',
            scope: 'branch',
            branchName: 'Synthetic branch',
            canRegistration: false,
            ...props,
        }),
    );

test('workspace renders server totals rather than a loaded-row estimate and retains zero', async () => {
    const html = await render();
    assert.match(html, />27<\/span>/);
    assert.match(html, />0<\/span>/);
    assert.match(html, /aria-pressed="true"/);
    assert.match(html, /Synthetic branch/);
    assert.match(html, /About this tab/);
    assert.match(html, /Red-zone emergencies remain in their own lane/);
    assert.doesNotMatch(html, /href="\/registration"/);
});
test('own serving view changes context and help without exposing a branch directory link', async () => {
    const html = await render({ scope: 'own', activeTab: 'serving' });
    assert.match(html, /Your patients/);
    assert.match(html, /Patients currently in consultation/);
    assert.doesNotMatch(html, /Other patients are ordered/);
    assert.doesNotMatch(html, /href="\/registration"/);
});
test('authorized registration link and absent counts remain explicit', async () => {
    const html = await render({
        canRegistration: true,
        tabs: [{ value: 'waiting', label: 'Waiting' }],
    });
    assert.match(html, /href="\/registration"/);
    assert.match(html, />—<\/span>/);
    assert.match(html, /aria-controls="queue-helpful-info"/);
});

const Vue = require('vue');
const passthrough = Vue.defineComponent({
    setup:
        (_, { slots }) =>
        () =>
            Vue.h('span', slots.default?.()),
});
const boardScript = compileScript(
    parse(
        readFileSync(
            'resources/js/components/patient-board/PatientBoard.vue',
            'utf8',
        ),
    ).descriptor,
    { id: 'queue-board-mode', inlineTemplate: true },
).content;
const boardModule = { exports: {} };
const waitingModule = { exports: {} };
runInNewContext(
    ts.transpileModule(
        readFileSync('resources/js/lib/waiting-time.ts', 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.CommonJS } },
    ).outputText,
    { exports: waitingModule.exports, require },
);
runInNewContext(
    ts.transpileModule(boardScript, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    {
        exports: boardModule.exports,
        require: (name) =>
            name === 'vue'
                ? Vue
                : name === '@/lib/waiting-time'
                  ? waitingModule.exports
                  : new Proxy(
                        {},
                        {
                            get: (_, key) =>
                                key === 'router' ? {} : passthrough,
                        },
                    ),
    },
);
const board = (queueLayout, extraRow = {}) =>
    renderToString(
        createSSRApp(boardModule.exports.default, {
            queueLayout,
            rows: [
                {
                    key: 'synthetic',
                    patientName: 'Synthetic Patient',
                    patientNumber: 'KP-00000001',
                    queueNumber: 'A-001',
                    coverageLabel: 'Synthetic coverage',
                    doctorName: 'Synthetic Doctor',
                    durationLabel: '5 min',
                    statusLabel: 'Waiting',
                    visitNotes: 'Synthetic reason',
                    can: {},
                    actions: {},
                    triage: null,
                    ...extraRow,
                },
            ],
        }),
    );
test('queue presentation uses focused columns while default Registration retains its original columns', async () => {
    const own = await board('own');
    const branch = await board('branch');
    const registration = await board(undefined);
    assert.doesNotMatch(own, /data-label="(?:Arrived|Payment|Doctor)"/);
    assert.match(branch, /data-label="Doctor"/);
    assert.doesNotMatch(branch, /data-label="(?:Arrived|Payment)"/);

    for (const field of ['Arrived', 'Payment', 'Doctor']) {
        assert.match(registration, new RegExp(`data-label="${field}"`));
    }

    for (const html of [own, branch, registration]) {
        assert.match(html, /Synthetic Patient/);
        assert.match(html, /A-001/);
    }

    assert.match(own, /data-queue-layout="own"/);
    assert.doesNotMatch(registration, /data-queue-layout=/);
    assert.match(own, /No\.<\/th>/);
    assert.match(own, /Reason\s*<\/th>/);
});

test('changing branch or losing branch-wide view leaves the Doctors section', async () => {
    const pageSource = readFileSync(
        'resources/js/pages/Queue/Index.vue',
        'utf8',
    );
    const callback = pageSource.match(
        /watch\(\s*\[\(\) => live\.value\.branch\.id, \(\) => live\.value\.scope\],[\s\S]*?\n\}\);/,
    )[0];
    const live = Vue.ref({ branch: { id: 1 }, scope: 'branch' });
    const workspaceSection = Vue.ref('doctors');
    const stop = runInNewContext(callback.replace(/;$/, ''), {
        watch: Vue.watch,
        live,
        workspaceSection,
    });
    live.value.scope = 'own';
    await Vue.nextTick();
    assert.equal(workspaceSection.value, 'queue');
    live.value.scope = 'branch';
    await Vue.nextTick();
    workspaceSection.value = 'doctors';
    live.value.branch.id = 2;
    await Vue.nextTick();
    assert.equal(workspaceSection.value, 'queue');
    stop();
});

test('an authorized own-scope reassignment warning survives the focused doctor column layout', async () => {
    const html = await board('own', {
        visitNumber: 'KPV-00000001',
        doctorWarning: { code: 'no_room', label: 'No room assigned' },
    });
    assert.match(html, /data-testid="own-doctor-warning"/);
    assert.match(html, /No room assigned/);
    assert.match(html, /Reassign visit KPV-00000001: No room assigned/);
    assert.match(html, /href="[^"]*KPV-00000001[^"]*"/);
    assert.doesNotMatch(await board('own'), /data-testid="own-doctor-warning"/);
});
