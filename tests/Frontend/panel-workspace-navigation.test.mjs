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
const source = readFileSync(
    'resources/js/components/panel/PanelWorkspaceNav.vue',
    'utf8',
);
const code = ts.transpileModule(
    compileScript(parse(source).descriptor, {
        id: 'panel-workspace-nav',
        inlineTemplate: true,
    }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const claims = 'panel.claims.manage.branch';
const payments = 'panel.remittances.record.branch';
async function render(
    permissions,
    active = 'claims',
    branch = { id: 1, name: 'Synthetic branch' },
    extra = {},
) {
    const module = { exports: {} };
    runInNewContext(code, {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    usePage: () => ({
                        props: {
                            auth: { permissions },
                            branchContext: { active: branch },
                        },
                    }),
                    Link: {
                        props: ['href'],
                        setup:
                            (p, { slots }) =>
                            () =>
                                h('a', { href: p.href }, slots.default?.()),
                    },
                };
            }

            return require(name);
        },
    });

    return renderToString(
        createSSRApp({
            render: () => h(module.exports.default, { active, ...extra }),
        }),
    );
}

test('navigation exposes only destinations from independent current permissions', async () => {
    const onlyClaims = await render([claims]);
    assert.match(onlyClaims, /href="\/panel-claims\/follow-up"/);
    assert.doesNotMatch(onlyClaims, /panel-remittances/);
    const onlyPayments = await render([payments], 'payments');
    assert.match(onlyPayments, /Record Panel payment/);
    assert.doesNotMatch(onlyPayments, /href="\/panel-claims/);
    const both = await render([claims, payments]);
    assert.match(both, /href="\/panel-remittances"/);
    assert.match(both, /href="\/panel-claims\/follow-up"/);
    assert.doesNotMatch(await render([]), /<nav/);
});
test('active destination is non-navigating and branchless navigation stays absent', async () => {
    for (const [active, url] of [
        ['claims', '/panel-claims'],
        ['follow-up', '/panel-claims/follow-up'],
        ['payments', '/panel-remittances'],
    ]) {
        const html = await render([claims, payments], active);
        assert.match(html, /<span[^>]*aria-current="page"/);
        assert.ok(
            !html.includes(`href="${url}"`),
            'Active route must not reload and discard the current draft',
        );
        assert.match(html, /focus-visible:ring-2/);
    }

    assert.doesNotMatch(
        await render([claims, payments], 'claims', null),
        /<nav/,
    );
});
test('Claims server capability can narrow remittance visibility without granting anything', async () => {
    assert.doesNotMatch(
        await render([claims, payments], 'claims', undefined, {
            canRecordPayments: false,
        }),
        /panel-remittances/,
    );
    assert.doesNotMatch(
        await render([claims], 'claims', undefined, {
            canRecordPayments: true,
        }),
        /panel-remittances/,
    );
});
test('all three existing pages bind the navigation while keeping the server capability on Claims', () => {
    for (const [file, active] of [
        ['PanelClaims/Index.vue', 'claims'],
        ['PanelClaims/FollowUp.vue', 'follow-up'],
        ['PanelRemittances/Index.vue', 'payments'],
    ]) {
        const page = readFileSync(`resources/js/pages/${file}`, 'utf8');
        assert.match(page, new RegExp(`active="${active}"`));
        assert.match(page, /import PanelWorkspaceNav from/);
    }

    const page = readFileSync(
        'resources/js/pages/PanelClaims/Index.vue',
        'utf8',
    );
    assert.match(
        page,
        /:can-record-payments="work\.can\.recordRemittances === true"/,
    );
    assert.doesNotMatch(
        source,
        /Paid|Waiting for payment|all-branch|amountSen|router\.(post|patch|delete)/,
    );
});
