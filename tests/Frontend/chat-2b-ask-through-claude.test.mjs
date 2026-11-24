// CHAT-2b: the "Ask through Claude" path of the Ask KPOne panel (frontend only, source checks).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const drawer = () => read('resources/js/components/agents/AskKponeDrawer.vue');
const panel = () => read('resources/js/components/agents/AskClaudePanel.vue');
const lib = () => read('resources/js/lib/ask-kpone.ts');

test('the panel talks only to the ask endpoints and shows the button when either path answers', () => {
    const source = lib();

    assert.match(source, /'\/agents\/ask'/);
    assert.match(source, /\/agents\/ask\/\$\{id\}/);
    assert.match(source, /\/agents\/ask\/\$\{id\}\/cancel/);
    assert.match(source, /X-XSRF-TOKEN/);
    assert.match(drawer(), /<div v-if="state \|\| ask" data-ask-kpone>/);
    assert.match(
        drawer(),
        /Promise\.allSettled\(\[getChat\(\), getAsk\(\)\]\)/,
    );
});

test('with both paths on, the person picks one and the choice is only remembered in the browser', () => {
    const source = drawer();

    assert.match(source, /data-ask-paths/);
    assert.match(source, /aria-label="How to ask"/);
    assert.match(source, /:aria-pressed="path === 'claude'"/);
    assert.match(source, /Ask through Claude/);
    assert.match(source, /Ask here/);
    assert.match(source, /readPath\(\) \?\? 'claude'/);
    assert.match(lib(), /localStorage/);
    assert.match(lib(), /catch \{\s*return null;/);
});

test('the panel leads with the desktop app link and always offers copy and open', () => {
    const source = panel();

    assert.match(source, /Open in Claude desktop/);
    assert.match(source, /request\.desktopLink/);
    assert.match(source, /Copy the question and open Claude/);
    assert.match(source, /copyPrompt\(request\.prompt\)/);
    assert.match(
        source,
        /window\.open\(request\.webLink, '_blank', 'noopener'\)/,
    );
    assert.match(source, /Copy this by hand/);
    assert.match(lib(), /navigator\.clipboard\.writeText/);
});

test('a pending request is polled every 3 seconds while the tab is visible and stops when nothing waits', () => {
    const source = panel();

    assert.match(source, /setInterval\(\(\) => void poll\(\), 3000\)/);
    assert.match(source, /document\.visibilityState !== 'visible'/);
    assert.match(source, /clearInterval\(timer\)/);
    assert.match(source, /onBeforeUnmount/);
});

test('the answer is labelled as unchecked, shown as text only, with no figures and no HTML', () => {
    const source = panel();

    assert.match(
        lib(),
        /Answered by Claude\. KPOne did not check this answer\./,
    );
    assert.match(source, /CLAUDE_LABEL/);
    assert.match(source, /break-words whitespace-pre-wrap/);
    assert.doesNotMatch(source, /v-html/);
    assert.doesNotMatch(source, /Figures/);
});

test('an expired request says so and offers Ask again; a waiting one can be cancelled', () => {
    const source = panel();

    assert.match(lib(), /No answer came back\. You can ask again\./);
    assert.match(source, /CLAUDE_EXPIRED_MESSAGE/);
    assert.match(source, /Ask again/);
    assert.match(source, /cancelAsk\(request\.id\)/);
    assert.match(
        source,
        /Waits up to\s+\{\{ state\.expiryMinutes \}\} minutes\./,
    );
});

test('the question box keeps the CHAT-1 rules: reminder, counter, refusal text and Send off while the same text is refused', () => {
    const source = panel();

    assert.match(source, /Do not type patient names or identity numbers\./);
    assert.match(source, /role="alert"/);
    assert.match(source, /\{\{ text\.length \}\} \/ \{\{ maxChars \}\}/);
    assert.match(source, /text\.value\.trim\(\) === lastSent\.value/);
    assert.match(source, /newRequestKey\(\)/);
});

test('targets are 44 px on phones, as in the rest of the drawer', () => {
    for (const source of [panel(), drawer()]) {
        assert.match(source, /min-h-11/);
    }
});
