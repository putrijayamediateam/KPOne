import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (s) => s.replace(/\s+/g, ' ');

const CLASSIC_CLINIC =
    squash(`<WorkspaceHeader v-if="!focusMode" context="clinic" />
            <div
                ref="content"
                id="main-content"
                class="flex min-h-0 w-full flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>`);
const CLASSIC_ADMIN = squash(`<WorkspaceHeader context="admin" />
            <div
                ref="content"
                id="main-content"
                class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>`);

test('UX-17: both staff layouts render the V2 shell only in Version 2 and keep the Classic markup in v-else', () => {
    for (const [path, classic] of [
        ['resources/js/layouts/ClinicLayout.vue', CLASSIC_CLINIC],
        ['resources/js/layouts/AdminLayout.vue', CLASSIC_ADMIN],
    ]) {
        const source = squash(read(path));

        assert.match(source, /const \{ isV2 \} = useUiVersion\(\);/);
        assert.match(
            source,
            /isV2\.value \? \(page\.props\.workspace\?\.shell \?\? null\) : null/,
        );
        assert.match(source, /<V2AppShell v-if="v2Shell" :shell="v2Shell"/);
        assert.ok(
            source.includes(`<template v-else> ${classic} </template>`),
            `${path}: the Classic branch must keep the original markup`,
        );
        // The one Toaster stays outside both branches.
        assert.equal(source.match(/<Toaster /g)?.length, 1);
    }
});

test('UX-17: the shell never sets data-ui or touches the gate files', () => {
    for (const path of [
        'resources/js/components/workspace/V2AppShell.vue',
        'resources/js/components/workspace/V2ShellNav.vue',
        'resources/js/components/workspace/V2JumpDialog.vue',
        'resources/js/layouts/ClinicLayout.vue',
        'resources/js/layouts/AdminLayout.vue',
    ]) {
        const source = read(path);

        assert.doesNotMatch(source, /dataset\.ui|data-ui|applyUiVersion/);
    }
});

