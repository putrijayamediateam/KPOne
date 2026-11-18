import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15d (owner, via B.E.R.N.I.S.S, 18 Nov 2026): a passed Locum candidate becomes a locum file with a sign-in request
// in one step, on both the Classic and the V2 Applications page. The account is still made on the Staff page.
const pages = {
    classic: readFileSync('resources/js/pages/HR/Applications.vue', 'utf8'),
    v2: readFileSync('resources/js/pages/HR/V2/Applications.vue', 'utf8'),
};
const composable = readFileSync(
    'resources/js/composables/hr/useApplicationsPage.ts',
    'utf8',
);

test('the row carries the locum file and its sign-in state', () => {
    assert.match(
        composable,
        /locumHire\?: \{ workerId: string; name: string; signIn: string \} \| null;/,
    );
});

for (const [name, page] of Object.entries(pages)) {
    test(`${name}: a passed Locum candidate offers one outline action, never a staff account`, () => {
        assert.match(page, /data-test="application-locum-make"/);
        // Daniel: the button asks first; the confirm sends the request.
        assert.match(
            page,
            /data-test="application-locum-make"\s+@click="askLocumFile"/,
        );
        assert.match(page, /data-test="application-locum-confirm"/);
        assert.match(
            page,
            /Make the locum file for \$\{viewing\?\.name \?\? ''\} and ask for a sign-in\?/,
        );
        assert.match(page, /Make file and ask/);
        assert.match(page, /@confirm="makeLocumFile"/);
        assert.doesNotMatch(page, /destructive\s+:processing="accountAction/);
        assert.match(page, /Make the locum file and ask for a\s+sign-in/);
        // Shown only for a Locum position, before a file exists and with no open or finished staff-account request.
        assert.match(
            page,
            /viewing\.type === 'locum' &&\s+!viewing\.locumHire &&\s+viewing\.accountRequest\?\.status !==\s+'open'/,
        );
        // The staff-account button is for other position types.
        assert.match(
            page,
            /viewing\.type !== 'locum' &&\s+viewing\.accountRequest/,
        );
    });

    test(`${name}: once made, the sheet names the file, its sign-in state and links to it`, () => {
        assert.match(page, /data-test="application-locum-hire"/);
        assert.match(page, /Locum file: \{\{ viewing\.locumHire\.name \}\}/);
        assert.match(page, /\{\{ viewing\.locumHire\.signIn \}\}/);
        assert.match(
            page,
            /:href="`\/hr\/records\/\$\{viewing\.locumHire\.workerId\}`"/,
        );
        // Daniel: a status line with a dot, and the link as its own outline button.
        assert.match(page, /data-test="application-locum-open"/);
        assert.match(page, /rounded-full bg-warning/);
        assert.match(
            page,
            /role="status"\s+data-test="application-locum-announce"/,
        );
        assert.match(page, /Open the locum file/);
        assert.match(
            page,
            /When they accept,\s+make\s+the locum file\. KPOne then asks the account\s+administrator for their sign-in\./,
        );
    });
}

test('the confirm posts once, keeps the sheet open on the file and announces it (a toast only when the row left the page)', () => {
    assert.match(
        composable,
        /`\/hr\/applications\/\$\{application\.id\}\/locum-file`/,
    );
    assert.match(composable, /viewing\.value = fresh \?\? null;/);
    assert.match(
        composable,
        /locumAnnounce\.value = `Locum file made\. \$\{fresh\.locumHire\.signIn\}\.`/,
    );
});

test('Suresh m1 to m3: a lost row still says so, a refusal refreshes the row, and the dialog shows only its own error', () => {
    assert.match(composable, /Find the candidate under Passed to see it\./);
    assert.match(
        composable,
        /onError: \(failed\) => \{[\s\S]*?viewing\.value = fresh;/,
    );
    assert.match(
        composable,
        /const askLocumFile = \(\) => \{\s+locumError\.value = '';/,
    );

    for (const page of Object.values(pages)) {
        assert.doesNotMatch(page, /errors\.application \?\? errors\.records/);
    }
});
