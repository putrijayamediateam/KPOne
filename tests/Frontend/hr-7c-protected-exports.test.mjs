import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PDPA 14 (owner, 2026-11-11, "go with b"): payroll files are AES-256 zips under a password typed at download time.
const tab = readFileSync(
    new URL(
        '../../resources/js/components/hr/payroll/ExportsTab.vue',
        import.meta.url,
    ),
    'utf8',
);

test('a download first asks for a password, sent once in the POST body and never kept', () => {
    assert.match(tab, /@click="ask\(run, 'bank', \$event\.currentTarget\)"/);
    assert.match(
        tab,
        /@click="ask\(run, 'accountant', \$event\.currentTarget\)"/,
    );
    assert.match(tab, /data-test="export-password"/);
    assert.match(tab, /file_password: secret\.password/);
    assert.match(tab, /file_password_confirmation: secret\.confirm/);
    assert.match(tab, /autocomplete="new-password"/);
    assert.match(tab, /const MIN_PASSWORD = 12;/);
    // Cleared when the dialog closes or the file is saved.
    assert.match(
        tab,
        /const forget = \(\) => \{\s+secret\.password = '';\s+secret\.confirm = '';/,
    );
    assert.doesNotMatch(tab, /localStorage|sessionStorage/);
});

test('the dialog and the tab say how to open and handle a protected file', () => {
    assert.match(tab, /7-Zip or WinZip on Windows/);
    assert.match(tab, /Type the password again/);
    assert.match(
        tab,
        /Many bank portals take only the CSV\. Unzip it before\s+uploading\./,
    );
    assert.doesNotMatch(tab, /Type it again/);
    assert.match(tab, /KPOne never keeps this password/);
    assert.match(tab, /send\s+the password by a different way from the file/);
    assert.match(tab, /Download protected file/);
});

test('the dialog cannot close while the file is being made, and focus goes back to its button', () => {
    assert.match(
        tab,
        /const dismiss = \(\) => \{\s+if \(!busy\.value\) \{\s+close\(\);/,
    );
    assert.match(
        tab,
        /@update:open="\(open: boolean\) => !open && dismiss\(\)"/,
    );
    assert.match(tab, /:disabled="!!busy"\s+@click="dismiss"\s+>Cancel</);
    assert.match(tab, /@close-auto-focus="returnFocus"/);
    assert.match(tab, /opener\.focus\(\);/);
});

test('focus goes back to the clicked button only while it is on the page, and a stalled download times out', () => {
    assert.match(tab, /ask\(run, 'bank', \$event\.currentTarget\)/);
    assert.match(tab, /ask\(run, 'accountant', \$event\.currentTarget\)/);
    assert.match(
        tab,
        /if \(opener\?\.isConnected\) \{\s+event\.preventDefault\(\);/,
    );
    assert.match(tab, /const TIMEOUT_MS = 60_000;/);
    assert.match(tab, /signal: controller\.signal/);
    assert.match(
        tab,
        /The file is taking too long\. Check the connection and try again\./,
    );
    assert.doesNotMatch(tab, /Nothing was downloaded/);
});

test('each message sits under its own field, and the close button hides while the file is made', () => {
    assert.match(
        tab,
        /Password \(at least\s+\{\{ MIN_PASSWORD \}\} characters\)/,
    );
    assert.doesNotMatch(tab, /letters,\s+numbers and symbols\)/);
    assert.match(
        tab,
        /id="export-file-password-error"\s+:message="secret\.passwordError"/,
    );
    assert.match(
        tab,
        /id="export-file-password-again-error"\s+:message="secret\.confirmError"/,
    );
    assert.match(tab, /aria-describedby="export-file-password-again-error"/);
    assert.match(
        tab,
        /secret\.confirmError = 'The two passwords do not match\.';/,
    );
    assert.match(tab, /:show-close-button="!busy"/);
});
