// Classic only, CO-8: the Classic half of v2-access-control, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/AccessControl/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');

test('Classic Access Control takes roles and permissions as props and shows the counts and role labels', () => {
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
    }
});

test('Classic Access Control keeps its wording', () => {
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
    }
});
