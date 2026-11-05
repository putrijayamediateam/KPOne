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
const bound = (node, name) =>
    node.props.find((prop) => prop.type === 7 && prop.arg?.content === name)
        ?.exp?.content;
const text = (node) =>
    node.children
        .map((child) => (child.type === 2 ? child.content : ''))
        .join('')
        .replace(/\s+/g, ' ')
        .trim();

// SMOKE-1 S1-009 to S1-014: every staff page has exactly one h1, and a board may hide it
// visually with sr-only (design guide sections 4 and 12).
for (const [path, heading] of [
    ['resources/js/pages/Registration/Index.vue', 'Registration'],
    ['resources/js/pages/Queue/Index.vue', 'Consultation queue'],
    ['resources/js/pages/QueueDisplay/Screen.vue', 'Queue display'],
]) {
    test(`UX-3: ${path.split('pages/')[1]} has one h1 "${heading}" for screen readers`, () => {
        const nodes = elements(template(path));
        const h1s = nodes.filter((node) => node.tag === 'h1');

        assert.equal(h1s.length, 1);
        assert.equal(text(h1s[0]), heading);
        assert.equal(attr(h1s[0], 'class'), 'sr-only');

        const main = nodes.find((node) => node.tag === 'main');

        assert.ok(main, 'the page keeps its main landmark');
        assert.equal(
            main.children.find((child) => child.type === 1),
            h1s[0],
            'the h1 is the first thing inside main',
        );
    });
}

// Icon-only search boxes: the label held only a magnifier icon, so the box had no name.
for (const [path, name, placeholder] of [
    [
        'resources/js/pages/Registration/Index.vue',
        'Search patients',
        'Patient name or exact Patient No.',
    ],
    [
        'resources/js/pages/Queue/Index.vue',
        'Search patients or queue numbers',
        'Patient or exact Queue number',
    ],
    [
        'resources/js/pages/Patient/Index.vue',
        'Search patients',
        'Enter at least 3 characters',
    ],
]) {
    test(`UX-3: the search box in ${path.split('pages/')[1]} is named "${name}"`, () => {
        const input = elements(template(path)).find(
            (node) =>
                node.tag === 'input' &&
                attr(node, 'placeholder') === placeholder,
        );
        const label = elements(template(path)).find(
            (node) =>
                node.tag === 'label' &&
                elements(node).some(
                    (child) =>
                        child.tag === 'input' &&
                        attr(child, 'placeholder') === placeholder,
                ),
        );

        assert.ok(input && label, 'the search input still sits in its label');
        const hidden = label.children.find(
            (child) => child.type === 1 && child.tag === 'span',
        );

        assert.equal(attr(hidden, 'class'), 'sr-only');
        assert.equal(text(hidden), name);
    });
}

test('UX-3: each Billing approval limit field is named by its column and the approver', () => {
    const inputs = elements(
        template('resources/js/pages/BillingApprovalLimits/Index.vue'),
    ).filter((node) => node.tag === 'Input');

    assert.deepEqual(
        inputs.map((node) => bound(node, 'aria-label')),
        [
            '`Panel approval limit (sen) for ${row.name}`',
            '`Pay later approval limit (sen) for ${row.name}`',
        ],
    );
});

test('UX-3: the Panel claim due days field is named', () => {
    const input = elements(
        template('resources/js/pages/PanelSettings/Form.vue'),
    ).find(
        (node) =>
            node.tag === 'Input' &&
            node.props.some(
                (prop) => prop.exp?.content === 'form.claim_due_days',
            ),
    );

    assert.equal(attr(input, 'aria-label'), 'Claim due days after the visit');
});

test('UX-3: the Quick create patient list takes its name from its visible caption', () => {
    const nodes = elements(
        template('resources/js/pages/Registration/Create.vue'),
    );
    const list = nodes.find((node) => attr(node, 'id') === 'patient-results');
    const caption = nodes.find(
        (node) => attr(node, 'id') === 'patient-results-heading',
    );

    assert.equal(attr(list, 'role'), 'listbox');
    assert.equal(attr(list, 'aria-labelledby'), 'patient-results-heading');
    assert.equal(attr(list, 'aria-label'), undefined);
    // The caption says "Recent patients" or "Search results", so the name stays true.
    assert.equal(caption.tag, 'p');
    assert.match(
        caption.children.map((child) => child.content?.content ?? '').join(''),
        /showingRecent \? 'Recent patients' : 'Search results'/,
    );
});

test('UX-3: the Patient identifier fields are named', () => {
    const nodes = elements(template('resources/js/pages/Patient/Show.vue'));
    const byModel = (model) =>
        nodes.find((node) =>
            node.props.some((prop) => prop.exp?.content === model),
        );

    assert.equal(
        attr(byModel('identifierForm.identifier_type'), 'aria-label'),
        'Identifier type',
    );
    assert.equal(
        attr(byModel('identifierForm.issuing_country_code'), 'aria-label'),
        'Issuing country',
    );
    assert.equal(
        attr(byModel('identifierForm.value'), 'aria-label'),
        'Identifier value',
    );
});

test('UX-3: each Pricing publish amount is named by the charge', () => {
    const input = elements(
        template('resources/js/pages/Pricing/Index.vue'),
    ).find(
        (node) =>
            node.tag === 'Input' &&
            node.props.some(
                (prop) => prop.exp?.content === 'publishForm(charge).amount',
            ),
    );

    assert.equal(
        bound(input, 'aria-label'),
        '`New price (RM) for ${charge.displayName}`',
    );
});