test('UX-17: sidebar below 1024 px is a sheet from a menu button; focus mode hides the chrome', () => {
    const shell = read('resources/js/components/workspace/V2AppShell.vue');

    assert.match(shell, /hidden h-screen w-60[^"]*lg:flex/);
    assert.match(shell, /<Sheet v-model:open="sheetOpen">/);
    assert.match(shell, /<SheetContent\s+side="left"/);
    assert.match(shell, /lg:hidden"\s+aria-label="Open menu"/);
    assert.match(
        shell,
        /<div\s+v-if="!focusMode"\s+class="sticky top-0 hidden h-screen/,
    );
    assert.match(shell, /<header\s+v-if="!focusMode"/);
    // The top bar is 64 px, the same offset Quick create's sticky action bar already uses (max-lg:top-16).
    assert.match(shell, /sticky top-0 z-40 flex h-16/);
});

test('UX-17: Back to Classic uses the existing switch endpoint with the lock version', () => {
    const shell = squash(
        read('resources/js/components/workspace/V2AppShell.vue'),
    );

    assert.match(
        shell,
        /router\.put\( '\/spaces\/preference', \{ enabled: false, lock_version: page\.props\.workspace\?\.spaces\?\.lockVersion \?\? 0, \}, \{ onError:/,
    );
    assert.match(shell, /data-test="v2-back-to-classic"/);
    assert.match(
        shell,
        /class="max-sm:hidden"\s+data-test="v2-back-to-classic"/,
    );
    assert.match(
        shell,
        /data-test="v2-sheet-back-to-classic"[\s\S]*?Back to Classic layout/,
    );
});

test('UX-17: the crumb comes only from the server and every link comes from workspace.shell', () => {
    const shell = read('resources/js/components/workspace/V2AppShell.vue');
    const nav = read('resources/js/components/workspace/V2ShellNav.vue');

    assert.match(shell, /providePageCrumbs\(\(\) =>\s+props\.shell\.current/);
    assert.match(shell, /v-if="!focusMode && crumbsInFrame === 0"/);
    assert.match(nav, /v-for="group in shell\.groups"/);
    assert.match(nav, /v-for="section in shell\.more"/);
    assert.doesNotMatch(nav, /href="\/(?!")/);
});

test('UX-17: the jump list is a dialog over the shell links only, with no request of its own', () => {
    const jump = read('resources/js/components/workspace/V2JumpDialog.vue');

    assert.match(jump, /<Dialog v-model:open="open">/);
    assert.match(
        jump,
        /<DialogTitle class="sr-only">Jump to a page<\/DialogTitle>/,
    );
    assert.match(jump, /role="combobox"/);
    assert.match(jump, /role="listbox"/);
    assert.match(jump, /placeholder="Search or jump to…"/);
    assert.doesNotMatch(jump, /fetch\(|axios|router\.(get|post|reload)/);
    assert.match(jump, /props\.shell\.groups\.flatMap/);
    assert.match(jump, /props\.shell\.more\.flatMap/);
});

test('UX-17: the server builds the shell only when the switch is on', () => {
    const middleware = read('app/Http/Middleware/HandleInertiaRequests.php');

    assert.match(
        middleware,
        /'shell' => app\(UiVersionResolver::class\)->isV2\(\$user, \$request\) \? fn \(\) => app\(V2ShellNavigation::class\)->for\(\$user, \$request->path\(\)\) : null,/,
    );
});

test('UX-17 review: one current link (longest href), unique keys, IME-safe Enter, failed switch is shown', () => {
    const nav = read('resources/js/components/workspace/V2ShellNav.vue');
    const jump = read('resources/js/components/workspace/V2JumpDialog.vue');
    const shell = read('resources/js/components/workspace/V2AppShell.vue');

    assert.match(nav, /\.sort\(\(a, b\) => b\.length - a\.length\)\[0\]/);
    assert.match(nav, /:key="`\$\{link\.href\}\|\$\{link\.title\}`"/);
    assert.match(jump, /:key="`\$\{entry\.href\}\|\$\{entry\.title\}`"/);
    assert.match(jump, /if \(!event\.isComposing\)/);
    assert.match(jump, /scrollIntoView\(\{ block: 'nearest' \}\)/);
    assert.match(shell, /toast\.error\(/);
    assert.match(
        read('resources/js/components/workspace/V2PageCrumbs.vue'),
        /aria-label="Breadcrumb"/,
    );
});

test('UX-17 DD-55: the sheet, the jump dialog and the sidebar chevron respect reduced motion', () => {
    const reduce =
        /motion-reduce:transition-none motion-reduce:data-\[state=closed\]:animate-none motion-reduce:data-\[state=open\]:animate-none/;

    assert.match(
        read('resources/js/components/workspace/V2AppShell.vue'),
        reduce,
    );
    assert.match(
        read('resources/js/components/workspace/V2JumpDialog.vue'),
        reduce,
    );
    assert.match(
        read('resources/js/components/workspace/V2ShellNav.vue'),
        /transition-transform group-open:rotate-90 motion-reduce:transition-none/,
    );
});

test('UX-17: a Version 2 page (Dir/V2/Name) gets the V2 layout, before every prefix case', () => {
    const app = read('resources/js/app.ts');
    const layout = read('resources/js/layouts/V2Layout.vue');

    assert.match(app, /import V2Layout from '@\/layouts\/V2Layout\.vue';/);
    assert.match(app, /case isV2Page\(name\):\s+return V2Layout;/);
    assert.ok(
        app.indexOf('case isV2Page(name)') <
            app.indexOf("case name.startsWith('PublicCheckIn/')"),
        'the V2 case comes before the prefix cases',
    );
    assert.ok(
        app.indexOf("case name === 'QueueDisplay/Screen'") <
            app.indexOf('case isV2Page(name)'),
        'print views and the TV keep their own no-layout cases first',
    );
    assert.match(layout, /<V2AppShell v-if="shell" :shell="shell">/);
    assert.match(layout, /<Toaster v-bind="STAFF_TOAST_PLACEMENT" \/>/);
    assert.equal(layout.match(/<Toaster /g)?.length, 1);
    assert.doesNotMatch(layout, /dataset\.ui|applyUiVersion/);
});

test('UX-19: Back to Classic in the top bar is a quiet ghost control the height of the branch switcher', () => {
    const shell = squash(
        read('resources/js/components/workspace/V2AppShell.vue'),
    );
    const switcher = read(
        'resources/js/components/workspace/WorkspaceBranchSwitcher.vue',
    );

    assert.match(
        shell,
        /<Button variant="ghost" size="sm" :class="QUIET_BACK" class="max-sm:hidden" data-test="v2-back-to-classic" @click="backToClassic" > <Undo2 class="size-3\.5" aria-hidden="true" \/> Back to Classic <\/Button>/,
    );
    assert.match(
        shell,
        /const QUIET_BACK = 'h-9 gap-1\.5 rounded-lg px-2\.5 text-xs font-normal/,
    );
    assert.match(switcher, /class="h-9 w-auto/);
});
