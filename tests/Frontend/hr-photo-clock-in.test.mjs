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
        'V2Layout.vue',
    ]) {
        const source = read(`resources/js/layouts/${layout}`);
        assert.equal(
            (source.match(/<ClockPhotoDialog \/>/g) ?? []).length,
            1,
            layout,
        );
    }
});

test('the notice version matches the server and comes before the camera', () => {
    const notice = read(
        'resources/js/components/hr/attendance/ClockPhotoNotice.vue',
    );
    const service = read(
        'app/Domain/HR/Attendance/Photos/ClockPhotoService.php',
    );
    const version = notice.match(/CLOCK_PHOTO_NOTICE_VERSION = '([^']+)'/)?.[1];
    assert.ok(version);
    assert.match(service, new RegExp(`NOTICE_VERSION = '${version}'`));
    assert.match(notice, /About clock-in photos/);
    assert.match(dialog, /state\.step = 'notice'/);
    assert.match(dialog, /markNoticeSeen\(CLOCK_PHOTO_NOTICE_VERSION\)/);
});

test('the clock shortcut opens the camera, or the Attendance page where location is checked', () => {
    const shortcut = read('resources/js/composables/useClockShortcut.ts');
    assert.match(shortcut, /useClockPhoto\(\)/);
    assert.match(shortcut, /open\(clock\.state === 'in' \? 'out' : 'in'/);
    assert.match(
        shortcut,
        /needsAttendancePage[\s\S]*router\.visit\(ATTENDANCE\)/,
    );
});

test('once a photo is shown only Hide photo remains, and phone cards name each photo In or Out', () => {
    const t = parse(thumb).descriptor.template.content;
    const show = t.indexOf('data-test="clock-photo-show"');
    const elseBlock = t.indexOf('<template v-else>');
    const hide = t.indexOf('data-test="clock-photo-hide"');
    assert.ok(show > -1 && elseBlock > show && hide > elseBlock);
    assert.match(
        t,
        /v-if="photo\.needsShow && !shown"[\s\S]*data-test="clock-photo-show"/,
    );
    // Show and Hide live in opposite branches, so they never appear together for one photo.
    assert.equal(
        (t.slice(elseBlock).match(/clock-photo-show/g) ?? []).length,
        0,
    );
    assert.match(
        attendance,
        /data-test="review-card-photos"[\s\S]*?>In<\/span[\s\S]*?r\.photos\.in[\s\S]*?>Out<\/span[\s\S]*?r\.photos\.out/,
    );
});

// Daniel's look (2026-11-12): the notice says why and what Not now does; it can be reopened; Show is recorded.
const notice = read(
    'resources/js/components/hr/attendance/ClockPhotoNotice.vue',
);

test('the notice says why, that faces are not compared, what Not now does, and that retention is to confirm', () => {
    const text = notice.replace(/\s+/g, ' ');
    assert.match(
        text,
        /It is used only to check that you were the one who clocked in\./,
    );
    assert.match(text, /KPOne does not identify faces or compare photos\./);
    assert.match(
        text,
        /If you choose Not now, nothing is recorded and you are not clocked in or out\. Only a live photo counts\. If you can't use the camera, ask HR to add your time as a correction\./,
    );
    assert.match(text, /\(to confirm in the PDPA review\)/);
    assert.match(notice, /mode === 'first-use'/);
    assert.match(notice, /data-test="clock-photo-notice-close"/);
});

test('My attendance reopens the notice, and Review says that Show is recorded', () => {
    assert.match(
        attendance,
        /data-test="clock-photo-about"[\s\S]*?About clock-in photos/,
    );
    assert.match(attendance, /<ClockPhotoNotice mode="info"/);
    assert.match(
        attendance,
        /data-test="review-photo-recorded"[\s\S]*?Showing a photo is recorded\./,
    );
});

test('Retake is outline and Use photo is filled', () => {
    assert.match(
        dialog,
        /border-2 border-white\/70 bg-transparent[^"]*"\s+data-test="clock-photo-retake"/,
    );
    assert.match(dialog, /bg-\[#6ba6ff\][^"]*"\s+data-test="clock-photo-use"/);
});

test('changing the day, branch or tab is a fresh visit, so a shown photo hides again', () => {
    const visit = attendance.match(/const visit = [\s\S]*?\);\n/)[0];
    assert.match(visit, /router\.get\(/);
    assert.doesNotMatch(visit, /preserveState/);
});

test('unmounting the dialog also resets the shared clock photo flow', () => {
    const unmount = dialog.slice(dialog.indexOf('onBeforeUnmount(() => {'));
    assert.match(
        unmount.slice(0, unmount.indexOf('});')),
        /resetClockPhoto\(\);/,
    );
});
