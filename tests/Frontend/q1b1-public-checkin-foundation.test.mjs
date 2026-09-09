import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const admin = read('resources/js/pages/PublicCheckInLinks/Index.vue');
const publicPage = read('resources/js/pages/PublicCheckIn/Show.vue');
const layout = read('resources/js/layouts/PublicCheckInLayout.vue');

test('admin surface supports lifecycle and one-time URL without reconstructing stored tokens', () => {
    assert.match(admin, /Create link/);
    assert.match(admin, /Rotate/);
    assert.match(admin, /Revoke/);
    assert.match(admin, /available once/);
    assert.match(admin, /cannot be reconstructed/);
    assert.match(admin, /navigator\.clipboard\.writeText/);
    assert.doesNotMatch(admin, /token_hash|tokenHash/);
});

test('public landing is branch-only and contains no patient intake form', () => {
    assert.match(publicPage, /branch\.name/);
    assert.match(publicPage, /No Patient information is collected/);
    assert.doesNotMatch(
        publicPage,
        /<form|NRIC|Passport|Phone|Gender|Panel|Visit Reason/,
    );
    assert.doesNotMatch(
        layout,
        /WorkspaceHeader|BranchSwitcher|NavUser|AppSidebar/,
    );
});

test('public and admin pages retain responsive and accessible semantics', () => {
    assert.match(publicPage, /<main/);
    assert.match(publicPage, /<h1/);
    assert.match(publicPage, /sm:px-8/);
    assert.match(admin, /Label id="checkin-branch-label"/);
    assert.match(admin, /labelledby="checkin-branch-label"/);
    assert.match(admin, /role="alert"/);
});
