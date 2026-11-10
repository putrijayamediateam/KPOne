import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17e result, talent pool and staff-account handoff (HR-PLAN 19.8).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    apply: 'resources/js/pages/Careers/Apply.vue',
    status: 'resources/js/pages/Careers/Status.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
    staff: 'resources/js/pages/Staff/Index.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('all four pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the talent-pool tick is optional, separate and never preset, in both languages', () => {
    assert.match(source.apply, /talent_pool: false,/);
    const t = template('apply');
    assert.match(t, /data-test="apply-talent-pool"/);
    assert.match(t, /Optional:/);
    assert.match(t, /Pilihan: simpan butiran saya/);
    assert.match(t, /Whatever the result of/);
    assert.match(t, /use the answers and CV\s+I sent/);
});

test('the candidate can leave the pool, asked first, in both languages', () => {
    const t = template('status');

    for (const id of [
        'status-pool',
        'status-pool-leave',
        'status-pool-confirm',
        'status-pool-leave-yes',
        'status-pool-left',
    ]) {
        assert.ok(t.includes(`data-test="${id}"`), id);
    }

    assert.match(source.status, /'\/careers\/status\/leave-pool'/);
    assert.match(t, /Keluarkan saya/);
});

test('HR has a Talent pool tab, a pool period setting, invitations and the staff-account request', () => {
    const t = template('hr');

    for (const id of [
        'talent-pool',
        'pool-member',
        'pool-invite',
        'pool-invitation-link',
        'pool-settings',
        'pool-days-save',
        'application-after-result',
        'application-account-ask',
        'application-account-cancel',
    ]) {
        assert.ok(t.includes(`data-test="${id}"`), id);
    }

    assert.match(source.hr, /pool: 'Talent pool'/);
    assert.match(t, /confirm-label="Invite"/);
    assert.match(source.hr, /\/account-request`/);
});

test('the Staff page lists HR requests only when the server sends them, and marks done through a dialog', () => {
    const t = template('staff');
    assert.match(t, /v-if="accountRequests && accountRequests\.length"/);
    assert.match(t, /data-test="staff-account-request-done"/);
    assert.match(t, /cancel-label="Not yet"/);
    assert.match(
        source.staff,
        /\/staff\/account-requests\/\$\{request\.id\}\/done/,
    );
});
