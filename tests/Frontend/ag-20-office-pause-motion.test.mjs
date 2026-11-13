import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the agent office offers a Pause motion button that stops the scene movement, not the live data', () => {
    const office = read('resources/js/pages/Agents/Office.vue');
    const scene = read('resources/js/components/agents/office/OfficeScene.vue');

    // One button in each of the two layouts, a real toggle button.
    assert.equal((office.match(/data-test="office-motion"/g) ?? []).length, 2);
    assert.equal((office.match(/:aria-pressed="motionOff"/g) ?? []).length, 2);
    assert.equal((office.match(/@click="toggleMotion"/g) ?? []).length, 2);
    assert.equal((office.match(/:motion-off="motionOff"/g) ?? []).length, 2);

    // A person whose device asks for reduced motion already has a still scene, so the button is not offered.
    assert.match(office, /prefers-reduced-motion: reduce/);
    assert.equal((office.match(/v-if="!osReducedMotion"/g) ?? []).length, 2);

    // The choice is remembered in this browser only, and storage failures never break the page.
    assert.match(office, /kpone\.agents\.office\.motion/);
    assert.match(office, /try \{[\s\S]*?localStorage\.getItem[\s\S]*?\} catch/);
    assert.match(office, /try \{[\s\S]*?localStorage\.setItem[\s\S]*?\} catch/);

    // It never touches the live poll: the live-updates flag is a separate ref.
    assert.match(office, /const paused = ref\(false\)/);
    assert.doesNotMatch(
        office,
        /toggleMotion = \(\) => \{[\s\S]{0,200}paused\.value/,
    );

    // The scene stops every animation and transition under the attribute, like the reduced-motion rule does.
    assert.match(scene, /motionOff\?: boolean/);
    assert.match(scene, /:data-motion="motionOff \? 'off' : 'on'"/);
    assert.match(
        scene,
        /\.office-scene\[data-motion='off'\],\s*\.office-scene\[data-motion='off'\] \* \{\s*animation: none !important;\s*transition: none !important;/,
    );
    assert.match(scene, /@media \(prefers-reduced-motion: reduce\)/);
});
