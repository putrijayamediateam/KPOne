// CHAT-1 slice 1: the "Ask KPOne" drawer (frontend only, source checks).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const drawer = () => read('resources/js/components/agents/AskKponeDrawer.vue');
const message = () =>
    read('resources/js/components/agents/AskKponeMessage.vue');
const lib = () => read('resources/js/lib/ask-kpone.ts');

test('the drawer talks only to the chat endpoints and renders nothing unless GET /agents/chat succeeds', () => {
    const source = lib();

    assert.match(source, /'\/agents\/chat'/);
    assert.match(source, /\/agents\/chat\/messages/);
    assert.match(source, /\/agents\/chat\/threads\/\$\{id\}/);
    assert.match(source, /\/agents\/chat\/threads\/\$\{id\}\/archive/);
    assert.match(source, /\/agents\/chat\/others/);
    assert.match(source, /X-XSRF-TOKEN/);
    assert.match(drawer(), /<div v-if="state" data-ask-kpone>/);
    assert.match(drawer(), /state\.value = null;/);
});

test('answer text is never rendered as HTML', () => {
    for (const source of [drawer(), message()]) {
        assert.doesNotMatch(source, /v-html/);
    }
});

test('a personal-data refusal shows the exact message and keeps the typed text', () => {
    assert.match(
        lib(),
        /Please don't type identity numbers, phone numbers or emails\./,
    );
    assert.match(drawer(), /text\.value = body;/);
    assert.match(drawer(), /role="alert"/);
});

test('the limit and switched-off states disable the box and name the reset date', () => {
    assert.match(lib(), /The monthly AI limit is reached\. It resets on/);
    assert.match(drawer(), /v-if="hasInput"/);
    assert.match(drawer(), /limitReached === true/);
    assert.match(drawer(), /Ask KPOne is switched off\./);
    assert.match(lib(), /code === 'off' \|\| caught\.status === 404/);
});

test('a failed answer offers Try again with the exact wording', () => {
    assert.match(lib(), /Couldn't answer\. Nothing was changed\. Try again\./);
    assert.match(drawer(), /Try again/);
    assert.match(drawer(), /send\(true\)/);
});

test('answers show a Figures block, the report link and the AI chip line', () => {
    const source = message();

    assert.match(source, /Figures/);
    assert.match(source, /groupFigures/);
    assert.match(source, /formatDateTime\(g\.items\[0\]\.asOf\)/);
    assert.match(source, /message\.report\.url/);
    assert.match(source, /<Badge variant="outline">AI<\/Badge>/);
    assert.match(lib(), /AI answer\. Check the figures on the report page\./);
    assert.match(source, /Show more/);
    assert.match(source, /REMOVED_MESSAGE/);
    assert.match(lib(), /Removed after 90 days\./);
});

test('Enter sends, Shift+Enter makes a new line, and the counter is visible', () => {
    const source = drawer();

    assert.match(source, /event\.key === 'Enter' && !event\.shiftKey/);
    assert.match(source, /event\.preventDefault\(\)/);
    assert.match(source, /\{\{ text\.length \}\} \/ \{\{ maxChars \}\}/);
    assert.match(source, /Ask a question about your branches/);
});

test('the drawer is non-modal, closes with focus back on the Ask KPOne button and uses 44 px targets', () => {
    const source = drawer();

    assert.match(source, /:modal="false"/);
    assert.match(source, /@interact-outside\.prevent/);
    assert.match(source, /getElementById\('ask-kpone-trigger'\)/);
    assert.match(source, /@close-auto-focus="onCloseAutoFocus"/);
    assert.match(source, /aria-live="polite"/);
    assert.match(source, /min-h-11/);
    assert.doesNotMatch(source, /Claude|AI assistant/);
});

test("example chips fill the box without sending, and history and other people's history are wired", () => {
    const source = drawer();

    assert.match(source, /const fill = \(example: string\)/);
    assert.match(source, /@click="fill\(example\)"/);
    assert.match(source, /View other people's history/);
    assert.match(source, /state\.canViewOthers/);
    assert.match(
        source,
        /You are viewing someone else's questions\. Every view is\s+recorded\./,
    );
    assert.match(source, /Ask a new question/);
    assert.match(source, /Working on it\.\.\./);
});

test('each send carries one request key that a retry reuses, and a purged thread title reads as removed', () => {
    const lib = read('resources/js/lib/ask-kpone.ts');
    const drawer = read('resources/js/components/agents/AskKponeDrawer.vue');

    assert.match(lib, /key,\s*\}\)/);
    assert.match(drawer, /newRequestKey\(\)/);
    assert.match(drawer, /!retry \|\| lastKey\.value === ''/);
    assert.match(drawer, /Removed after 90 days\./);
});

test('an answer scrolls to its question, branch codes read as names and the report link looks like a link', () => {
    const drawer = read('resources/js/components/agents/AskKponeDrawer.vue');
    const message = read('resources/js/components/agents/AskKponeMessage.vue');
    const lib = read('resources/js/lib/ask-kpone.ts');

    assert.match(drawer, /scrollToMessage\(pending\.id\)/);
    assert.match(message, /branchLabel\(g\.group\)/);
    assert.match(lib, /export const branchLabel/);
    assert.match(message, /underline underline-offset-4/);
    assert.match(drawer, /title="Not answered"/);
});

test('the limit message is a card in the thread area, Send stays off while a refusal for the same text shows, and each branch shows one As of line', () => {
    const drawer = read('resources/js/components/agents/AskKponeDrawer.vue');
    const message = read('resources/js/components/agents/AskKponeMessage.vue');

    assert.match(drawer, /data-ask-limit/);
    assert.match(drawer, /text\.value\.trim\(\) === lastSent\.value/);
    assert.equal((message.match(/As of \{\{/g) ?? []).length, 1);
    assert.match(message, /g\.items\[0\]\?\.asOf/);
});
