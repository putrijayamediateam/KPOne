import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);
const code = (n) => v2(n).replace(/\/\*[\s\S]*?\*\//g, '');

test('V2ChipStack: wraps, one column in a phone card, built from V2Chip', () => {
    const s = v2('V2ChipStack');
    assert.match(s, /flex-wrap/);
    assert.match(s, /max-sm:flex-col/);
    assert.match(s, /<V2Chip/);
});

test('V2DateRange: a named From and To pair with an announced order error, 44px', () => {
    const s = v2('V2DateRange');
    assert.match(s, /<fieldset/);
    assert.match(s, /<legend/);
    assert.match(s, /type="date"/);
    assert.match(s, /From must be on or before To\./);
    assert.match(s, /role="alert"/);
    assert.match(s, /h-11[^'"]*lg:h-9/);
});

test('V2Dialog: 18px corners, a phone bottom sheet, a pinned footer, a required reason, focus trap from reka-ui', () => {
    const s = v2('V2Dialog');
    assert.match(s, /from 'reka-ui'/);
    assert.match(s, /rounded-\[18px\]/);
    assert.match(s, /max-sm:bottom-0/);
    assert.match(s, /max-sm:rounded-t-\[18px\]/);
    assert.match(s, /max-h-\[90dvh\]/);
    assert.match(s, /env\(safe-area-inset-bottom\)/);
    assert.match(s, /reasonLabel/);
    assert.match(s, /Enter a reason to continue\./);
    assert.match(s, /tone === 'danger'\s*\?\s*'bg-destructive/);
    assert.match(s, /aria-label="Close"/);
    assert.match(s, /size-11/);
    assert.match(s, /h-11[^'"]*lg:h-9/);
    assert.doesNotMatch(code('V2Dialog'), /fetch\(|axios|usePage/);
    // No routing of its own: the open-sheet leave guard is the one shared mechanism (`useLeaveGuard`).
    assert.doesNotMatch(code('V2Dialog'), /router\./);
    assert.doesNotMatch(s, /from '@inertiajs\/vue3'/);
    assert.match(s, /useLeaveGuard\(/);
});

test('The new blocks import nothing from the Classic ui components', () => {
    for (const n of ['V2ChipStack', 'V2DateRange', 'V2Dialog']) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});

test('V2Dialog: Confirm is aria-disabled with an empty reason, pressing it shows the error and focuses the box', () => {
    const s = v2('V2Dialog');
    assert.match(s, /:aria-disabled="[^"]*reason\.trim\(\) === ''[^"]*'true'/);
    assert.match(s, /nextTick\(\(\) => box\.value\?\.focus\(\)\)/);
    assert.match(s, /ref="box"/);
});

test('V2Dialog: the filled Confirm text reaches 4.5:1 in the Version 2 look, light and dark', () => {
    const css = read('resources/css/app.css');
    const block = (selector) =>
        Object.fromEntries(
            [
                ...css
                    .slice(css.indexOf(`${selector} {`))
                    .split('}')[0]
                    .matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6}|hsl\([^)]*\));/gi),
            ].map((m) => [m[1], m[2]]),
        );
    const rgb = (value) => {
        const hsl = value.match(
            /^hsl\((\d+(?:\.\d+)?) (\d+(?:\.\d+)?)% (\d+(?:\.\d+)?)%\)$/,
        );

        if (hsl) {
            const [h, s, l] = [
                Number(hsl[1]),
                Number(hsl[2]) / 100,
                Number(hsl[3]) / 100,
            ];
            const a = s * Math.min(l, 1 - l);
            const f = (n) => {
                const k = (n + h / 30) % 12;

                return Math.round(
                    255 * (l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1))),
                );
            };

            return [f(0), f(8), f(4)];
        }

        return [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16));
    };
    const lum = (c) => {
        const [r, g, b] = c
            .map((v) => v / 255)
            .map((v) =>
                v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4,
            );

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (a, b) => {
        const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);

        return (hi + 0.05) / (lo + 0.05);
    };

    for (const [v2sel, base] of [
        [":root[data-ui='v2']:not(.dark)", ':root'],
        [".dark[data-ui='v2']", '.dark'],
    ]) {
        const t = { ...block(base), ...block(v2sel) };

        for (const pair of ['destructive', 'primary']) {
            const value = ratio(rgb(t[`${pair}-foreground`]), rgb(t[pair]));
            assert.ok(
                value >= 4.5,
                `${v2sel} filled ${pair} button text is ${value.toFixed(2)}`,
            );
        }
    }
});

test('V2Dialog: Confirm stays at full opacity when aria-disabled; aria-describedby only while the error shows', () => {
    const s = v2('V2Dialog');
    assert.doesNotMatch(s, /aria-disabled:opacity/);
    assert.match(
        s,
        /:aria-describedby="[^"]*'v2-dialog-reason-error'[^"]*undefined/,
    );
    assert.doesNotMatch(s, /\n\s*aria-describedby="v2-dialog-reason-error"/);
});

test('V2DateRange: a plain-date echo ("1 Nov 2026 to 13 Nov 2026") on by default, announced politely, formatted without a time zone', () => {
    const s = v2('V2DateRange');
    assert.match(s, /echo\?: boolean/);
    assert.match(s, /echo: true/);
    assert.match(s, /data-slot="v2-date-range-echo"/);
    assert.match(s, /role="status"\s+aria-live="polite"/);
    assert.match(s, /data-slot="v2-date-range-spoken"/);
    assert.match(s, /setTimeout\(speakNow, 1200\)/);
    assert.match(s, /@focusout="speakNow"/);
    // The visible echo is not itself a live region: only the delayed spoken copy is.
    assert.match(
        s,
        /data-slot="v2-date-range-echo"[^>]*aria-hidden="true"|aria-hidden="true"[^>]*data-slot="v2-date-range-echo"/s,
    );
    assert.match(s, /`\$\{from\} to \$\{to\}`/);
    assert.match(s, /`From \$\{from\}`/);
    assert.match(s, /`Up to \$\{to\}`/);
    assert.doesNotMatch(s, /new Date|Intl\.|toLocale/);
});

test('V2Dialog size="tall": full height on a phone, 42rem from 640px, 56rem from 1024px, up to 90dvh', () => {
    const s = v2('V2Dialog');
    assert.match(s, /size\?: 'default' \| 'tall'/);
    assert.match(s, /size: 'default'/);
    assert.match(
        s,
        /max-sm:inset-0 max-sm:h-dvh max-sm:rounded-none sm:max-h-\[90dvh\] sm:max-w-2xl lg:max-w-\[56rem\]/,
    );
    // The default size keeps its bottom sheet.
    assert.match(
        s,
        /max-h-\[90dvh\] max-sm:inset-x-0 max-sm:bottom-0 max-sm:rounded-t-\[18px\] sm:max-w-lg/,
    );
});

test('V2Dialog dirty guard: every close path asks first, Keep editing is focused, Esc on the question means keep, the rest is inert', () => {
    const s = v2('V2Dialog');
    assert.match(s, /dirty\?: boolean/);
    assert.match(s, /dirty: false/);
    assert.match(s, /<DialogRoot :open="open" @update:open="onOpenChange">/);
    assert.match(s, /if \(asking\.value\) \{\s*keepEditing\(\);/);
    assert.match(s, /if \(props\.dirty\) \{/);
    assert.match(s, /keepButton\.value\?\.focus\(\)/);
    assert.match(s, /Discard your changes\?/);
    assert.match(s, />\s*Keep editing\s*</);
    assert.match(s, />\s*Discard changes\s*</);
    assert.match(s, /role="alertdialog"/);
    assert.match(s, /discard: \[\]/);
    assert.equal((s.match(/:inert="asking"/g) ?? []).length, 3);
    // A close from the page (after a save) is not guarded: the guard only sits on the dialog's own close paths.
    assert.doesNotMatch(
        s,
        /watch\(\s*\(\) => props\.open,[\s\S]{0,200}asking\.value = true/,
    );
});

test('V2Dialog discard question keeps Tab and Shift+Tab inside its two buttons (the page behind is inert)', () => {
    const s = v2('V2Dialog');
    assert.match(s, /@keydown="trapTab"/);
    assert.match(s, /ref="discardButton"/);
    assert.match(s, /event\.shiftKey && document\.activeElement === first/);
    assert.match(s, /!event\.shiftKey && document\.activeElement === last/);
});

test('V2Dialog: tall sheets pin the header (title and X); the open-sheet guard is the shared one; the four points are written', () => {
    const s = v2('V2Dialog');
    assert.match(s, /data-slot="v2-dialog-header"/);
    assert.match(s, /sticky -top-5 z-10 -mx-5 -mt-5/);
    assert.match(s, /v-if="size !== 'tall'"/);
    assert.match(s, /props\.open && props\.dirty/);
    assert.match(s, /useLeaveGuard\(/);
    assert.match(
        s,
        /\(1\) A same-path visit and a partial reload or a polling visit \(such as the Registration and Queue live/,
    );
    assert.match(s, /\(2\) a successful save never asks/);
    assert.match(
        s,
        /\(3\) the listener is removed when the page or sheet unmounts/,
    );
    assert.match(
        s,
        /\(4\) browser Back and Forward are held too; `beforeunload` covers reload and tab close\./,
    );
});

test('V2Dialog: confirmDisabled switches the filled action off and says why above the footer', () => {
    const d = read('resources/js/components/v2/V2Dialog.vue');
    assert.match(d, /confirmDisabled\?: boolean;/);
    assert.match(d, /confirmDisabledReason\?: string;/);
    // focusable (aria-disabled, not native disabled) and pressing does nothing
    assert.match(d, /:disabled="busy"/);
    assert.match(d, /confirmDisabled \|\|/);
    assert.match(d, /if \(props\.confirmDisabled\) \{\s*return;/);
    // the status region is always in the DOM; no reason gives a dev warning
    assert.match(d, /aria-live="polite"/);
    assert.doesNotMatch(d, /<p\s+v-if="confirmDisabled/);
    assert.match(d, /confirmDisabled needs a confirmDisabledReason/);
    assert.match(d, /id="v2-dialog-confirm-reason"/);
    assert.match(d, /role="status"/);
    assert.match(d, /'v2-dialog-confirm-reason'/);
    // the reason line sits above the footer row
    assert.ok(
        d.indexOf('id="v2-dialog-confirm-reason"') <
            d.indexOf('sm:flex-row sm:justify-end'),
    );
    assert.match(d, /Choose a supplier to continue\./);
});
