import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import { compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const root = fileURLToPath(new URL('../../', import.meta.url));
const read = (file) =>
    readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const roles = JSON.parse(
    execFileSync(
        'php',
        [
            '-r',
            'require "vendor/autoload.php"; echo json_encode(App\\Domain\\Access\\PermissionCatalogue::roles());',
        ],
        { cwd: root, encoding: 'utf8' },
    ),
);
const source = read('resources/js/lib/workspace-navigation.ts');
const js = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const { headerDestinations } = runInNewContext(
    `${js.replaceAll('export ', '')}\n({ headerDestinations })`,
);
const capabilities = (permissions) => {
    const can = (permission) => permissions.includes(permission);
    const any = (...permissions) => permissions.some(can);
    const summary = any('billing.view.branch', 'billing.summary.branch');

    return {
        clinicManagement: true,
        insights: can('insights.view.organisation'),
        registration: can('visits.view.branch'),
        consultation: any('queue.view.own', 'queue.view.branch'),
        inventory: can('inventory.view.branch'),
        patientRecords: can('patients.search.organisation'),
        panelClaims: can('panel.claims.manage.branch'),
        panelInvoices: can('panel.invoices.export.branch'),
        panelWork: summary && can('panel.work.view.branch'),
        financeWork: summary && can('finance.work.view.branch'),
        paymentMethods: can('payment_methods.manage.organisation'),
        billingApprovalLimits: can(
            'billing.approval_limits.manage.organisation',
        ),
        paymentReconciliations: can('payments.reconcile.branch'),
        staff: any(
            'staff.view.own',
            'staff.view.branch',
            'staff.view.organisation',
        ),
        branches: any('branches.view.branch', 'branches.view.organisation'),
        accessControl: can('access.view.organisation'),
        auditLogs: can('audit.view.organisation'),
        publicCheckInLinks: any(
            'public_checkin_links.manage.organisation',
            'public_checkin_links.manage.branch',
        ),
        queueDisplay: any(
            'queue_display.manage.organisation',
            'queue_display.manage.branch',
        ),
        queueDisplayScreen: any(
            'queue.display.branch',
            'queue_display.manage.organisation',
            'queue_display.manage.branch',
        ),
    };
};
const labels = (caps, context) =>
    Array.from(headerDestinations(caps, context)).map((item) => item.label);
const expected = {
    director: ['Insights', 'Finance / Billing', 'Clinic management'],
    ca: [
        'Registration',
        'Consultation',
        'Inventory',
        'Patient Records',
        'Clinic management',
    ],
    ca_supervisor: [
        'Registration',
        'Consultation',
        'Inventory',
        'Patient Records',
        'Clinic management',
    ],
    finance_officer: [
        'Insights',
        'Finance / Billing',
        'Inventory',
        'Terminal Reconciliation',
        'Clinic management',
    ],
    panel_officer: [
        'Panel Claims',
        'Panel Responsibility',
        'Panel Invoices',
        'Insights',
        'Clinic management',
    ],
    resident_doctor: [
        'Consultation',
        'Registration',
        'Patient Records',
        'Insights',
        'Clinic management',
    ],
    administrator: ['Insights', 'Inventory', 'Clinic management'],
    technical_admin: ['Insights', 'Staff', 'Clinic management'],
    hr_manager: ['Insights', 'Staff', 'Clinic management'],
    marketing: ['Insights', 'Clinic management'],
    business_development: ['Insights', 'Clinic management'],
};

test('every standard role gets exactly its approved daily list in both page contexts', () => {
    assert.deepEqual(
        Object.keys(roles).sort(),
        [...Object.keys(expected), 'queue_display'].sort(),
    );

    for (const [role, items] of Object.entries(expected)) {
        for (const context of ['clinic', 'admin']) {
            assert.deepEqual(
                labels(capabilities(roles[role]), context),
                items,
                `${role}/${context}`,
            );
        }
    }
});

test('the real TV role retains both original menus with TV Screen and no added header item', () => {
    const caps = capabilities(roles.queue_display);
    assert.deepEqual(labels(caps, 'clinic'), ['TV Screen']);
    assert.deepEqual(labels(caps, 'admin'), ['Staff', 'Branches', 'TV Screen']);
});

test('mixed capabilities retain approved priority, deduplicate, reserve the last place for the hub and cap six items', () => {
    const permissions = [
        ...new Set(
            ['finance_officer', 'panel_officer', 'ca', 'hr_manager'].flatMap(
                (role) => roles[role],
            ),
        ),
    ];
    const caps = capabilities(permissions);
    const result = headerDestinations(caps, 'clinic');
    assert.deepEqual(labels(caps, 'clinic'), [
        'Insights',
        'Finance / Billing',
        'Inventory',
        'Terminal Reconciliation',
        'Panel Claims',
        'Clinic management',
    ]);
    assert.deepEqual(labels(caps, 'admin'), labels(caps, 'clinic'));
    assert.equal(new Set(Array.from(result).map((item) => item.href)).size, 6);

    for (const item of result) {
        assert.equal(caps[item.key], true);
    }

    assert.deepEqual(labels({ ...caps, clinicManagement: false }, 'clinic'), [
        'Insights',
        'Finance / Billing',
        'Inventory',
        'Terminal Reconciliation',
        'Panel Claims',
        'Panel Responsibility',
    ]);
    assert.deepEqual(labels({ clinicManagement: true }, 'clinic'), [
        'Clinic management',
    ]);
    assert.deepEqual(labels({}, 'clinic'), []);
});

test('direct grants only affect permitted daily items and management links stay out of the header', () => {
    const caps = capabilities(roles.marketing);
    caps.accessControl = true;
    assert.deepEqual(labels(caps, 'admin'), [
        'Insights',
        'Staff',
        'Clinic management',
    ]);
    const denied = {
        ...capabilities(roles.director),
        financeWork: false,
        billingApprovalLimits: false,
        registration: false,
        consultation: false,
        inventory: false,
        patientRecords: false,
        panelWork: false,
    };

    for (const item of headerDestinations(denied, 'admin')) {
        assert.equal(denied[item.key], true);
    }

    for (const caps of Object.values(roles).map(capabilities)) {
        for (const context of ['clinic', 'admin']) {
            const header = labels(caps, context);

            for (const omitted of [
                'Payment Methods',
                'Billing Approval Limits',
                'Branches',
                'Access Control',
                'Audit Logs',
                'Public Check-In',
                'Queue Display (TV)',
            ]) {
                if (
                    context === 'admin' &&
                    header.includes('TV Screen') &&
                    !caps.insights &&
                    omitted === 'Branches'
                ) {
                    continue;
                }

                assert.ok(!header.includes(omitted));
            }
        }
    }
});

test('header keeps accessible semantic links and switches to the vertical drawer when desktop space is insufficient', () => {
    const header = read(
        'resources/js/components/workspace/WorkspaceHeader.vue',
    );
    assert.deepEqual(
        compileTemplate({
            source: parse(header).descriptor.template.content,
            id: 'nav02',
            filename: 'WorkspaceHeader.vue',
        }).errors,
        [],
    );
    assert.doesNotMatch(header, /overflow-x-auto|overflow-x-scroll/);
    assert.match(header, /useResizeObserver\(\[desktopNav, desktopLinks\]/);
    assert.match(header, /scrollWidth <= desktopNav\.value\.clientWidth/);
    assert.match(header, /:class="headerFits \? 'xl:hidden' : ''"/);
    assert.match(header, /:class="\{ invisible: !headerFits \}"/);
    assert.match(header, /aria-label="Mobile primary navigation"/);
    assert.match(header, /aria-label="Desktop primary navigation"/);
    assert.match(header, /:aria-current=/);
    assert.match(header, /focus-visible:ring-2/);
});
