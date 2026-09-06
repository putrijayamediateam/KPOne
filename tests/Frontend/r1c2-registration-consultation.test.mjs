import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const { createRenderer, defineComponent, h, nextTick, ref } = Vue;

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const operationalSource = read('resources/js/lib/r1c2-presentation.ts');
const presentationSource = read('resources/js/lib/presentation.ts');
const presentationJs = ts.transpileModule(presentationSource, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
    },
}).outputText;
const operationalJs = ts.transpileModule(operationalSource, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
    },
}).outputText;
const presentationExports = {};
Function('exports', presentationJs)(presentationExports);
const operationalExports = {};
Function(
    'exports',
    'require',
    operationalJs,
)(operationalExports, (specifier) => {
    if (specifier === '@/lib/presentation') {
        return presentationExports;
    }

    throw new Error(`Unexpected import ${specifier}`);
});

const compileComponent = (path, requireMap = {}) => {
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const componentModule = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === 'vue') {
            return Vue;
        }

        if (specifier in requireMap) {
            return requireMap[specifier];
        }

        throw new Error(`Unexpected component import: ${specifier}`);
    };

    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(localRequire, componentModule, componentModule.exports);

    return componentModule.exports.default;
};

const createMountedRenderer = () => {
    if (!globalThis.Document) {
        globalThis.Document = class {};
    }

    const rootDocument = new globalThis.Document();
    rootDocument.activeElement = null;
    const createElement = (type) => {
        const element = {
            type,
            props: {},
            children: [],
            listeners: {},
            parent: null,
            addEventListener(name, listener) {
                this.listeners[name] = listener;
            },
            removeEventListener(name) {
                delete this.listeners[name];
            },
            getRootNode() {
                return rootDocument;
            },
            focus() {},
        };

        return element;
    };
    const renderer = createRenderer({
        patchProp(element, key, _previous, next) {
            element.props[key] = next;

            if (key === 'value') {
                element.value = next;
            }
        },
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const position = anchor ? parent.children.indexOf(anchor) : -1;

            if (position >= 0) {
                parent.children.splice(position, 0, child);
            } else {
                parent.children.push(child);
            }
        },
        remove(child) {
            const position = child.parent?.children.indexOf(child) ?? -1;

            if (position >= 0) {
                child.parent.children.splice(position, 1);
            }
        },
        createElement,
        createText: (text) => ({ type: '#text', text, parent: null }),
        createComment: (text) => ({ type: '#comment', text, parent: null }),
        setText(node, text) {
            node.text = text;
        },
        setElementText(element, text) {
            element.text = text;
            element.children = [];
        },
        parentNode: (node) => node.parent,
        nextSibling(node) {
            const siblings = node.parent?.children ?? [];

            return siblings[siblings.indexOf(node) + 1] ?? null;
        },
        querySelector: () => null,
        setScopeId: () => {},
        cloneNode: (node) => ({ ...node, props: { ...node.props } }),
        insertStaticContent: () => [null, null],
    });

    return { renderer, root: createElement('root') };
};

const descendants = (node, type) => {
    if (Array.isArray(node)) {
        return node.flatMap((child) => descendants(child, type));
    }

    if (!node) {
        return [];
    }

    return [
        ...(node.type === type ? [node] : []),
        ...descendants(node.children ?? [], type),
    ];
};
const nodeText = (node) => {
    if (Array.isArray(node)) {
        return node.map(nodeText).join('');
    }

    if (!node) {
        return '';
    }

    return `${node.text ?? ''}${nodeText(node.children ?? [])}`;
};

const ButtonStub = defineComponent({
    inheritAttrs: false,
    props: { disabled: Boolean, variant: String, type: String },
    setup:
        (props, { attrs, slots }) =>
        () =>
            h(
                'button',
                {
                    ...attrs,
                    disabled: props.disabled,
                    'data-variant': props.variant,
                },
                slots.default?.(),
            ),
});
const InputErrorStub = defineComponent({
    props: { message: String },
    setup: (props) => () => h('p', props.message),
});
const waitForSearch = async () => {
    await new Promise((resolve) => setTimeout(resolve, 180));
    await nextTick();
    await nextTick();
};

test('Queue labels preserve backend values while presenting operational language', () => {
    const label = operationalExports.queuePresentationLabel;
    assert.equal(
        label({ status: 'removed', removalReason: 'sent_to_dispensary' }),
        'Sent to Dispensary',
    );
    assert.equal(
        label({ status: 'removed', removalReason: 'sent_to_billing' }),
        'Awaiting Billing',
    );
    assert.equal(
        label({ status: 'removed', removalReason: null }),
        'Consultation Closed',
    );
    assert.equal(
        label({ status: 'removed', visitStatus: 'cancelled' }),
        'Cancelled',
    );
    assert.equal(
        label({ status: 'serving', returnedFromDispensary: true }),
        'Returned to Doctor',
    );
});

