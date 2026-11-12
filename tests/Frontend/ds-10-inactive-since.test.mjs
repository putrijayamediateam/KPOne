import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const panel = readFileSync(
    'resources/js/components/spaces/SpaceAskPanel.vue',
    'utf8',
);

test('DS-10: on cards the inactive-since date is prefixed with its heading, and a missing date is left plain', () => {
    assert.match(panel, /columns\[i\] === 'Inactive since'/);
    assert.match(panel, /cell !== 'Date not recorded'/);
    assert.match(panel, /`Inactive since \$\{cell\}`/);
});

test('DS-10: the missing-date text uses no warning or error colour', () => {
    const answerSection = panel.slice(panel.indexOf('<template'));
    assert.doesNotMatch(
        answerSection,
        /Date not recorded[^]{0,200}(is-red|is-amber|text-red|text-amber)/,
    );
});
