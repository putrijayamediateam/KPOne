import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(
    readFileSync(
        new URL('../../resources/css/app.css', import.meta.url),
        'utf8',
    ),
);
const declarations = (selector, inPrint) => {
    const found = [];

    css.walkRules((rule) => {
        const print =
            rule.parent?.type === 'atrule' && rule.parent.params === 'print';

        if (rule.selector === selector && print === inPrint) {
            rule.walkDecls('color-scheme', (decl) => found.push(decl.value));
        }
    });

    return found;
};

// UX-8 (design debt DD-07): without color-scheme the browser drew its own control parts
// (the date and time picker icons, checkboxes, scrollbars) for light mode, so the calendar
// and clock icons were dark on dark.
test('UX-8: the page tells the browser which theme is showing', () => {
    assert.deepEqual(declarations(':root', false), ['light']);
    assert.deepEqual(declarations('.dark', false), ['dark']);
});

test('UX-8: printed documents and labels stay light in dark mode', () => {
    assert.deepEqual(
        declarations(':root:has(.document-print, .label-preview)', true),
        ['light'],
    );
});

test('UX-8: the theme class goes on the root element the color-scheme rule targets', () => {
    const appearance = readFileSync(
        new URL(
            '../../resources/js/composables/useAppearance.ts',
            import.meta.url,
        ),
        'utf8',
    );
    const shell = readFileSync(
        new URL('../../resources/views/app.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(
        appearance,
        /document\.documentElement\.classList\.toggle\(\s*'dark'/,
    );
    assert.match(shell, /document\.documentElement\.classList\.add\('dark'\)/);
});
