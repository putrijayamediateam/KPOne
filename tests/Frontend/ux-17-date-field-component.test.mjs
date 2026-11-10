import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UX-17: the shared date fields keep what a native input gave the page (id, name, label, error link,
// required, disabled, min and max) and add the dd/mm/yyyy text box and a calendar.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const date = read('resources/js/components/ui/date-field/DateField.vue');
const month = read('resources/js/components/ui/date-field/MonthField.vue');
const dateTime = read(
    'resources/js/components/ui/date-field/DateTimeField.vue',
);
const calendar = read('resources/js/components/ui/date-field/DateCalendar.vue');

test('the fields send the same values a native input sends and add no dependency', () => {
    for (const source of [date, month, dateTime]) {
        assert.match(source, /'update:modelValue': \[value: string\]/);
        assert.match(source, /defineOptions\(\{ inheritAttrs: false \}\)/);
    }

    assert.match(dateTime, /\$\{date\.value\}T\$\{time\}/);
    assert.doesNotMatch(
        date + month + dateTime + calendar,
        /@internationalized|vue-datepicker|flatpickr/,
    );
    assert.doesNotMatch(date + month, /type="date"|type="month"/);
});

test('id, name, required, disabled and aria attributes land on the text box, class on the wrapper', () => {
    assert.match(date, /v-bind="inputAttrs"/);
    assert.match(date, /const \{ class: _class, \.\.\.rest \} = attrs/);
    assert.match(date, /cn\('w-full', attrs\.class as string\)/);
    assert.match(date, /'aria-invalid': problem\.value/);
    assert.match(date, /aria-describedby/);
    assert.match(date, /role="alert"/);
});

test('the box reads dd/mm/yyyy and the calendar is reachable by keyboard and 44 px on a phone', () => {
    assert.match(date, /placeholder \?\? words\.placeholder/);
    assert.match(date, /inputmode="numeric"/);
    assert.match(date, /max-sm:h-11/);
    assert.match(calendar, /role="grid"/);
    assert.match(calendar, /ArrowLeft/);
    assert.match(calendar, /PageDown/);
    assert.match(calendar, /size-11/);
    assert.match(
        calendar,
        /aria-label="longLabel\(cell\.iso\)"|:aria-label="longLabel\(cell\.iso\)"/,
    );
    assert.match(date, /@open-auto-focus="onOpenFocus"/);
});

test('min and max disable days and the Malay wording is available for the public form', () => {
    assert.match(calendar, /:disabled="!allowed\(cell\.iso\)"/);
    assert.match(date, /withinRange\(iso, props\.min, props\.max\)/);
    assert.match(
        read('resources/js/lib/date-field.ts'),
        /choose: 'Pilih tarikh'/,
    );
});

test('the date and time field is 12-hour with AM and PM and the month field is mm/yyyy', () => {
    assert.match(dateTime, /<option value="AM">AM<\/option>/);
    assert.match(dateTime, /<option value="PM">PM<\/option>/);
    assert.match(month, /monthPlaceholder/);
});
