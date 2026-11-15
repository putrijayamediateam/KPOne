import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-1 (Catalogue & settings team): labels, phone layout and row actions on
// the catalogue screens. Design debt DD-04 (Pricing) and DD-13 (Medicine).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = (source) =>
    source.slice(
        source.indexOf('<template>'),
        source.lastIndexOf('</template>'),
    );

const pricing = template(read('resources/js/pages/Pricing/Index.vue'));
const medicine = template(read('resources/js/pages/Medicine/Index.vue'));
const service = template(read('resources/js/pages/ClinicalService/Index.vue'));

// Every opening tag of the given component, with its attributes.
const tags = (source, name) =>
    [...source.matchAll(new RegExp(`<${name}(?=[\\s>])[^>]*>`, 'g'))].map(
        (match) => match[0],
    );
const idOf = (tag) => tag.match(/\s(:?id)="([^"]+)"/);

// A field counts as labelled when it has an id and a <Label> or <label>
// points at that same id with for / :for (the same expression).
const assertEveryFieldLabelled = (source, name, component) => {
    const fields = tags(source, component);
    assert.ok(fields.length > 0, `${name}: expected ${component} fields`);

    for (const field of fields) {
        const id = idOf(field);
        assert.ok(id, `${name}: ${component} without an id: ${field}`);
        const attribute = id[1] === ':id' ? ':for' : 'for';
        assert.ok(
            source.includes(`${attribute}="${id[2]}"`),
            `${name}: no visible label points at ${id[1]}="${id[2]}"`,
        );
    }
};

test('DD-04: every Pricing field has a visible label connected to it', () => {
    assertEveryFieldLabelled(pricing, 'Pricing', 'Input');
    assertEveryFieldLabelled(pricing, 'Pricing', 'OperationalSelect');

    for (const label of [
        '>Scope</Label',
        '>Type</Label',
        '>Medicine *</Label',
        '>Clinical service *</Label',
        '>Price book *</Label',
        '>Amount (RM) *</Label',
    ]) {
        assert.ok(pricing.includes(label), `missing label ${label}`);
    }

    // The per-row fields name the charge for screen readers.
    assert.ok(
        pricing.includes(':label="`Price book for ${charge.displayName}`"'),
    );
    assert.ok(
        pricing.includes(
            ':aria-label="`Amount (RM) for ${charge.displayName}`"',
        ),
    );
    // Required is announced without the browser blocking the submit, so the
    // server's own validation message still shows under the field.
    assert.doesNotMatch(pricing, /^\s+required$/m);
    assert.ok((pricing.match(/aria-required="true"/g) ?? []).length >= 5);
    // No field is left with only a placeholder.
    assert.doesNotMatch(pricing, /<label class="grid gap-1 text-xs">/);
});

test('DD-04: Pricing shows charge cards on a phone and the table from 768 px', () => {
    assert.match(pricing, /<section class="grid gap-3 md:hidden"/);
    assert.match(pricing, /data-testid="charge-card"/);
    assert.match(
        pricing,
        /<div class="hidden min-w-0 md:block">\s*<OperationalTable/,
    );
    assert.match(pricing, /class="min-h-11 w-full"/);
    assert.match(pricing, /'Publishing…'/);
    assert.match(pricing, /<EmptyState/);
    // Money right-aligned as RM 0.00.
    assert.match(pricing, /<th class="text-right">Current price<\/th>/);
    assert.ok(
        read('resources/js/pages/Pricing/Index.vue').includes(
            '`RM ${(charge.currentPrice.amountSen / 100).toFixed(2)}`',
        ),
    );
});

