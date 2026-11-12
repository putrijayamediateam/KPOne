import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-3a attendance (owner 2026-11-06; Daniel's early look folded in, HR-PLAN 9.6).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/Attendance.vue');
const template = parse(source).descriptor.template.content;
const roster = read('resources/js/pages/HR/Roster.vue');

test('the page compiles', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-attendance',
            source: template,
            filename: 'Attendance.vue',
        }).errors,
        [],
    );
});

test('one button with three states; only Clock out asks for confirmation', () => {
    assert.match(template, /data-test="clock-in"/);
    assert.match(template, /data-test="clock-out"/);
    assert.match(template, /data-test="done-today"/);
    assert.match(template, /'Clocking in…'/);
    assert.match(template, /title="Clock out now\?"/);
    assert.match(template, /@click="clock\.confirmOut = true"/);
    assert.match(template, /@click="press\('in'\)"/);
    assert.match(template, /min-h-14/);
});

test('the ticking server time is never in a live region; one polite region announces state changes', () => {
    assert.equal((template.match(/aria-live=/g) ?? []).length, 1);
    assert.match(
        template,
        /aria-live="polite"[^>]*data-test="clock-announcer"/,
    );
    assert.match(template, /data-test="server-time"/);
    const timeLine = template.slice(
        template.indexOf('data-test="server-time"') - 120,
        template.indexOf('data-test="server-time"'),
    );
    assert.doesNotMatch(timeLine, /aria-live/);
    assert.match(
        source,
        /Date\.parse\(props\.attendance\.my\.serverNow\) - Date\.now\(\)/,
    );
});

test('the privacy line says the time and a photo are recorded', () => {
    // HR-PHOTO (owner-approved change, 2026-11-12): every press carries a live photo, so the line says so.
    assert.match(
        template,
        /KPOne records the time and a photo of you at each press\.\s+HR can see it\. Kept for 7 years\./,
    );
    // The camera lives in the shared ClockPhotoDialog; this page only opens it, and never takes a file upload.
    assert.match(source, /useClockPhoto\(\)/);
    assert.doesNotMatch(source, /getUserMedia|capture=/);
});

test('flags are warning chips with text, never danger; corrections show recorded beside new', () => {
    assert.match(
        template,
        /status="flag"\s+:label="f\.label"\s+tone="warning"/,
    );
    assert.doesNotMatch(template, /status="flag"[^/]*tone="danger"/);
    assert.match(template, /label="Correction waiting"/);
    assert.match(
        source,
        /Recorded: \$\{correction\.recordedOut \?\? 'none'\} · New:/,
    );
    assert.match(template, /Do not write medical details here\./);
});

test('review has a summary line, outline Approve and Reject, and the primary action in the dialog', () => {
    assert.match(template, /data-test="review-summary"/);
    assert.match(
        template,
        /variant="outline"[^>]*@click="openDecision\(c, true\)"/,
    );
    assert.match(
        template,
        /variant="outline"[^>]*@click="openDecision\(c, false\)"/,
    );
    assert.match(template, /'Approve correction'/);
});

test('My roster links to attendance with an outline button, never a second primary', () => {
    assert.match(
        roster,
        /v-if="roster\.my\.canClock"\s+as-child\s+variant="outline"/,
    );
    assert.match(roster, /href="\/hr\/attendance"/);
});

test('a clock refusal is read once and the branch hint is muted text only before clocking in', () => {
    assert.match(template, /<InputError :message="errors\.clock" \/>/);
    assert.match(
        template,
        /attendance\.my\.state === 'out' &&\s+attendance\.my\.refusal/,
    );
    assert.match(template, /data-test="clock-refusal"/);
    const watcher = source.slice(
        source.indexOf('const announcement'),
        source.indexOf('// ---- Clock in and out'),
    );
    assert.doesNotMatch(watcher, /errors\.value\.clock/);
});
