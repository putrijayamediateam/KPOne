import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

// Double-encoded UTF-8 (Windows-1252-over-UTF-8) mojibake sequences that have
// appeared in this codebase (R1-01 found the em dash character stored as three
// mis-decoded characters in resources/js/components/registration/QrIntakeTable.vue).
// Each pattern is built from its raw UTF-16 code units via String.fromCharCode,
// never written as a string literal, so this file's own source never contains
// the literal mojibake it looks for. A correctly encoded UTF-8 file never
// contains these code point sequences, so any match is a real defect — this
// never false-positives on legitimately encoded punctuation such as an em dash
// or a middle dot.
const build = (codeUnits) => String.fromCharCode(...codeUnits);
const MOJIBAKE_PATTERNS = [
    [build([0xe2, 0x20ac, 0x201d]), 'em dash'],
    [build([0xe2, 0x20ac, 0x201c]), 'en dash'],
    [build([0xe2, 0x20ac, 0x02dc]), 'left single quote'],
    [build([0xe2, 0x20ac, 0x2122]), 'right single quote'],
    [build([0xe2, 0x20ac, 0x0153]), 'left double quote'],
    [build([0xe2, 0x20ac, 0x009d]), 'right double quote'],
    [build([0xe2, 0x20ac, 0x00a6]), 'ellipsis'],
    [build([0xc2, 0x00b7]), 'middle dot'],
    [build([0xc3, 0x00a9]), 'e-acute (é)'],
    [build([0xc3, 0x00a8]), 'e-grave (è)'],
    [build([0xc3, 0x00a0]), 'a-grave (à)'],
    [build([0xc3, 0x00a2]), 'a-circumflex (â)'],
    [build([0xc3, 0x00ab]), 'e-diaeresis (ë)'],
    [build([0xc3, 0x00af]), 'i-diaeresis (ï)'],
    [build([0xc3, 0x00b4]), 'o-circumflex (ô)'],
    [build([0xc3, 0x00b9]), 'u-grave (ù)'],
    [build([0xc3, 0x00a7]), 'c-cedilla (ç)'],
];

const ROOT = fileURLToPath(new URL('../../resources/js', import.meta.url));
const SCANNABLE = /\.(vue|ts|tsx|js|mjs)$/;

const walk = (dir) => {
    const files = [];

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = `${dir}/${entry.name}`;

        if (entry.isDirectory()) {
            files.push(...walk(path));
        } else if (SCANNABLE.test(entry.name)) {
            files.push(path);
        }
    }

    return files;
};

test('no resources/js file contains double-encoded UTF-8 (mojibake) sequences', () => {
    const offenders = [];

    for (const path of walk(ROOT)) {
        const text = readFileSync(path, 'utf8');

        for (const [pattern, label] of MOJIBAKE_PATTERNS) {
            const index = text.indexOf(pattern);

            if (index !== -1) {
                const line = text.slice(0, index).split('\n').length;
                offenders.push(
                    `${path}:${line} looks like mojibake for "${label}"`,
                );
            }
        }
    }

    assert.deepEqual(offenders, []);
});

test('no resources/js file starts with a UTF-8 byte order mark', () => {
    const offenders = walk(ROOT).filter((path) => {
        const buffer = readFileSync(path);

        return (
            buffer.length >= 3 &&
            buffer[0] === 0xef &&
            buffer[1] === 0xbb &&
            buffer[2] === 0xbf
        );
    });

    assert.deepEqual(offenders, []);
});
