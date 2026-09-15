import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { NodeTypes, baseParse } from '@vue/compiler-dom';
import { parse as parseSfc } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const templateAst = (path) => {
    const source = read(path);
    const { descriptor, errors } = parseSfc(source, { filename: path });

    assert.deepEqual(errors, [], `${path} must remain a valid Vue SFC.`);
    assert.ok(descriptor.template, `${path} must retain its template.`);

    return { source, ast: baseParse(descriptor.template.content) };
};

const textNodes = (node, found = []) => {
    if (node.type === NodeTypes.TEXT) {
        found.push(node.content);
    }

    for (const child of node.children ?? []) {
        textNodes(child, found);
    }

    for (const branch of node.branches ?? []) {
        textNodes(branch, found);
    }

    return found;
};

const headerLabels = (source) =>
    [...source.matchAll(/<th\b[^>]*scope="col"[^>]*>([\s\S]*?)<\/th>/g)].map(
        ([, content]) =>
            content
                .replace(/<[^>]+>/g, '')
                .replace(/\s+/g, ' ')
                .trim(),
    );

test('Inventory directory keeps readable, separate semantic Quantity and Status columns', () => {
    const inventory = read('resources/js/pages/Inventory/Index.vue');
    const labels = headerLabels(inventory);

    assert.ok(labels.includes('Quantity'));
    assert.ok(labels.includes('Status'));
    assert.equal(labels.includes('QuantityStatus'), false);
    assert.match(inventory, /\[&_thead_th\]:px-4/);
    assert.match(inventory, /\[&_thead_th\]:text-sm/);
    assert.match(inventory, /\[&_thead_th\]:font-semibold/);
    assert.match(inventory, /min-w-28 text-right/);
    assert.match(
        inventory,
        /<th[^>]*class="[^"]*min-w-32[^"]*"[^>]*>\s*Status\s*<\/th>/,
    );
    assert.match(inventory, /break-words/);
    assert.match(inventory, /whitespace-normal/);
    assert.match(inventory, /row\.quantity/);
    assert.match(inventory, /row\.availabilityStatus/);
});

test('Visit Type remains a compact accessible two-option control with unchanged values', () => {
    const create = read('resources/js/pages/Registration/Create.vue');
    const optionLabels = [
        ...create.matchAll(/@click="form\.visit_type = '([^']+)'"/g),
    ].map(([, value]) => value);

    assert.deepEqual(optionLabels, ['consultation', 'otc']);
    assert.match(create, /form\.visit_type === 'consultation'/);
    assert.match(create, /form\.visit_type === 'otc'/);
    assert.match(create, /sm:w-72/);
    assert.match(create, /focus-visible:ring-2/);
    assert.doesNotMatch(create, /<select\b/i);
});

test('Visit forms contain no standalone arrow text and retain labelled Panel references', () => {
    for (const path of [
        'resources/js/pages/Registration/Create.vue',
        'resources/js/pages/Registration/Edit.vue',
    ]) {
        const { source, ast } = templateAst(path);
        const standaloneArrows = textNodes(ast).filter(
            (content) => content.trim() === '>',
        );

        assert.deepEqual(
            standaloneArrows,
            [],
            `${path} must not render a stray >.`,
        );
        assert.match(source, /id="panel-id"/);
        assert.match(source, /for="panel-id"/);
        assert.match(source, /id="coverage-member-reference"/);
        assert.match(source, /for="coverage-member-reference"/);
        assert.match(source, /trigger-class="h-10"/);
        assert.match(source, /v-model="form\.coverage_member_reference"/);
    }
});

test('Presentation changes retain the registration submission payload contract', () => {
    const create = read('resources/js/pages/Registration/Create.vue');

    for (const field of [
        'visit_type',
        'assigned_doctor_user_id',
        'coverage_type',
        'panel_id',
        'coverage_member_reference',
    ]) {
        assert.match(create, new RegExp(`\\b${field}:`));
    }

    assert.match(create, /form\.post\('\/registration'/);
});
