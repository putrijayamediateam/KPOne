import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-21 foreign worker permits: a credential type flag, and a warning line (never red) on the register, the staff file
// and the HR dashboard, in Classic and Version 2.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const warnings = read(
    'resources/js/components/hr/credentials/WorkPermitWarnings.vue',
);
const composable = read('resources/js/composables/hr/useCredentialsPage.ts');

test('the warnings component compiles, uses the warning tone and never red', () => {
    const template = parse(warnings).descriptor.template.content;
    assert.deepEqual(
        compileTemplate({
            id: 'hr-21',
            source: template,
            filename: 'WorkPermitWarnings.vue',
        }).errors,
        [],
    );
    assert.match(template, /border-warning\/40 bg-warning\/10/);
    assert.doesNotMatch(warnings, /destructive|red-/);
    assert.match(template, /Work permits to renew/);
    assert.match(template, /:href="`\/hr\/records\/\$\{p\.workerId\}`"/);
    // In-sentence links are always underlined; the icon is decorative and the box is a labelled region.
    assert.match(template, /font-medium underline underline-offset-4/);
    assert.match(template, /aria-hidden="true"/);
    assert.match(template, /:aria-labelledby="headingId"/);
});

test('a new type sends work_permit only when it expires and is not a health item', () => {
    // Daniel: the permanent answer starts unpicked, and Save waits for it.
    assert.match(composable, /permit: '',/);
    assert.doesNotMatch(composable, /permit: 'no'/);
    assert.match(composable, /Pick Yes or No for Work permit to continue\./);
    assert.match(
        composable,
        /newType\.health !== 'yes' &&\s*newType\.expires === 'yes' &&\s*newType\.permit === 'yes'/,
    );
    assert.match(composable, /work_permit: isPermit\(\),/);
    // A work permit type is permanent, so it is asked once more before it is saved.
    assert.match(
        composable,
        /if \(isPermit\(\) && !permitConfirm\.value\) \{\s*permitConfirm\.value = true;/,
    );
    assert.match(composable, /permits: WorkPermitDue\[\];/);
});

for (const [name, path] of [
    ['Classic', 'resources/js/pages/HR/Credentials.vue'],
    ['V2', 'resources/js/pages/HR/V2/Credentials.vue'],
]) {
    test(`${name} Credentials: the Work permit choice and the register warnings`, () => {
        const page = read(path);
        assert.match(page, /v-model="newType\.permit"/);
        assert.match(page, /label="Work permit"/);
        assert.match(page, /errors\.work_permit/);
        assert.match(page, /:items="register\.permits"/);
        assert.match(
            page,
            /Work permit · warn from \$\{t\.warnFromDays\} days/,
        );
    });
}

for (const [name, path] of [
    ['Classic', 'resources/js/pages/HR/StaffFile.vue'],
    ['V2', 'resources/js/pages/HR/V2/StaffFile.vue'],
]) {
    test(`${name} staff file shows its work permit lines`, () => {
        assert.match(
            read(path),
            /:items="file\.workPermits\.map\(\(line\) => \(\{ line \}\)\)"/,
        );
    });
}

for (const [name, path] of [
    ['Classic', 'resources/js/pages/HR/Dashboard.vue'],
    ['V2', 'resources/js/pages/HR/V2/Dashboard.vue'],
]) {
    test(`${name} dashboard shows the count only when there is one`, () => {
        const page = read(path);
        assert.match(page, /v-if="dashboard\.workPermits"/);
        assert.match(page, /work permits expire soon or have expired\./);
        assert.match(
            page,
            /href="\/hr\/credentials\?tab=register"\s*class="[^"]*\bunderline underline-offset-4/,
        );
        assert.doesNotMatch(
            page,
            /data-test="dashboard-work-permits"[^>]*destructive/,
        );
    });
}
