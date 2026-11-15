import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/Agents/V2/Office.vue');
const classic = read('resources/js/pages/Agents/Office.vue');
const logic = read('resources/js/components/agents/office/useAgentOffice.ts');
const scene = read('resources/js/components/agents/office/OfficeScene.vue');

test('the V2 office is registered and uses the shared V2 frame, crumb and chips', () => {
    assert.match(read('config/v2-pages.php'), /'Agents\/Office'/);
    assert.match(v2, /<PageFrame>/);
    assert.match(v2, /<V2WorkLayout/);
    assert.match(v2, /title="Agent office"/);
    assert.match(
        v2,
        /usePageCrumbs\(\(\) => \[\{ title: 'Agents' \}, \{ title: 'Office' \}\]\)/,
    );
    assert.match(v2, /import V2Chip from '@\/components\/v2\/V2Chip\.vue'/);
    assert.match(
        v2,
        /tone="success"\s+:label="`\$\{counts\.working\} working`"/,
    );
    assert.match(v2, /waiting for a decision`/);
    // The AI-agent notice keeps its wording.
    assert.match(
        v2,
        /Everyone in this office is an AI agent, not a member of\s+staff\.\s+Agents only suggest; people decide in the approval\s+inbox\./,
    );
    // No Classic leftovers in the Version 2 page.
    assert.doesNotMatch(v2, /<Sheet|useUiVersion|PageHeader/);
});

test('Classic is Classic only and shares the live behaviour with V2 through one composable', () => {
    assert.doesNotMatch(classic, /useUiVersion|isV2|V2WorkLayout|V2Dialog/);
    assert.match(classic, /useAgentOffice\(props\)/);
    assert.match(v2, /useAgentOffice\(props\)/);
    // The scene and figure keep their own Version 2 switch: untouched by AG-23.
    assert.match(scene, /useUiVersion/);
    // One poll, one place.
    assert.equal((classic.match(/POLL_MS/g) ?? []).length, 0);
    assert.equal((v2.match(/POLL_MS/g) ?? []).length, 0);
    assert.equal((logic.match(/POLL_MS = 10_000/g) ?? []).length, 1);
});

test('the agent details open in a V2 dialog with the existing rows, updated in place', () => {
    assert.match(v2, /<V2Dialog/);
    assert.match(v2, /v-model:open="panelOpen"/);
    assert.match(v2, /single-action/);
    assert.match(v2, /confirm-label="Close"/);
    assert.match(v2, /@confirm="closePanel"/);
    // Title, status, current task, last steps, pending suggestions: as before.
    assert.match(v2, /selectedAgent\?\.displayName/);
    assert.match(v2, /Status:/);
    assert.match(v2, /Doing now:/);
    assert.match(v2, /Last steps/);
    assert.match(v2, /data-test="office-steps"/);
    assert.match(
        v2,
        /Waiting for a decision \(\{\{ detail\.pendingTotal \}\}\)/,
    );
    assert.match(v2, /data-test="office-pending-list"/);
    assert.match(
        v2,
        /Read only\. Counts and plain wording, never\s+patient details\./,
    );
    // The detail is not emptied by a poll: only a new selection clears it, so the open dialog updates in place.
    assert.match(
        logic,
        /watch\(\s*\(\) => feed\.value\.serverTime,\s*\(\) => \{\s*if \(selected\.value\) \{\s*void loadDetail\(selected\.value\);/,
    );
    assert.match(
        logic,
        /const select = \(code: string\) => \{\s*selected\.value = code;\s*detail\.value = null;/,
    );
});

test('there is a plain list of agents with name, AI agent, status in words and the latest message', () => {
    assert.match(v2, /aria-labelledby="office-agents-heading"/);
    assert.match(v2, /v-for="agent in feed\.agents"/);
    assert.match(v2, /<V2Chip tone="info" label="AI agent" \/>/);
    assert.match(v2, /statusFor\(agent\)/);
    assert.match(v2, /latestMessage\.get\(agent\.code\)\s*\?\.summary/);
    assert.match(v2, /:data-test="`office-card-\$\{agent\.code\}`"/);
});

test('Pause motion stays in the scene header with the same behaviour', () => {
    assert.match(v2, /Studio floor · live/);
    assert.match(v2, /v-if="!osReducedMotion"/);
    assert.match(v2, /:aria-pressed="motionOff"/);
    assert.match(v2, /min-h-11/);
    assert.match(v2, /:motion-off="motionOff"/);
    assert.match(logic, /kpone\.agents\.office\.motion/);
});
