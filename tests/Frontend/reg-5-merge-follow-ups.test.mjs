import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-5: the REG-3 follow-ups. Merge notes are plain muted text, never a coloured badge.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const note = read('resources/js/components/patient/MergedFromNote.vue');
const index = read('resources/js/pages/Patient/Index.vue');
const picker = read('resources/js/pages/Registration/Create.vue');
const show = read('resources/js/pages/Patient/Show.vue');
const problems = read(
    'resources/js/pages/Clinical/Partials/AllergyProblemPanel.vue',
);
const payLater = read('resources/js/pages/Billing/PayLaterCollections.vue');

test('REG-5: search rows and the Registration picker show who was merged in', () => {
    for (const source of [index, picker]) {
        assert.match(
            source,
            /<MergedFromNote\s+:numbers="patient\.mergedFrom \?\? \[\]"/,
        );
    }

    assert.match(
        note,
        /class="block text-xs break-words text-muted-foreground"/,
    );
    assert.match(note, /and \{\{ more \}\} more/);
    assert.match(note, /class="sr-only"/);
    assert.doesNotMatch(note, /Badge|bg-/);
});

test('REG-5: a merged guardian shows read only and only when the record has none', () => {
    assert.match(show, /v-else-if="mergedGuardian"/);
    assert.match(show, /From merged record/);
    assert.match(show, /Read only\./);
    assert.doesNotMatch(
        show.slice(show.indexOf('data-test="merged-guardian"')),
        /<Button|@click/,
    );
});

test('REG-5: merged problems are a separate read-only group with no actions', () => {
    const start = problems.indexOf('data-test="merged-problems"');
    assert.ok(start > 0);
    const group = problems.slice(start, problems.indexOf('</details>', start));
    assert.match(
        group,
        /From merged records \(\{\{ problems\.merged\.length \}\}\)/,
    );
    assert.match(
        group,
        /Read only\. Shown from merged records; they cannot be/,
    );
    assert.doesNotMatch(group, /<Button|@click|requestConfirmation/);
});

test('REG-5: pay-later rows note the old KP number of a merged-away record', () => {
    assert.match(payLater, /mergedFrom: string \| null/);
    assert.match(payLater, /<MergedFromNote\s+v-if="row\.mergedFrom"/);
});
