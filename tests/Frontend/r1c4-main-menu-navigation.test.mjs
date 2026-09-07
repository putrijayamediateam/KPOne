import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const source = read('resources/js/lib/workspace-navigation.ts');
const javascript = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const navigation = runInNewContext(
    `${javascript.replaceAll('export ', '')}\n({ mainMenuGroups, headerDestinations })`,
);

const capabilities = (overrides = {}) => ({
    registration: false,
    consultation: false,
    patientRecords: false,
    panelWork: false,
    financeWork: false,
    staff: false,
    branches: false,
    accessControl: false,
    auditLogs: false,
    ...overrides,
});

const labels = (groups) =>
    groups.flatMap((group) => group.destinations.map((item) => item.label));

test('Main Menu exposes only permission-backed implemented destinations', () => {
    const clinic = navigation.mainMenuGroups(
        capabilities({
            registration: true,
            consultation: true,
            patientRecords: true,
        }),
    );

    assert.deepEqual(
        [...labels(clinic)],
        ['Registration', 'Consultation', 'Patient Records'],
    );
    assert.deepEqual(
        [...clinic.map((group) => group.label)],
        ['Clinic Operations', 'Patients'],
    );
    assert.doesNotMatch(
        source,
        /Reviews|Insight|Purchase|Dispensary|Inventory/,
    );
});

test('Panel Finance and technical-administration visibility remains least privilege', () => {
    const panel = labels(
        navigation.mainMenuGroups(capabilities({ panelWork: true })),
    );
    const finance = labels(
        navigation.mainMenuGroups(capabilities({ financeWork: true })),
    );
    const technical = labels(
        navigation.mainMenuGroups(
            capabilities({
                staff: true,
                branches: true,
                accessControl: true,
                auditLogs: true,
            }),
        ),
    );

    assert.deepEqual([...panel], ['Panel Responsibility']);
    assert.deepEqual([...finance], ['Finance / Billing']);
    assert.deepEqual(
        [...technical],
        ['Staff', 'Branches', 'Access Control', 'Audit Logs'],
    );
    assert.ok(!technical.includes('Registration'));
    assert.ok(!technical.includes('Patient Records'));
    assert.ok(!technical.includes('Panel Responsibility'));
    assert.ok(!technical.includes('Finance / Billing'));
});

test('desktop and mobile header share one context-aware destination list', () => {
    const allowed = capabilities({
        registration: true,
        consultation: true,
        patientRecords: true,
        staff: true,
    });

    assert.deepEqual(
        [
            ...navigation
                .headerDestinations(allowed, 'clinic')
                .map((item) => item.label),
        ],
        ['Main Menu', 'Registration', 'Consultation', 'Patient Records'],
    );
    assert.deepEqual(
        [
            ...navigation
                .headerDestinations(allowed, 'admin')
                .map((item) => item.label),
        ],
        ['Main Menu', 'Staff'],
    );
});

test('Main Menu cards and header navigation retain semantic link and focus contracts', () => {
    const dashboard = read('resources/js/pages/Dashboard.vue');
    const header = read(
        'resources/js/components/workspace/WorkspaceHeader.vue',
    );

    assert.match(
        dashboard,
        /<Link[\s\S]*v-for="destination in group\.destinations"/,
    );
    assert.match(dashboard, /cursor-pointer/);
    assert.match(dashboard, /focus-visible:ring-2/);
    assert.match(header, /href="\/dashboard"/);
    assert.match(header, /aria-label="Open KPOne Main Menu"/);
    assert.match(header, /aria-label="Mobile primary navigation"/);
    assert.match(header, /aria-label="Desktop primary navigation"/);
    assert.match(header, /:aria-current=/);
    assert.doesNotMatch(header, /Reviews|Insight|Purchase/);
});
