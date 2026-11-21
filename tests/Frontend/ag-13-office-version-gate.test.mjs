import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Office.vue');
const scene = read('resources/js/components/agents/office/OfficeScene.vue');
const figure = read('resources/js/components/agents/office/AgentFigure.vue');

test('Classic keeps the original office: no version switch, its sheet and its desktop test', () => {
    assert.doesNotMatch(page, /useUiVersion|isV2/);
    // Classic keeps its sheet and its desktop test.
    assert.match(page, /<Sheet/);
    assert.match(page, /isDesktop/);
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