test('Operational durations are prefixed, finite and never negative', () => {
    assert.equal(operationalExports.waitingDurationLabel(18), 'Waiting 18 min');
    assert.equal(operationalExports.servingDurationLabel(6), 'Serving 6 min');
    assert.equal(operationalExports.waitedDurationLabel(18), 'Waited 18 min');
    assert.equal(operationalExports.waitingDurationLabel(-4), 'Waiting <1 min');
    assert.equal(operationalExports.servingDurationLabel(Number.NaN), '—');
});

test('Treatment action hierarchy keeps completion as the sole primary action', () => {
    assert.equal(
        operationalExports.treatmentSaveButtonVariant(true),
        'secondary',
    );
    assert.equal(
        operationalExports.treatmentSaveButtonVariant(false),
        'primary',
    );
    const panel = read(
        'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
    );
    assert.match(
        panel,
        /:variant="\s*treatmentSaveButtonVariant\(\s*plan\.canCompleteConsultation,?\s*\)\s*"/,
    );
});

test('R1-C2 operational areas do not render native selects', () => {
    const files = [
        'resources/js/pages/Registration/Index.vue',
        'resources/js/pages/Registration/Create.vue',
        'resources/js/pages/Registration/Edit.vue',
        'resources/js/pages/Queue/Index.vue',
        'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
        'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
    ];

    for (const file of files) {
        assert.doesNotMatch(read(file), /<select\b/i, file);
    }
});

