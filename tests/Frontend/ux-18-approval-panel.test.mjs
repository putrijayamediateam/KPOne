import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';

const PATH = 'resources/js/components/approval/ApprovalPanel.vue';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const source = read(PATH);
const squash = (s) => s.replace(/\s+/g, ' ');

const buttons = () => {
    const out = [];
    const walk = (node) => {
        if (node.tag === 'Button') {
            out.push(node);
        }

        for (const child of node.children ?? []) {
            walk(child);
        }
    };

    walk(parse(source).descriptor.template.ast);

    return out;
};
const directiveExp = (node, arg) =>
    node.props.find((p) => p.type === 7 && p.arg?.content === arg)?.exp
        ?.content;
const attr = (node, name) =>
    node.props.find((p) => p.type === 6 && p.name === name)?.value?.content;

test('UX-18: Approve is outline and Reject is destructive-outline; no filled button anywhere', () => {
    for (const button of buttons()) {
        const variant = attr(button, 'variant');

        assert.ok(
            ['outline', 'ghost'].includes(variant),
            `every ApprovalPanel button is outline or ghost, got ${variant}`,
        );
    }

    const reject = buttons().find(
        (b) => attr(b, 'data-test') === 'approval-panel-reject',
    );

    assert.equal(
        directiveExp(reject, 'class'),
        "[REJECT_OUTLINE, 'max-sm:h-11 max-sm:w-full']",
    );
    // Daniel: the dialog's Reject is the same destructive-outline as the card's, never filled.
    const confirm = buttons().find(
        (b) => attr(b, 'data-test') === 'approval-panel-reject-confirm',
    );

    assert.equal(attr(confirm, 'variant'), 'outline');
    assert.equal(
        directiveExp(confirm, 'class'),
        "[REJECT_OUTLINE, 'max-sm:h-11']",
    );
    assert.match(
        source,
        /const REJECT_OUTLINE =\s+'border-destructive\/50 text-destructive/,
    );
    assert.match(source, /data-test="approval-panel-approve"/);
});

test('UX-18: Reject takes its required reason in a dialog, as a code list or free text', () => {
    assert.match(
        source,
        /<Dialog :open="rejectOpen" @update:open="setRejectOpen">/,
    );
    assert.match(source, /@click="openReject"/);
    assert.match(source, /v-if="reject\.reasons\?\.length"/);
    assert.match(source, /<Textarea\s+v-else/);
    assert.match(source, /'Give a reason to reject\.'/);
    assert.match(
        squash(source),
        /\[props\.reject\.reasonField\]: typeof reason\.value === 'string' \? reason\.value\.trim\(\) : reason\.value/,
    );
});

test('UX-18: it calls only the caller-given endpoint with the caller payload, and shows the server answer', () => {
    assert.match(
        squash(source),
        /router\.visit\(action\.url, \{ method: action\.method \?\? 'post'/,
    );
    assert.match(
        squash(source),
        /data: \{ \.\.\.\(action\.data \?\? \{\}\), \.\.\.extra \}/,
    );
    assert.match(source, /onError: \(bag\) => \{\s+errors\.value = bag;/);
    assert.doesNotMatch(source, /fetch\(|axios|route\(|href="\//);
    // No business rule in the component: who may decide comes from the server.
    assert.match(source, /v-if="canDecide"/);
    assert.match(source, /blockedReason \?\?/);
    assert.doesNotMatch(source, /auth\.user|permissions|hasRole|can\(/);
});

test('UX-18: the avatar stack has a text equivalent and the empty dashed circle', () => {
    assert.match(source, /role="group"\s+:aria-label="recorderText"/);
    assert.match(source, /aria-label="Second person: not yet"/);
    assert.match(source, /bg-\[color-mix\(in_oklab,var\(--foreground\)_12%,var\(--card\)\)\]/);
    assert.match(source, /<DialogTitle class="pr-8 text-left"\s*>/);
    assert.match(source, /border-dashed/);
    assert.match(source, /Waiting for a second person/);
    assert.match(source, /Needs a second person/);
    assert.match(source, /relative z-10/);
});

test('UX-18 review: ids are unique per panel, so two panels can share a page', () => {
    assert.match(source, /const uid = useId\(\);/);
    assert.doesNotMatch(source, /\sid="approval-panel/);
    assert.match(source, /:id="reasonId"/);
    assert.match(source, /:aria-labelledby="titleId"/);
});

test('UX-18: 44 px controls below 640 px', () => {
    assert.match(source, /max-sm:h-11 max-sm:w-full/);
    assert.match(source, /trigger-class="max-sm:h-11"/);
});

test('UX-18: no page adopts the panel in this slice', () => {
    const pages = readdirSync(
        new URL('../../resources/js/pages', import.meta.url),
        { recursive: true },
    ).filter((file) => file.endsWith('.vue'));

    for (const file of pages) {
        assert.doesNotMatch(
            read(`resources/js/pages/${file}`),
            /ApprovalPanel/,
            `${file} should not use ApprovalPanel yet`,
        );
    }
});

test('UX-18 review: both reason fields are linked to their error, and other server errors show in the dialog', () => {
    assert.match(source, /<Textarea[\s\S]*?:aria-describedby="reasonErrorId"/);
    assert.match(
        source,
        /<OperationalSelect[\s\S]*?:describedby="reasonErrorId"/,
    );
    assert.doesNotMatch(source, /aria-:/);
    assert.match(
        source,
        /<InputError :id="reasonErrorId" :message="reasonError" \/>/,
    );
    assert.match(source, /data-test="approval-panel-dialog-error"/);
    assert.match(source, /if \(!open && busy\.value === 'reject'\)/);
});

test('UX-18 DD-55: the reject dialog respects reduced motion', () => {
    assert.match(
        source,
        /motion-reduce:transition-none motion-reduce:data-\[state=closed\]:animate-none motion-reduce:data-\[state=open\]:animate-none/,
    );
});
