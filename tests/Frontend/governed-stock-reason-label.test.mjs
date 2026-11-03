import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const loadLibrary = (path) => {
    const module = { exports: {} };
    const output = ts.transpileModule(read(path), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;

    runInNewContext(output, { exports: module.exports, require });

    return module.exports;
};

test('governed stock count variance uses a readable reason label', () => {
    const helper = read('resources/js/lib/inventory-picker.ts');
    const { formatInventoryReason } = loadLibrary(
        'resources/js/lib/inventory-picker.ts',
    );

    assert.match(helper, /count_variance:\s*'Count variance'/);
    assert.equal(formatInventoryReason('count_variance'), 'Count variance');
    assert.equal(formatInventoryReason('future_reason'), 'Future reason');
    assert.match(
        read('resources/js/pages/Inventory/GovernedStock.vue'),
        /formatInventoryReason\(row\.reason\)/,
    );
});
