import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const sfc = (path) => parse(read(path)).descriptor;
const elements = (node) =>
    node.children?.flatMap((child) =>
        child.type === 1 ? [child, ...elements(child)] : [],
    ) ?? [];
const directive = (node, name, arg) =>
    node.props.find(
        (prop) =>
            prop.type === 7 &&
            prop.name === name &&
            (arg === undefined || prop.arg?.content === arg),
    );
const attr = (node, name) =>
    node.props.find((prop) => prop.type === 6 && prop.name === name)?.value
        ?.content;
const squash = (value) => value?.replace(/\s+/g, ' ').trim();
const mcHref = (node) =>
    directive(node, 'bind', 'href')?.exp?.content?.includes(
        'medical-certificates',
    );

test('UX-2 S1-005: the visit page links to Medical certificates only when the server says a consultation exists', () => {
    const page = sfc('resources/js/pages/Registration/Show.vue');
    const script = page.scriptSetup.content;
    const nodes = elements(baseParse(page.template.content));
    const links = nodes.filter((node) => mcHref(node));

    assert.equal(links.length, 1, 'one Medical certificates link');
    assert.equal(
        directive(links[0], 'if')?.exp?.content,
        'visit.hasConsultation',
    );

    const wrapper = nodes.find(
        (node) =>
            node.tag === 'template' &&
            directive(node, 'if')?.exp?.content ===
                'canViewMedicalCertificates',
    );

    assert.ok(wrapper, 'the existing permission and visit-type gate stays');
    assert.ok(elements(wrapper).includes(links[0]));

    // The permission and consultation-only rule are unchanged; only the timing is new.
    const gate = squash(
        script.match(
            /const canViewMedicalCertificates = computed\([\s\S]*?\n\);/,
        )?.[0],
    );

    assert.match(gate, /'medical_certificates\.view\.branch'/);
    assert.match(gate, /props\.visit\.visitType === 'consultation'/);
    // No client-side guess from the queue any more.
    assert.doesNotMatch(script, /calledAt/);
    assert.match(
        read('resources/js/types/visit.ts'),
        /lockVersion: number;\s*hasConsultation: boolean;/,
    );
});

test('UX-2 S1-005: without the link the visit page says why, in plain muted text (cancelled, or no consultation yet)', () => {
    const page = sfc('resources/js/pages/Registration/Show.vue');
    const notes = elements(baseParse(page.template.content)).filter(
        (node) => attr(node, 'data-testid') === 'mc-unavailable',
    );
    const text = (node) =>
        squash(node.children.map((child) => child.content ?? '').join(' '));

    assert.equal(notes.length, 2);
    const [cancelled, notYet] = notes;

    assert.equal(
        directive(cancelled, 'else-if')?.exp?.content,
        "visit.status === 'cancelled'",
    );
    assert.equal(
        text(cancelled),
        'No medical certificate: this visit was cancelled.',
    );
    assert.ok(directive(notYet, 'else'), 'shown exactly when neither applies');
    assert.equal(
        text(notYet),
        'Medical certificates are available after the doctor starts the consultation.',
    );

    // Design guide (sections 1, 2, 15): same size as the link, muted token, plain text
    // that cannot look like a link.
    for (const note of notes) {
        assert.equal(note.tag, 'p');
        assert.deepEqual(attr(note, 'class').split(/\s+/).sort(), [
            'text-muted-foreground',
            'text-sm',
        ]);
    }
});

test('UX-2 S1-005: the visit history page offers Medical certificates only when it has a consultation record', () => {
    const page = sfc('resources/js/pages/Visits/History.vue');
    const link = elements(baseParse(page.template.content)).find((node) =>
        mcHref(node),
    );
    const condition = squash(directive(link, 'if')?.exp?.content);

    assert.ok(condition.startsWith('history.consultation &&'));
    assert.match(condition, /'medical_certificates\.view\.own'/);
    assert.match(condition, /'medical_certificates\.view\.branch'/);
});

test('UX-2 keeps the consultation page entry unchanged (an encounter always exists there)', () => {
    const encounter = read('resources/js/pages/Clinical/Show.vue');
    const anchor = encounter.match(
        /<Button\b[^>]*medical_certificates\.view\.own[\s\S]*?<\/Button\s*>/,
    )?.[0];

    assert.ok(anchor);
    assert.doesNotMatch(anchor, /consultationMayHaveStarted|mc-unavailable/);
});
