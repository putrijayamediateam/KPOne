import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (text) => text.replace(/\s+/g, ' ');

test('AUTO-1: Register Visit offers Auto only when the server says it is available', () => {
    const page = squash(read('resources/js/pages/Registration/Create.vue'));
    assert.match(
        page,
        /const autoAssign = computed\(\(\) => props\.options\.autoAssign\?\.available === true\)/,
    );
    assert.match(
        page,
        /autoAssign\.value \? 'Auto \(recommended\)' : 'No doctor'/,
    );
    // Free or busy and how many wait; nothing about sales.
    assert.match(
        page,
        /doctor\.free \? 'Free' : `Busy, \$\{doctor\.waiting \?\? 0\} waiting`/,
    );
    const block = page.slice(
        page.indexOf('const doctorOptions = computed'),
        page.indexOf('const panelOptions'),
    );
    assert.doesNotMatch(block, /sales|target|percent|progress/i);
    // A single doctor is only preselected when Auto is not available.
    assert.match(page, /props\.options\.autoAssign\?\.available !== true/);
});

test('AUTO-1: choosing a doctor by hand asks for a fixed reason, sent as doctor_override_reason', () => {
    const page = squash(read('resources/js/pages/Registration/Create.vue'));

    for (const code of [
        'patient_requested',
        'continuity',
        'recommended_unavailable',
        'other',
    ]) {
        assert.ok(page.includes(`value: '${code}'`), code);
    }

    assert.match(page, /doctor_override_reason: string;/);
    assert.match(page, /v-model="form\.doctor_override_reason"/);
    assert.match(page, /errorFor\('doctor_override_reason'\)/);
});

test('AUTO-1: only the Director section on Registration settings switches it, per branch, with a lock version', () => {
    const page = squash(
        read('resources/js/pages/RegistrationSettings/Index.vue'),
    );
    assert.match(page, /v-if="autoAssign\?\.canManage"/);
    assert.match(page, /'\/registration-settings\/auto-assign'/);
    assert.match(page, /lock_version: props\.autoAssign\?\.lockVersion \?\? 0/);
    assert.match(page, /It is off by default\./);
});

test('AUTO-1: the visit option type carries free and waiting only', () => {
    const types = squash(read('resources/js/types/visit.ts'));
    assert.match(
        types,
        /doctors: Array<\{ id: number; name: string; free\?: boolean; waiting\?: number; \}>/,
    );
    assert.match(
        types,
        /autoAssign\?: \{ available: boolean; overrideReasons: string\[\] \}/,
    );
});
