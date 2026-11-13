import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const block = read('resources/js/components/v2/V2MoneyInput.vue');

const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(read('resources/js/lib/v2-money.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('tidyMoney: two decimals, empty stays empty, a typed zero is an amount', async () => {
    const { tidyMoney } = await loadLib();
    assert.deepEqual(tidyMoney('12'), { value: '12.00', valid: true });
    assert.deepEqual(tidyMoney('12.5'), { value: '12.50', valid: true });
    assert.deepEqual(tidyMoney('12.50'), { value: '12.50', valid: true });
    assert.deepEqual(tidyMoney('.5'), { value: '0.50', valid: true });
    assert.deepEqual(tidyMoney('12.'), { value: '12.00', valid: true });
    assert.deepEqual(tidyMoney(''), { value: '', valid: true });
    assert.deepEqual(tidyMoney('   '), { value: '', valid: true });
    // Only the prefix typed: no amount was given, so it is empty, not 0.00.
    assert.deepEqual(tidyMoney('RM'), { value: '', valid: true });
    assert.deepEqual(tidyMoney('0'), { value: '0.00', valid: true });
    assert.deepEqual(tidyMoney('0.00'), { value: '0.00', valid: true });
    assert.deepEqual(tidyMoney('007'), { value: '7.00', valid: true });
});

test('tidyMoney: spaces, thousands commas and a leading RM are dropped; no floating-point maths', async () => {
    const { tidyMoney } = await loadLib();
    assert.deepEqual(tidyMoney('1,234.5'), { value: '1234.50', valid: true });
    assert.deepEqual(tidyMoney(' RM 1 234 '), {
        value: '1234.00',
        valid: true,
    });
    assert.deepEqual(tidyMoney('rm0.1'), { value: '0.10', valid: true });
    assert.deepEqual(tidyMoney('9007199254740993.10'), {
        value: '9007199254740993.10',
        valid: true,
    });
    assert.doesNotMatch(
        read('resources/js/lib/v2-money.ts'),
        /parseFloat|Number\(|toFixed/,
    );
});

test('tidyMoney: what is not an amount comes back unchanged and not valid', async () => {
    const { tidyMoney } = await loadLib();

    for (const bad of ['abc', '-5', '1.234', '1.2.3', '.', '12abc', '+3']) {
        assert.deepEqual(tidyMoney(bad), { value: bad, valid: false }, bad);
    }
});

test('V2MoneyInput: RM prefix, decimal keypad, right-aligned tabular figures, tidy on blur, errors under the field', () => {
    assert.match(block, />RM<\/span/);
    assert.match(block, /inputmode="decimal"/);
    assert.match(block, /type="text"/);
    assert.doesNotMatch(block, /type="number"/);
    assert.match(
        block,
        /text-right[^"]*tabular-nums|tabular-nums[^"]*text-right/,
    );
    assert.match(block, /@blur="onBlur"/);
    assert.match(block, /tidyMoney\(props\.modelValue\)/);
    assert.match(block, /h-11[^"]*lg:h-9/);
    assert.match(
        block,
        /props\.error \?\? \(typedInvalid\.value \? MONEY_ERROR : undefined\)/,
    );
    assert.match(block, /<V2Field/);
});

test('V2MoneyInput: the prefix is spoken as part of the description, the model is text and nothing is added up', () => {
    assert.match(block, /Amount in ringgit \(RM\)/);
    assert.match(block, /aria-describedby/);
    assert.match(block, /modelValue: string/);
    assert.doesNotMatch(block, /@\/components\/ui\//);
    assert.doesNotMatch(block, /parseFloat|Number\(/);
});
