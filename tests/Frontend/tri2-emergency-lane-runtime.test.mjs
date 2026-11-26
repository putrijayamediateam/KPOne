import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

function mapper(page) {
    const script = readFileSync(
        `resources/js/pages/${page}/Index.vue`,
        'utf8',
    ).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
    const ast = ts.createSourceFile(
        'page.ts',
        script,
        ts.ScriptTarget.Latest,
        true,
    );
    const node = ast.statements.find(
        (n) =>
            ts.isVariableStatement(n) &&
            n.declarationList.declarations.some(
                (d) => d.name.getText(ast) === 'toBoardRow',
            ),
    );
    assert.ok(node);
    const scope = {
        props: {
            treatmentRooms: [],
            options: { branch: { timezone: 'Asia/Kuala_Lumpur' } },
        },
        live: { value: { operationalDate: '2026-11-26' } },
        formatDate: (v) => v,
        compactTime: (v) => v,
        treatmentMinutes: () => null,
        effectiveNowMs: () => 0,
        elapsedMinutes: () => 45,
        waitingDurationLabel: (n) => `Waiting ${n} min`,
        servingDurationLabel: (n) => `Serving ${n} min`,
        queuePresentationLabel: () => 'Waiting',
    };
    vm.runInNewContext(
        ts.transpileModule(`${node.getText(ast)};globalThis.map=toBoardRow`, {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        }).outputText,
        scope,
    );

    return scope.map;
}

test('shared Queue mapper retains exact source versions, readiness and Call In authority for carry-over emergency rows', () => {
    const map = mapper('Queue');
    const row = {
        operationalDate: '2026-11-25',
        queueNumber: 'A-1',
        patientName: 'Synthetic patient',
        patientNumber: 'P-1',
        visitNumber: 'V-1',
        queuedTime: '09:00',
        status: 'waiting',
        isHeld: false,
        can: { viewPatient: true, update: false, cancel: false },
        canCall: true,
        canOpenEncounter: false,
        callHold: { active: true, canOverride: true },
        visitLockVersion: 7,
        queueLockVersion: 9,
    };
    const result = map(row);
    assert.equal(result.source, row);
    assert.equal(result.callHold, row.callHold);
    assert.equal(result.can.call, true);
    assert.equal(result.can.update, false);
    assert.equal(result.statusLabel, 'Waiting · carry-over');
    assert.equal(map({ ...row, canCall: false }).can.call, false);
});

test('shared Registration mapper retains every server permission and version without inferring emergency authority', () => {
    const map = mapper('Registration');
    const row = {
        visitNumber: 'V-2',
        patientName: 'Synthetic patient',
        registeredAtDate: '2026-11-25',
        registeredAt: '09:00',
        queueStatus: 'waiting',
        status: 'registered',
        durationMinutes: 40,
        can: { call: false, update: false, viewPatient: true },
        callHold: { active: true, canOverride: false },
        visitLockVersion: 6,
        queueLockVersion: 8,
    };
    const result = map(row);
    assert.equal(result.source, row);
    assert.equal(result.can, row.can);
    assert.equal(result.callHold, row.callHold);
    assert.equal(result.can.call, false);
    assert.equal(result.durationLabel, 'Waiting 40 min');
});

const metadataModule = { exports: {} };
vm.runInNewContext(
    ts.transpileModule(
        readFileSync('resources/js/lib/emergency-lane.ts', 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.CommonJS } },
    ).outputText,
    {
        exports: metadataModule.exports,
        require: () => ({ formatDateTime: (v, t) => `${v} in ${t}` }),
    },
);
test('alert observation time and taking are separate from waiting duration and use branch timezone', () => {
    const map = metadataModule.exports.emergencyBoardMetadata;
    const result = map(
        {
            alertStartedAt: '09:00',
            alertOrigin: 'activation',
            takenBy: 'Synthetic doctor',
            takenAt: '09:05',
        },
        'Asia/Kuala_Lumpur',
    );
    assert.equal(
        result.alertLabel,
        'Alert observed at 09:00 in Asia/Kuala_Lumpur',
    );
    assert.equal(
        result.takenLabel,
        'Taken by Synthetic doctor · 09:05 in Asia/Kuala_Lumpur',
    );
    assert.equal(
        map(
            { alertStartedAt: '09:00', alertOrigin: 'clinical', takenBy: null },
            'UTC',
        ).alertLabel,
        'Alert recorded at 09:00 in UTC',
    );
    assert.equal(
        map({ alertStartedAt: null, takenBy: null }, 'UTC').takenLabel,
        'Not taken',
    );
});