test('Visit Reason combobox prevents Enter submission and exposes complete semantics', () => {
    const picker = read('resources/js/components/visit/VisitReasonPicker.vue');
    assert.match(
        picker,
        /event\.key === 'Enter'[\s\S]{0,80}event\.preventDefault\(\)/,
    );
    assert.doesNotMatch(picker, /event\.key === 'Enter'[\s\S]{0,220}\badd\(/);
    assert.match(picker, /aria-activedescendant/);
    assert.match(picker, /event\.key === 'Home'/);
    assert.match(picker, /event\.key === 'End'/);
    assert.match(picker, /event\.key === 'Escape'/);
    assert.match(
        picker,
        /inactive\.[\s\S]*Choose another or ask a\s+Supervisor/,
    );
    assert.match(picker, /maxlength="120"/);
});

test('Visit Reason picker selects only a rendered active option and clears ARIA state on close', async () => {
    const originalFetch = globalThis.fetch;
    const requestedUrls = [];
    globalThis.fetch = async (url) => {
        requestedUrls.push(String(url));

        return {
            ok: true,
            json: async () => ({
                data: String(url).includes('Unknown')
                    ? []
                    : String(url).includes('Inactive')
                      ? [
                            {
                                publicId: 'reason-inactive',
                                name: 'Inactive Reason',
                                isActive: false,
                            },
                        ]
                      : String(url).toLowerCase().includes('pregnancy')
                        ? [
                              {
                                  publicId: 'reason-pregnancy',
                                  name: 'Pregnancy Scan',
                                  isActive: true,
                              },
                          ]
                        : [
                              {
                                  publicId: 'reason-fever',
                                  name: 'Fever',
                                  isActive: true,
                              },
                          ],
            }),
        };
    };

    try {
        const Picker = compileComponent(
            'resources/js/components/visit/VisitReasonPicker.vue',
            {
                '@/components/InputError.vue': { default: InputErrorStub },
                '@/components/ui/button': { Button: ButtonStub },
            },
        );
        const selected = ref([]);
        const selectedOptions = ref([]);
        const emitted = [];
        const harness = defineComponent({
            setup: () => () =>
                h(Picker, {
                    modelValue: selected.value,
                    selected: selectedOptions.value,
                    'onUpdate:modelValue': (value) => {
                        emitted.push(value);
                        selected.value = value;
                    },
                    'onUpdate:selected': (value) => {
                        selectedOptions.value = value;
                    },
                }),
        });
        const mounted = createMountedRenderer();
        mounted.renderer.createApp(harness).mount(mounted.root);
        await nextTick();
        const input = descendants(mounted.root, 'input')[0];
        assert.ok(input);
        const enter = () => {
            let prevented = false;
            input.props.onKeydown({
                key: 'Enter',
                preventDefault: () => {
                    prevented = true;
                },
            });
            assert.equal(prevented, true);
        };
        const updateQuery = (value) => {
            if (input.props['onUpdate:modelValue']) {
                input.props['onUpdate:modelValue'](value);
            } else {
                input.value = value;
                input.listeners.input({ target: input });
            }
        };
        const setQuery = async (value) => {
            updateQuery(value);

            await waitForSearch();
        };

        updateQuery('Pending search');
        await nextTick();
        input.props.onKeydown({ key: 'Escape', preventDefault() {} });
        await waitForSearch();
        assert.equal(requestedUrls.length, 0);
        assert.equal(input.props['aria-expanded'], false);
        assert.equal(input.props['aria-activedescendant'], undefined);

        await setQuery('Fever');
        assert.equal(
            input.props['aria-expanded'],
            true,
            JSON.stringify(requestedUrls),
        );
        const visibleOption = descendants(mounted.root, 'button').find(
            (button) => button.props.role === 'option',
        );
        assert.ok(visibleOption);
        assert.equal(
            input.props['aria-activedescendant'],
            visibleOption.props.id,
        );

        input.props.onKeydown({ key: 'Escape', preventDefault() {} });
        await nextTick();
        assert.equal(input.props['aria-expanded'], false);
        assert.equal(input.props['aria-activedescendant'], undefined);
        enter();
        assert.equal(emitted.length, 0);

        await input.props.onFocus();
        await nextTick();
        await nextTick();
        enter();
        await nextTick();
        assert.deepEqual(emitted.at(-1), ['reason-fever']);

        selected.value = [];
        selectedOptions.value = [];
        await nextTick();
        await setQuery('Unknown');
        enter();
        assert.equal(emitted.length, 1);
        assert.equal(input.props['aria-activedescendant'], undefined);

        await setQuery(' pregnancy   scan ');
        assert.equal(
            descendants(mounted.root, 'button').filter((button) =>
                nodeText(button).includes('Add'),
            ).length,
            0,
        );
        assert.ok(
            descendants(mounted.root, 'button').some(
                (button) => button.props.role === 'option',
            ),
        );

        await setQuery('Inactive Reason');
        assert.equal(
            descendants(mounted.root, 'button').filter(
                (button) => button.props.role === 'option',
            ).length,
            0,
        );
        assert.match(
            nodeText(mounted.root),
            /This Visit Reason is inactive\. Choose another or ask a Supervisor\./,
        );
        enter();
        assert.equal(emitted.length, 1);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('OperationalSelect preserves empty, numeric and string values through mounted updates', async () => {
    const SelectStub = defineComponent({
        props: { modelValue: String, disabled: Boolean },
        emits: ['update:modelValue'],
        setup:
            (props, { emit, slots }) =>
            () =>
                h(
                    'div',
                    {
                        'data-select-root': '',
                        'data-model-value': props.modelValue,
                        onChoose: (value) => emit('update:modelValue', value),
                    },
                    slots.default?.(),
                ),
    });
    const passthrough = (type) =>
        defineComponent({
            inheritAttrs: false,
            props: { value: String, disabled: Boolean },
            setup:
                (props, { attrs, slots }) =>
                () =>
                    h(
                        type,
                        {
                            ...attrs,
                            value: props.value,
                            disabled: props.disabled,
                        },
                        slots.default?.(),
                    ),
        });
    const OperationalSelect = compileComponent(
        'resources/js/components/ui/select/OperationalSelect.vue',
        {
            './Select.vue': { default: SelectStub },
            './SelectContent.vue': { default: passthrough('section') },
            './SelectItem.vue': { default: passthrough('button') },
            './SelectTrigger.vue': { default: passthrough('button') },
            './SelectValue.vue': { default: passthrough('span') },
        },
    );
    const value = ref('');
    const emitted = [];
    const options = [
        { value: '', label: 'All' },
        { value: 42, label: 'Doctor 42' },
        { value: 'urgent', label: 'Urgent' },
        { value: 'disabled', label: 'Disabled', disabled: true },
    ];
    const harness = defineComponent({
        setup: () => () =>
            h(OperationalSelect, {
                modelValue: value.value,
                options,
                label: 'Operational filter',
                'onUpdate:modelValue': (next) => {
                    emitted.push(next);
                    value.value = next;
                },
            }),
    });
    const mounted = createMountedRenderer();
    mounted.renderer.createApp(harness).mount(mounted.root);
    await nextTick();
    const root = descendants(mounted.root, 'div').find(
        (node) => node.props['data-select-root'] === '',
    );
    assert.equal(root.props['data-model-value'], '__kpone_empty_selection__');
    root.props.onChoose('42');
    await nextTick();
    assert.equal(emitted.at(-1), 42);
    root.props.onChoose('urgent');
    await nextTick();
    assert.equal(emitted.at(-1), 'urgent');
    root.props.onChoose('__kpone_empty_selection__');
    await nextTick();
    assert.equal(emitted.at(-1), '');
    const disabled = descendants(mounted.root, 'button').find(
        (node) => node.props.value === 'disabled',
    );
    assert.equal(disabled.props.disabled, true);
});

test('Clinical editable controls use the shared visible input foundation', () => {
    for (const file of [
        'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
        'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
    ]) {
        const source = read(file);
        const editableControls = [
            ...source.matchAll(/<(?:input|textarea)\b[\s\S]*?\/>/g),
        ].map((match) => match[0]);
        assert.ok(editableControls.length > 0, `${file} has editable controls`);

        for (const control of editableControls) {
            assert.match(control, /border-input/);
            assert.match(control, /bg-card/);
            assert.match(control, /focus-visible:ring-3/);
        }
    }
});
