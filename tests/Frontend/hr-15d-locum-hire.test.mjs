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
        assert.match(page, /\/hr\/applications\/\$\{viewing\.id\}\/locum-file/);
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
        assert.match(page, /Open the locum file/);
        assert.match(
            page,
            /When they accept,\s+make\s+the locum file\. KPOne then asks the account\s+administrator for their sign-in\./,
        );
    });
}
