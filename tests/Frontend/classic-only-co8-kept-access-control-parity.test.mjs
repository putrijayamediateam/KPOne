import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

// Classic only, CO-8: the Classic half of v2-access-control-parity, kept when its V2 page was removed.
// The Classic Access Control page is rendered with a snapshot of the real role catalogue
// (`fixtures/access-control-roles.json`) and must show each role's label, its exact permission names and the counts.
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

            return require(name);
        },
    });

    return module.exports.default;
};
const classic = compile('resources/js/pages/AccessControl/Index.vue');
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

test('Classic shows the two counts for the whole catalogue', async () => {
    const props = { roles, permissions: allPermissions };
    const a = flat(await render(classic, props));

    for (const [label, expected] of [
        ['Configured roles', roles.length],
        ['Explicit permissions', allPermissions.length],
    ]) {
        const pick = (text) =>
            Number(new RegExp(`${label}\\D*?(\\d+)`).exec(text)?.[1]);

        assert.equal(pick(a), expected, `Classic ${label}`);
    }
});

test('for every real role, Classic shows its label, its count and exactly its permission names', async () => {
    for (const role of roles) {
        const props = { roles: [role], permissions: allPermissions };
        const a = await render(classic, props);

        assert.deepEqual(
            [...names(a)].sort(),
            [...role.permissions].sort(),
            `Classic names for ${role.name}`,
        );

        const label = labels.exports.formatRoleLabel(role.name);
        const count = `${role.permissions.length} permissions`;

        assert.ok(flat(a).includes(label), `${role.name}: label ${label}`);
        assert.ok(flat(a).includes(count), `${role.name}: ${count}`);
    }
});
