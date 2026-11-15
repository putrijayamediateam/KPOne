import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = (p) => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Attendance/Index.vue');
test('attendance composer compiles, only recipient is free text, and clinical content is excluded', () => {
    const { descriptor } = parse(page);
    assert.ok(compileScript(descriptor, { id: 'attendance' }));
    assert.deepEqual(compileTemplate({ id: 'attendance', filename: 'Attendance.vue', source: descriptor.template.content }).errors, []);
    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage|type="file"|textarea|form\.(diagnoses|medicines|allergies|reason|history|findings|management)/);
    assert.match(page.replace(/\s+/g, ' '), /Do not enter a diagnosis, symptoms or medical details here/);
    assert.match(page, /maxlength="200"/);
    assert.match(page, /form\.consent/);
    assert.match(page, /review_token/);
    assert.match(page, /lg:grid-cols-2/);
});
test('attendance branch switch cancels loads and hides history and CA links', () => {
    for (const pattern of [/generation\+\+/, /controller\?\.abort/, /source\.value = null/, /Object\.assign\(form, blank\(\)\)/, /v-if="!branchChanged"/]) {
assert.match(page, pattern);
}

    assert.match(read('resources/js/pages/Clinical/Show.vue'), /attendance_documents\.view\.own[\s\S]*target="_blank"[\s\S]*Attendance confirmation/);
    const visit = read('resources/js/pages/Registration/Show.vue');
    assert.match(visit, /attendance_documents\.view\.branch/);
    assert.match(visit, /attendance_documents\.print\.branch/);
    assert.match(visit, /!attendanceBranchChanged/);
    assert.match(visit, /v-for="copy in attendanceCopies"/);
});
test('attendance print uses escaped A4 Arial white paper with date-only identity and last signature', () => {
    const content = read('resources/views/attendance/content.blade.php');

    for (const pattern of [/size:A4 portrait/, /background:#fff!important/, /color:#18181b!important/, /Arial,sans-serif/, /DRAFT WORDING/, /@if\(\$loop->last\)/, /Page \/ Halaman/]) {
assert.match(content, pattern);
}

    assert.doesNotMatch(content, /dateOfBirth|issuedAtUtc|monospace|{!!|<script|overflow:hidden|text-overflow/);
    assert.match(read('resources/views/attendance/show.blade.php'), /NOT VALID/);
});
