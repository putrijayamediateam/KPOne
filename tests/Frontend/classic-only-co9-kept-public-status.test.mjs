// Classic only, CO-9: the Classic half of v2-public-status, kept when the V2 page (never live) was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const classic = readFileSync(
    new URL(
        '../../resources/js/pages/PublicCheckIn/Status.vue',
        import.meta.url,
    ),
    'utf8',
);
const tight = (s) => s.replace(/\s+/g, '');
const script = (s) =>
    s.split('<script setup lang="ts">')[1].split('</script>')[0];
const template = (s) => s.split('</script>')[1];

test('Classic QR status keeps every Malay string and the privacy lines, word for word', () => {
    const strings = [
        'Status pendaftaran',
        'KPOne · Klinik Putrijaya',
        'Nombor queue anda',
        'Pesakit di hadapan anda:',
        'Anda seterusnya dalam giliran.',
        'Anggaran masa menunggu: kira-kira',
        'minit',
        'Anggaran berdasarkan purata terkini, bukan jaminan.',
        'Giliran anda! Sila ke kaunter farmasi.',
        'Giliran anda! Sila masuk ke bilik doktor.',
        'Kemajuan pendaftaran',
        '(selesai)',
        'Aktifkan bunyi pemberitahuan',
        'Semak status',
        'Cawangan lain',
        'Cawangan Klinik Putrijaya',
        'Mendapatkan lokasi…',
        'Cari cawangan terdekat',
        'Cawangan paling dekat:',
        'Cawangan anda',
        'Kira-kira',
        '(garis lurus)',
        'Buka peta',
        'menunggu',
        'Lokasi anda hanya digunakan pada peranti ini untuk mengira jarak dan tidak dihantar atau disimpan.',
        'Halaman ini tidak menyimpan maklumat anda dalam storan pelayar.',
    ];

    for (const text of strings) {
        assert.ok(
            tight(template(classic)).includes(tight(text)) ||
                tight(script(classic)).includes(tight(text)),
            `Classic lacks ${text}`,
        );
    }
});

test('Classic QR status shows identity only with a queue number', () => {
    const t = tight(template(classic));
    const from = t.indexOf('v-if="status.queueNumber"');
    const to = t.indexOf('{{status.queueNumber}}');

    assert.ok(from >= 0 && to > from, 'queue-number block');
    // The first name sits inside the queue-number block, behind its own v-if.
    const block = t.slice(from, to);
    assert.ok(block.includes('v-if="status.firstName"'));
    assert.ok(block.includes('{{status.firstName}}'));
});
