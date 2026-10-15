import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const create = read('resources/js/pages/Registration/Create.vue');
const edit = read('resources/js/pages/Registration/Edit.vue');
const show = read('resources/js/pages/Clinical/Show.vue');

test('Panel note fields are optional bounded plain text in the existing Panel-only registration groups', () => {
    for (const source of [create, edit]) {
        assert.ok(
            source.indexOf('v-if="form.coverage_type === \'panel\'"') <
                source.indexOf('data-testid="registration-panel-note"'),
        );
        assert.match(source, /Panel note \(optional\)/);
        assert.match(
            source,
            /v-model="form.coverage_panel_note"[\s\S]*?maxlength="500"/,
        );
        assert.match(source, /form.coverage_panel_note.length/);
        assert.match(source, /errorFor\('coverage_panel_note'\)/);
        assert.doesNotMatch(source, /v-html|contenteditable/);
    }
});

test('changing Panels clears old text and binds any new note to the selected Panel', () => {
    for (const source of [create, edit]) {
        assert.match(
            source,
            /\(\) => form.panel_id,[\s\S]*?form.coverage_panel_note = ''[\s\S]*?form.panel_note_panel_id = panelId/,
        );
        assert.match(
            source,
            /coverage === 'self_pay'[\s\S]*?form.coverage_panel_note = ''/,
        );
    }

    assert.match(edit, /panel_note_idempotency_key: crypto.randomUUID\(\)/);
    assert.match(edit, /coverage_panel_note: props.visit.coverage.panelNote/);
});

test('the doctor sees an escaped read-only note only for nonempty Panel visits', () => {
    assert.match(
        show,
        /const panelNote = computed\(\(\) => props.clinical.visit.panelNote\)/,
    );
    assert.match(show, /v-if="clinical.visit.isPanel && panelNote"/);
    assert.match(show, /data-testid="consultation-panel-note"/);
    assert.match(show, /break-words whitespace-pre-wrap/);
    assert.match(show, /\{\{ panelNote \}\}/);
    assert.doesNotMatch(show, /v-model="[^\"]*panelNote|v-html/);
});

test('the domain owns note binding and retry rules and audit never stores the note body', () => {
    const domain = read('app/Domain/Visit/Services/VisitPanelNoteService.php');
    const administration = read(
        'app/Domain/Visit/Services/VisitAdministrationService.php',
    );
    const registration = read(
        'app/Domain/Visit/Services/VisitRegistrationService.php',
    );

    assert.match(domain, /panel_note_panel_id/);
    assert.match(domain, /hash_equals/);
    assert.match(domain, /lockForUpdate/);
    assert.match(domain, /'payload_hash' => \$hash/);
    assert.match(
        administration,
        /sensitive_fields_changed[\s\S]*?coverage_panel_note/,
    );
    assert.match(
        registration,
        /visit.panel_note.updated[\s\S]*?'has_note' => true/,
    );
    assert.match(
        read('app/Domain/Visit/Models/Visit.php'),
        /Hidden\(\['encrypted_presenting_information', 'coverage_panel_note'\]\)/,
    );
});

test('Registration and Queue share a narrow truncated Panel note column with a full-text keyboard tooltip and mobile cards', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );

    for (const page of ['Registration', 'Queue']) {
        assert.match(
            read(`resources/js/pages/${page}/Index.vue`),
            /panelNote: (?:visit|row)\.panelNote/,
        );
    }

    assert.match(board, /w-\[10%\][^>]*>Panel note<\/th>/);
    assert.match(board, /TooltipProvider\s+v-if="row.panelNote"/);
    assert.match(board, /tabindex="0"[^>]*truncate[^>]*:title="row.panelNote"/);
    assert.match(board, /TooltipContent[^>]*>[\s\S]*?\{\{ row.panelNote \}\}/);
    assert.match(board, /@media \(width < 768px\)/);
    assert.match(board, /content: attr\(data-label\)/);
    assert.match(board, /colspan="10"/);
});
