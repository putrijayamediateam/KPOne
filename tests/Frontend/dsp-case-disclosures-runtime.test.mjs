import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = readFileSync('resources/js/pages/Dispensary/Show.vue', 'utf8');
const script = compileScript(parse(source).descriptor, {
    id: 'case-disclosure-test',
}).content;
const transpile = (text) =>
    ts.transpileModule(text, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
function mount() {
    const calls = [],
        scrolls = [];
    let api;
    const load = (text) => {
        const module = { exports: {} };
        vm.runInNewContext(transpile(text), {
            exports: module.exports,
            require: (name) => {
                if (name === 'vue') {
                    return vue;
                }

                if (name === '@inertiajs/vue3') {
                    return {
                        router: {
                            put: (...args) => calls.push(args),
                            post: (...args) => calls.push(args),
                        },
                        usePage: () => ({
                            props: { branchContext: { active: { id: 7 } } },
                        }),
                    };
                }

                if (name.startsWith('@/lib/')) {
                    return load(
                        readFileSync(
                            resolve('resources/js', name.slice(2) + '.ts'),
                            'utf8',
                        ),
                    );
                }

                return {};
            },
            document: {
                querySelector: () => ({
                    scrollIntoView: () =>
                        scrolls.push(api.lineDetailsOpen.line),
                }),
            },
            window: { matchMedia: () => ({ matches: true }) },
        });

        return module.exports;
    };
    const item = vue.reactive({
        publicId: 'line',
        lockVersion: 2,
        name: 'Synthetic medicine',
        code: 'SYN',
        unit: 'tablet',
        quantityOrdered: '10',
        quantityDispensed: '10',
        status: 'dispensed',
        dosage: '1 tablet',
        frequency: 'Daily',
        duration: '5 days',
        route: null,
        instruction: null,
        precaution: null,
        source: 'doctor',
        changeState: 'unchanged',
        original: { quantity: '10' },
        sku: { publicId: 'sku', unit: 'tablet' },
        availability: [],
        allocations: [],
        allergyMatches: [],
        exception: null,
    });
    const props = vue.reactive({
        dispensary: {
            publicId: 'case',
            lockVersion: 3,
            caseType: 'consultation',
            items: [item],
            services: [],
            can: { update: true },
            allergySafety: { allergies: [], isCurrent: true },
            doctorCheck: null,
        },
    });
    const scope = vue.effectScope();
    api = scope.run(() =>
        load(script).default.setup(props, { expose: () => {} }),
    );

    return { api, item, props, calls, scrolls, stop: () => scope.stop() };
}

test('closing and reopening a dirty line preserves draft, unsaved blocker and stale refresh protection', async () => {
    const a = mount();
    assert.equal(a.api.detailsOpen(a.item), false);
    a.api.lineDetailsOpen.line = true;
    a.api.forms.line.dosage = 'Synthetic unsaved draft';
    a.api.lineDetailsOpen.line = false;
    assert.equal(a.api.detailsOpen(a.item), false);
    assert.equal(a.api.isDirty(a.item), true);
    assert.equal(a.api.unsaved.value.length, 1);
    assert.match(a.api.completeBlockedReason.value, /Save the lines/);
    a.item.lockVersion++;
    a.item.dosage = 'Changed server dose';
    await vue.nextTick();
    a.api.lineDetailsOpen.line = true;
    assert.equal(a.api.forms.line.dosage, 'Synthetic unsaved draft');
    a.stop();
});

test('pending and allergy-unconfirmed lines start open; scanner opens before scrolling without selecting a batch', async () => {
    const a = mount();
    a.item.status = 'pending';
    assert.equal(a.api.detailsOpen(a.item), true);
    a.item.status = 'dispensed';
    a.item.allergyUnconfirmed = true;
    assert.equal(a.api.detailsOpen(a.item), true);
    a.api.lineDetailsOpen.line = false;
    const before = a.api.forms.line.availabilityIndex;
    await a.api.pointAtBatch('line');
    assert.deepEqual(a.scrolls, [true]);
    assert.equal(a.api.forms.line.availabilityIndex, before);
    a.stop();
});

test('failed save reopens its line and keeps payload/draft; successful retry retains its done callback', () => {
    const a = mount();
    a.api.forms.line.dosage = 'Changed dose';
    a.api.lineDetailsOpen.line = false;
    let done = 0;
    a.api.saveItem(a.item, () => done++);
    const [url, payload, callbacks] = a.calls[0];
    assert.equal(url, '/dispensary/case/items/line/final');
    assert.equal(payload.dosage, 'Changed dose');
    assert.equal(payload.expected_branch_id, 7);
    assert.equal(payload.case_lock_version, 3);
    assert.equal(payload.item_lock_version, 2);
    callbacks.onError({ dosage: 'Synthetic validation refusal' });
    assert.equal(a.api.detailsOpen(a.item), true);
    assert.equal(a.api.forms.line.dosage, 'Changed dose');
    assert.equal(a.api.actionError.value, 'Synthetic validation refusal');
    assert.equal(done, 0);
    callbacks.onSuccess();
    assert.equal(done, 1);
    a.stop();
});

test('Save all exposes an allergy-blocked hidden line without sending or advancing any save', () => {
    const a = mount();
    a.item.allergyMatches = [
        { publicId: 'allergy', allergen: 'Synthetic match' },
    ];
    a.api.forms.line.dosage = 'Changed dose';
    a.api.lineDetailsOpen.line = false;
    a.api.saveAll();
    assert.equal(a.calls.length, 0);
    assert.equal(a.api.detailsOpen(a.item), true);
    assert.match(a.api.actionError.value, /Tick/);
    a.stop();
});

test('add disclosures retain chosen medicine/service drafts and search queries', () => {
    const a = mount();
    a.api.searchTerm.value = 'Synthetic';
    a.api.addForm.dosage = 'Retained draft';
    a.api.serviceTerm.value = 'Service';
    a.api.serviceAdd.instruction = 'Retained instruction';

    for (const disclosure of [a.api.addMedicineOpen, a.api.addServiceOpen]) {
        disclosure.value = true;
        disclosure.value = false;
        disclosure.value = true;
    }

    assert.equal(a.api.searchTerm.value, 'Synthetic');
    assert.equal(a.api.addForm.dosage, 'Retained draft');
    assert.equal(a.api.serviceTerm.value, 'Service');
    assert.equal(a.api.serviceAdd.instruction, 'Retained instruction');
    a.stop();
});

test('saving a pending line keeps its editor open after the server confirms it', async () => {
    const a = mount();
    a.item.status = 'pending';
    a.api.saveItem(a.item);
    a.item.status = 'dispensed';
    a.calls[0][2].onSuccess();
    await vue.nextTick();
    assert.equal(a.api.detailsOpen(a.item), true);
    a.stop();
});
