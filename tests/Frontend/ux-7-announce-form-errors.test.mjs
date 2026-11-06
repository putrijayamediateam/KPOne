import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const elements = (node) =>
    node.children?.flatMap((child) =>
        child.type === 1 ? [child, ...elements(child)] : [],
    ) ?? [];
const attr = (node, name) =>
    node.props.find((prop) => prop.type === 6 && prop.name === name)?.value
        ?.content;
const directive = (node, name) =>
    node.props.find((prop) => prop.type === 7 && prop.name === name)?.exp
        ?.content;

const bound = (node, name) =>
    node.props.find((prop) => prop.type === 7 && prop.arg?.content === name)
        ?.exp?.content;
// A live region, static or bound: role alert, status or log, or any aria-live.
const isLive = (node) =>
    /\b(alert|status|log)\b/.test(
        `${attr(node, 'role') ?? ''} ${bound(node, 'role') ?? ''}`,
    ) ||
    attr(node, 'aria-live') !== undefined ||
    bound(node, 'aria-live') !== undefined;

test('UX-7: InputError inserts its message as role="alert" so screen readers announce it', () => {
    const root = baseParse(
        parse(read('resources/js/components/InputError.vue')).descriptor
            .template.content,
    );
    const [wrapper] = root.children.filter((child) => child.type === 1);
    const message = elements(wrapper).find((node) => node.tag === 'p');

    // The outer element keeps v-show, so the layout and any aria-describedby id are unchanged.
    assert.equal(wrapper.tag, 'div');
    assert.equal(directive(wrapper, 'show'), 'message');
    assert.equal(attr(wrapper, 'role'), undefined);
    // The alert is inserted only when there is a message: an alert already in the page
    // while hidden is often not read when its text appears.
    assert.equal(directive(message, 'if'), 'message');
    assert.equal(attr(message, 'role'), 'alert');
});

test('UX-7: no caller adds a second alert around InputError, so nothing is read twice', () => {
    const files = readdirSync(new URL('../../resources/js', import.meta.url), {
        recursive: true,
    }).filter((file) => file.endsWith('.vue'));
    const offenders = [];

    for (const file of files) {
        const source = read(`resources/js/${file}`);

        if (!source.includes('InputError')) {
            continue;
        }

        const ast = parse(source).descriptor.template?.ast;

        for (const node of ast ? elements(ast) : []) {
            const live = isLive(node);

            if (
                live &&
                (node.tag === 'InputError' ||
                    elements(node).some((child) => child.tag === 'InputError'))
            ) {
                offenders.push(`${file}:${node.loc.start.line}`);
            }
        }
    }

    assert.deepEqual(offenders, []);
});

test('UX-7: the Panel claim error summary announces only errors no field announces', () => {
    const source = read('resources/js/pages/PanelClaims/Create.vue');
    const fields = [
        ...source.matchAll(/<InputError[^>]*:message="([^"]+)"/g),
    ].flatMap(([, message]) =>
        [...message.matchAll(/form\.errors\.(\w+)/g)].map(([, key]) => key),
    );

    assert.deepEqual([...new Set(fields)].sort(), [
        'photos',
        'remarks',
        'submitted_date',
    ]);
    assert.match(
        source,
        /const fieldAnnouncedErrors = new Set\(\['submitted_date', 'remarks', 'photos'\]\);/,
    );
    assert.match(
        source,
        /:role="\s*fieldAnnouncedErrors\.has\(field\) \? undefined : 'alert'\s*"/,
    );
    // The summary box itself is no longer an alert.
    assert.doesNotMatch(source, /v-if="errors\.length"\s+role="alert"/);
});
