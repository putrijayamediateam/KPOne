import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

const board = read('resources/js/components/patient-board/PatientBoard.vue');
const consultation = read('resources/js/pages/Clinical/Show.vue');
const callNext = read('resources/js/components/queue/CallNextControl.vue');
const callDialog = read('resources/js/components/queue/QueueCallDialog.vue');

test('TRI-1c: the zone chip words and dot colours are the same on the board, the consultation page and Call next', () => {
    for (const source of [board, consultation, callNext]) {
        assert.match(
            source,
            /red: 'Red zone', yellow: 'Yellow zone', green: 'Green zone'/,
        );
        assert.match(source, /'Triaged'/);
    }

    for (const source of [board, consultation]) {
        assert.match(
            source,
            /red: 'bg-destructive', yellow: 'bg-warning', green: 'bg-success'/,
        );
    }

    assert.match(
        callNext,
        /red: 'danger', yellow: 'warning', green: 'success'/,
    );
});

test('TRI-1c: the board shows the zone, or "No triage yet", inside the Status cell (no new column)', () => {
    assert.match(board, /v-if="row\.triage\?\.triaged"/);
    assert.match(board, /data-testid="triage-zone-chip"/);
    assert.match(
        board,
        /v-else-if="row\.triage" [^>]*data-testid="triage-missing-chip" >No triage yet</,
    );
    // The word is always shown; the dot is only decoration.
    assert.match(
        board,
        /aria-hidden="true" class="size-2 shrink-0 rounded-full"/,
    );
});

test('TRI-1c: both boards pass the server triage summary through untouched', () => {
    assert.match(
        read('resources/js/pages/Queue/Index.vue'),
        /triage: row\.triage,/,
    );
    assert.match(
        read('resources/js/pages/Registration/Index.vue'),
        /triage: visit\.triage,/,
    );
});

test('TRI-1c: the consultation page shows the zone and the triage label while the vitals are the copy', () => {
    assert.match(consultation, /v-if="clinical\.visit\.triage\?\.triaged"/);
    assert.match(consultation, /data-testid="consultation-triage-zone"/);
    assert.match(
        consultation,
        /v-if="clinical\.vitals\.fromTriage && !vitalsUnsaved"/,
    );
    assert.match(consultation, /From triage, last taken by/);
    // Q-11 stays untouched: the form is still seeded from the saved vitals.
    assert.match(
        consultation,
        /JSON\.stringify\(form\.vitals\) !== JSON\.stringify\(vitalsFromProps\(\)\)/,
    );
});

test('TRI-1c: Call In and Call next warn about a missing triage and never block', () => {
    assert.match(callDialog, /v-if="row\?\.triage && !row\.triage\.triaged"/);
    assert.match(callDialog, /No triage yet\. You can still call\./);
    assert.match(
        callNext,
        /v-if="confirmed\.triage && !confirmed\.triage\.triaged"/,
    );
    assert.match(callNext, /No triage yet\. You can still call\./);
    // No new disabled state tied to triage.
    assert.doesNotMatch(callDialog, /:disabled="[^"]*triage/);
    assert.doesNotMatch(callNext, /:disabled="[^"]*triage/);
});
