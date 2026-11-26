import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (text) => text.replace(/\s+/g, ' ');

test('AUTO-1c: the board shows a neutral warning chip and a Reassign link to Edit visit, only when the server sends one', () => {
    const board = squash(
        read('resources/js/components/patient-board/PatientBoard.vue'),
    );
    assert.match(board, /v-if="row\.doctorWarning"/);
    assert.match(board, /data-testid="doctor-warning"/);
    assert.match(board, /\{\{ row\.doctorWarning\.label \}\}/);
    // The chip is a word plus an icon (not colour alone), in the warning tokens.
    assert.match(board, /text-warning-strong/);
    assert.match(
        board,
        /<AlertTriangle\s+v-else\s+class="size-3 shrink-0"\s+aria-hidden="true"\s*\/>/,
    );
    // Reassign goes to the existing Edit visit, and is 44 px tall below 1024 px.
    assert.match(board, /:href="editHref\(row\.visitNumber\)"/);
    assert.match(board, /max-lg:min-h-11/);
    assert.match(
        board,
        /Reassign visit \$\{row\.visitNumber\}: \$\{row\.doctorWarning\.label\}/,
    );
});

test('AUTO-1c: both boards hand the warning to the shared board row', () => {
    for (const path of [
        'resources/js/pages/Registration/Index.vue',
        'resources/js/pages/Queue/Index.vue',
    ]) {
        assert.match(
            squash(read(path)),
            /doctorWarning: (visit|row)\.doctorWarning,/,
        );
    }
});

test('AUTO-1c: the warning type carries a code and a label only, never a doctor or a figure', () => {
    const types = squash(read('resources/js/types/patientBoard.ts'));
    assert.match(
        types,
        /doctorWarning\?: \{ code: 'off_roster' \| 'on_leave' \| 'no_room' \| 'another_free'; label: string; \} \| null;/,
    );
});

test('AUTO-1c: the projection is batched and the two boards pass it only to viewers who may edit', () => {
    const projection = read(
        'app/Domain/Visit/Assignment/DoctorWarningProjection.php',
    );
    assert.match(projection, /Batched/);

    for (const code of ['off_roster', 'on_leave', 'no_room', 'another_free']) {
        assert.ok(projection.includes(`'${code}'`), code);
    }

    for (const path of [
        'app/Domain/Visit/Services/VisitDirectoryService.php',
        'app/Domain/Queue/Services/QueueDirectoryService.php',
    ]) {
        const source = squash(read(path));
        assert.match(
            source,
            /'doctorWarning' => \$actions\['update'\] && \$visit->relationLoaded\('doctorWarning'\)/,
        );
    }
});
