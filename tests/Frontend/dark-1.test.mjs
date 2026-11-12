import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const template = (file) => baseParse(parse(readFileSync(new URL(`../../resources/js/pages/${file}`, import.meta.url), 'utf8')).descriptor.template.content);
const elements = (root) => root.children?.flatMap((node) => node.type === 1 ? [node, ...elements(node)] : []) ?? [];
const attribute = (node, name) => node.props.find((prop) => prop.type === 6 && prop.name === name)?.value?.content;
const directive = (node, name, argument) => node.props.find((prop) => prop.type === 7 && prop.name === name && (argument === undefined || prop.arg?.content === argument))?.exp?.content;
const classes = (node, context = {}) => `${attribute(node, 'class') ?? ''} ${runInNewContext(directive(node, 'bind', 'class') ?? "''", context) || ''}`.trim().split(/\s+/);
const includes = (actual, expected) => expected.split(' ').forEach((token) => assert.ok(actual.includes(token), `Missing appearance token: ${token}`));
const green = 'dark:border-emerald-900 dark:bg-emerald-950/30';

test('DARK-1 S1-001: selected patient has a dark surface without changing light or unselected styling', () => {
    const option = elements(template('Registration/Create.vue')).find((node) => node.tag === 'button' && attribute(node, 'role') === 'option');
    includes(classes(option, { activeResultIndex: 0, index: 0 }), 'bg-emerald-50/80 dark:bg-emerald-950/30');
    includes(classes(option, { activeResultIndex: 0, index: 1 }), 'hover:bg-muted/35');
    assert.ok(!classes(option, { activeResultIndex: 0, index: 1 }).includes('bg-emerald-50/80'));
});

test('DARK-1 S1-006: both selected sign-in cards adapt to dark mode and retain their light appearance', () => {
    const labels = elements(template('Staff/Create.vue')).filter((node) => node.tag === 'label' && directive(node, 'bind', 'class')?.includes('credential_strategy'));
    assert.equal(labels.length, 2);

    for (const [index, strategy] of ['google_only', 'password'].entries()) {
        includes(classes(labels[index], { form: { credential_strategy: strategy } }), `border-emerald-400 bg-emerald-50/60 ${green}`);
        const unselected = classes(labels[index], { form: { credential_strategy: strategy === 'password' ? 'google_only' : 'password' } });
        assert.ok(!unselected.includes('bg-emerald-50/60'));
    }
});

test('DARK-1 S1-007: confirmed and unconfirmed OTC allergy states have dark surfaces and readable Recorded text', () => {
    const nodes = elements(template('Dispensary/Show.vue'));
    const panel = nodes.find((node) => attribute(node, 'data-testid') === 'otc-allergy');
    includes(classes(panel, { otcAllergyConfirmed: true }), `border-emerald-200 bg-emerald-50/50 ${green}`);
    includes(classes(panel, { otcAllergyConfirmed: false }), 'border-amber-300 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/30');
    const recorded = elements(panel).find((node) => node.tag === 'span' && directive(node, 'if') === 'otcAllergyConfirmed');
    includes(classes(recorded), 'text-emerald-800 dark:text-emerald-300');
});

test('DARK-1 S1-008: visit queue card and icon adapt to dark mode without changing the light styles', () => {
    const queue = elements(template('Registration/Show.vue')).find((node) => node.tag === 'section' && directive(node, 'if') === 'visit.queue');
    includes(classes(queue), 'border-success/25 bg-success/10');
    const icon = elements(queue).find((node) => node.tag === 'ListOrdered');
    includes(classes(icon), 'text-success');
});
