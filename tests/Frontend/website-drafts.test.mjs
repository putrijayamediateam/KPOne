import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const profile = read('resources/js/pages/Website/Profiles.vue');
const media = read('resources/js/pages/Website/Media.vue');
const preview = read('resources/js/pages/Website/DraftPreview.vue');
test('Website forms and escaped saved preview compile without HTML or outbound actions', () => {
    for (const source of [profile, media, preview]) {
        assert.deepEqual(compileTemplate({ id: 'website', source: parse(source).descriptor.template.content, filename: 'Website.vue' }).errors, []);
        assert.doesNotMatch(source, /v-html|fetch\(|axios|window\.open|https:\/\/|iframe|markdown|publish\(/);
        assert.match(source, /Nothing is public yet/);
    }

    assert.match(preview, /revision\.content\[key\]/);
    assert.match(preview, /Saved draft preview/);
    assert.match(profile, /profile\.canManage/);
    assert.match(profile, /:disabled="!profile.canManage \|\| form.processing"/);
});
test('Branch switch hides loaded content, clears profile text, hours, hero, contact blocks and upload file', () => {
    for (const source of [profile, media]) {
        assert.match(source, /\/branch-context/);
        assert.match(source, /blocked.value = true/);
        assert.match(source, /<template v-else>/);
        assert.match(source, /onUnmounted\(stop\)/);
        assert.match(source, /watch\(/);
    }

    assert.match(profile, /form.content\[key\] = ''/);
    assert.match(profile, /form.content.contact_blocks = \[\]/);
    assert.match(profile, /form.hero_asset_public_id = null/);
    assert.match(media, /fileInput.value.value = ''/);
    assert.match(media, /form.reset\(\)/);
});
test('Editor has separate EN/BM saved revisions, completeness, readonly history and bounded fields', () => {
    assert.match(profile, /English \(EN\)/);
    assert.match(profile, /Bahasa Melayu \(BM\)/);
    assert.match(profile, /missing.length/);
    assert.match(profile, /Revision history · read only/);
    assert.match(profile, /:maxlength="profile.limits\[key\]"/);
    assert.match(profile, /form.isDirty \|\| form.processing/);
    assert.match(profile, /crypto.randomUUID/);
    assert.match(profile, /lock_version: props.profile.lockVersion/);
    assert.match(profile, /xl:grid-cols-2/);
    assert.match(preview, /overflow-wrap:anywhere/);
    assert.match(media, /md:grid-cols-2 xl:grid-cols-3/);
    assert.match(media, /Retired · cannot be attached/);
    assert.match(media, /original is not kept/);
    assert.match(media, /required for an identifiable\s+person/);
});
test('Website hub icon and destinations remain permission-filtered without publishing or unauthorised modules', () => {
    const nav = read('app/Domain/Access/ClinicManagementNavigation.php');
    assert.match(nav, /\$add\('Website', 'website'/);
    assert.match(nav, /website.content.view.organisation/);
    assert.match(nav, /website.assets.manage.organisation/);
    assert.match(read('resources/js/pages/ClinicManagement/Index.vue'), /website: Globe/);
    assert.doesNotMatch(nav, /website\.publish|website\.requests|website\.careers|website\.doctor_profiles/);
});
