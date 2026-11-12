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
    assert.match(crumbs, /v-if="show"/);
    assert.doesNotMatch(
        crumbs + api,
        /dataset\.ui|data-ui|applyUiVersion|useUiVersion/,
    );
});

test('UX-20: PageFrame draws the crumb at the content edge, 8 px above the title; the shell draws it only for pages without PageFrame', () => {
    assert.match(frame, /<V2PageCrumbs in-frame class="-mb-4" \/>\s+<slot \/>/);
    assert.match(
        squash(shell),
        /<V2PageCrumbs v-if="!focusMode && crumbsInFrame === 0" class="px-4 pt-4 md:px-6" data-test="v2-shell-crumb" \/>/,
    );
    assert.match(
        shell,
        /const crumbsInFrame = computed\(\(\) => placed\.value\.length\);/,
    );
});

test('UX-20: links go back, the last item without href is the current page, separators are hidden', () => {
    assert.match(crumbs, /aria-label="Breadcrumb"/);
    assert.match(crumbs, /<ol\s/);
    assert.match(crumbs, /<Link\s+v-if="crumb\.href"/);
    assert.match(crumbs, /aria-current="page"/);
    assert.match(
        crumbs,
        /<ChevronRight\s+v-if="index > 0"[\s\S]*?aria-hidden="true"/,
    );
    assert.match(crumbs, /max-sm:min-h-11/);
});

test('UX-20: Back to Classic shows a full-strength focus ring on keyboard focus', () => {
    assert.match(
        shell,
        /const QUIET_BACK =\s+'[^']*focus-visible:ring-2 focus-visible:ring-ring';/,
    );
});

test('UX-20 review: hidden in focus mode, only the first PageFrame crumb draws, long titles end in an ellipsis', () => {
    assert.match(
        shell,
        /watchEffect\(\(\) => \(crumbHidden\.value = props\.focusMode === true\)\);/,
    );
    assert.match(
        squash(api),
        /!context\.hidden\.value && context\.trail\.value\.length > 0 && \(placedInFrame \? context\.placed\.value\[0\] === me : context\.placed\.value\.length === 0\)/,
    );
    assert.equal(crumbs.match(/<span class="truncate">/g)?.length, 2);
});

test('UX-20 Daniel: crumb links get a 3:1 focus ring locally; long trails shorten to "… › last" on phones', () => {
    assert.match(
        crumbs,
        /const LINK =\s+'[^']*outline-none[^']*focus-visible:ring-2 focus-visible:ring-ring[^']*';/,
    );
    assert.equal(crumbs.match(/:class="LINK"/g)?.length, 2);
    // A two-item trail is unchanged; only longer trails collapse, and only below 640 px.
    assert.match(
        crumbs,
        /const collapses = computed\(\(\) => trail\.value\.length > 2\);/,
    );
    assert.match(
        squash(crumbs),
        /<li v-if="collapses && parent" class="flex shrink-0 items-center sm:hidden"/,
    );
    assert.match(
        squash(crumbs),
        /'max-sm:hidden': collapses && parent && index < trail\.length - 1,/,
    );
    assert.match(crumbs, /:aria-label="`Back to \$\{parent\.title\}`"/);
    assert.match(
        crumbs,
        /:class="\{ 'max-sm:flex-nowrap': collapses && parent \}"/,
    );
    // The shared Button is untouched (DD-74 waits for the owner).
    assert.doesNotMatch(
        read('resources/js/components/ui/button/index.ts'),
        /focus-visible:ring-ring(?!\/40)/,
    );
});
