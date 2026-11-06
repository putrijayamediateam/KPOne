import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (path) =>
    ts
        .transpileModule(read(path), {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        })
        .outputText.replace(/^import .*$/gm, '')
        .replaceAll('export ', '');
const { isNumberCell, numericColumns, reportCell } = runInNewContext(
    `${transpile('resources/js/lib/presentation.ts')}\n${transpile('resources/js/lib/report-cells.ts')}\n({ isNumberCell, numericColumns, reportCell })`,
    { Intl },
);
const page = read('resources/js/pages/Reports/BranchReport.vue');

// UX-9 (design debt DD-23): the shared branch report page.
test('UX-9: number columns are recognised from their cells, including negatives and quantities', () => {
    for (const value of [
        '0.00',
        '30.30',
        '-4.00',
        '-0.50',
        '12',
        '1.250',
        '-1.250',
        '1,234.50',
    ]) {
        assert.equal(isNumberCell(value), true, value);
    }

    for (const value of [
        'KP-00000012',
        '2026-11-05',
        'Cash',
        'Yes',
        '',
        '1.2.3',
    ]) {
        assert.equal(isNumberCell(value), false, value);
    }

    const rows = [
        ['2026-11-01', '0.00', 'Cash', ''],
        ['2026-11-02', '-4.00', 'Card', '3'],
    ];

    assert.deepEqual(
        JSON.parse(
            JSON.stringify(
                numericColumns(rows, [
                    'Local date',
                    'Net (MYR)',
                    'Method',
                    'Count',
                ]),
            ),
        ),
        [false, true, false, true],
    );
    // A SKU or other identifier stays left-aligned even when every value is digits.
    assert.deepEqual(
        JSON.parse(
            JSON.stringify(
                numericColumns(
                    [
                        ['Paracetamol', '8888001', '2.000'],
                        ['Amoxicillin', '8888002', '0.000'],
                    ],
                    ['Item', 'SKU', 'Closing quantity'],
                ),
            ),
        ),
        [false, false, true],
    );
    // A money heading that merely mentions Panel is still a number column.
    assert.deepEqual(
        JSON.parse(
            JSON.stringify(
                numericColumns(
                    [['Synthetic Panel A', '12.00', 'KPI-0001']],
                    [
                        'Panel',
                        'Panel approved (invoice finalisation date, MYR)',
                        'Invoice number(s)',
                    ],
                ),
            ),
        ),
        [false, true, false],
    );
    // An empty table has no number columns.
    assert.deepEqual(
        JSON.parse(JSON.stringify(numericColumns([], ['A', 'B']))),
        [false, false],
    );
});

test('UX-9: dates read like the rest of KPOne and keep the original value for machines', () => {
    assert.deepEqual(JSON.parse(JSON.stringify(reportCell('2026-11-05'))), {
        text: '5 Nov 2026',
        datetime: '2026-11-05',
    });
    assert.deepEqual(
        JSON.parse(JSON.stringify(reportCell('2026-11-05 17:36:02'))),
        { text: '5 Nov 2026, 17:36', datetime: '2026-11-05T17:36' },
    );

    for (const value of [
        '2026-02-30',
        '2026-11-05T17:36:02',
        '30.30',
        'Paracetamol',
        'KP-00000012',
    ]) {
        assert.deepEqual(
            JSON.parse(JSON.stringify(reportCell(value))),
            { text: value, datetime: null },
            value,
        );
    }
});

test('UX-9: the page uses the standard table, aligns number columns and folds the basis text', () => {
    assert.match(page, /<OperationalTable/);
    assert.doesNotMatch(page, /<table\b/);
    assert.match(
        page,
        /numericColumns\(data\.value\.rows, data\.value\.columns\)/,
    );
    assert.match(page, /numeric\[j\] \? 'text-right' : ''/);
    assert.match(page, /row\.map\(reportCell\)/);
    assert.match(page, /columns\.map\(isIdentifierHeading\)/);
    assert.match(
        page,
        /'whitespace-nowrap':\s*unbroken\[j\] \|\| cells\[i\]\?\.\[j\]\?\.datetime/,
    );
    assert.match(page, /<time\s+v-if="cells\[i\]\?\.\[j\]\?\.datetime"/);
    assert.match(page, /empty-message="No matching rows\."/);
    // The basis text sits behind "How this report counts", closed by default.
    assert.match(
        page,
        /<details[^>]*data-testid="report-basis"[\s\S]*?How this report counts[\s\S]*?\{\{ basis \}\}[\s\S]*?<\/details>/,
    );
    assert.doesNotMatch(page, /<details[^>]*\bopen\b/);
});

test('UX-9: the first column stays in view while a wide report scrolls sideways', () => {
    const page = readFileSync(
        new URL(
            '../../resources/js/pages/Reports/BranchReport.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(page, /sticky left-0 z-10/);
    assert.match(page, /j === 0 \? stickyHead : ''/);
    assert.match(page, /j === 0 \? stickyCell : ''/);
    // Opaque backgrounds, so cells scrolling underneath never show through.
    assert.match(page, /stickyHead = `\$\{stickyBase\} bg-\[color-mix\(/);
    assert.match(page, /stickyCell = `\$\{stickyBase\} bg-card /);
});