const { descriptor } = parse(
    readFileSync('resources/js/components/queue/EmergencyLane.vue', 'utf8'),
);
const mod = { exports: {} };
vm.runInNewContext(
    ts.transpileModule(
        compileScript(descriptor, { id: 'lane-test', inlineTemplate: true })
            .content,
        { compilerOptions: { module: ts.ModuleKind.CommonJS } },
    ).outputText,
    {
        exports: mod.exports,
        require: (name) =>
            name === 'vue'
                ? vue
                : name === '@lucide/vue'
                  ? { Siren: { render: () => vue.h('svg') } }
                  : {
                        Button: {
                            setup:
                                (_, { attrs, slots }) =>
                                () =>
                                    vue.h('button', attrs, slots.default?.()),
                        },
                    },
    },
);
const render = (props) =>
    renderToString(
        vue.createSSRApp({
            render: () =>
                vue.h(mod.exports.default, props, {
                    default: () =>
                        vue.h(
                            'div',
                            { 'data-testid': 'existing-patient-board' },
                            'Existing Call In controls',
                        ),
                }),
        }),
    );
test('lane exposes total and independent page navigation without dropping the existing board slot', async () => {
    const html = await render({
        total: 26,
        currentPage: 1,
        lastPage: 2,
        busy: false,
    });
    assert.match(html, /Emergency page 1 of 2 · 26 total/);
    assert.match(html, /Previous emergencies/);
    assert.match(html, /Next emergencies/);
    assert.match(html, /data-testid="existing-patient-board"/);
    assert.match(html, /Separate from the ordinary board filters/);
    assert.match(html, /<button[^>]*disabled[^>]*>Previous emergencies/);
});
test('last page blocks next and an in-flight refresh blocks both lane navigations', async () => {
    assert.match(
        await render({ total: 26, currentPage: 2, lastPage: 2, busy: false }),
        /<button[^>]*disabled[^>]*>Next emergencies/,
    );
    const html = await render({
        total: 26,
        currentPage: 1,
        lastPage: 2,
        busy: true,
    });
    assert.equal((html.match(/<button[^>]*disabled/g) || []).length, 2);
});

