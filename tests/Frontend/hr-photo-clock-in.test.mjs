import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-PHOTO (owner, 2026-11-12: option 2 "Camera app", decision B and "grace yes").
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dialog = read(
    'resources/js/components/hr/attendance/ClockPhotoDialog.vue',
);
const thumb = read('resources/js/components/hr/attendance/ClockPhotoThumb.vue');
const composable = read('resources/js/composables/useClockPhoto.ts');
const attendance = read('resources/js/pages/HR/Attendance.vue');

for (const [name, source] of [
    ['ClockPhotoDialog.vue', dialog],
    ['ClockPhotoThumb.vue', thumb],
]) {
    test(`${name} compiles`, () => {
        assert.deepEqual(
            compileTemplate({
                id: name,
                source: parse(source).descriptor.template.content,
                filename: name,
            }).errors,
            [],
        );
    });
}

test('the camera is live only: no file picker, no gallery, no capture attribute', () => {
    assert.match(dialog, /getUserMedia\(/);
    assert.doesNotMatch(dialog, /type="file"|capture=|<input/);
    assert.doesNotMatch(composable, /localStorage|sessionStorage|indexedDB/);
});

test('buttons are named, 44 px, and the shutter is 84 px on phones and 72 px from 640 px', () => {
    assert.match(dialog, /aria-label="Close"/);
    assert.match(dialog, /aria-label="Switch camera"/);
    assert.match(dialog, /aria-label="Take photo"/);
    assert.match(dialog, /size-\[84px\][^"]*sm:size-\[72px\]/);
    assert.match(dialog, /size-11/);
    assert.match(dialog, /Centre your face in the oval/);
    assert.match(dialog, />\s*Retake\s*</);
    assert.match(dialog, /'Use photo'/);
});

test('the camera refused says what to do, and there is no clock-in without a photo', () => {
    assert.match(
        composable,
        /KPOne needs your camera to clock in\. Allow the camera, or ask HR to add your time as a correction\./,
    );
    assert.match(dialog, /CAMERA_REFUSED/);
    assert.match(composable, /body\.append\('photo', photo, 'photo\.jpg'\)/);
    assert.match(composable, /'\/hr\/attendance\/capture'/);
});

test('the confirmation shows the time, the branch and a chip with a dot and words', () => {
    assert.match(
        dialog,
        /state\.result\.time \}\} at \{\{ state\.result\.branch/,
    );
    assert.match(dialog, /rounded-full bg-current/);
    assert.match(dialog, /state\.result\.chip\.label/);
    assert.match(dialog, />\s*Done\s*</);
    assert.match(dialog, /motion-safe:/);
});

test("someone else's photo stays hidden until Show; your own shows; a correction says so", () => {
    assert.match(thumb, /photo\.needsShow && !shown/);
    assert.match(thumb, /Show photo/);
    assert.match(thumb, /photo\.label/);
    assert.match(attendance, /<ClockPhotoThumb/);
    assert.match(attendance, /useClockPhoto\(\)/);
});

test('the dialog is mounted once in each layout', () => {
    for (const layout of [
        'AdminLayout.vue',
        'ClinicLayout.vue',
        'SpacePassThroughLayout.vue',
    ]) {
        const source = read(`resources/js/layouts/${layout}`);
        assert.equal(
            (source.match(/<ClockPhotoDialog \/>/g) ?? []).length,
            1,
            layout,
        );
    }
});
