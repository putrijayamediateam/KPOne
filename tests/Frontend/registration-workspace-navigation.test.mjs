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
    'resources/js/components/registration/RegistrationWorkspaceNav.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'branch-description',
    inlineTemplate: true,
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const render = async (permissions, active, branch = true, qrAvailable, qrPendingCount) => {
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        require: (name) => name === '@inertiajs/vue3'
            ? {
                usePage: () => ({ props: { auth: { permissions }, branchContext: { active: branch ? { id: 1 } : null } } }),
                Link: { props: ['href'], setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href }, slots.default?.()) },
            }
            : require(name),
    });
    return renderToString(createSSRApp({ render: () => h(module.exports.default, { active, ...(qrAvailable === undefined ? {} : { qrAvailable }), ...(qrPendingCount === undefined ? {} : { qrPendingCount }) }) }));
};
const all = ['visits.view.branch', 'patients.search.organisation', 'public_intakes.review.branch'];
test('authorized workspace links retain real routes and only active page is current', async () => {
    for (const [active, href] of [['today','/registration'], ['patients','/patients'], ['qr','/registration?tab=qr-intake']]) {
        const html = await render(all, active);
        assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
        assert.match(html, new RegExp('href="' + href.replace('?', '\\?') + '"[^>]*aria-current="page"|aria-current="page"[^>]*href="' + href.replace('?', '\\?') + '"'));
        assert.match(html, /QR check-ins/);
        assert.match(html, /Patients/);
        assert.doesNotMatch(html, /Follow-up|All branches/);
    }
});
test('patient-only authority cannot expose visits or QR navigation', async () => {
    const html = await render(['patients.search.organisation'], 'patients');
    assert.match(html, /href="\/patients"/);
    assert.doesNotMatch(html, /href="\/registration/);
});
test('QR requires both visit and review authority and available server projection', async () => {
    for (const permissions of [['visits.view.branch'], ['public_intakes.review.branch']]) {
        assert.doesNotMatch(await render(permissions, 'today'), /QR check-ins/);
    }
    assert.doesNotMatch(await render(all, 'today', true, false), /QR check-ins/);
});
test('no active branch removes branch links while organisation search stays accessible', async () => {
    const html = await render(all, 'patients', false);
    assert.match(html, /Patients/);
    assert.doesNotMatch(html, /QR check-ins|>Today</);
    assert.doesNotMatch(await render([], 'today'), /<nav/);
});

test('QR badge uses only supplied actual pending counts including zero', async () => {
    for (const count of [0, 7]) {
        assert.match(await render(all, 'today', true, true, count), new RegExp(`aria-label="${count} pending check-ins"`));
    }
    assert.doesNotMatch(await render(all, 'patients'), /pending check-ins/);
});
