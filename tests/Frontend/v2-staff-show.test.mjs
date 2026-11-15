import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Staff/V2/Show.vue');

test('Staff/Show is registered for V2 and uses the V2 layout, one rail, and no Classic ui blocks', () => {
    assert.match(read('config/v2-pages.php'), /'Staff\/Show'/);
    assert.match(page, /<V2WorkLayout/);
    assert.match(page, /rail-below="stack-bottom"/);
    assert.match(page, /PageFrame/);
    assert.doesNotMatch(page, /components\/ui\/(button|card|badge|dialog)/);
});

test('Staff/Show keeps every permission check and the same requests as Classic', () => {
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
    const roles = read(
        'resources/js/components/staff-v2/V2StaffRolesDialog.vue',
    );
    const status = read(
        'resources/js/components/staff-v2/V2StaffStatusDialog.vue',
    );
    const assign = read(
        'resources/js/components/staff-v2/V2StaffAssignmentDialog.vue',
    );
    assert.match(roles, /form\.put\(`\/staff\/\$\{props\.staffId\}\/roles`/);
    assert.match(
        status,
        /form\.patch\(`\/staff\/\$\{props\.staffId\}\/status`/,
    );
    assert.match(assign, /branch-assignments`, options/);
    assert.match(assign, /branch-assignments\/\$\{props\.assignment\.id\}`/);
    assert.match(assign, /\/end`/);
});
