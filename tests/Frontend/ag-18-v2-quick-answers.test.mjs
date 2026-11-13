import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = () => read('resources/js/pages/Agents/V2/Assistant.vue');

test('the V2 quick answers page is registered and keeps the Classic route, payload and notice', () => {
    assert.match(read('config/v2-pages.php'), /'Agents\/Assistant'/);

    const v2 = page();

    assert.match(v2, /router\.get\(\s*'\/agents\/assistant'/);
    assert.match(v2, /question: selected\.value\.key/);
    assert.match(v2, /branch: branch\.value/);
    assert.match(
        v2,
        /\.\.\.\(usesDate\.value \? \{ date: date\.value \} : \{\}\)/,
    );
    assert.match(v2, /@submit\.prevent="ask"/);
    assert.match(v2, /value: 'all', label: 'All branches'/);
    assert.match(v2, /tone="info" label="AI agent"/);
    assert.match(v2, /not generated text/);
    assert.match(v2, /Nothing is changed\./);
});

test('V2 quick answers: one filled button, questions as a radio group, answers announced and focused', () => {
    const v2 = page();

    assert.equal((v2.match(/:class="filled"/g) ?? []).length, 1);
    assert.match(v2, /type="radio"/);
    assert.match(v2, /name="question"/);
    assert.match(v2, /<fieldset/);
    assert.match(v2, /<legend/);
    assert.match(v2, /aria-live="polite"/);
    assert.match(v2, /role="alert"/);
    assert.match(v2, /ref="answerHeading"/);
    assert.match(v2, /heading\.focus\(\{ preventScroll: true \}\)/);
    assert.match(v2, /scrollIntoView/);
    assert.match(v2, /prefers-reduced-motion: reduce/);
});

test('V2 quick answers: money comes from whole sen, the answer is a V2 table with cards, and a cut-off answer says so', () => {
    const v2 = page();

    assert.match(v2, /cell\.kind === 'money'/);
    assert.match(v2, /Math\.abs\(sen\) \/ 100/);
    assert.match(v2, /<V2Table/);
    assert.match(v2, /cards="xl"/);
    assert.match(v2, /data-test="assistant-totals"/);
    assert.match(
        v2,
        /Showing the first \{\{ answer\.rows\.length \}\} of\s+\{\{ answer\.totalRows \}\} rows\. The sentence counts\s+every row\./,
    );
    assert.match(v2, /Choose a question, then press Answer\./);
    assert.doesNotMatch(v2, /font-mono/);
});

test('V2 quick answers: the scope line starts with a capital', () => {
    const v2 = page();

    assert.match(v2, /scope\.branch\.charAt\(0\)\.toUpperCase\(\)/);
});
