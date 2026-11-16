import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/AccessControl/Index.vue');
const page = read('resources/js/pages/AccessControl/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');

test('V2 keeps the Classic props and shows the same counts and the same role label', () => {
    for (const piece of [
        'roles: Array<{ id: number; name: string; permissions: string[] }>;',
        'permissions: string[];',
        "import { formatRoleLabel } from '@/lib/displayLabels';",
        'formatRoleLabel(role.name)',
        'roles.length',
        'permissions.length',
    ]) {
        assert.ok(
            tight(classic).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
        assert.ok(tight(page).includes(tight(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 keeps every Classic wording string', () => {
    for (const text of [
        'Access Control',
        'Roles group permissions; permission scope remains explicit.',
        'Configured roles',
        'Explicit permissions',
        'Role permission matrix',
        'Technical administration is intentionally separate from future clinical-content access.',
        'permissions',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 is read only: no request, no form; the search box only narrows what is on screen', () => {
    assert.ok(
        !/fetch\(|router\.|useForm|<form|<button|localStorage|sessionStorage/.test(
            page,
        ),
    );
    assert.ok(
        flat(page).includes(
            'Narrows the permission names shown below. Nothing is saved.',
        ),
    );
    assert.ok(
        flat(page).includes('permission.toLowerCase().includes(term.value)'),
    );
});

test('V2 never hides a role or a permission without saying so: the filter status is spoken, the counts say "N of M"', () => {
    const f = flat(page);

    assert.ok(
        f.includes('data-testid="filter-status"') &&
            f.includes('role="status"'),
    );
    assert.ok(f.includes('roles hold a permission that matches'));
    assert.ok(
        f.includes('permissions` : `${role.permissions.length} permissions`') ||
            f.includes('of ${role.permissions.length} permissions'),
    );
    assert.ok(
        f.includes('No role holds a permission that matches your search.'),
    );
    // Each role starts open, so nothing is hidden until the person folds it.
    assert.ok(/<details[^>]*\sopen\b/.test(page.replace(/\s+/g, ' ')));
});

test('V2 role rows are 44 px targets with a visible focus ring', () => {
    assert.ok(tight(page).includes('min-h-11'));
    assert.ok(tight(page).includes('focus-visible:ring-2'));
});
