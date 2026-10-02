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
    inventory: false,
    medicineCatalogue: false,
    clinicalServiceCatalogue: false,
    paymentMethods: false,
    paymentReconciliations: false,
    patientRecords: false,
    panelWork: false,
    financeWork: false,
    staff: false,
    branches: false,
    accessControl: false,
    auditLogs: false,
    publicCheckInLinks: false,
    insights: false,
    ...overrides,
});

const labels = (groups) =>
    groups.flatMap((group) => group.destinations.map((item) => item.label));

test('Main Menu exposes only permission-backed implemented destinations', () => {
    const clinic = navigation.mainMenuGroups(
        capabilities({
            registration: true,
            consultation: true,
            inventory: true,
            patientRecords: true,
        }),
    );

    assert.deepEqual(
        [...labels(clinic)],
        ['Registration', 'Consultation', 'Inventory', 'Patient Records'],
    );
    assert.deepEqual(
        [...clinic.map((group) => group.label)],
        ['Clinic Operations', 'Patients'],
    );
    assert.doesNotMatch(source, /Reviews|Purchase|Dispensary/);
});

test('Insights is exposed only as the implemented, permission-backed Today destination', () => {
    const groups = navigation.mainMenuGroups(capabilities({ insights: true }));
    const destinations = navigation.headerDestinations(
        capabilities({ insights: true }),
        'clinic',
    );
    const sidebar = read('resources/js/components/AppSidebar.vue');

    assert.deepEqual([...labels(groups)], ['Insights']);
    assert.deepEqual(
        [...destinations.map((item) => item.label)],
        ['Main Menu', 'Insights'],
    );
    assert.match(source, /href: '\/insights\/today'/);
    assert.match(sidebar, /can\('insights\.view\.organisation'\)/);
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
    assert.ok(!technical.includes('Payment Methods'));
});

test('Payment Method setup is permission-backed and grouped with Finance', () => {
    const finance = navigation.mainMenuGroups(
        capabilities({ paymentMethods: true }),
    );
    const destinations = navigation.headerDestinations(
        capabilities({ paymentMethods: true }),
        'clinic',
    );
    const middleware = read('app/Http/Middleware/HandleInertiaRequests.php');

    assert.deepEqual([...labels(finance)], ['Payment Methods']);
    assert.deepEqual(
        [...destinations.map((item) => item.label)],
        ['Main Menu', 'Payment Methods'],
    );
    assert.match(source, /href: '\/payment-methods'/);
    assert.match(
        middleware,
        /'paymentMethods' => \$user->can\('payment_methods\.manage\.organisation'\)/,
    );
});

test('Terminal Reconciliation is permission-backed and kept in the Finance menu', () => {
    const finance = navigation.mainMenuGroups(
        capabilities({ paymentReconciliations: true }),
    );
    const middleware = read('app/Http/Middleware/HandleInertiaRequests.php');

    assert.deepEqual([...labels(finance)], ['Terminal Reconciliation']);
    assert.match(source, /href: '\/payment-reconciliations'/);
    assert.match(
        middleware,
        /'paymentReconciliations' => \$user->can\('payments\.reconcile\.branch'\)/,
    );
});

test('desktop and mobile header share one context-aware destination list', () => {
    const allowed = capabilities({
        registration: true,
        consultation: true,
        inventory: true,
        patientRecords: true,
        staff: true,
    });

    assert.deepEqual(
        [
            ...navigation
                .headerDestinations(allowed, 'clinic')
                .map((item) => item.label),
        ],
        [
            'Main Menu',
            'Registration',
            'Consultation',
            'Inventory',
            'Patient Records',
        ],
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
    assert.match(
        header,
        /class="hidden min-w-0 flex-1 overflow-x-auto xl:flex"[\s\S]*?<div class="flex w-max min-w-full justify-center">/,
    );
    assert.match(
        header,
        /class="relative flex h-13 shrink-0 cursor-pointer items-center/,
    );
    assert.doesNotMatch(
        header,
        /xl:grid-cols-\[minmax\(180px,1fr\)_auto_minmax\(180px,1fr\)\]/,
    );
    assert.match(header, /:aria-current=/);
    assert.doesNotMatch(header, /Reviews|Purchase/);
});
