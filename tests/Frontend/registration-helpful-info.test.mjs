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
    'resources/js/components/registration/RegistrationHelpfulInfo.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'registration-helpful-info',
    inlineTemplate: true,
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const all = [
    'visits.view.branch',
    'public_intakes.review.branch',
    'patients.search.organisation',
    'patients.merge.propose.organisation',
];
async function render(permissions = all, props = {}, activeId = 1) {
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        require: (name) =>
            name === '@inertiajs/vue3'
                ? {
                      usePage: () => ({
                          props: {
                              auth: { permissions },
                              branchContext: {
                                  active:
                                      activeId === null
                                          ? null
                                          : { id: activeId },
                              },
                          },
                      }),
                      Link: {
                          props: ['href'],
                          setup:
                              (p, { slots, attrs }) =>
                              () =>
                                  h(
                                      'a',
                                      { ...attrs, href: p.href },
                                      slots.default?.(),
                                  ),
                      },
                  }
                : require(name),
    });

    return renderToString(
        createSSRApp({
            render: () =>
                h(module.exports.default, {
                    branchId: 1,
                    branchName: 'Synthetic Branch',
                    viewLabel: 'Waiting',
                    isQr: false,
                    qrAvailable: true,
                    ...props,
                }),
        }),
    );
}
test('context uses actual supplied branch and view with escaped text and no fabricated counts', async () => {
    const html = await render([], {
        branchName: '<Private>',
        viewLabel: 'Completed',
    });
    assert.match(html, /&lt;Private&gt; · Completed/);
    assert.doesNotMatch(
        html,
        /<Private>|Related pages|href=|patients today|pending check-ins|Follow-up|All branches/,
    );
    assert.match(html, /Search for the patient before creating a new record/);
});
test('related links are individually permission gated', async () => {
    for (const [permission, path] of [
        ['patients.search.organisation', '/patients'],
        ['patients.merge.propose.organisation', '/patient-merges'],
        ['patients.merge.approve.organisation', '/patient-merges'],
    ]) {
        const html = await render([permission]);
        assert.match(html, new RegExp(`href="${path}"`));
        assert.equal((html.match(/href=/g) ?? []).length, 1);
    }
});
test('QR link requires both permissions, available projection and matching current branch', async () => {
    assert.match(await render(), /href="\/registration\?tab=qr-intake"/);

    for (const permissions of [
        ['visits.view.branch'],
        ['public_intakes.review.branch'],
        [],
    ]) {
        assert.doesNotMatch(await render(permissions), /href="\/registration/);
    }

    for (const activeId of [2, null]) {
        assert.doesNotMatch(
            await render(all, {}, activeId),
            /href="\/registration/,
        );
    }

    assert.doesNotMatch(
        await render(all, { qrAvailable: false }),
        /href="\/registration/,
    );
});
test('QR context explains review without showing Today guidance or self-link', async () => {
    const html = await render(all, { isQr: true, viewLabel: 'QR check-ins' });
    assert.match(html, /Review submitted check-ins before creating a visit/);
    assert.match(html, /not a registered visit until it is/);
    assert.doesNotMatch(
        html,
        /href="\/registration|Find visits in the selected branch|Search for the patient before/,
    );
    assert.match(html, /href="\/patients"/);
});
