import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (p) =>
    fs.readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Staff/Index.vue');
const v2 = read('resources/js/pages/Staff/V2/Index.vue');

test('Staff directory V2 is registered and has its own file', () => {
    assert.match(read('config/v2-pages.php'), /'Staff\/Index'/);
});

test('V2 keeps the same props, routes and actions as Classic', () => {
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
        assert.ok(v2.includes(s), `v2 has ${s}`);
    }

    for (const p of [
        'canCreate',
        'accountRequests',
        'locumSignIns',
        'filterOptions',
    ]) {
        assert.match(v2, new RegExp(`${p}\\??:`));
    }
});

test('V2 uses the V2 blocks and no Classic cards, badges or confirm dialog', () => {
    for (const b of [
        'V2WorkLayout',
        'V2Toolbar',
        'V2Table',
        'V2Pager',
        'V2Chip',
        'V2Dialog',
        'V2FormCard',
    ]) {
        assert.match(v2, new RegExp(`import ${b} from`));
    }

    assert.doesNotMatch(
        v2,
        /components\/ui\/(card|badge|button|OperationalConfirmDialog|page-header)/,
    );
    assert.match(v2, /cards="xl"/);
});

test('V2 states are words, and Create staff only shows for those who may', () => {
    assert.match(v2, /v-if="canCreate" #actions/);
    assert.match(v2, /'Active' : 'Inactive'/);
    assert.match(v2, /No current assignment/);
    assert.match(v2, /No staff accounts match the current filters\./);
});

test("Daniel's fixes: branch names not codes, plain role chips, neutral Inactive, a named link, a phone filter sheet", () => {
    assert.match(v2, /assignment\.branch\.name/);
    assert.doesNotMatch(v2, /assignment\.branch\.code/);
    assert.match(v2, /tone="neutral"\s+plain/);
    assert.doesNotMatch(v2, /'danger'/);
    assert.match(v2, /:aria-label="`Open \$\{member\.name\}`"/);
    assert.match(v2, /max-xl:after:absolute max-xl:after:inset-0/);
    assert.match(v2, /hover:text-primary/);
    assert.match(v2, /:more-filters="!phone"/);
    assert.match(v2, /Filters\{\{/);
    assert.match(v2, /v-model:open="sheetOpen"/);
    assert.match(v2, /@click="resetFilters"/);
});
