import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const completed = read('resources/js/pages/Dispensary/Completed.vue');
const section = read(
    'resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
);
const presentation = read('resources/js/lib/presentation.ts');
const flat = (text) => text.replace(/\s+/g, ' ');

test('the completed page uses PageHeader, a wider layout and the separate returns prop', () => {
    assert.ok(completed.includes('<PageHeader'));
    assert.ok(completed.includes('title="Dispensary completed"'));
    assert.ok(completed.includes('max-w-5xl'));
    assert.ok(completed.includes('returns?: DispensaryReturnsPanel;'));
    assert.ok(completed.includes('<MedicineReturnsSection'));
    // Continue to Billing stays the only primary action; the other two are outline.
    assert.equal((completed.match(/variant="outline"/g) ?? []).length, 2);
});

test('the returns section says no refund happens in KPOne, using warning tokens', () => {
    assert.ok(flat(section).includes('No refund in KPOne.'));

    for (const token of [
        'border-warning/25',
        'bg-warning/10',
        'text-warning-strong',
    ]) {
        assert.ok(section.includes(token));
    }

    assert.equal(/amber-\d/.test(section), false);
});

test('recording posts the counts the server checks and a fresh retry key', () => {
    assert.ok(section.includes('/items/${line.publicId}/returns'));
    assert.ok(section.includes('expected_return_count: line.returnCount'));
    assert.ok(section.includes('expected_branch_id: props.returns.branchId'));
    assert.ok(section.includes('retry_key: newRetryKey()'));
    assert.ok(section.includes('inputmode="decimal"'));
});

test('the Record a return button hides while its form is open', () => {
    assert.ok(section.includes('openLine !== line.publicId'));
});

test('statuses use StatusBadge tones: waiting is warning, outcomes neutral', () => {
    assert.ok(section.includes('<StatusBadge :status="row.status" />'));
    assert.ok(presentation.includes("waiting_check: 'Waiting for check'"));
    assert.ok(presentation.includes("waiting_check: 'warning'"));

    for (const key of ['disposed', 'quarantined', 'refused']) {
        assert.equal(
            presentation.includes(`${key}: '`) &&
                new RegExp(`${key}: '(warning|danger|accent|success)'`).test(
                    presentation,
                ),
            false,
        );
    }
});

test('a controlled medicine shows a neutral Controlled badge and the always-disposed wording', () => {
    assert.ok(section.includes('variant="outline"'));
    assert.ok(flat(section).includes('>Controlled</Badge'));
    assert.ok(
        flat(section).includes(
            'a returned controlled medicine is always disposed',
        ),
    );
});

test('the recorder is told someone else must check the return', () => {
    assert.ok(
        flat(section).includes(
            'You recorded this return, so you cannot check it.',
        ),
    );
});

const panel = read(
    'resources/js/pages/Dispensary/Partials/ReturnCheckPanel.vue',
);

test('each check outcome has its own confirm; Dispose is destructive and names the amount', () => {
    assert.ok(panel.includes('<OperationalConfirmDialog'));
    assert.ok(panel.includes('confirm: `Dispose ${amount.value}`'));
    assert.ok(panel.includes("confirm: 'Move to returns location'"));
    assert.ok(panel.includes("confirm: 'Refuse return'"));
    assert.ok(panel.includes(':destructive="dialog.destructive"'));
    assert.ok(panel.includes('/returns/${props.row.publicId}/check'));
});

test('a controlled medicine is only disposed, or refused when recorded by mistake', () => {
    assert.ok(
        flat(panel).includes('Controlled medicines are always disposed.'),
    );
    assert.ok(panel.includes('data-action="refuse-instead"'));
    assert.equal(/value="quarantine"/.test(panel), false);
});

test('refusing uses the fixed list and the note hint keeps clinical and identity details out', () => {
    assert.ok(panel.includes('returns.refuseReasons'));
    assert.ok(
        flat(panel).includes('Do not write a diagnosis or identity details.'),
    );
    assert.ok(section.includes('<ReturnCheckPanel'));
    assert.ok(section.includes('v-if="row.canCheck"'));
});

test('each confirm dialog shows its own icon (Daniel: no empty icon box)', () => {
    assert.ok(
        panel.includes(
            "import { Ban, PackageOpen, Trash2 } from '@lucide/vue';",
        ),
    );
    assert.ok(panel.includes('<template #icon>'));

    for (const icon of ['icon: Trash2', 'icon: PackageOpen', 'icon: Ban']) {
        assert.ok(panel.includes(icon));
    }
});
