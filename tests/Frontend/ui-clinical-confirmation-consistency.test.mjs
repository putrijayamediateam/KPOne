import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const { createRenderer, defineComponent, h, nextTick } = Vue;
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;

const compileComponent = (path, requireMap) => {
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const loaded = { exports: {} };

    Function(
        'require',
        'module',
        'exports',
        transpile(compiled),
    )(
        (specifier) => {
            if (specifier === 'vue') {
                return Vue;
            }

            if (specifier in requireMap) {
                return requireMap[specifier];
            }

            throw new Error(`Unexpected import: ${specifier}`);
        },
        loaded,
        loaded.exports,
    );

    return loaded.exports.default;
};

const createMountedRenderer = () => {
    const createElement = (type) => ({
        type,
        props: {},
        children: [],
        parent: null,
    });
    const renderer = createRenderer({
        patchProp(element, key, _previous, next) {
            element.props[key] = next;
        },
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const index = anchor ? parent.children.indexOf(anchor) : -1;

            if (index >= 0) {
                parent.children.splice(index, 0, child);
            } else {
                parent.children.push(child);
            }
        },
        remove(child) {
            const index = child.parent?.children.indexOf(child) ?? -1;

            if (index >= 0) {
                child.parent.children.splice(index, 1);
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

const descendants = (node, type) => [
    ...(node.type === type ? [node] : []),
    ...(node.children ?? []).flatMap((child) => descendants(child, type)),
];

const renderedText = (node) =>
    [
        node.text ?? '',
        ...(node.children ?? []).map((child) => renderedText(child)),
    ].join(' ');

const icon = defineComponent(() => () => h('svg'));
const operationalDialogStub = defineComponent({
    props: {
        open: Boolean,
        title: String,
        description: String,
        confirmLabel: String,
        processing: Boolean,
        destructive: Boolean,
        error: String,
    },
    emits: ['update:open', 'confirm'],
    setup:
        (props, { emit, slots }) =>
        () =>
            h('section', { 'data-open': props.open }, [
                h('h2', props.title),
                h('p', props.description),
                h('output', {
                    'data-destructive': props.destructive,
                    'data-processing': props.processing,
                }),
                slots.default?.(),
                h(
                    'button',
                    {
                        'data-action': 'cancel',
                        disabled: props.processing,
                        onClick: () => emit('update:open', false),
                    },
                    'Cancel',
                ),
                h(
                    'button',
                    {
                        'data-action': 'confirm',
                        disabled: props.processing,
                        onClick: () => emit('confirm'),
                    },
                    props.confirmLabel,
                ),
            ]),
});

const mountDialog = (kind) => {
    const calls = [];
    const updates = [];
    const processing = [];
    const ClinicalSafetyConfirmDialog = compileComponent(
        'resources/js/components/clinical/ClinicalSafetyConfirmDialog.vue',
        {
            '@inertiajs/vue3': {
                router: {
                    post: (...args) => calls.push(['post', ...args]),
                    patch: (...args) => calls.push(['patch', ...args]),
                },
            },
            '@lucide/vue': {
                AlertTriangle: icon,
                CheckCircle2: icon,
                ShieldCheck: icon,
            },
            '@/components/ui/OperationalConfirmDialog.vue': {
                default: operationalDialogStub,
            },
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(ClinicalSafetyConfirmDialog, {
            open: true,
            kind,
            visitNumber: 'KPV-SYNTHETIC',
            branchId: 12,
            profileLockVersion: 7,
            recordPublicId: 'clinical-record-1',
            recordLockVersion: 4,
            recordLabel: 'Synthetic clinical record',
            'onUpdate:open': (value) => updates.push(value),
            onProcessing: (value) => processing.push(value),
        })
        .mount(mounted.root);

    return { calls, mounted, processing, updates };
};

const actionButton = (mounted, action) =>
    descendants(mounted.root, 'button').find(
        (button) => button.props['data-action'] === action,
    );

test('every Clinical safety dialog cancels without mutation', async () => {
    for (const kind of [
        'declare-no-known',
        'allergy-entered-in-error',
        'problem-resolve',
        'problem-entered-in-error',
    ]) {
        const harness = mountDialog(kind);
        await nextTick();
        actionButton(harness.mounted, 'cancel').props.onClick();
        assert.deepEqual(harness.calls, []);
        assert.deepEqual(harness.updates, [false]);
    }
});

test('No Known Allergies custom dialog confirms its existing mutation once', async () => {
    const confirmed = mountDialog('declare-no-known');
    await nextTick();
    const confirm = actionButton(confirmed.mounted, 'confirm');
    confirm.props.onClick();
    confirm.props.onClick();

    assert.equal(confirmed.calls.length, 1);
    assert.deepEqual(confirmed.calls[0].slice(0, 3), [
        'post',
        '/visits/KPV-SYNTHETIC/encounter/allergies/no-known',
        { expected_branch_id: 12, profile_lock_version: 7 },
    ]);
    assert.match(
        renderedText(confirmed.mounted.root),
        /Declare no known allergies/,
    );
});

test('Allergy entered-in-error uses destructive context and preserves its PATCH', async () => {
    const harness = mountDialog('allergy-entered-in-error');
    await nextTick();
    const output = descendants(harness.mounted.root, 'output')[0];
    assert.equal(output.props['data-destructive'], true);
    assert.match(
        renderedText(harness.mounted.root),
        /not when an allergy was cured/,
    );
    assert.match(
        renderedText(harness.mounted.root),
        /Synthetic clinical record/,
    );
    const confirm = actionButton(harness.mounted, 'confirm');
    confirm.props.onClick();
    confirm.props.onClick();

    assert.equal(harness.calls.length, 1);
    assert.deepEqual(harness.calls[0].slice(0, 3), [
        'patch',
        '/visits/KPV-SYNTHETIC/encounter/allergies/clinical-record-1/entered-in-error',
        { expected_branch_id: 12, profile_lock_version: 7 },
    ]);
});

test('Problem Resolve is operational and sends the unchanged resolve payload once', async () => {
    const harness = mountDialog('problem-resolve');
    await nextTick();
    assert.equal(
        descendants(harness.mounted.root, 'output')[0].props[
            'data-destructive'
        ],
        false,
    );
    assert.match(renderedText(harness.mounted.root), /Resolve this problem/);
    const confirm = actionButton(harness.mounted, 'confirm');
    confirm.props.onClick();
    confirm.props.onClick();

    assert.equal(harness.calls.length, 1);
    assert.deepEqual(harness.calls[0].slice(0, 3), [
        'patch',
        '/visits/KPV-SYNTHETIC/encounter/problems/clinical-record-1/resolve',
        { expected_branch_id: 12, lock_version: 4, resolved_date: null },
    ]);
});

test('Problem entered-in-error is destructive and keeps the historical PATCH contract', async () => {
    const harness = mountDialog('problem-entered-in-error');
    await nextTick();
    assert.equal(
        descendants(harness.mounted.root, 'output')[0].props[
            'data-destructive'
        ],
        true,
    );
    assert.match(
        renderedText(harness.mounted.root),
        /Mark this problem as entered in error/,
    );
    const confirm = actionButton(harness.mounted, 'confirm');
    confirm.props.onClick();
    confirm.props.onClick();

    assert.equal(harness.calls.length, 1);
    assert.deepEqual(harness.calls[0].slice(0, 3), [
        'patch',
        '/visits/KPV-SYNTHETIC/encounter/problems/clinical-record-1/entered-in-error',
        { expected_branch_id: 12, lock_version: 4 },
    ]);
});

test('targeted Clinical actions no longer use browser-native confirmation', () => {
    const panel = read(
        'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
    );
    const dialog = read(
        'resources/js/components/clinical/ClinicalSafetyConfirmDialog.vue',
    );

    assert.doesNotMatch(panel, /window\.confirm|\bconfirm\s*\(/);
    assert.doesNotMatch(dialog, /window\.confirm|\bconfirm\s*\(/);
    assert.match(panel, /<ClinicalSafetyConfirmDialog/);
});

test('Clinical action triggers open the custom dialog with the matching action context', async () => {
    const dialogStub = defineComponent({
        props: {
            open: Boolean,
            kind: String,
            recordPublicId: String,
        },
        setup: (props) => () =>
            h('aside', {
                'data-open': props.open,
                'data-kind': props.kind,
                'data-record': props.recordPublicId,
            }),
    });
    const passthrough = (tag = 'div') =>
        defineComponent({
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h(tag, attrs, slots.default?.()),
        });
    const useForm = (values) => ({
        ...values,
        errors: {},
        processing: false,
        reset() {},
        clearErrors() {},
        patch() {},
        post() {},
        transform() {},
    });
    const Panel = compileComponent(
        'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
        {
            '@inertiajs/vue3': { router: {}, useForm },
            '@lucide/vue': {
                AlertTriangle: icon,
                Check: icon,
                Plus: icon,
                ShieldCheck: icon,
            },
            '@/components/clinical/ClinicalSafetyConfirmDialog.vue': {
                default: dialogStub,
            },
            '@/components/ui/button': { Button: passthrough('button') },
            '@/components/ui/select': {
                OperationalSelect: passthrough('button'),
            },
            '@/lib/presentation': { formatDateTime: () => '9 Sep 2026' },
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(Panel, {
            visitNumber: 'KPV-SYNTHETIC',
            branchId: 12,
            timeZone: 'Asia/Kuala_Lumpur',
            allergies: {
                status: 'unknown',
                profileLockVersion: 7,
                canReview: false,
                canUpdate: true,
                records: [
                    {
                        publicId: 'allergy-1',
                        allergen: 'Synthetic allergen',
                        category: null,
                        reaction: null,
                        severity: null,
                    },
                ],
                encounterReview: { isCurrent: false },
                reviewedAt: null,
            },
            problems: {
                canUpdate: true,
                active: [
                    {
                        publicId: 'problem-1',
                        condition: 'Synthetic problem',
                        conditionCode: null,
                        codeSystem: null,
                        onsetDate: null,
                        lockVersion: 4,
                    },
                ],
                resolved: [],
            },
        })
        .mount(mounted.root);
    await nextTick();

    const buttons = () => descendants(mounted.root, 'button');
    const click = async (label, occurrence = 0) => {
        const button = buttons().filter((candidate) =>
            renderedText(candidate).includes(label),
        )[occurrence];
        button.props.onClick();
        await nextTick();

        return descendants(mounted.root, 'aside')[0].props;
    };

    assert.equal(
        (await click('Declare no known allergies'))['data-kind'],
        'declare-no-known',
    );
    assert.deepEqual(
        {
            kind: (await click('Entered in error', 0))['data-kind'],
            record: descendants(mounted.root, 'aside')[0].props['data-record'],
        },
        { kind: 'allergy-entered-in-error', record: 'allergy-1' },
    );
    assert.equal((await click('Resolve'))['data-kind'], 'problem-resolve');
    assert.deepEqual(
        {
            kind: (await click('Entered in error', 1))['data-kind'],
            record: descendants(mounted.root, 'aside')[0].props['data-record'],
        },
        { kind: 'problem-entered-in-error', record: 'problem-1' },
    );
});
