import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/SupplierReturns.vue');
const page = read('resources/js/pages/Inventory/V2/SupplierReturns.vue');
const registry = read('config/v2-pages.php');
const script = page.slice(0, page.indexOf('<template>'));
const template = page.slice(page.indexOf('<template>'));

/** The source of `const name = …` up to the next top-level statement, without whitespace. */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|function) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (next < 0 ? rest : rest.slice(0, next + indent.length))
        .replace(/\s+/g, '')
        .replace(/,(?=[)\]}])/g, '');
};

test('page 5: the Classic script is copied verbatim (types, search, forms, group lots and submit)', () => {
    for (const name of [
        'Lot',
        'HistoryRow',
        'ReturnRow',
        'Page',
        'GroupLine',
        'GroupRow',
        'Delivery',
        'DeliveryLot',
        'props',
        'q',
        'open',
        'label',
        'endpoint',
        'operation',
        'history',
        'isGroup',
        'activeGroup',
        'groupTitle',
        'groupTotals',
        'form',
        'errors',
        'scanned',
        'search',
        'selectedDelivery',
        'GroupDraft',
        'blankDrafts',
        'drafts',
        'chosenCount',
        'groupForm',
        'groupErrors',
        'chooseDelivery',
        'lineError',
        'unbroken',
        'lotError',
        'chosenIndex',
        'submitGroup',
        'showGroupAction',
        'showRequest',
        'showAction',
        'submit',
    ]) {
        assert.equal(block(script, name), block(classic, name), name);
    }

    // The same routes; no new server call.
    for (const path of [
        "'/inventory/supplier-returns'",
        "'/inventory/supplier-return-groups'",
    ]) {
        assert.ok(classic.includes(path) && page.includes(path), path);
    }
});

test('page 5: registered, uses the V2 blocks, and Classic does not import V2', () => {
    assert.match(registry, /'Inventory\/SupplierReturns',/);

    for (const name of [
        'V2WorkLayout',
        'V2Table',
        'V2Dialog',
        'V2Chip',
        'V2Pager',
        'V2Picker',
        'V2RowMenu',
        'V2OptionGroup',
        'V2KeyValueList',
        'V2DisclosureBar',
    ]) {
        assert.ok(page.includes(`components/v2/${name}.vue`), name);
    }

    assert.ok(!classic.includes('components/v2'));
});

test("page 5: Daniel's checklist (sentences, blocked reasons, destructive sheets, own request, history)", () => {
    // What each press does sits in the pinned footer note.
    assert.match(
        template,
        /<template #footer-note>[\s\S]*?\{\{ footerNote \}\}/,
    );
    assert.match(
        script,
        /Approving lets it be dispatched; stock leaves only then\./,
    );
    assert.match(script, /KPOne moves no money and restocks nothing\./);
    assert.match(script, /It can't be undone\./);
    // Over-returnable switches the action off with a reason.
    assert.match(script, /Only \$\{/);
    assert.match(template, /:confirm-disabled="blockedBy !== null"/);
    assert.match(
        template,
        /:confirm-disabled-reason="blockedBy \?\? undefined"/,
    );
    // Reject and Cancel are destructive.
    assert.match(
        template,
        /operation === 'rejected' \|\| operation === 'cancelled'\s*\? 'danger'/,
    );
    // Own request line, on both tables.
    assert.equal(
        (
            template
                .replace(/\s+/g, ' ')
                .match(
                    /You requested this, so someone else must approve it\./g,
                ) ?? []
        ).length,
        2,
    );
    // Dirty guard on the request sheet.
    assert.match(template, /:dirty="hasInput && form\.isDirty"/);
    // History: outline Close, actor names, local time.
    assert.equal(
        (template.match(/single-action-style="outline"/g) ?? []).length,
        2,
    );
    assert.match(template, /step\.actor_name \?\? 'Staff member'/);
    assert.match(template, /formatDateTime\(step\.createdAt\)/);
    // Per-lot errors beside each lot.
    assert.match(template, /:id="`lot-error-\$\{lot\.id\}`"/);
    // Groups table only when there are groups; two pagers for the two tables plus one for lots.
    assert.match(template, /v-if="returns\.groups\.total > 0"/);
    assert.equal((template.match(/<V2Pager/g) ?? []).length, 3);
    // Cards below 1280 px; multi-line cells stack.
    assert.equal((template.match(/cards="xl"/g) ?? []).length, 3);
    assert.match(page, /td\[data-stack\]/);
    // Request sections behind bars, open on wide screens only.
    assert.match(template, /:default-open="wide"/);
});

test("page 5, Daniel's round 2: no native select or checkbox, codes never break, plain server quantities", () => {
    assert.ok(!template.includes('<select'));
    assert.ok(!template.includes('type="checkbox"'));
    assert.match(template, /<Checkbox\s/);
    assert.match(template, /<OperationalSelect\s/);
    assert.match(template, /min-h-11 min-w-11/);
    // The quantity box shows "9", like the line above it.
    assert.match(template, /:value="draftQuantity\(lot\.id\)"/);

    // SKU, return, group, delivery and batch codes use non-breaking hyphens.
    for (const code of [
        'row.return_number',
        'group.groupNumber',
        'row.sku_code',
        'lot.sku_code',
        'line.sku_code',
    ]) {
        assert.match(
            template,
            new RegExp(`unbroken\\(\\s*${code.replace('.', '\\.')}\\s*\\)`),
            code,
        );
    }

    assert.match(script, /value: unbroken\(row\.return_number\)/);
    // Status stays on one line from 1280.
    assert.equal((template.match(/class="w-56">Status/g) ?? []).length, 2);
    // "6.000 unit" from the server shows as "6 units".
    assert.match(script, /const plainError = /);
    assert.match(template, /plainError\(lotError\(lot\.id\)\)/);
    assert.match(template, /class="v2-section-title text-lg font-semibold"/);
});
