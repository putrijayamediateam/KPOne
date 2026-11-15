import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const source = readFileSync(
    new URL('../../resources/js/pages/Registration/Show.vue', import.meta.url),
    'utf8',
);
const root = baseParse(parse(source).descriptor.template.content);
const elements = (node) =>
    node.children?.flatMap((child) =>
        child.type === 1 ? [child, ...elements(child)] : [],
    ) ?? [];
const classes = (node) =>
    (
        node.props.find((prop) => prop.type === 6 && prop.name === 'class')
            ?.value?.content ?? ''
    ).split(/\s+/);
const routeOf = (node) =>
    elements(node)
        .filter((child) => child.tag === 'Link')
        .map(
            (link) =>
                link.props.find((prop) => prop.name === 'href')?.value
                    ?.content ??
                link.props.find((prop) => prop.arg?.content === 'href')?.exp
                    ?.content,
        );

test('REG-1: the visit result action row wraps on a phone instead of widening the page', () => {
    // Innermost <div> holding both Console and Register another Patient links.
    const actionRow = elements(root)
        .filter(
            (node) =>
                node.tag === 'div' &&
                routeOf(node).includes('/registration') &&
                routeOf(node).includes('/registration/create'),
        )
        .at(-1);
    assert.ok(
        actionRow,
        'the Console / Edit / Register another Patient / Send to Waiting row exists',
    );

    const tokens = classes(actionRow);

    for (const token of ['flex', 'flex-wrap', 'gap-2']) {
        assert.ok(tokens.includes(token), `action row keeps or gains ${token}`);
    }

    assert.ok(
        !tokens.includes('flex-nowrap'),
        'action row must not be forced onto one line',
    );

    const sendToWaiting = elements(actionRow).find(
        (node) =>
            node.tag === 'Button' &&
            node.props.some(
                (prop) =>
                    prop.type === 7 &&
                    prop.name === 'on' &&
                    prop.exp?.content === 'sendToWaiting',
            ),
    );
    assert.ok(
        sendToWaiting,
        'Send to Waiting stays in the same action row with the same handler',
    );
});

test('REG-1: the visit number stays on one line and its badges wrap below it', () => {
    const title = elements(root).find(
        (node) => node.tag === 'h1' && classes(node).includes('font-mono'),
    );
    assert.ok(title, 'visit number heading exists');
    assert.ok(classes(title).includes('whitespace-nowrap'));
    const titleRow = elements(root)
        .filter((node) => node.tag === 'div' && node.children.includes(title))
        .at(-1);
    assert.ok(
        classes(titleRow).includes('flex-wrap'),
        'status badges can wrap',
    );
});

test('REG-1: long unbroken text (Panel, reference, reasons, visit values) breaks instead of widening the page', () => {
    const textOf = (node) =>
        node.children
            .map((child) => (child.type === 5 ? child.content.content : ''))
            .join(' ');

    for (const expression of [
        'visit.coverage.panelName',
        'visit.coverage.memberReference',
        'visit.visitReasons.legacy',
        'visit.cancellationReason',
        'visit.visitType',
        'visit.doctor',
    ]) {
        const node = elements(root).find(
            (candidate) =>
                ['p', 'dd'].includes(candidate.tag) &&
                textOf(candidate).includes(expression),
        );
        assert.ok(node, `element showing ${expression} exists`);
        assert.ok(
            classes(node).includes('break-words'),
            `${expression} breaks long words`,
        );
    }
});
