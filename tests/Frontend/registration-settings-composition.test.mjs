import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { router as coreRouter } from '@inertiajs/core';
import { useForm } from '@inertiajs/vue3';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, reactive } from 'vue';
const require = createRequire(import.meta.url);
function harness(canManage = true) {
    const elements = [],
        posts = [],
        reloads = [],
        listeners = new Map();
    let visitGuard;
    const props = reactive({
        branchId: 7,
        requirements: {
            requireDateOfBirth: false,
            requireNationality: false,
            requireEmail: false,
            requireAddress: false,
            lockVersion: 3,
        },
        autoAssign: {
            canManage,
            enabled: false,
            lockVersion: 2,
            branchName: 'Synthetic branch',
        },
    });
    const router = {
        on: (name, callback) => {
            visitGuard = callback;

            return () => {
                visitGuard = null;
            };
        },
        reload: (options) => {
            reloads.push(options);
            options.onSuccess();
        },
    };
    const button = {
        setup: (_, context) => () =>
            h('button', context.attrs, context.slots.default?.()),
    };
    const link = {
        setup: (_, context) => () =>
            h('a', context.attrs, context.slots.default?.()),
    };
    const load = (file) => {
        const module = { exports: {} };
        runInNewContext(
            ts.transpileModule(
                compileScript(parse(readFileSync(file, 'utf8')).descriptor, {
                    id: 'settings-proof',
                    inlineTemplate: true,
                    templateOptions: {
                        compilerOptions: { hoistStatic: false },
                    },
                }).content,
                {
                    compilerOptions: {
                        module: ts.ModuleKind.CommonJS,
                        target: ts.ScriptTarget.ES2022,
                    },
                },
            ).outputText,
            {
                exports: module.exports,
                crypto: webcrypto,
                window: {
                    addEventListener: (name, callback) =>
                        listeners.set(name, callback),
                    removeEventListener: (name) => listeners.delete(name),
                    confirm: () => false,
                },
                require: (name) =>
                    name === '@inertiajs/vue3'
                        ? { Head: () => null, Link: link, useForm, router }
                        : name === '@/components/InputError.vue'
                          ? {
                                default: load(
                                    'resources/js/components/InputError.vue',
                                ),
                            }
                          : name === '@/components/ui/button'
                            ? { Button: button }
                            : require(name),
            },
        );

        return module.exports.default;
    };
    const previousDocument = globalThis.document;
    globalThis.document = { activeElement: null };
    const renderer = createRenderer({
        createElement: (tag) => {
            const e = {
                tag,
                tagName: tag.toUpperCase(),
                style: {},
                children: [],
                listeners: {},
                addEventListener(name, fn) {
                    this.listeners[name] = fn;
                },
                removeEventListener() {},
                getAttribute(name) {
                    return this[name];
                },
            };
            elements.push(e);

            return e;
        },
        createText: (text) => ({ text }),
        createComment: (text) => ({ text }),
        insert: (e, parent) => {
            e.parent = parent;
            parent.children ??= [];
            parent.children.push(e);
        },
        remove: (e) => {
            if (e?.parent) {
                e.parent.children = e.parent.children.filter((c) => c !== e);
            }
        },
        setText: (e, text) => {
            e.text = text;
        },
        setElementText: (e, text) => {
            e.text = text;
        },
        parentNode: (e) => e.parent,
        nextSibling: (e) =>
            e?.parent?.children[e.parent.children.indexOf(e) + 1] ?? null,
        patchProp: (e, key, old, value) => {
            e[key] =
                key === 'disabled' ? value !== null && value !== false : value;
        },
    });
    const component = load('resources/js/pages/RegistrationSettings/Index.vue');
    const app = renderer.createApp({ render: () => h(component, props) });
    app.mount({ children: [] });
    const oldPost = coreRouter.post;
    coreRouter.post = (url, data, options) => {
        posts.push({ url, data: structuredClone(data), options });
    };

    return {
        props,
        elements,
        posts,
        reloads,
        listeners,
        guard: () => visitGuard,
        dispose: () => {
            app.unmount();
            coreRouter.post = oldPost;
            globalThis.document = previousDocument;
        },
    };
}
const nodeText = (e) =>
    (e.text ?? '') + (e.children ?? []).map(nodeText).join('');
