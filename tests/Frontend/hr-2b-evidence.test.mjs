import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-2b leave evidence files (owner 2026-11-06; Daniel's early look folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/Leave.vue');
const template = parse(source).descriptor.template.content;

test('the page still compiles with the evidence panel', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-leave',
            source: template,
            filename: 'Leave.vue',
        }).errors,
        [],
    );
    assert.match(template, /data-test="evidence-panel"/);
});

test('files are chosen with a labelled outline button, never the bare file control or the camera', () => {
    assert.match(template, /accept="image\/jpeg,image\/png,application\/pdf"/);
    assert.doesNotMatch(template, /\bcapture\b/);
    assert.match(template, />Add a photo or PDF<\/Button/);
    assert.match(template, /type="file"\s+class="sr-only"/);
    assert.match(
        template,
        /JPG, PNG or PDF, up to 5 MB\. Take a clear photo of\s+the whole MC\./,
    );
    assert.match(template, /'Uploading…'/);
});

test('View and Add file are named for their request, and photo alt text carries no file content', () => {
    assert.match(template, /files for \{\{ r\.type \}\},/);
    assert.match(template, /to \{\{ r\.type \}\}, \{\{ r\.dates \}\}/);
    assert.match(
        source,
        /`Photo \$\{photos\.indexOf\(file\) \+ 1\} of \$\{photos\.length\}, added by \$\{file\.addedBy\} on \$\{file\.addedOn\}`/,
    );
    assert.doesNotMatch(source, /f\.name \}\}.*alt|alt=.*\.name/);
});

test('every Remove goes through a destructive confirm, with a reason only for managers', () => {
    assert.match(
        template,
        /variant="ghost"[\s\S]{0,300}@click="openRemoval\(f\)"/,
    );
    assert.match(
        template,
        /title="Remove this file\?"[\s\S]{0,300}destructive/,
    );
    assert.match(
        template,
        /<div v-if="removalNeedsReason" class="grid gap-2">/,
    );
});

test('retention is explained, "No file yet" is never red and photos sit on a bordered neutral surface', () => {
    assert.match(
        template,
        /Files are deleted after this\. Requests and their\s+history stay\./,
    );
    assert.match(template, /Files are kept for/);
    assert.match(source, /'No file yet'/);
    assert.match(template, /class="text-muted-foreground"\s*>Evidence:/);
    assert.match(
        template,
        /class="overflow-hidden rounded-lg border bg-muted p-2"/,
    );
    assert.doesNotMatch(
        template,
        /\b(bg|text|border)-(white|black|slate|gray|zinc|red|amber|pink|rose|green|emerald|yellow|blue)-\d*/,
    );
});

test('a PDF is offered as a download only, never shown inside the page', () => {
    assert.match(template, /Download PDF/);
    assert.doesNotMatch(template, /<(iframe|embed|object)\b/);
});
