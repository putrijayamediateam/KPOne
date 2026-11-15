import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

const loadRule = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(read('resources/js/lib/v2-refused-rule.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('DD-84 rule: only a V2 page and only 403 and 419; Classic and every other status do nothing', async () => {
    const { refusalFor } = await loadRule();

    // V2: 403 and 419 get Daniel's sentences, word for word
    assert.deepEqual(refusalFor('v2', 403), {
        status: 403,
        message: "You don't have permission to do this. Nothing was changed.",
    });
    assert.deepEqual(refusalFor('v2', 419), {
        status: 419,
        message:
            'This page was open too long, so nothing was saved. Reload the page and try again.',
    });

    // Classic and an unset ui: nothing, for every status
    for (const ui of ['classic', undefined, '', 'V2']) {
        for (const status of [403, 419, 409, 500, 404, 422]) {
            assert.equal(refusalFor(ui, status), null, `${ui} ${status}`);
        }
    }

    // V2 but any other status: left to Inertia
    for (const status of [409, 404, 422, 500, 502, 503, undefined, 200]) {
        assert.equal(refusalFor('v2', status), null, `v2 ${status}`);
    }
});

test('DD-84 handler: cancels only through the rule, clears on the next request, is booted before the app', () => {
    const m = read('resources/js/lib/v2-refused-request.ts');
    const app = read('resources/js/app.ts');
    assert.match(m, /document\.addEventListener\('inertia:httpException'/);
    assert.match(
        m,
        /refusalFor\(document\.documentElement\.dataset\.ui, status\)/,
    );
    // nothing is cancelled unless the rule says so
    assert.match(
        m,
        /if \(refusal === null\) \{\s*return;\s*\}\s*event\.preventDefault\(\)/,
    );
    assert.match(m, /document\.addEventListener\('inertia:start'/);
    assert.match(app, /import '@\/lib\/v2-refused-request';/);
});

test('DD-84 notice: icon plus words, alert role, Reload only on 419, no Classic imports', () => {
    const n = read('resources/js/components/v2/V2RefusedNotice.vue');
    assert.match(n, /role="alert"/);
    assert.match(n, /<svg/);
    assert.match(n, /border-warning\/40 bg-warning-soft/);
    assert.match(n, /v-if="refused\.status === 419"/);
    assert.match(n, /Reload page/);
    assert.match(n, /h-11[^"]*lg:h-9/);
    assert.doesNotMatch(n, /from '@\/components\/ui\//);
    // it is drawn by the shell (form case) and inside an open sheet (dialog case), never both
    const shell = read('resources/js/components/workspace/V2AppShell.vue');
    assert.match(shell, /<V2RefusedNotice[\s\S]*?v-if="openDialogs === 0"/);
    const d = read('resources/js/components/v2/V2Dialog.vue');
    assert.match(d, /v-if="noticeShown"/);
    assert.match(d, /openDialogs\.value\+\+/);
    assert.match(d, /openDialogs\.value--/);
});
