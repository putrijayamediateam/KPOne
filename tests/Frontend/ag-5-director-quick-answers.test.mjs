import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Assistant.vue');
const routes = read('routes/web.php');

test('the assistant is labelled as an AI agent that changes nothing', () => {
    assert.match(page, /label="AI agent"/);
    assert.match(page, /Nothing is changed\./);
    assert.match(page, /not generated text/);
});

test('an answer is asked for explicitly with the question, branch and date', () => {
    assert.match(page, /router\.get\(\s*'\/agents\/assistant'/);
    assert.match(page, /question: selected\.value\.key/);
    assert.match(page, /branch: branch\.value/);
    assert.match(page, /@submit\.prevent="ask"/);
    assert.match(page, /value: 'all', label: 'All branches'/);
});

test('money is formatted from whole sen and errors are announced', () => {
    assert.match(page, /cell\.kind === 'money'/);
    assert.match(page, /Math\.abs\(sen\) \/ 100/);
    assert.match(page, /role="alert"/);
    assert.match(page, /The sentence counts every\s+row\./);
});

test('the page has a table from 768 px and cards below it', () => {
    assert.match(page, /class="hidden md:block"/);
    assert.match(page, /class="grid gap-2 md:hidden"/);
    assert.match(page, /OperationalTable/);
});

test('the route is Director-only, no-store and throttled', () => {
    assert.match(
        routes,
        /permission:ai_assistant\.use\.organisation', 'sensitive\.no-store'\]\)\s*->get\('agents\/assistant'.*throttle:30,1.*name\('agents\.assistant'\)/s,
    );
});
