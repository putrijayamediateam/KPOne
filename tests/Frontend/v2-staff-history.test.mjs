import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('describeStaffEvent: plain words from the payload only', async () => {
    const ts = (await import('typescript')).default;
    const src = read('resources/js/lib/v2-staff-history.ts').replace(
        "import { formatRoleLabel } from '@/lib/displayLabels';",
        "const formatRoleLabel = (r) => ({ ca_supervisor: 'CA Supervisor' })[r] ?? r;",
    );
    const js = ts.transpileModule(src, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;
    const { describeStaffEvent } = await import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );

    assert.equal(
        describeStaffEvent(
            'access.role.attached',
            ['ca_supervisor'],
            'Demo Director',
        ),
        'CA Supervisor role added by Demo Director',
    );
    assert.equal(
        describeStaffEvent('access.role.detached', [], null),
        'Role removed by the system',
    );
    assert.equal(
        describeStaffEvent(
            'staff.branch_assignment.primary.promoted',
            [],
            'Demo Director',
        ),
        'A branch was made the primary branch by Demo Director',
    );
    assert.equal(
        describeStaffEvent('staff.created', [], 'Demo Director'),
        'Staff account created by Demo Director',
    );
    assert.equal(
        describeStaffEvent('something.new_thing', [], 'X'),
        'Something new thing by X',
    );
    assert.doesNotMatch(
        describeStaffEvent('access.role.attached', ['ca_supervisor'], 'A'),
        /›|>|&gt;/,
    );
});

test('Staff/Show V2 wording: outline Deactivate, plain note, ended and future rows, date echoes', () => {
    const page = read('resources/js/pages/Staff/V2/Show.vue');
    const dialog = read(
        'resources/js/components/staff-v2/V2StaffAssignmentDialog.vue',
    );
    assert.doesNotMatch(page, /(?<![:\w-])bg-destructive(?!\/)/);
    assert.match(page, /border-destructive text-destructive/);
    assert.match(page, /What this person can open depends on their roles/);
    assert.match(page, /Ended \$\{date\(assignment\.validUntil\)\}/);
    assert.match(page, /Starts \$\{date\(assignment\.validFrom\)\}/);
    // approved by B.E.R.N.I.S.S: V2DateField draws the date echo in words, so the dialog pins the block, not its own echo
    assert.equal((dialog.match(/<V2DateField/g) ?? []).length, 3);
    assert.doesNotMatch(dialog, /type="date"/);
    assert.match(dialog, /The last day counts \(inclusive\)\./);
    assert.match(dialog, /@update:invalid/);
});
