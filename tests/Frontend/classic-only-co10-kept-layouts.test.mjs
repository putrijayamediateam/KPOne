// Classic only, CO-10: the Classic half of ux-17-v2-shell, kept when the V2 shell was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (s) => s.replace(/\s+/g, ' ');

const CLASSIC_CLINIC =
    squash(`<WorkspaceRail v-if="!focusMode" context="clinic" />
        <div
            ref="content"
            id="main-content"
            class="flex min-h-0 w-full flex-1 flex-col"
            :class="focusMode ? '' : 'lg:pl-[var(--shell-offset,72px)]'"
            :role="role"
        >
            <EmergencyAlertHost />
            <slot />
        </div>`);
const CLASSIC_ADMIN = squash(`<WorkspaceRail context="admin" />
        <div
            ref="content"
            id="main-content"
            class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col lg:pl-[var(--shell-offset,72px)]"
            :role="role"
        >
            <EmergencyAlertHost />
            <slot />
        </div>`);

test('both staff layouts draw the Clean Look rail and main landmark once, with one Toaster and no V2 shell', () => {
    for (const [path, classic] of [
        ['resources/js/layouts/ClinicLayout.vue', CLASSIC_CLINIC],
        ['resources/js/layouts/AdminLayout.vue', CLASSIC_ADMIN],
    ]) {
        const source = squash(read(path));

        assert.ok(source.includes(classic), `${path}: the Classic markup`);
        assert.equal(source.match(/id="main-content"/g)?.length, 1, path);
        assert.equal(source.match(/<Toaster /g)?.length, 1, path);
        assert.doesNotMatch(
            source,
            /V2AppShell|useUiVersion|workspace\?\.shell/,
        );
    }
});
