import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const picker = readFileSync(
    new URL(
        '../../resources/js/components/insights/DateRangePicker.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the Insights range calendar starts its weeks on Monday', () => {
    assert.match(
        picker,
        /weekdayLabels = \['M', 'T', 'W', 'T', 'F', 'S', 'S'\]/,
    );
    assert.match(picker, /\(new Date\(year, month, 1\)\.getDay\(\) \+ 6\) % 7/);
});
