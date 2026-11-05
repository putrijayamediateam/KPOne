import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const template = (path) =>
    baseParse(
        parse(readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8'))
            .descriptor.template.content,
    );
const elements = (node) =>
    node.children?.flatMap((child) =>
        child.type === 1 ? [child, ...elements(child)] : [],
    ) ?? [];
const attr = (node, name) =>
    node.props.find((prop) => prop.type === 6 && prop.name === name)?.value
        ?.content;
const byModel = (nodes, model) =>
    nodes.find((node) =>
        node.props.some(
            (prop) =>
                prop.type === 7 &&
                prop.name === 'model' &&
                prop.exp?.content === model,
        ),
    );

// UX-6 (UX-3 leftovers): these fields were named only by their placeholder, which screen
// readers read weakly and which disappears once the field has text.
test('UX-6: the clinical note box is named by its visible "Clinical Note" heading', () => {
    const nodes = elements(template('resources/js/pages/Clinical/Show.vue'));
    const note = byModel(nodes, 'form.clinical_note');
    const heading = nodes.find(
        (node) => attr(node, 'id') === attr(note, 'aria-labelledby'),
    );

    assert.equal(note.tag, 'textarea');
    assert.equal(attr(note, 'aria-labelledby'), 'clinical-note-heading');
    assert.equal(heading.tag, 'h2');
    assert.equal(
        heading.children
            .map((child) => (child.type === 2 ? child.content : ''))
            .join('')
            .trim(),
        'Clinical Note',
    );
});

for (const [path, model, name] of [
    [
        'resources/js/pages/Registration/Show.vue',
        'cancelForm.cancellation_reason',
        'Cancellation reason',
    ],
    [
        'resources/js/pages/Patient/Index.vue',
        'form.issuing_country_code',
        'Passport issuing country code',
    ],
    [
        'resources/js/components/catalogue/LocationPicker.vue',
        'code',
        'New location code',
    ],
    [
        'resources/js/components/catalogue/LocationPicker.vue',
        'name',
        'New location name',
    ],
    [
        'resources/js/components/catalogue/SupplierPicker.vue',
        'code',
        'New supplier code',
    ],
    [
        'resources/js/components/catalogue/SupplierPicker.vue',
        'name',
        'New supplier name',
    ],
    [
        'resources/js/components/catalogue/PanelTariffEditor.vue',
        'panelCode',
        'New Panel code',
    ],
    [
        'resources/js/components/catalogue/PanelTariffEditor.vue',
        'panelName',
        'New Panel name',
    ],
    ...[
        ['address_line_1', 'Address line 1'],
        ['address_line_2', 'Address line 2'],
        ['postcode', 'Postcode'],
        ['city', 'City'],
        ['state', 'State'],
        ['country', 'Country'],
    ].map(([field, label]) => [
        'resources/js/pages/PanelSettings/Form.vue',
        `form.${field}`,
        label,
    ]),
]) {
    test(`UX-6: ${path.split('/').pop()} ${model} is named "${name}"`, () => {
        const field = byModel(elements(template(path)), model);

        assert.ok(field, 'the field still exists');
        assert.equal(attr(field, 'aria-label'), name);
    });
}
