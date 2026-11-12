import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (s) => s.replace(/\s+/g, ' ');

const api = read('resources/js/composables/usePageCrumbs.ts');
const crumbs = read('resources/js/components/workspace/V2PageCrumbs.vue');
const shell = read('resources/js/components/workspace/V2AppShell.vue');
const frame = read('resources/js/components/ui/page-frame/PageFrame.vue');

test('UX-20: a page sets its own trail; with none, the shell space label is the fallback', () => {
    assert.match(
        api,
        /export function usePageCrumbs\(source: MaybeRefOrGetter<PageCrumb\[\]>\): void/,
    );
    assert.match(
        api,
        /export type PageCrumb = \{ title: string; href\?: string \};/,
    );
    assert.match(
        squash(api),
        /if \(pageCrumbs\.value && pageCrumbs\.value\.length > 0\) \{ return pageCrumbs\.value; \} const current = toValue\(fallback\); return current \? \[current\] : \[\];/,
    );
    assert.match(
        squash(shell),
        /providePageCrumbs\(\(\) => props\.shell\.current \? \{ title: props\.shell\.current\.title, href: props\.shell\.current\.href \} : null, \);/,
    );
});

test("UX-20: a page trail is cleared when that page leaves, never another page's", () => {
    assert.match(
        squash(api),
        /onBeforeUnmount\(\(\) => \{ if \(context\.owner\.value === me\) \{ context\.owner\.value = null; context\.pageCrumbs\.value = null; \} \}\);/,
    );
});

test('UX-20: Classic renders nothing: without the V2 shell every call is a no-op', () => {
    assert.match(
        squash(api),
        /const context = inject\(KEY, null\); if \(!context\) \{ return; \}/,
    );
    assert.match(crumbs, /v-if="active && trail\.length > 0"/);
    assert.doesNotMatch(
        crumbs + api,
        /dataset\.ui|data-ui|applyUiVersion|useUiVersion/,
    );
});

test('UX-20: PageFrame draws the crumb at the content edge, 8 px above the title; the shell draws it only for pages without PageFrame', () => {
    assert.match(frame, /<V2PageCrumbs in-frame class="-mb-4" \/>\s+<slot \/>/);
    assert.match(
        squash(shell),
        /<V2PageCrumbs v-if="!focusMode && crumbsInFrame === 0" class="px-4 pt-4 lg:px-8" data-test="v2-shell-crumb" \/>/,
    );
    // The fallback uses PageFrame's own side padding (GUIDE section 19).
    assert.match(frame, /px-4 py-6 lg:px-8/);
});

test('UX-20: links go back, the last item without href is the current page, separators are hidden', () => {
    assert.match(crumbs, /aria-label="Breadcrumb"/);
    assert.match(crumbs, /<ol /);
    assert.match(crumbs, /<Link\s+v-if="crumb\.href"/);
    assert.match(crumbs, /aria-current="page"/);
    assert.match(
        crumbs,
        /<ChevronRight\s+v-if="index > 0"[\s\S]*?aria-hidden="true"/,
    );
    assert.match(crumbs, /max-sm:min-h-11/);
});
