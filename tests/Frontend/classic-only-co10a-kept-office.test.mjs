// Classic only, CO-10a: the Classic half of ag-13-office-version-gate, kept when the V2 control room look was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const scene = read('resources/js/components/agents/office/OfficeScene.vue');
const figure = read('resources/js/components/agents/office/AgentFigure.vue');
const layout = read('resources/js/components/agents/office/office-layout.ts');

test('the office scene has one look: no version switch, no control room, and its name is "Agent office"', () => {
    assert.doesNotMatch(scene, /useUiVersion|isV2|control-room|desk-led/);
    assert.match(scene, /aria-label="Agent office"/);
    assert.match(scene, /:viewBox="`0 0 \$\{VIEW_W\} \$\{VIEW_H\}`"/);
});

test('the figure draws the original name tag and bubble', () => {
    assert.match(figure, /Name tag: always says "AI agent"/);
    assert.match(
        figure,
        /:transform="`translate\(\$\{-tagWidth \/ 2\} \$\{seated \? -84 : -93\}\)`"/,
    );
    assert.match(
        figure,
        /\{\{ agent\.isEnabled \? 'AI agent' : 'AI agent · Off' \}\}/,
    );
    assert.match(figure, /:width="bubbleWidth"/);
    assert.doesNotMatch(figure, /v2|tagScale|tagDx|tagDy|showRole|tag-chip/);
    assert.doesNotMatch(layout, /spreadTags|tagBox|tagBottom|BUBBLE_GAP_PX/);
});
