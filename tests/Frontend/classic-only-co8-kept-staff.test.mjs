// Classic only, CO-8: the Classic half of v2-staff-create, v2-staff-directory, v2-staff-edit and v2-staff-show,
// kept when their V2 pages were removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('Classic Staff/Create sends the staff fields (v2-staff-create)', () => {
    const classic = read('resources/js/pages/Staff/Create.vue');

    assert.match(classic, /form\.post\('\/staff'/);

    for (const key of [
        'name',
        'email',
        'staff_number',
        'job_title',
        'department_id',
        'credential_strategy',
        'password',
        'password_confirmation',
        'is_active',
        'roles',
        'assignments',
    ]) {
        assert.ok(new RegExp(`\\b${key}:`).test(classic), `classic ${key}`);
    }
});

test('Classic staff directory keeps its routes and actions (v2-staff-directory)', () => {
    const classic = read('resources/js/pages/Staff/Index.vue');

    for (const s of [
        "'/staff'",
        '/staff/create',
        '`/staff/${member.id}`',
        '/staff/account-requests/${request.id}/done',
        '/staff/locum-sign-ins/${request.id}/link',
        'expected_lock_version',
        'idempotency_key',
    ]) {
        assert.ok(classic.includes(s), `classic has ${s}`);
    }
});

test('Classic Staff/Edit sends the profile fields with PATCH /staff/{id} (v2-staff-edit)', () => {
    const classic = read('resources/js/pages/Staff/Edit.vue');

    assert.match(classic, /form\.patch\(`\/staff\/\$\{props\.staff\.id\}`\)/);

    for (const key of [
        'name',
        'email',
        'staff_number',
        'job_title',
        'department_id',
    ]) {
        assert.ok(new RegExp(`\\b${key}:`).test(classic), `classic ${key}`);
    }
});

test('Classic Staff/Show keeps every permission check and its requests (v2-staff-show)', () => {
    const page = read('resources/js/pages/Staff/Show.vue');
    const partial = (name) =>
        read(`resources/js/pages/Staff/Partials/${name}.vue`);

    for (const can of [
        'can.update',
        'can.manageRoles',
        'can.manageStatus',
        'can.manageAccess',
    ]) {
        assert.ok(page.includes(`staff.${can}`), can);
    }

    assert.match(
        page,
        /\/staff\/\$\{props\.staff\.id\}\/branch-assignments\/\$\{assignmentId\}\/primary/,
    );
    assert.match(
        partial('RoleManagementDialog'),
        /form\.put\(`\/staff\/\$\{props\.staffId\}\/roles`/,
    );
    assert.match(
        partial('StatusConfirmationDialog'),
        /form\.patch\(`\/staff\/\$\{props\.staffId\}\/status`/,
    );

    const assign = partial('BranchAssignmentDialog');

    assert.match(assign, /branch-assignments`, options/);
    assert.match(assign, /branch-assignments\/\$\{props\.assignment\.id\}`/);
    assert.match(assign, /\/end`/);
});
