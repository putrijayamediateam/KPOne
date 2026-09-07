import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { cva } from 'class-variance-authority';
import ts from 'typescript';
import * as Vue from 'vue';

const { createRenderer, defineComponent, h, nextTick } = Vue;
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const transpileModule = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;

const navigationSource = read('resources/js/lib/workspace-navigation.ts');
const navigationModule = { exports: {} };
Function(
    'module',
    'exports',
    transpileModule(navigationSource),
)(navigationModule, navigationModule.exports);
const navigation = navigationModule.exports;

const compileComponent = (
    path,
    requireMap = {},
    transformSource = (source) => source,
) => {
    const descriptor = parse(transformSource(read(path)), {
        filename: path,
    }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
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
        transpileModule(compiled),
    )(localRequire, componentModule, componentModule.exports);

    return componentModule.exports.default;
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

const descendants = (node, type) => [
    ...(node.type === type ? [node] : []),
    ...(node.children ?? []).flatMap((child) => descendants(child, type)),
];

const passthrough = (tag = 'div') =>
    defineComponent({
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(tag, attrs, slots.default?.()),
    });

const loadCommonJsModule = (source, requireMap = {}) => {
    const loaded = { exports: {} };
    Function(
        'require',
        'module',
        'exports',
        transpileModule(source),
    )(
        (specifier) => {
            if (specifier in requireMap) {
                return requireMap[specifier];
            }

            throw new Error(`Unexpected module import: ${specifier}`);
        },
        loaded,
        loaded.exports,
    );

    return loaded.exports;
};

const renderedText = (node) =>
    [
        typeof node.text === 'string' ? node.text : '',
        ...(node.children ?? []).map(renderedText),
    ].join(' ');

test('query and hash do not remove a parent workspace active state', () => {
    assert.equal(
        navigation.isWorkspaceDestinationActive(
            '/registration?doctor=doctor-1#filters',
            '/registration',
        ),
        true,
    );
    assert.equal(
        navigation.isWorkspaceDestinationActive(
            '/registration/visit-1',
            '/registration',
        ),
        true,
    );
    assert.equal(
        navigation.isWorkspaceDestinationActive('/queue', '/registration'),
        false,
    );
    assert.equal(
        navigation.isWorkspaceDestinationActive(
            '/dashboard/menu',
            '/dashboard',
        ),
        false,
    );
});

test('Main Menu mounts permission-filtered semantic module links', async () => {
    const page = {
        props: {
            workspace: {
                navigation: {
                    registration: true,
                    consultation: true,
                    patientRecords: false,
                    panelWork: false,
                    financeWork: false,
                    staff: false,
                    branches: false,
                    accessControl: false,
                    auditLogs: false,
                },
            },
        },
    };
    const Link = defineComponent({
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(
                    'a',
                    { href: attrs.href, class: attrs.class },
                    slots.default?.(),
                ),
    });
    const icon = passthrough('svg');
    const Dashboard = compileComponent('resources/js/pages/Dashboard.vue', {
        '@inertiajs/vue3': {
            Head: passthrough(),
            Link,
            usePage: () => page,
        },
        '@lucide/vue': {
            Activity: icon,
            Building2: icon,
            ChevronRight: icon,
            ClipboardPlus: icon,
            FileText: icon,
            ShieldCheck: icon,
            Users: icon,
        },
        '@/lib/workspace-navigation': navigation,
    });
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(Dashboard, {
            summary: { organisation: 'Synthetic Clinic', activeBranch: null },
        })
        .mount(mounted.root);
    await nextTick();

    assert.deepEqual(
        descendants(mounted.root, 'a').map((link) => link.props.href),
        ['/registration', '/queue'],
    );
    assert.equal(descendants(mounted.root, 'section').length, 1);
});

test('OperationalSelect mounts an explicitly associated custom trigger', async () => {
    const SelectTrigger = passthrough('button');
    const OperationalSelect = compileComponent(
        'resources/js/components/ui/select/OperationalSelect.vue',
        {
            './Select.vue': { default: passthrough() },
            './SelectContent.vue': { default: passthrough() },
            './SelectItem.vue': { default: passthrough() },
            './SelectTrigger.vue': { default: SelectTrigger },
            './SelectValue.vue': { default: passthrough('span') },
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(OperationalSelect, {
            id: 'doctor-filter',
            labelledby: 'doctor-filter-label',
            modelValue: '',
            label: 'Doctor',
            options: [{ value: '', label: 'All doctors' }],
        })
        .mount(mounted.root);
    await nextTick();

    const trigger = descendants(mounted.root, 'button')[0];
    assert.equal(trigger.props.id, 'doctor-filter');
    assert.equal(trigger.props['aria-labelledby'], 'doctor-filter-label');
    assert.equal(trigger.props['aria-label'], undefined);
});

test('ErrorState mounts with one coherent assertive live-region model', async () => {
    const ErrorState = compileComponent(
        'resources/js/components/ui/state/ErrorState.vue',
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(ErrorState, { message: 'Synthetic error' })
        .mount(mounted.root);
    await nextTick();

    const state = descendants(mounted.root, 'div').find(
        (node) => node.props['data-slot'] === 'error-state',
    );
    assert.equal(state.props.role, 'alert');
    assert.equal(state.props['aria-live'], undefined);
});

test('Visit Reason uses the visible 120-character input boundary without slicing requests', () => {
    const picker = read('resources/js/components/visit/VisitReasonPicker.vue');

    assert.match(picker, /maxlength="120"/);
    assert.doesNotMatch(picker, /searchTerm\.slice\(0, 120\)/);
});

test('Registration detail mounts a contextual closed queue status', async () => {
    const queuePresentation = loadCommonJsModule(
        read('resources/js/lib/r1c2-presentation.ts'),
        {
            '@/lib/presentation': {
                formatElapsedMinutes: (minutes) => `${minutes} min`,
            },
        },
    );
    const StatusBadge = defineComponent({
        props: { status: String, label: String },
        setup: (props) => () => h('span', props.label ?? props.status),
    });
    const RegistrationShow = compileComponent(
        'resources/js/pages/Registration/Show.vue',
        {
            '@inertiajs/vue3': {
                Head: passthrough(),
                Link: passthrough('a'),
                useForm: (values) => ({
                    ...values,
                    errors: {},
                    processing: false,
                    post: () => {},
                    patch: () => {},
                }),
                usePage: () => ({
                    props: {
                        auth: { permissions: [] },
                        branchContext: { active: { id: 1 } },
                    },
                }),
            },
            '@lucide/vue': {
                ArrowLeft: passthrough('svg'),
                Ban: passthrough('svg'),
                ListOrdered: passthrough('svg'),
                Pencil: passthrough('svg'),
            },
            '@/components/InputError.vue': { default: passthrough() },
            '@/components/ui/button': { Button: passthrough('button') },
            '@/components/ui/status': { StatusBadge },
            '@/lib/presentation': {
                formatDate: (value) => value,
                formatDateTime: (value) => value,
            },
            '@/lib/r1c2-presentation': queuePresentation,
        },
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(RegistrationShow, {
            visit: {
                visitNumber: 'KPV-TEST-CLOSED',
                status: 'completed',
                priority: 'normal',
                registeredAt: '2026-09-07T00:00:00Z',
                cancelledAt: null,
                cancellationReason: null,
                lockVersion: 1,
                branch: {
                    name: 'Synthetic Branch',
                    timezone: 'Asia/Kuala_Lumpur',
                },
                patient: {
                    fullName: 'Synthetic Patient',
                    patientNumber: 'KP-TEST-CLOSED',
                    dateOfBirth: null,
                    sex: 'unknown',
                },
                visitType: 'consultation',
                doctor: null,
                coverage: { type: 'self_pay', panelName: null },
                queue: {
                    queueNumber: '001',
                    operationalDate: '2026-09-07',
                    status: 'removed',
                    lockVersion: 1,
                },
                visitReasons: {
                    primary: 'Synthetic reason',
                    additional: [],
                    legacy: null,
                },
                can: {
                    update: false,
                    cancel: false,
                    sendToWaiting: false,
                },
            },
        })
        .mount(mounted.root);
    await nextTick();

    const output = renderedText(mounted.root);
    assert.match(output, /Consultation Closed/);
    assert.doesNotMatch(output, /\bRemoved\b/);
});

test('disabled destructive ghost Button retains its semantic text contract', async () => {
    const buttonModule = loadCommonJsModule(
        read('resources/js/components/ui/button/index.ts'),
        {
            'class-variance-authority': { cva },
            './Button.vue': { default: {} },
        },
    );
    const Button = compileComponent(
        'resources/js/components/ui/button/Button.vue',
        {
            'reka-ui': { Primitive: passthrough('button') },
            '@/lib/utils': {
                cn: (...classes) => classes.filter(Boolean).join(' '),
            },
            '.': buttonModule,
        },
        (source) =>
            source.replace(
                /interface Props extends PrimitiveProps \{[\s\S]*?\n\}/,
                `interface Props {
  as?: string
  asChild?: boolean
  variant?: 'default' | 'primary' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'accent' | 'link'
  size?: 'default' | 'sm' | 'lg' | 'icon' | 'icon-sm' | 'icon-lg'
  class?: string
}`,
            ),
    );
    const mounted = createMountedRenderer();
    mounted.renderer
        .createApp(Button, {
            variant: 'ghost',
            disabled: true,
            class: 'text-red-700 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300',
        })
        .mount(mounted.root);
    await nextTick();

    const button = descendants(mounted.root, 'button')[0];
    assert.equal(button.props.disabled, true);
    assert.match(button.props.class, /disabled:cursor-not-allowed/);
    assert.match(button.props.class, /disabled:opacity-50/);
    assert.match(button.props.class, /text-red-700/);
    assert.match(button.props.class, /disabled:hover:bg-transparent/);
    assert.doesNotMatch(button.props.class, /hover:text-accent-foreground/);
    assert.doesNotMatch(button.props.class, /disabled:hover:text-inherit/);
});
