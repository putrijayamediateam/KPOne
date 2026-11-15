import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import test from 'node:test';

const root = new URL('../../resources/js/', import.meta.url).pathname;

const vueFiles = (dir) =>
    readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory()
            ? vueFiles(path)
            : path.endsWith('.vue')
              ? [path]
              : [];
    });

/**
 * The opening tag that starts at `from` (`<Checkbox`), read up to its closing `>` while skipping quoted attribute values
 * and `{{ }}`-free expressions, so an arrow function (`=>`) inside an attribute does not end the tag early.
 */
const openingTag = (src, from) => {
    let quote = null;

    for (let i = from; i < src.length; i++) {
        const c = src[i];

        if (quote) {
            if (c === quote) {
                quote = null;
            }
        } else if (c === '"' || c === "'" || c === '`') {
            quote = c;
        } else if (c === '>' && src[i - 1] !== '=') {
            return src.slice(from, i + 1);
        }
    }

    return src.slice(from);
};

// reka-ui 2.10 checkboxes take `modelValue` (v-model or :model-value). `v-model:checked` and `:checked` are not props of
// the ui Checkbox or of CheckboxRoot: Vue binds them as a plain attribute and nothing happens, with no type error.
const BAD = /(?:^|\s)(?:v-model:checked|v-bind:checked|:checked)(?=[\s=>/]|$)/;

test('the ui Checkbox and reka-ui CheckboxRoot are never bound with v-model:checked or :checked (use v-model / :model-value)', () => {
    const offenders = [];
    let checked = 0;

    for (const file of vueFiles(root)) {
        const src = readFileSync(file, 'utf8');
        const usesComponent =
            /from ['"]@\/components\/ui\/checkbox['"]/.test(src) ||
            /\bCheckboxRoot\b/.test(src);

        if (!usesComponent) {
            continue;
        }

        for (const match of src.matchAll(
            /<(Checkbox|CheckboxRoot)(?=[\s/>])/g,
        )) {
            checked++;
            const tag = openingTag(src, match.index);

            if (BAD.test(tag)) {
                const line = src.slice(0, match.index).split('\n').length;
                offenders.push(`${relative(root, file)}:${line}`);
            }
        }
    }

    assert.ok(checked > 0, 'the scan found no Checkbox usage at all');
    assert.deepEqual(
        offenders,
        [],
        `bind the checkbox with v-model (or :model-value), not v-model:checked or :checked: ${offenders.join(', ')}`,
    );
});

test('the scan itself catches the wrong bindings and allows the right ones', () => {
    const find = (tag) => BAD.test(openingTag(tag, 0));

    assert.equal(find('<Checkbox v-model:checked="a.b" />'), true);
    assert.equal(find('<Checkbox :checked="x" @update:checked="y" />'), true);
    assert.equal(find('<Checkbox v-model="a.b" />'), false);
    assert.equal(
        find(
            '<Checkbox :model-value="x" @update:model-value="(v) => y = v" />',
        ),
        false,
    );
    // an arrow function inside an attribute does not end the tag early
    assert.equal(
        find('<Checkbox @update:model-value="(v) => (a = v)" :checked="x" />'),
        true,
    );
});
