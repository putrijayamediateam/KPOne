import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-34 and DD-35 (Yasmin's sketch, owner 2026-11-06): theme tokens instead of palette colours, so dark mode works.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Clinical/Show.vue');
const callNext = read('resources/js/components/queue/CallNextControl.vue');

test('DD-34: the consultation page panels use theme tokens, never pale palette colours', () => {
    assert.match(
        show,
        /class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning\/25 bg-warning\/10 p-3 text-sm text-warning-strong"/,
    );
    assert.equal(
        show.match(
            /border-destructive\/20 bg-destructive\/5 p-3 text-sm text-destructive"/g,
        )?.length,
        2,
    );
    assert.doesNotMatch(
        show,
        /border-red-200 bg-red-50 p-3 text-sm text-red-900/,
    );
    assert.doesNotMatch(
        show,
        /border-amber-300 bg-amber-50 p-3 text-sm text-amber-950/,
    );
});

test('DD-35: the Call next dialog uses the popover token, not a hard-coded dark colour', () => {
    assert.match(
        callNext,
        /class="gap-5 border-border\/80 bg-popover p-5 shadow-2xl shadow-black\/15 sm:max-w-md sm:p-6 dark:border-border"/,
    );
    assert.doesNotMatch(callNext, /dark:bg-slate-900/);
});

test('DD-36: the My Queue link uses the success token, so it reads in dark mode', () => {
    assert.match(
        show,
        /class="inline-flex items-center gap-1 text-xs font-medium text-success hover:underline"\s*>\s*<ArrowLeft class="size-3\.5" \/> My Queue/,
    );
    assert.doesNotMatch(show, /text-emerald-800/);
});
