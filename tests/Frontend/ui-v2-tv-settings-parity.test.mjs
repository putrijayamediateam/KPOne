import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const classic = readFileSync(
    'resources/js/pages/QueueDisplay/Settings.vue',
    'utf8',
);
const v2 = readFileSync(
    'resources/js/pages/QueueDisplay/V2/Settings.vue',
    'utf8',
);
const config = readFileSync('config/v2-pages.php', 'utf8');
const script = (s) => s.slice(s.indexOf('<script'), s.indexOf('</script>'));
const norm = (s) => s.replace(/\s+/g, '');

test('the V2 TV settings page is listed for the V2 gate', () => {
    assert.match(config, /'QueueDisplay\/Settings'/);
});

test('V2 uses the same endpoints as Classic and no others', () => {
    const urls = (s) =>
        [
            ...script(s).matchAll(
                /`(\/queue-display-settings[^`]*)`|'(\/queue-display-settings[^']*)'/g,
            ),
        ]
            .map((m) =>
                (m[1] ?? m[2]).replace(
                    /\$\{(posterId|removing\.value|editingId\.value|editing\.value\.id)\}/,
                    '${id}',
                ),
            )
            .sort();
    assert.deepEqual(urls(v2), urls(classic));
});

test('the versioned payload keys are the Classic ones', () => {
    for (const key of [
        'ticker_text',
        'youtube_url',
        'poster_seconds',
        'call_display_mode',
        'lock_version',
        'sort_order',
        'kind',
        'poster',
    ]) {
        assert.ok(script(v2).includes(key), key);
    }

    assert.ok(
        norm(script(v2)).includes(
            norm('ticker_text: data.ticker_text.trim() || null'),
        ),
    );
    assert.ok(
        norm(script(v2)).includes(
            norm('youtube_url: data.youtube_url.trim() || null'),
        ),
    );
    assert.ok(
        norm(script(v2)).includes(norm('{ lock_version: room.lockVersion }')),
    );
    assert.ok(
        norm(script(v2)).includes(
            norm('{ lock_version: props.settings.lockVersion }'),
        ),
    );
});

test('call mode is an option group with its privacy line and a warning for full names', () => {
    assert.match(v2, /<V2OptionGroup[\s\S]*?label="Call patients by"/);
    assert.match(v2, /data-testid="call-display-mode-help"/);
    assert.match(v2, /call_display_mode === 'name'/);
    assert.match(v2, /role="note"/);

    for (const text of [
        'No patient name is shown or spoken',
        'Everyone in the waiting room sees and hears part of the name',
        'least private choice',
    ]) {
        assert.ok(v2.includes(text), text);
    }
});

test('one versioned Save, cobalt only when changed, with an unsaved-changes guard', () => {
    assert.match(v2, /settingsForm\.isDirty \? 'default' : 'outline'/);
    assert.match(v2, /<V2StickyBar>/);
    assert.match(v2, /router\.on\('before'/);
    assert.match(v2, /beforeunload/);
    assert.match(v2, /Leave without saving/);
});

test('posters use FileChooseButton and Remove poster confirms in a V2Dialog; the TV link opens a new tab', () => {
    assert.match(v2, /<FileChooseButton/);
    assert.match(v2, /Remove poster\?/);
    assert.match(v2, /tone="danger"/);
    assert.match(v2, /Open TV screen/);
    assert.match(v2, /target="_blank"/);
    assert.match(v2, /opens in a new tab/);
});

test('Classic is untouched and no Classic dialog or table is used in V2', () => {
    assert.ok(!v2.includes('PageHeader'));
    assert.ok(!v2.includes('StatusBadge'));
    assert.ok(!/<table/.test(v2));
});

test('only Save screen content is filled on the page; Leave is never the filled button', () => {
    assert.match(v2, /type="submit"\s+variant="outline"[\s\S]{0,300}?Add room/);
    assert.match(
        v2,
        /type="submit"\s+variant="outline"[\s\S]{0,300}?Upload poster/,
    );
    assert.match(v2, /confirm-label="Save and leave"/);
    assert.match(v2, /variant="outline"[\s\S]{0,160}?Leave without saving/);
});

test('moving the saved mode to short or full names asks first; room names show in full with a counter', () => {
    assert.match(v2, /needsPrivacyConfirm/);
    assert.match(v2, /Change who sees patient names\?/);
    assert.match(v2, /Everyone in the waiting room will see and hear/);
    assert.match(v2, /data-primary/);
    assert.match(v2, /\/ 60/);
    assert.match(v2, /Deactivate \$\{deactivating\.name\}\?/);
});

test('Save and leave goes through the privacy confirm before it saves and leaves', () => {
    const body = v2.slice(v2.indexOf('const saveAndLeave'));
    const fn = body.slice(0, body.indexOf('const warnUnload'));

    assert.match(
        fn,
        /if \(needsPrivacyConfirm\.value\) \{\s*privacyOpen\.value = true;\s*return;\s*\}/,
    );
    assert.match(fn, /leaveTarget = pendingUrl/);
    assert.match(v2, /@confirm="performSave\(leaveAfterSave\)"/);
    assert.match(v2, /if \(!value\) \{\s*leaveTarget = null;/);
});
