import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Office.vue');
const v2page = read('resources/js/pages/Agents/V2/Office.vue');
const scene = read('resources/js/components/agents/office/OfficeScene.vue');
const figure = read('resources/js/components/agents/office/AgentFigure.vue');

test('the control room look is Version 2 only: Classic keeps the original office', () => {
    // The page chooses by the one shared rule.
    assert.match(read('config/v2-pages.php'), /'Agents\/Office'/);
    assert.doesNotMatch(page, /useUiVersion|isV2/);
    assert.match(v2page, /V2WorkLayout/);
    // Classic keeps its sheet and its desktop test; Version 2 has its own side panel.
    assert.match(page, /<Sheet/);
    assert.match(page, /isDesktop/);
    assert.match(v2page, /data-test="office-panel"/);
});

test('the scene applies the control room class, tag layout and LEDs only under Version 2', () => {
    assert.match(scene, /useUiVersion/);
    assert.match(
        scene,
        /:class="\{ 'control-room max-md:min-w-\[560px\]': isV2 \}"/,
    );
    assert.doesNotMatch(
        scene,
        /class="office-scene control-room/,
        'the control-room class must not be in the static class list',
    );
    assert.match(scene, /if \(!isV2\.value\)/);
    assert.match(scene, /<template v-if="isV2">/);
    assert.match(scene, /:v2="isV2"/);
});

test('the figure draws the original name tag and bubble unless it is told it is Version 2', () => {
    assert.match(figure, /v2\?: boolean/);
    assert.match(figure, /<template v-if="v2">/);
    assert.match(figure, /<template v-else>/);
    // The original tag drawing is still there for Classic.
    assert.match(figure, /Name tag: always says "AI agent"/);
});

test('Version 2 panel says steps in plain words and wraps long card text', () => {
    const v2 = readFileSync('resources/js/pages/Agents/V2/Office.vue', 'utf8');
    assert.match(v2, /const plainStep = /);
    assert.match(v2, /plainStep\(step\.summary\)/);
    assert.match(v2, /plainStep\(\s*agent\.currentTask/);
    assert.match(v2, /line-clamp-3/);
    assert.match(v2, /rounded-lg border border-border bg-muted\/40/);
    // Classic keeps the raw summary.
    assert.match(page, /\{\{ step\.summary \}\}/);
});

test('Version 2 scene group is named, Classic keeps its name', () => {
    const scene = readFileSync(
        'resources/js/components/agents/office/OfficeScene.vue',
        'utf8',
    );
    assert.match(
        scene,
        /Agent office scene\. The agent list has the same information\./,
    );
    assert.match(scene, /: 'Agent office'/);
});
