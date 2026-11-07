import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const dialog = readFileSync(
    new URL(
        '../../resources/js/components/ui/OperationalConfirmDialog.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the shared confirm dialog uses theme tokens, not a fixed slate surface', () => {
    assert.match(
        dialog,
        /class="gap-5 border-border\/80 bg-popover p-5 shadow-2xl shadow-black\/15 sm:max-w-md sm:p-6 dark:border-border"/,
    );
    assert.doesNotMatch(dialog, /dark:bg-slate-\d+/);
});

test('the icon tile is never empty and a destructive question uses the destructive tone', () => {
    assert.match(
        dialog,
        /<slot name="icon">\s*<AlertTriangle v-if="destructive" class="size-5" \/>\s*<CircleHelp v-else class="size-5" \/>\s*<\/slot>/,
    );
    assert.match(
        dialog,
        /destructive\s*\?\s*'bg-destructive\/10 text-destructive dark:bg-destructive\/15'/,
    );
});

// UX-16 (the rest of design debt DD-20): a non-destructive question's tile uses the brand
// tokens, which have their own dark values, instead of fixed pink palette colours.
test('UX-16: a non-destructive question uses the brand tone', () => {
    assert.match(
        dialog,
        /:\s*'bg-brand\/10 text-brand-strong dark:bg-brand\/15'/,
    );
    assert.doesNotMatch(dialog, /pink-\d+/);
});
