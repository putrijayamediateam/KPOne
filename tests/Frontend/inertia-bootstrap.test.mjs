import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { getInitialPageFromDOM } from '@inertiajs/core';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const bootstrapSource = read('resources/js/lib/inertia-bootstrap.ts');
const bootstrapJavascript = ts.transpileModule(bootstrapSource, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;

const loadBootstrap = (getInitialPage) => {
    const bootstrapModule = { exports: {} };

    Function(
        'require',
        'module',
        'exports',
        bootstrapJavascript,
    )(
        (specifier) => {
            assert.equal(specifier, '@inertiajs/core');

            return { getInitialPageFromDOM: getInitialPage };
        },
        bootstrapModule,
        bootstrapModule.exports,
    );

    return bootstrapModule.exports.readInitialInertiaPage;
};

const withInitialPageDom = (textContent, callback) => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;

    globalThis.window = {};
    globalThis.document = {
        querySelector(selector) {
            assert.equal(
                selector,
                'script[data-page="app"][type="application/json"]',
            );

            return textContent === null ? null : { textContent };
        },
    };

    try {
        return callback();
    } finally {
        if (originalWindow === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = originalWindow;
        }

        if (originalDocument === undefined) {
            delete globalThis.document;
        } else {
            globalThis.document = originalDocument;
        }
    }
};

test('bootstrap uses the locked Inertia JSON script contract', () => {
    const page = {
        component: 'auth/Login',
        props: { errors: {} },
        url: '/login',
        version: null,
    };
    const parsed = withInitialPageDom(JSON.stringify(page), () =>
        getInitialPageFromDOM('app'),
    );

    assert.deepEqual(parsed, page);
    assert.match(
        read('resources/views/app.blade.php'),
        /<x-inertia::app\s*\/>/,
    );
    assert.match(
        read('resources/js/app.ts'),
        /const initialPage = readInitialInertiaPage\(\);/,
    );
    assert.doesNotMatch(read('resources/js/app.ts'), /dataset\.page/);
});

test('bootstrap supplies the parsed page exactly once', () => {
    const page = {
        component: 'auth/Login',
        props: {},
        url: '/login',
        version: null,
    };
    const requestedIds = [];
    const readInitialInertiaPage = loadBootstrap((id) => {
        requestedIds.push(id);

        return page;
    });

    assert.equal(readInitialInertiaPage(), page);
    assert.deepEqual(requestedIds, ['app']);
    assert.equal(
        read('resources/js/app.ts').match(/createInertiaApp\s*\(/g)?.length,
        1,
    );
});

test('bootstrap fails closed when the initial page is missing or malformed', () => {
    const readInitialInertiaPage = loadBootstrap(() => null);

    assert.throws(
        () => readInitialInertiaPage(),
        /Inertia initial page payload is missing\./,
    );
    assert.throws(
        () =>
            withInitialPageDom('{not-json', () => getInitialPageFromDOM('app')),
        SyntaxError,
    );
});
