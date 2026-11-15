import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const s = fs.readFileSync(
    new URL('../../resources/js/components/v2/V2Stat.vue', import.meta.url),
    'utf8',
);

test('V2Stat: label, big number, muted line, optional tone with a status word', () => {
    for (const p of [
        'label: string',
        'value: string',
        'detail?: string',
        "tone?: 'neutral' | 'success' | 'warning' | 'danger'",
        'status?: string',
    ]) {
        assert.ok(s.includes(p), p);
    }

    assert.match(s, /tone: 'neutral'/);
    assert.match(s, /data-slot="v2-stat"/);
});

test('V2Stat: the number is text with tabular figures that never wraps; no formatting or maths in the block', () => {
    assert.match(s, /text-2xl[^"]*whitespace-nowrap tabular-nums/);
    assert.match(s, /\{\{ value \}\}/);
    assert.doesNotMatch(s, /toFixed|Intl\.|toLocaleString|parseFloat/);
});

test('V2Stat: colour is never the only signal (a tone shows its status word as a chip) and a toneless misuse warns in development', () => {
    assert.match(s, /v-if="tone !== 'neutral' && status"/);
    assert.match(s, /import V2Chip from '\.\/V2Chip\.vue'/);
    assert.match(s, /needs a `status` word/);
});

test('V2Stat: imports nothing from Classic ui', () => {
    assert.doesNotMatch(s, /@\/components\/ui\//);
});

test('V2Stat reads as one group: label, value, status, detail, in that order', () => {
    assert.match(s, /role="group"/);
    assert.match(s, /:aria-label="reading"/);
    assert.match(
        s,
        /props\.label,\s+props\.value,\s+detailSaysStatus \? undefined : props\.status,\s+props\.detail,/,
    );
    assert.match(s, /\.join\(', '\)/);
});

test('V2Stat warns in development for an empty value and documents Not available, the real minus and the clinic wording', () => {
    assert.match(s, /`value` is empty; pass "Not available" in words/);
    assert.match(s, /Not available/);
    assert.match(s, /real minus sign/);
    assert.match(s, /"Short", not\s+\* "Negative"/);
});

test('V2Stat: the status word is left out of the group name when the detail already says it', () => {
    assert.match(s, /detailSaysStatus/);
    assert.match(
        s,
        /props\.detail\.toLowerCase\(\)\.includes\(props\.status\.toLowerCase\(\)\)/,
    );
    assert.match(s, /adds information; it does not repeat the status/);
});
