import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

// Parity: the Classic and the Version 2 Access Control pages are rendered with the SAME props (a snapshot of the real
// role catalogue, `fixtures/access-control-roles.json`) and must show the same role labels, the same permission names
// and the same counts. Synthetic data cannot make one role look different from another here; it is the real catalogue.
const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const labels = { exports: {} };
runInNewContext(transpile(read('resources/js/lib/displayLabels.ts')), {
    exports: labels.exports,
    require,
});
// Every layout block is replaced by a stub that prints its text props and renders all of its slots.
const stub = (extra = {}) => ({
    inheritAttrs: false,
    props: ['label', 'value', 'title', 'description', 'hint'],
    render() {
        const slots = Object.values(this.$slots).flatMap((slot) =>
            slot({ id: 'x', describedBy: undefined }),
        );

        return h('div', [
            this.label ? h('span', this.label) : null,
            this.value ? h('span', this.value) : null,
            this.title ? h('span', this.title) : null,
            this.description ? h('span', this.description) : null,
            ...slots,
        ]);
    },
    ...extra,
});
const icons = new Proxy({}, { get: () => stub() });
const compile = (path) => {
    const module = { exports: {} };
    const code = compileScript(parse(read(path)).descriptor, {
        id: path,
        inlineTemplate: true,
    }).content;
    runInNewContext(transpile(code), {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { Head: stub() };
            }

            if (name === '@lucide/vue') {
                return icons;
            }

            if (name === '@/lib/displayLabels') {
                return labels.exports;
            }

            if (name === '@/composables/usePageCrumbs') {
                return { usePageCrumbs: () => undefined };
            }

            if (name === '@/components/ui/badge') {
                return { Badge: stub() };
            }

            if (name === '@/components/ui/card') {
                return Object.fromEntries(
                    [
                        'Card',
                        'CardContent',
                        'CardDescription',
                        'CardHeader',
                        'CardTitle',
                    ].map((key) => [key, stub()]),
                );
            }

            if (name === '@/components/ui/page-frame') {
                return { PageFrame: stub() };
            }

            if (name.startsWith('@/components/v2/')) {
                return { default: stub() };
            }

            return require(name);
        },
    });

    return module.exports.default;
};
const classic = compile('resources/js/pages/AccessControl/Index.vue');
const v2 = compile('resources/js/pages/AccessControl/V2/Index.vue');
const roles = JSON.parse(
    readFileSync(
        new URL('./fixtures/access-control-roles.json', import.meta.url),
        'utf8',
    ),
);
const allPermissions = [...new Set(roles.flatMap((role) => role.permissions))];
const render = async (page, props) =>
    renderToString(createSSRApp({ render: () => h(page, props) }));
const flat = (html) =>
    html
        .replace(/<[^>]+>/g, ' ')
        .replace(/&quot;/g, '"')
        .replace(/\s+/g, ' ');
const names = (html) =>
    [...html.matchAll(/\b[a-z_]+(?:\.[a-z_]+)+\b/g)].map((m) => m[0]);

test('the fixture is the real catalogue: the TV account is display-only and holds no audit permission', () => {
    const tv = roles.find((role) => role.name === 'queue_display');

    assert.ok(tv.permissions.length <= 10);
    assert.ok(!tv.permissions.includes('audit.view.organisation'));
    assert.ok(
        roles
            .find((role) => role.name === 'director')
            .permissions.includes('audit.view.organisation'),
    );
    assert.ok(
        roles.find((role) => role.name === 'director').permissions.length >=
            roles.find((role) => role.name === 'ca').permissions.length,
    );
});

test('V2 shows the same two counts as Classic for the whole catalogue', async () => {
    const props = { roles, permissions: allPermissions };
    const a = flat(await render(classic, props));
    const b = flat(await render(v2, props));

    for (const [label, expected] of [
        ['Configured roles', roles.length],
        ['Explicit permissions', allPermissions.length],
    ]) {
        const pick = (text) =>
            Number(new RegExp(`${label}\\D*?(\\d+)`).exec(text)?.[1]);

        assert.equal(pick(a), expected, `Classic ${label}`);
        assert.equal(pick(b), expected, `V2 ${label}`);
    }
});

test('for every real role, V2 shows the same label, the same count and exactly the same permission names as Classic', async () => {
    for (const role of roles) {
        const props = { roles: [role], permissions: allPermissions };
        const a = await render(classic, props);
        const b = await render(v2, props);

        assert.deepEqual(
            [...names(a)].sort(),
            [...role.permissions].sort(),
            `Classic names for ${role.name}`,
        );
        assert.deepEqual(
            [...names(b)].sort(),
            [...role.permissions].sort(),
            `V2 names for ${role.name}`,
        );

        const label = labels.exports.formatRoleLabel(role.name);
        const count = `${role.permissions.length} permissions`;

        for (const text of [flat(a), flat(b)]) {
            assert.ok(text.includes(label), `${role.name}: label ${label}`);
            assert.ok(text.includes(count), `${role.name}: ${count}`);
        }
    }
});

test('V2 keeps the roles in the order the server sent them', async () => {
    const b = flat(await render(v2, { roles, permissions: allPermissions }));
    const positions = roles.map((role) =>
        b.indexOf(`${labels.exports.formatRoleLabel(role.name)} `),
    );

    assert.ok(positions.every((p) => p >= 0));
    assert.deepEqual(
        positions,
        [...positions].sort((x, y) => x - y),
    );
});
