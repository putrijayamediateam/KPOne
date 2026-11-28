import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const template = (file) =>
    baseParse(
        parse(
            readFileSync(
                new URL(`../../resources/js/pages/${file}`, import.meta.url),
                'utf8',
            ),
        ).descriptor.template.content,
    );
const elements = (root) =>
    root.children?.flatMap((node) =>
        node.type === 1 ? [node, ...elements(node)] : [],
    ) ?? [];
const attribute = (node, name) =>
    node.props.find((prop) => prop.type === 6 && prop.name === name)?.value
        ?.content;
const directive = (node, name, argument) =>
    node.props.find(
        (prop) =>
            prop.type === 7 &&
            prop.name === name &&
            (argument === undefined || prop.arg?.content === argument),
    )?.exp?.content;
const classes = (node, context = {}) =>
    `${attribute(node, 'class') ?? ''} ${runInNewContext(directive(node, 'bind', 'class') ?? "''", context) || ''}`
        .trim()
        .split(/\s+/);
const includes = (actual, expected) =>
    expected
        .split(' ')
        .forEach((token) =>
            assert.ok(
                actual.includes(token),
                `Missing appearance token: ${token}`,
            ),
        );

test('DARK-1 S1-001: selected patient uses Clean Look blue washes in both themes without changing unselected styling', () => {
    const option = elements(template('Registration/Create.vue')).find(
        (node) => node.tag === 'button' && attribute(node, 'role') === 'option',
    );
    includes(
        classes(option, { activeResultIndex: 0, index: 0 }),
        'bg-primary/8 dark:bg-primary/16',
    );
    includes(
        classes(option, { activeResultIndex: 0, index: 1 }),
        'hover:bg-muted/35',
    );
    assert.ok(
        !classes(option, { activeResultIndex: 0, index: 1 }).includes(
            'bg-primary/8',
        ),
    );
});

test('DARK-1 S1-006: both selected sign-in cards adapt to dark mode and retain their light appearance', () => {
    const labels = elements(template('Staff/Create.vue')).filter(
        (node) =>
            node.tag === 'label' &&
            directive(node, 'bind', 'class')?.includes('credential_strategy'),
    );
    assert.equal(labels.length, 2);

    for (const [index, strategy] of ['google_only', 'password'].entries()) {
        includes(
            classes(labels[index], { form: { credential_strategy: strategy } }),
            'border-primary bg-primary/10',
        );
        const unselected = classes(labels[index], {
            form: {
                credential_strategy:
                    strategy === 'password' ? 'google_only' : 'password',
            },
        });
        assert.ok(!unselected.includes('bg-primary/10'));
    }
});

test('DARK-1 S1-007: confirmed and unconfirmed OTC allergy states have dark surfaces and readable Recorded text', () => {
    const nodes = elements(template('Dispensary/Show.vue'));
    const panel = nodes.find(
        (node) => attribute(node, 'data-testid') === 'otc-allergy',
    );
    includes(
        classes(panel, { otcAllergyConfirmed: true }),
        'border-success/25 bg-success-wash',
    );
    includes(
        classes(panel, { otcAllergyConfirmed: false }),
        'border-warning/25 bg-warning-wash',
    );
    const recorded = elements(panel).find(
        (node) =>
            node.tag === 'span' &&
            directive(node, 'if') === 'otcAllergyConfirmed',
    );
    includes(classes(recorded), 'text-success');
});

test('DARK-1 S1-008: visit queue card and icon use shared semantic tokens in both themes', () => {
    const queue = elements(template('Registration/Show.vue')).find(
        (node) =>
            node.tag === 'section' && directive(node, 'if') === 'visit.queue',
    );
    includes(classes(queue), 'border-success/25 bg-success-wash');
    const icon = elements(queue).find((node) => node.tag === 'ListOrdered');
    includes(classes(icon), 'text-success');
});
