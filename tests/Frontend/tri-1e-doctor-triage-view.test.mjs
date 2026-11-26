import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

const view = read('resources/js/pages/Triage/View.vue');
const consultation = read('resources/js/pages/Clinical/Show.vue');
const board = read('resources/js/components/patient-board/PatientBoard.vue');

test('TRI-1e: the doctor triage page is view only (no form, no post, nothing to send back)', () => {
    assert.doesNotMatch(view, /<form/);
    assert.doesNotMatch(
        view,
        /useForm|router\.(post|put|patch|delete)|method="post"/i,
    );
    assert.doesNotMatch(
        view,
        /lockVersion|idempotency_key|idempotencyKey|expected(Branch|Latest|Guideline)/,
    );
    assert.match(
        view,
        /View only\. The CA records triage; you cannot change it here\./,
    );
    assert.match(view, /'No guideline in force'/);
    assert.match(view, /No triage yet/);
});

test('TRI-1e: zones show their word, never colour alone', () => {
    assert.match(
        view,
        /red: 'Red zone', yellow: 'Yellow zone', green: 'Green zone'/,
    );
    assert.match(view, /:label="zoneLabel\(save\.chosenZone\)"/);
});

test('TRI-1e: "View triage" links come only from the server URL', () => {
    assert.match(
        consultation,
        /v-if="clinical\.visit\.triageViewUrl" :href="clinical\.visit\.triageViewUrl"/,
    );
    assert.match(
        consultation,
        /data-testid="consultation-view-triage" >View triage</,
    );
    assert.match(board, /v-if="row\.triageViewUrl"/);
    assert.match(
        board,
        /:href="row\.triageViewUrl" data-testid="view-triage-menu" >View triage</,
    );
    assert.match(
        read('resources/js/pages/Queue/Index.vue'),
        /triageViewUrl: row\.triageViewUrl \?\? null,/,
    );
});

test('TRI-1e (Daniel): readings show what was measured, and the link is underlined', () => {
    assert.match(view, /value\.replace\(\/\\\.\?0\+\$\/, ''\)/);
    assert.match(view, /trimReading\(save\.readings\[f\.key\]/);
    assert.match(
        consultation,
        /text-link underline underline-offset-4 lg:min-h-0" data-testid="consultation-view-triage"/,
    );
    assert.match(consultation, /min-h-11/);
});
