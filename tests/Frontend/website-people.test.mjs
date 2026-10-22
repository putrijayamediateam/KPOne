import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
const source = readFileSync(new URL('../../resources/js/pages/Website/People.vue', import.meta.url), 'utf8');
test('People drafts compile with escaped saved preview and bounded accessible content controls', () => {
    assert.deepEqual(compileTemplate({ id: 'people', source: parse(source).descriptor.template.content, filename: 'People.vue' }).errors, []);
    assert.doesNotMatch(source, /v-html|fetch\(|axios|window\.open|iframe/);

    for (const text of ['Nothing is public yet', 'Manual entries only', 'No applicant records', 'Revision history · read only', 'Saved draft preview', 'profile consent reference', 'portrait_public_id', 'employmentTypes', ':maxlength="limit"']) {
assert.ok(source.replace(/\s+/g, ' ').includes(text), text);
}
});
test('People layout wraps at mobile and permission gates every write, preserves languages and clears branch drafts', () => {
    for (const text of ['xl:grid-cols-2', 'min-w-0', 'flex-wrap', '[overflow-wrap:anywhere]', 'v-if="people.canManage"', '!people.canManage || form.processing', 'form.isDirty || form.processing', 'English (EN)', 'Bahasa Melayu (BM)', 'lock_version:', 'crypto.randomUUID()', '/branch-context', 'blocked.value = true', 'form.content = {}', 'form.branch_ids = []', 'form.portrait_public_id = null', 'selected.value = null', 'onUnmounted(stop)', '<template v-else>']) {
assert.ok(source.replace(/\s+/g, ' ').includes(text), text);
}
});
test('New hub entries use existing view permission, with no publishing or additional family', () => {
    const nav = readFileSync(new URL('../../app/Domain/Access/ClinicManagementNavigation.php', import.meta.url), 'utf8');

    for (const label of ['Doctor profiles', 'Careers']) {
assert.match(nav, new RegExp(`website.content.view.organisation'\\), '${label}'`));
}

    assert.doesNotMatch(nav, /website\.publish|website\.services|website\.requests/);
});