const toggle = async (e) => {
    e.checked = !e.checked;
    e.listeners.change({ target: e });
    await nextTick();
};
const submit = async (e) => {
    e.onSubmit({ preventDefault() {} });
    await nextTick();
};

test('four native switches update draft status without a request and submit only the organisation requirements command', async () => {
    const p = harness();

    try {
        const switches = p.elements.filter(
            (e) => e.tag === 'input' && e.role === 'switch',
        );
        assert.equal(switches.length, 4);
        const email = switches.find((e) => e.id === 'require-email');
        await toggle(email);
        assert.equal(email.checked, true);
        assert.equal(p.posts.length, 0);
        await submit(
            p.elements.find((e) => e.id === 'registration-settings-form'),
        );
        assert.equal(p.posts.length, 1);
        const command = p.posts[0];
        assert.equal(command.url, '/registration-settings');
        assert.equal(command.data.expected_branch_id, 7);
        assert.equal(command.data.lock_version, 3);
        assert.equal(command.data.require_email, true);
        assert.equal(command.data.require_date_of_birth, false);
        assert.equal(command.data.require_address, false);
        assert.match(command.data.idempotency_key, /^[a-f0-9-]{36}$/);
        assert.equal(Object.hasOwn(command.data, 'enabled'), false);
    } finally {
        p.dispose();
    }
});

test('auto assignment remains separately permitted and submits its own versioned branch command with existing dirty guard', async () => {
    const p = harness();

    try {
        const auto = p.elements.find(
            (e) =>
                e.tag === 'input' &&
                e.type === 'checkbox' &&
                !e.disabled &&
                !e.role,
        );
        assert.ok(auto);
        await toggle(auto);
        assert.equal(p.guard()(), false);
        await submit(p.elements.filter((e) => e.tag === 'form')[1]);
        assert.deepEqual(p.posts[0].data, {
            expected_branch_id: 7,
            enabled: true,
            lock_version: 2,
        });
        assert.equal(p.posts[0].url, '/registration-settings/auto-assign');
        assert.equal(p.posts.length, 1);
    } finally {
        p.dispose();
    }

    const denied = harness(false);

    try {
        assert.equal(denied.elements.filter((e) => e.tag === 'form').length, 1);
        assert.equal(denied.posts.length, 0);
    } finally {
        denied.dispose();
    }
});

test('existing refusal reload keeps errors visible until explicit latest-settings reload and cleans listeners on unmount', async () => {
    const p = harness();

    try {
        await submit(
            p.elements.find((e) => e.id === 'registration-settings-form'),
        );
        p.posts[0].options.onError({
            lock_version: 'Synthetic stale settings',
        });
        await nextTick();
        const text = p.elements.map((e) => e.text ?? '').join(' ');
        assert.match(text, /Synthetic stale settings/);
        const reload = p.elements.find(
            (e) =>
                e.tag === 'button' &&
                e.onClick &&
                nodeText(e).trim() === 'Reload latest settings',
        );
        assert.ok(reload);
        p.props.requirements.lockVersion = 4;
        p.props.requirements.requireEmail = true;
        await reload.onClick();
        await nextTick();
        assert.equal(p.reloads.length, 1);
        await submit(
            p.elements.find((e) => e.id === 'registration-settings-form'),
        );
        assert.equal(p.posts[1].data.lock_version, 4);
        assert.equal(p.posts[1].data.require_email, true);
        assert.notEqual(
            p.posts[1].data.idempotency_key,
            p.posts[0].data.idempotency_key,
        );
    } finally {
        p.dispose();
    }

    assert.equal(p.listeners.size, 0);
    assert.equal(p.guard(), null);
});
