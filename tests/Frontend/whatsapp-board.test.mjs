import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('both operational projections pass the server boolean and existing Visit key to the shared row menu', () => {
    for (const [page, source] of [
        ['Registration', 'visit'],
        ['Queue', 'row'],
    ]) {
        const text = read(`resources/js/pages/${page}/Index.vue`);
        assert.match(text, new RegExp(`canWhatsapp: ${source}\\.canWhatsapp`));
    }

    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );
    assert.match(
        board,
        /<WhatsAppLink[\s\S]*?:can-whatsapp="row.canWhatsapp"[\s\S]*?:route-key="row.visitNumber"[\s\S]*?context="visit"[\s\S]*?<DropdownMenu>/,
    );
    assert.doesNotMatch(board, /mobile_phone|mobilePhone|:phone=|wa\.me/);
    assert.match(board, /flex min-h-11 items-center justify-end/);
});

test('contact tooltip opens below the row with opaque contrasting colours in both themes', () => {
    const text = read('resources/js/components/patient/WhatsAppLink.vue');
    assert.match(text, /side="bottom"/);
    assert.match(text, /align="end"/);
    assert.match(text, /:avoid-collisions="false"/);
    assert.match(text, /bg-slate-900 text-white/);
    assert.match(text, /dark:bg-slate-100 dark:text-slate-950/);
    assert.match(text, /min-h-12 min-w-12/);
});
