import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import { compile, createSSRApp, defineComponent, h } from 'vue';

const clinical = readFileSync('resources/js/pages/Clinical/Show.vue', 'utf8');
const panels = ['TreatmentRoomReadPanel', 'FamilyWaitingCard'].map((name) => {
    const matches = [
        ...clinical.matchAll(new RegExp(`<${name}\\b[^>]*\\/>`, 'g')),
    ];
    assert.equal(matches.length, 1, `${name} has exactly one live insertion`);

    return matches[0][0];
});
const render = compile(`<section>${panels.join('\n')}</section>`);
const components = Object.fromEntries(
    ['TreatmentRoomReadPanel', 'FamilyWaitingCard'].map((name) => [
        name,
        defineComponent({
            setup: () => () => h('div', { 'data-panel': name }, name),
        }),
    ]),
);

test('actual Clinical panel guards independently render absent, single and both authorized projections', async () => {
    for (const [familyWaiting, treatmentRoomUrl] of [
        [null, null],
        [{ total: 1 }, null],
        [null, '/visits/synthetic/treatment-room-records'],
        [{ total: 1 }, '/visits/synthetic/treatment-room-records'],
    ]) {
        const html = await renderToString(
            createSSRApp(
                { props: ['clinical', 'treatmentRoomUrl'], components, render },
                { clinical: { familyWaiting }, treatmentRoomUrl },
            ),
        );
        assert.equal(
            (html.match(/data-panel="FamilyWaitingCard"/g) ?? []).length,
            familyWaiting ? 1 : 0,
        );
        assert.equal(
            (html.match(/data-panel="TreatmentRoomReadPanel"/g) ?? []).length,
            treatmentRoomUrl ? 1 : 0,
        );
    }
});
