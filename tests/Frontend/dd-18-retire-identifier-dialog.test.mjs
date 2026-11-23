import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/js/pages/Patient/Show.vue', import.meta.url),
    'utf8',
);
const flat = (s) => s.replace(/\s+/g, ' ');

test('DD-18: Classic Patient page retires an identifier through the themed dialog, never confirm()', () => {
    assert.ok(!/\bconfirm\(/.test(source), 'no browser confirm()');
    const s = flat(source);
    assert.ok(
        s.includes(
            "import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue'",
        ),
    );
    assert.ok(s.includes('title="Retire this identifier?"'));
    assert.ok(
        s.includes(
            'The value remains permanently reserved in Patient Master history.',
        ),
    );
    assert.ok(s.includes('destructive'));
    assert.ok(s.includes('confirm-label="Retire identifier"'));
});

test('DD-18: the retire request is unchanged and only sent from Confirm, once', () => {
    const s = flat(source);
    assert.ok(
        s.includes(
            "router.patch( patientUrl() + '/identifiers/' + identifier.id + '/retire'",
        ),
    );
    assert.ok(s.includes('if (!identifier || retireProcessing.value) {'));
    // The Retire button only opens the dialog.
    const button = s.slice(
        s.indexOf('@click="retire(identifier)"') - 200,
        s.indexOf('@click="retire(identifier)"') + 40,
    );
    assert.ok(button.includes('retire(identifier)'));
    assert.ok(
        !s.includes(
            "router.patch(patientUrl() + '/identifiers/' + identifier.id + '/retire');",
        ),
    );
});
