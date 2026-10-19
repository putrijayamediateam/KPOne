import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { nextTick, reactive, ref, watch } from 'vue';

const read = (file) => readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/MedicalCertificates/Index.vue');

test('MC page compiles with accessible bounded consent and no unsafe HTML, upload or browser persistence', () => {
    const { descriptor } = parse(source);
    assert.ok(compileScript(descriptor, { id: 'mc' }));
    assert.deepEqual(compileTemplate({ id: 'mc', filename: 'MC.vue', source: descriptor.template.content }).errors, []);
    assert.match(source, /DialogTitle/);
    assert.match(source, /DialogDescription/);
    assert.match(source, /maxlength="200"/);
    assert.match(source, /form.reason_consent/);
    assert.doesNotMatch(source, /v-html|localStorage|sessionStorage|type="file"/);
    assert.match(source.replace(/\s+/g, ' '), /No diagnosis is copied automatically/);
});

test('MC writes have independent optimistic versions, UUID retry identity and explicit draft evidence', () => {
    assert.match(source, /lock_version: props.certificates.lockVersion/);
    assert.match(source, /idempotency_key: crypto.randomUUID/);
    assert.match(source, /canFirstIssue/);
    assert.match(source, /credentialVerified/);
    assert.match(source, /form.processing \|\| branchChanged/);
    assert.match(source, /pre-signature/);
    assert.match(source, /Wet-sign/);
    assert.match(source, /Reload history/);
});

test('actual MC branch watcher preserves a stale dialog on same-branch responses and clears all state on switch', async () => {
    const marker = source.indexOf('[\n        () => props.certificates.branchId');
    const compactMarker = source.indexOf('[() => props.certificates.branchId');
    const position = marker >= 0 ? marker : compactMarker;
    assert.ok(position > 0);
    const start = source.lastIndexOf('watch(', position);
    const end = source.indexOf('\n);', position) + 3;
    const snippet = source.slice(start, end);
    const props = reactive({ certificates: { branchId: 1 } });
    const page = reactive({ props: { branchContext: { active: { id: 1 } } } });
    const open = ref(true); const loaded = ref(true); const saved = ref(true);
    let resets = 0;
    const stop = new Function('watch', 'props', 'page', 'open', 'loaded', 'saved', 'clear', `return ${snippet}`)(watch, props, page, open, loaded, saved, () => resets++);
    page.props = { branchContext: { active: { id: 1 } } };
    await nextTick(); assert.equal(open.value, true); assert.equal(resets, 0);
    page.props = { branchContext: { active: { id: 2 } } };
    await nextTick(); assert.equal(open.value, false); assert.equal(loaded.value, false); assert.equal(saved.value, false); assert.equal(resets, 1);
    stop();
});

test('retained print renderer enforces white A4 output and distinguishes pre-signature content from signed originals', () => {
    const print = read('resources/views/medical-certificates/content.blade.php');
    assert.match(print, /@page \{size:A4 portrait/);
    assert.match(print, /background:#fff!important/);
    assert.match(print, /color:#18181b!important/);
    assert.match(print, /Not an archived signed original/);
    assert.match(print, /DRAFT WORDING/);
    assert.doesNotMatch(print, /{!!|<script|<iframe/);
});


test('Encounter MC entry opens separately and preserves the unsaved clinical form', () => {
    const encounter = read('resources/js/pages/Clinical/Show.vue');
    const anchor = encounter.match(/<a\b[^>]*medical_certificates\.view\.own[\s\S]*?<\/a\s*>/)?.[0];
    assert.ok(anchor);
    assert.match(anchor, /target="_blank"/);
    assert.match(anchor, /rel="noopener"/);
    assert.match(anchor, /Medical certificates \(new tab\)/);
});
