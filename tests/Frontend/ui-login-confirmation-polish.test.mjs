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

const compileComponent = (path, requireMap = {}) => {
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

const passthrough = (tag = 'div') =>
    defineComponent({
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(tag, attrs, slots.default?.()),
    });

test('Login mounts the existing authentication contract with mature internal-only copy', async () => {
    const Form = defineComponent({
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(
                    'form',
                    attrs,
                    slots.default?.({ errors: {}, processing: false }),
                ),
    });
    const Login = compileComponent('resources/js/pages/auth/Login.vue', {
        '@inertiajs/vue3': {
            Form,
            Head: passthrough(),
            usePage: () => ({ props: { errors: {} } }),
        },
        '@/components/InputError.vue': { default: passthrough('p') },
        '@/components/PasswordInput.vue': { default: passthrough('input') },
        '@/components/TextLink.vue': { default: passthrough('a') },
        '@/components/ui/button': { Button: passthrough('button') },
        '@/components/ui/checkbox': { Checkbox: passthrough('input') },
        '@/components/ui/input': { Input: passthrough('input') },
        '@/components/ui/label': { Label: passthrough('label') },
        '@/components/ui/spinner': { Spinner: passthrough('svg') },
        '@/routes/login': {
            store: { form: () => ({ action: '/login', method: 'post' }) },
        },
        '@/routes/password': { request: () => '/forgot-password' },
    });
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(Login, {
            canResetPassword: true,
            googleEnabled: true,
        })
        .mount(mounted.root);
    await nextTick();

    const form = descendants(mounted.root, 'form')[0];
    const inputs = descendants(mounted.root, 'input');
    const links = descendants(mounted.root, 'a');
    const output = renderedText(mounted.root);

    assert.equal(form.props.action, '/login');
    assert.equal(form.props.method, 'post');
    assert.deepEqual(
        inputs.map((input) => input.props.name),
        ['email', 'password', 'remember'],
    );
    assert.equal(inputs[0].props.autocomplete, 'email');
    assert.equal(inputs[1].props.autocomplete, 'current-password');
    assert.ok(links.some((link) => link.props.href === '/forgot-password'));
    assert.ok(
        links.some((link) => link.props.href === '/auth/google/redirect'),
    );
    assert.match(output, /Remember me/);
    assert.match(output, /authorised Klinik Putrijaya staff/);
    assert.doesNotMatch(output, /Create account|Sign up|Register/);
});

test('Login split layout mounts one responsive form region with restrained KPOne branding', async () => {
    const Layout = compileComponent(
        'resources/js/layouts/auth/AuthSplitLayout.vue',
        {
            '@inertiajs/vue3': {
                Link: passthrough('a'),
                usePage: () => ({ props: { name: 'KPOne' } }),
            },
            '@lucide/vue': {
                Building2: passthrough('svg'),
                ShieldCheck: passthrough('svg'),
            },
            '@/components/AppLogoIcon.vue': { default: passthrough('svg') },
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(Layout, {
            title: 'Welcome to KPOne',
            description: 'Sign in to continue.',
        })
        .mount(mounted.root);
    await nextTick();

    const root = descendants(mounted.root, 'div')[0];
    const output = renderedText(mounted.root);
    assert.match(root.props.class, /min-h-svh/);
    assert.match(root.props.class, /lg:grid-cols/);
    assert.equal(descendants(mounted.root, 'main').length, 1);
    assert.match(output, /Clear, connected clinic operations/);
    assert.match(output, /Welcome to KPOne/);
});

test('Password reveal remains keyboard focusable and accessibly named', async () => {
    const PasswordInput = compileComponent(
        'resources/js/components/PasswordInput.vue',
        {
            '@lucide/vue': {
                Eye: passthrough('svg'),
                EyeOff: passthrough('svg'),
            },
            '@/components/ui/input': { Input: passthrough('input') },
            '@/lib/utils': {
                cn: (...classes) => classes.filter(Boolean).join(' '),
            },
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(PasswordInput, {
            id: 'password',
            name: 'password',
            autocomplete: 'current-password',
        })
        .mount(mounted.root);
    await nextTick();

    const reveal = descendants(mounted.root, 'button')[0];
    assert.equal(reveal.props.type, 'button');
    assert.equal(reveal.props['aria-label'], 'Show password');
    assert.equal(reveal.props.tabindex, undefined);
});

const operationalDialogStub = defineComponent({
    props: {
        open: Boolean,
        title: String,
        description: String,
        confirmLabel: String,
        processing: Boolean,
        error: String,
    },
    emits: ['update:open', 'confirm'],
    setup:
        (props, { emit, slots }) =>
        () =>
            h('section', { 'data-open': props.open }, [
                h('h2', props.title),
                h('p', props.description),
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

const queueRow = {
    key: '2026-09-09-001',
    patientName: 'Synthetic Patient',
    patientNumber: 'KP-SYNTHETIC',
    visitNumber: 'KPV-SYNTHETIC',
    queueNumber: '001',
    source: { visitLockVersion: 4, queueLockVersion: 7 },
};

const mountQueueCallDialog = (patch) => {
    const QueueCallDialog = compileComponent(
        'resources/js/components/queue/QueueCallDialog.vue',
        {
            '@inertiajs/vue3': { router: { patch } },
            '@lucide/vue': { PhoneCall: passthrough('svg') },
            '@/components/ui/OperationalConfirmDialog.vue': {
                default: operationalDialogStub,
            },
        },
    );
    const mounted = createMountedRenderer();
    const updates = [];
    const processing = [];
    let finished = 0;
    mounted.renderer
        .createApp(QueueCallDialog, {
            open: true,
            row: queueRow,
            branchId: 12,
            'onUpdate:open': (value) => updates.push(value),
            onProcessing: (value) => processing.push(value),
            onFinished: () => finished++,
        })
        .mount(mounted.root);

    return { mounted, updates, processing, finished: () => finished };
};

test('Call Patient cancel closes the custom dialog without a mutation', async () => {
    const calls = [];
    const harness = mountQueueCallDialog((...args) => calls.push(args));
    await nextTick();
    const cancel = descendants(harness.mounted.root, 'button').find(
        (button) => button.props['data-action'] === 'cancel',
    );
    cancel.props.onClick();
    await nextTick();

    assert.deepEqual(calls, []);
    assert.deepEqual(harness.updates, [false]);
});

test('Call Patient confirms the existing PATCH exactly once and blocks duplicates while pending', async () => {
    const calls = [];
    const harness = mountQueueCallDialog((...args) => calls.push(args));
    await nextTick();
    const confirm = descendants(harness.mounted.root, 'button').find(
        (button) => button.props['data-action'] === 'confirm',
    );

    confirm.props.onClick();
    confirm.props.onClick();
    await nextTick();

    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/visits/KPV-SYNTHETIC/queue/call');
    assert.deepEqual(calls[0][1], {
        expected_branch_id: 12,
        visit_lock_version: 4,
        queue_lock_version: 7,
    });
    assert.equal(calls[0][2].preserveScroll, true);
    assert.deepEqual(harness.processing, ['2026-09-09-001']);

    calls[0][2].onSuccess();
    calls[0][2].onFinish();
    await nextTick();
    assert.deepEqual(harness.updates, [false]);
    assert.deepEqual(harness.processing, ['2026-09-09-001', null]);
    assert.equal(harness.finished(), 1);
});

test('Queue targeted Call Patient flow no longer uses browser confirm', () => {
    const queue = read('resources/js/pages/Queue/Index.vue');
    const dialog = read('resources/js/components/queue/QueueCallDialog.vue');

    assert.doesNotMatch(queue, /window\.confirm|\bconfirm\s*\(/);
    assert.doesNotMatch(dialog, /window\.confirm|\bconfirm\s*\(/);
    assert.match(queue, /@call="requestCall"/);
    assert.match(queue, /<QueueCallDialog/);
});
