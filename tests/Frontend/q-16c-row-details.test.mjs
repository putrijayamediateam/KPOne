import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const dialog = readFileSync(
    'resources/js/components/queue/QueueRowDetailsDialog.vue',
    'utf8',
);
const board = readFileSync(
    'resources/js/components/patient-board/PatientBoard.vue',
    'utf8',
);

test('the details dialog reads one audited endpoint, shows only the agreed fields and never the date of birth (Q-16c)', () => {
    assert.match(
        dialog,
        /\/visits\/\$\{encodeURIComponent\(visitNumber\)\}\/queue\/details/,
    );

    for (const label of [
        'Age, sex',
        'Allergies',
        'Last BP',
        'Last visit',
        'Who pays',
        'Reason',
        'Vitals (triage)',
    ]) {
        assert.ok(dialog.includes(label), label);
    }

    assert.doesNotMatch(
        dialog,
        /dateOfBirth|date_of_birth|patientName|identifier/i,
    );
    assert.match(dialog, /Opening this is recorded/);
    assert.match(dialog, /role="alert"/);
});

test('the row menu offers Show details only where the board already shows the triage zone', () => {
    assert.match(
        board,
        /v-if="row\.triage && from === 'queue'"[\s\S]{0,200}row-details-menu/,
    );
    assert.match(board, /\$emit\('showDetails', row\)/);
    assert.doesNotMatch(board, /QueueRowDetailsDialog/);

    for (const page of ['Queue/Index.vue']) {
        const source = readFileSync(`resources/js/pages/${page}`, 'utf8');
        assert.match(source, /<QueueRowDetailsDialog/);
        assert.match(
            source,
            /@show-details="detailsVisit = \$event\.visitNumber"/,
        );
    }
});

test('the details open as a right-hand side panel from 1280 px and a full-screen sheet below, with allergies kept distinct (Daniel)', () => {
    assert.match(dialog, /<SheetContent/);
    assert.match(dialog, /side="right"/);
    assert.match(dialog, /w-full[\s\S]{0,80}xl:w-\[28rem\]/);
    assert.match(dialog, /'None known'/);
    assert.match(dialog, /'Not yet reviewed'/);
});