test('DD-13: Medicine rows show one Edit button and a named row menu', () => {
    const tableStart = medicine.indexOf('<OperationalTable');
    const table = medicine.slice(
        tableStart,
        medicine.indexOf('</OperationalTable>'),
    );
    const rowButtons = tags(table, 'Button');

    // Edit plus the "..." trigger; Deactivate, Drug groups and Mark controlled
    // moved into the menu.
    assert.equal(rowButtons.length, 2);
    assert.match(
        table,
        /:aria-label="`More actions for \$\{row\.displayName\}`"/,
    );
    assert.match(table, /<Ellipsis[\s\S]*?aria-hidden="true"/);

    const menu = table.slice(table.indexOf('<DropdownMenuContent'));
    assert.match(menu, /@select="requestToggle\(row\)"/);
    assert.match(
        menu,
        /data-action="edit-allergy-groups"[\s\S]*?@select="openGroups\(row\)"/,
    );
    assert.match(
        menu,
        /data-action="toggle-controlled"[\s\S]*?@select="\s*pendingControlled\.row = row\s*"/,
    );
});

test('DD-13: Medicine shows cards with prices, status and actions on a phone', () => {
    assert.match(
        medicine,
        /<section class="grid gap-3 lg:hidden" aria-label="Medicine Catalogue">/,
    );
    assert.match(medicine, /data-testid="medicine-card"/);
    assert.match(
        medicine,
        /<div class="hidden min-w-0 lg:block">\s*<OperationalTable/,
    );

    const cards = medicine.slice(
        medicine.indexOf('data-testid="medicine-card"'),
        medicine.indexOf('<div class="hidden min-w-0 lg:block">'),
    );
    assert.match(cards, /<dl v-if="setup\.canManagePrices"/);
    assert.match(cards, /Self-pay/);
    assert.match(cards, /Default Panel/);
    assert.match(cards, /<StatusBadge/);
    assert.match(cards, /v-if="row\.isControlled"/);
    assert.match(cards, /class="min-h-11 min-w-11"/);
    assert.match(cards, /@click="openEdit\(row\)"/);
    assert.match(cards, /@select="requestToggle\(row\)"/);
    assert.match(cards, /@select="openGroups\(row\)"/);
    // Up to three drug groups on a card; the rest are counted, and the full
    // list stays in the Drug groups dialog (Daniel's review).
    assert.match(cards, /row\.allergyGroups\.slice\(0, 3\)\.join\(', '\)/);
    assert.match(cards, /\+\{\{ row\.allergyGroups\.length - 3 \}\} more/);
});

test('catalogue filters have connected labels and the lists follow the list width', () => {
    for (const [name, source] of [
        ['Medicine', medicine],
        ['Clinical Service', service],
    ]) {
        assertEveryFieldLabelled(
            source.slice(0, source.indexOf('</form>')),
            name,
            'Input',
        );
        assertEveryFieldLabelled(
            source.slice(0, source.indexOf('</form>')),
            name,
            'OperationalSelect',
        );
        assert.match(source, /max-w-7xl min-w-0/);
        assert.match(source, /<Plus class="size-4" aria-hidden="true" \/>/);
    }
});

test('Clinical Service shows cards on a phone with Edit and Activate named per service', () => {
    assert.match(service, /data-testid="service-card"/);
    assert.match(
        service,
        /<div class="hidden min-w-0 lg:block">\s*<OperationalTable/,
    );
    assert.equal(
        (service.match(/:aria-label="`Edit \$\{row\.displayName\}`"/g) ?? [])
            .length,
        2,
    );
    assert.match(service, /<EmptyState/);
});

test('Consultation tariff prices have visible labels on a phone', () => {
    const panel = template(
        read('resources/js/components/catalogue/ConsultationTariffPanel.vue'),
    );

    for (const id of [
        'consultation-self-pay-price',
        'consultation-panel-default-price',
    ]) {
        assert.ok(panel.includes(`for="${id}"`), `no label for ${id}`);
        assert.ok(panel.includes(`id="${id}"`), `no input with id ${id}`);
    }

    assert.match(panel, />Self-pay price \(RM\)<\/Label/);
    assert.match(panel, />Default Panel price \(RM\)<\/Label/);
    // The accessible name contains the visible label text.
    assert.ok(
        panel.includes('aria-label="Self-pay price (RM) for consultation"'),
    );
    assert.ok(
        panel.includes(
            'aria-label="Default Panel price (RM) for consultation"',
        ),
    );
});