test('actual Registration lane paging retains applied filters, ordinary page and server-clamped emergency page', async () => {
    const script = readFileSync(
        'resources/js/pages/Registration/Index.vue',
        'utf8',
    ).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
    const ast = ts.createSourceFile(
        'Registration.ts',
        script,
        ts.ScriptTarget.Latest,
        true,
    );
    const node = ast.statements.find(
        (n) =>
            ts.isVariableStatement(n) &&
            n.declarationList.declarations.some(
                (d) => d.name.getText(ast) === 'search',
            ),
    );
    const requests = [];
    const refs = Object.fromEntries(
        [
            'loading',
            'error',
            'liveRefreshNotice',
            'tabCounts',
            'rows',
            'total',
            'currentPage',
            'lastPage',
        ].map((k) => [k, { value: null }]),
    );
    const scope = {
        ...refs,
        disposed: false,
        searchGeneration: 0,
        requestController: undefined,
        plannedTab: { value: false },
        pollingEnabled: true,
        pollingStopped: true,
        pollFailures: 3,
        isQrIntake: { value: false },
        document: { hidden: false },
        clearPollTimer: () => {},
        schedulePoll: () => {},
        pollProtected: () => false,
        csrf: () => '',
        AbortController,
        appliedFilters: { patient_query: 'Applied', board_status: 'waiting' },
        form: { patient_query: 'Unsubmitted draft', board_status: 'all' },
        emergency: { value: { currentPage: 2 } },
        recordSearchFailure: () => assert.fail('request failed'),
        console,
        fetch: async (_url, options) => {
            requests.push(JSON.parse(options.body));

            return {
                ok: true,
                json: async () => ({
                    data: [{ visitNumber: 'ORDINARY' }],
                    total: 1,
                    currentPage: 4,
                    lastPage: 5,
                    emergency: {
                        data: [],
                        currentPage: 1,
                        lastPage: 1,
                        total: 0,
                        perPage: 25,
                        hasMore: false,
                    },
                }),
            };
        },
    };
    vm.runInNewContext(
        ts.transpileModule(
            `${node.getText(ast)};globalThis.searchPage=search`,
            { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
        ).outputText,
        scope,
    );
    await scope.searchPage(4, false, 2);
    assert.deepEqual(requests[0], {
        patient_query: 'Applied',
        board_status: 'waiting',
        page: 4,
        emergency_page: 2,
    });
    assert.equal(scope.appliedFilters.patient_query, 'Applied');
    assert.equal(scope.form.patient_query, 'Unsubmitted draft');
    assert.equal(scope.currentPage.value, 4);
    assert.equal(scope.emergency.value.currentPage, 1);
    assert.equal(scope.pollingStopped, false);
    await scope.searchPage(4, true);
    assert.equal(requests[1].emergency_page, 1);
    assert.equal(requests[1].patient_query, 'Applied');
});

for (const automatic of [false, true]) {
    test(`actual Registration ${automatic ? 'automatic' : 'manual'} failure ${automatic ? 'retains last-known emergency with pause notice' : 'clears stale emergency identities and actions'}`, async () => {
        const script = readFileSync(
            'resources/js/pages/Registration/Index.vue',
            'utf8',
        ).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
        const ast = ts.createSourceFile(
            'Registration.ts',
            script,
            ts.ScriptTarget.Latest,
            true,
        );
        const wanted = ['clearBoard', 'recordSearchFailure', 'search'];
        const nodes = ast.statements.filter(
            (n) =>
                ts.isVariableStatement(n) &&
                n.declarationList.declarations.some((d) =>
                    wanted.includes(d.name.getText(ast)),
                ),
        );
        assert.equal(nodes.length, 3);
        const refs = Object.fromEntries(
            [
                'loading',
                'error',
                'liveRefreshNotice',
                'tabCounts',
                'rows',
                'total',
                'currentPage',
                'lastPage',
            ].map((k) => [k, { value: null }]),
        );
        const scope = {
            ...refs,
            disposed: false,
            searchGeneration: 0,
            requestController: undefined,
            plannedTab: { value: false },
            pollingEnabled: true,
            pollingStopped: false,
            pollFailures: 0,
            isQrIntake: { value: false },
            document: { hidden: false },
            clearPollTimer: () => {},
            schedulePoll: () => {},
            pollProtected: () => false,
            csrf: () => '',
            AbortController,
            appliedFilters: { patient_query: 'Applied' },
            form: { patient_query: 'Draft' },
            emergency: {
                value: {
                    data: [
                        {
                            patientName: 'Synthetic emergency',
                            boardRow: { can: { call: true } },
                        },
                    ],
                    currentPage: 1,
                    total: 1,
                },
            },
            console: { error: () => {} },
            fetch: async () => ({
                ok: false,
                status: 403,
                json: async () => ({ message: 'Permission changed. Reload.' }),
            }),
        };
        scope.rows.value = [{ patientName: 'Synthetic ordinary' }];
        vm.runInNewContext(
            ts.transpileModule(
                `${nodes.map((n) => n.getText(ast)).join('\n')};globalThis.searchPage=search`,
                { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
            ).outputText,
            scope,
        );
        await scope.searchPage(1, automatic, 2);

        if (automatic) {
            assert.equal(scope.emergency.value.data.length, 1);
            assert.equal(scope.rows.value.length, 1);
            assert.match(scope.liveRefreshNotice.value, /Live refresh paused/);
        } else {
            assert.equal(scope.emergency.value, undefined);
            assert.equal(scope.rows.value.length, 0);
            assert.equal(scope.error.value, 'Permission changed. Reload.');
        }
    });
}
