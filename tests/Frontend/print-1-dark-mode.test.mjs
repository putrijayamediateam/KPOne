import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const stylesheet = postcss.parse(
    readFileSync(
        new URL('../../resources/css/app.css', import.meta.url),
        'utf8',
    ),
);
const surfaces = [
    'html:has(.document-print, .label-preview)',
    'body:has(.document-print, .label-preview)',
    '.document-print',
    '.label-preview',
];
const surfaceRules = [];
stylesheet.walkRules((rule) => {
    if (surfaces.some((selector) => rule.selectors.includes(selector))) {
        surfaceRules.push(rule);
    }
});

test('PRINT-1 pins white paper and dark text for the HTML, body and document print surfaces', () => {
    assert.equal(
        surfaceRules.length,
        1,
        'One shared print surface rule is required.',
    );
    const rule = surfaceRules[0];
    assert.deepEqual([...rule.selectors].sort(), [...surfaces].sort());
    assert.deepEqual(
        rule.nodes.map(({ prop, value, important }) => ({
            prop,
            value,
            important,
        })),
        [
            { prop: 'background', value: '#fff', important: true },
            { prop: 'color', value: '#000', important: true },
        ],
    );
});

test('PRINT-1 keeps the canvas override inside print media with no screen or layout override', () => {
    assert.equal(surfaceRules.length, 1);
    const rule = surfaceRules[0];
    assert.equal(rule.parent.type, 'atrule');
    assert.equal(rule.parent.name, 'media');
    assert.equal(rule.parent.params, 'print');
    assert.equal(rule.parent.parent.type, 'root');
    assert.ok(
        rule.nodes.every(
            (node) =>
                node.type === 'decl' &&
                ['background', 'color'].includes(node.prop),
        ),
    );
});
