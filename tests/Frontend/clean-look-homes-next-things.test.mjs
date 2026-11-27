import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const day = readFileSync('resources/js/pages/MyDay/Index.vue', 'utf8');
const next = readFileSync(
    'resources/js/components/spaces/NextThings.vue',
    'utf8',
);

test('My day shows Your next things first, from the page prop, polled from my-day/next', () => {
    assert.match(day, /<NextThings :next="next" \/>/);
    assert.ok(
        day.indexOf('<NextThings') < day.indexOf('data-test="my-day-waiting"'),
    );
    assert.match(next, /requestJson<NextBlock>\('\/my-day\/next'/);
    assert.match(next, /data-test="my-day-next"/);
});

test('the home reuses the shared red-zone alert and builds none of its own', () => {
    assert.match(day, /RedZoneAlertBanner/);
    assert.match(day, /queue\.call\.own/);
});

test('the next things card uses tokens only, with no hard-coded colours', () => {
    assert.doesNotMatch(
        next,
        /#[0-9a-fA-F]{3,6}\b|bg-pink|text-pink|rose-|brand/,
    );
});

test('an urgent patient is badged Urgent, not Overdue', () => {
    assert.match(next, /startsWith\('Urgent'\)/);
});

const css = readFileSync('resources/css/space-shell-layout.css', 'utf8');
const read = (p) => readFileSync(p, 'utf8');

test('Daniel 27 Nov: the branch shows once, in the date line', () => {
    assert.match(
        read('resources/js/components/ui/page-header/PageHeader.vue'),
        /showBranchLine/,
    );
    assert.match(day, /hide-branch/);
});

test('Daniel 27 Nov: Clock in is the one filled blue pill, Add is an outline pill', () => {
    assert.match(
        css,
        /\.me-compact-button \{[^}]*background: var\(--primary\);[^}]*color: var\(--primary-foreground\)/s,
    );
    assert.match(css, /\.me-compact-button\.is-secondary/);
    const routine = read('resources/js/components/MyRoutine.vue');
    assert.match(routine, /variant="outline"\s+class="rounded-full/);
    assert.match(routine, /newTitle\.trim\(\) === ''/);
});

test('Daniel 27 Nov: the leave, claims, roster and attendance tiles are plain card tiles', () => {
    assert.doesNotMatch(css, /\.me-tile\.is-(leave|claims|roster|attendance)/);
    assert.match(css, /\.me-tile \{[^}]*background: var\(--space-surface\)/s);
    assert.match(
        read('resources/js/components/spaces/MeHome.vue'),
        /Open attendance/,
    );
});

test('Daniel 27 Nov: This week is 15/600 and stacks at 390', () => {
    assert.match(
        css,
        /\.me-week-head h2 \{[^}]*font-size: 15px;[^}]*font-weight: 600/s,
    );
    assert.match(
        css,
        /max-width: 479px\) \{\s*\.space-shell \.me-week-head \{\s*flex-direction: column/,
    );
});
