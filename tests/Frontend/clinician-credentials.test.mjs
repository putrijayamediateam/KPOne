import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { nextTick, reactive, ref, watch } from 'vue';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const credentials = read('resources/js/pages/ClinicianCredentials/Index.vue');
const templates = read('resources/js/pages/MedicalCertificateTemplates/Index.vue');

test('credential and template screens compile without unsafe HTML or new issuance controls', () => {
    for (const [filename, source] of [['Credentials.vue', credentials], ['Templates.vue', templates]]) {
        const { descriptor } = parse(source);
        assert.ok(compileScript(descriptor, { id: filename }));
        assert.deepEqual(compileTemplate({ id: filename, filename, source: descriptor.template.content }).errors, []);
        assert.doesNotMatch(source, /v-html|type="file"|window\.print|localStorage|sessionStorage/);
    }
});

test('credentials have bounded linked inputs, manual verification and read-only own presentation', () => {
    assert.match(credentials.replace(/\s+/g, ' '), /canManage && clinician.active/);
    assert.match(credentials, /maxlength="200"/);
    assert.match(credentials, /maxlength="30"/);
    assert.match(credentials, /inputmode="numeric"/);
    assert.match(credentials, /aria-describedby="professional-name-error"/);
    assert.match(credentials, /aria-describedby="mmc-number-error"/);
    assert.match(credentials, /v-model="form.verified"/);
    assert.match(credentials.replace(/\s+/g, ' '), /not an automated MMC or current APC validation/);
});

test('credential writes retain optimistic retry identity and clear private drafts on branch change and close', () => {
    assert.match(credentials, /lock_version: 0/);
    assert.match(credentials, /idempotency_key: crypto.randomUUID/);
    assert.match(credentials, /page.props.branchContext\?\.active\?\.id/);
    assert.match(credentials, /form.cancel\(\)/);
    assert.match(credentials, /form.reset\(\)/);
    assert.match(credentials, /onBeforeUnmount\(clear\)/);
    assert.match(credentials, /Reload latest credentials/);
    assert.match(credentials, /form.processing \|\| branchChanged/);
});

test('fixed EN and BM previews are escaped, patient-free and explicitly non-issuable', () => {
    assert.match(templates, /value="en"/);
    assert.match(templates, /value="bm"/);
    assert.match(templates, /selected.wording/);
    assert.match(templates, /Not production-approved/);
    assert.match(templates.replace(/\s+/g, ' '), /No patient data, numbering, issuance or printing/);
    assert.match(templates, /Diagnosis is not a merge field/);
    assert.match(templates, /bg-white.*text-black/);
    assert.doesNotMatch(templates, /useForm|router\.(post|put|delete)|type="submit"|@click=/);
});

test('actual branch watcher preserves a validation dialog on same-branch responses and clears it on switch', async () => {
    const marker = credentials.indexOf('[() => props.branchId');
    assert.ok(marker > 0, 'watch individual branch values, not a newly allocated tuple on each response');
    const start = credentials.lastIndexOf('watch(', marker);
    const end = credentials.indexOf('\n);', marker) + 3;
    const watcher = credentials.slice(start, end);
    const props = reactive({ branchId: 1 });
    const page = reactive({ props: { branchContext: { active: { id: 1 } } } });
    const open = ref(true);
    const saved = ref(true);
    let cleared = 0;
    const stop = new Function('watch', 'props', 'page', 'open', 'saved', 'clear', `return ${watcher}`)(watch, props, page, open, saved, () => {
 cleared++;
});
    page.props = { branchContext: { active: { id: 1 } } };
    await nextTick();
    assert.equal(open.value, true);
    assert.equal(cleared, 0);
    page.props = { branchContext: { active: { id: 2 } } };
    await nextTick();
    assert.equal(open.value, false);
    assert.equal(saved.value, false);
    assert.equal(cleared, 1);
    stop();
});
