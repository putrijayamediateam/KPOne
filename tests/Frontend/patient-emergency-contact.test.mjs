import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';
const require = createRequire(import.meta.url);
const read = path => readFileSync(path, 'utf8');
const source = read('resources/js/components/patient/EmergencyContactCard.vue');
const script = compileScript(parse(source).descriptor, { id: 'emergency-contact', inlineTemplate: true }).content;
const js = ts.transpileModule(script, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
async function render(props) {
    const module = { exports: {} };
    const button = { setup: (_, {slots}) => () => h('button', slots.default?.()) };
    runInNewContext(js, { exports: module.exports, crypto: {randomUUID: () => 'synthetic-key'}, require: name => {
        if (name === '@/components/ui/button') return {Button: button};
        if (name.startsWith('@/')) return {default: {render: () => null}};
        return require(name);
    }});
    return renderToString(createSSRApp({render: () => h(module.exports.default, {branchId: 1, ...props})}));
}
test('read-only saved contact escapes all values and offers no edit control', async () => {
    const html = await render({canEdit: false, initial: {values: {full_name: '<script>contact</script>', relationship: 'Friend & neighbour', phone: '+12025550121'}, lock_version: 2}});
    assert.match(html, /&lt;script&gt;contact&lt;\/script&gt;/);
    assert.match(html, /Friend &amp; neighbour/);
    assert.doesNotMatch(html, /Edit contact|Add contact|<script>/);
});
test('empty optional contact has an explicit empty state and permitted add action', async () => {
    const html = await render({canEdit: true, initial: {values: null, lock_version: 4}});
    assert.match(html, /optional/); assert.match(html, /No emergency contact recorded/); assert.match(html, /Add contact/);
});
test('registration clearly stages contact changes with the visit rather than auto-saving', async () => {
    const html = await render({canEdit: true, registration: true, initial: {values: null, lock_version: 0}});
    assert.match(html, /Contact changes are saved with this registration/);
    assert.match(source, /if \(props.registration\)/);
    assert.match(source, /emit\('update:modelValue', command\)/);
    assert.match(source, /sequence === loadSequence/);
});
test('contact is scoped to selected patient/details, absent from recent/search board and public surfaces', () => {
    const create = read('resources/js/pages/Registration/Create.vue');
    assert.match(create, /:patient-number="selectedPatient\?\.patientNumber"/);
    assert.match(create, /canViewEmergencyContact/);
    for (const path of ['resources/js/pages/Registration/Index.vue','resources/js/pages/QueueDisplay/Screen.vue','resources/js/pages/PublicCheckIn/Show.vue','app/Domain/Patient/Services/PatientIdentityService.php','app/Domain/Patient/Services/PatientWhatsAppService.php']) assert.doesNotMatch(read(path), /EmergencyContact|emergency_contact/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|v-html|WhatsAppLink/);
});
