import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// R1-07: the shared long-text disclosure reused by the QR Intake table and
// the Registration/Queue boards' Visit Notes column — a tooltip on desktop
// hover/focus, and a tap-to-expand panel on mobile, for text too long to show
// in full inline.
const source = readFileSync(
    new URL(
        '../../resources/js/components/ui/disclosure/DisclosureText.vue',
        import.meta.url,
    ),
    'utf8',
);

test('provides a desktop tooltip trigger and a mobile tap-to-expand panel', () => {
    assert.match(source, /<TooltipTrigger\b/);
    assert.match(source, /<TooltipContent\b/);
    assert.match(source, /<details\b/);
    assert.match(source, /<summary\b/);
});

test('the full text is only ever placed in element content, never in an HTML attribute', () => {
    assert.doesNotMatch(source, /:title=/);
    assert.doesNotMatch(source, /\btitle="/);
    // Both the tooltip content and the expanded mobile panel render the text
    // as a mustache child, not as an attribute value.
    assert.match(source, /<TooltipContent[^>]*>\s*\{\{\s*text\s*\}\}/);
    assert.match(source, /<p[^>]*>\s*\{\{\s*text\s*\}\}/);
});

test('an empty or missing text falls back to the given fallback text, not a mojibake-prone default', () => {
    assert.match(source, /v-else/);
    assert.match(source, /\{\{\s*fallback\s*\}\}/);
    assert.match(source, /fallback:\s*'—'/);
});

test('the clamp variant switches between single-line truncate and two-line clamp', () => {
    assert.match(
        source,
        /variant === 'clamp-2' \? 'line-clamp-2' : 'truncate'/,
    );
});
