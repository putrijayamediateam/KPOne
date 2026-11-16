import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Clock photo fix (owner, 16 Nov 2026): the pass is taken at the shutter; a pass that ran out sends the person back to
// the camera with one line; "Use photo" is never pressable after a refusal; the preview title names the press.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dialog = read(
    'resources/js/components/hr/attendance/ClockPhotoDialog.vue',
);
const flow = read('resources/js/composables/useClockPhoto.ts');
const take = dialog.slice(
    dialog.indexOf('const take = async'),
    dialog.indexOf('const retake = async'),
);
const usePhoto = dialog.slice(
    dialog.indexOf('const usePhoto = async'),
    dialog.indexOf('const close = ()'),
);

test('a fresh pass is asked for at the shutter, after the photo is drawn and before the preview', () => {
    assert.match(
        take,
        /canvas\.toBlob[\s\S]*?state\.token = await takeToken\(state\.kind\);[\s\S]*?state\.step = 'review';/,
    );
    assert.match(take, /if \(e instanceof NoticeNeeded\)/);
    assert.match(dialog, /:disabled="shooting"/);
});

test('a pass that ran out throws the photo away and goes back to the camera with one line', () => {
    assert.match(
        flow,
        /export const TOOK_TOO_LONG =\s*'That took too long, so the photo was not used\. Take it again\.';/,
    );
    assert.match(
        flow,
        /'The camera was open too long\. Take the photo again\.',\s*'Open the camera again and take a new photo\.',/,
    );
    assert.match(
        usePhoto,
        /if \(PASS_REFUSALS\.includes\(message\)\) \{\s*[\s\S]*?clearPreview\(\);[\s\S]*?await startCamera\(\);\s*cameraNote\.value = TOOK_TOO_LONG;/,
    );
    assert.match(
        dialog,
        /v-if="cameraNote && state\.step === 'camera'"[\s\S]*?role="alert"\s+data-test="clock-photo-camera-note"/,
    );
});

test('after any other refusal "Use photo" is off and says why, and Retake is the filled action', () => {
    assert.match(usePhoto, /state\.error = message;\s*refused\.value = true;/);
    assert.match(usePhoto, /state\.step !== 'review' \|\| refused\.value/);
    assert.match(dialog, /:disabled="state\.step === 'sending' \|\| refused"/);
    assert.match(
        dialog,
        /:aria-describedby="\s*refused \? 'clock-photo-error' : undefined\s*"/,
    );
    assert.match(
        dialog,
        /refused\s*\?\s*'bg-\[#6ba6ff\] text-\[#0b1a33\]'\s*:\s*'border-2 border-white\/70 bg-transparent'[\s\S]*?data-test="clock-photo-retake"/,
    );
});

test('the preview title says which press it is', () => {
    assert.match(dialog, /\? `\$\{title\} · Check your photo`/);
    assert.match(
        dialog,
        /const title = computed\(\(\) => \(state\.kind === 'in' \? 'Clock in' : 'Clock out'\)\);/,
    );
});
