// Classic only, CO-9: the Classic half of v2-public-show, kept when the V2 page (never live) was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const classic = readFileSync(
    new URL('../../resources/js/pages/PublicCheckIn/Show.vue', import.meta.url),
    'utf8',
);
const tight = (s) => s.replace(/\s+/g, '');
const template = (s) => s.split('</script>')[1];

test('Classic QR check-in form keeps every Malay string of its template', () => {
    const strings = [
        'Daftar untuk lawatan anda',
        'Mula Daftar',
        'Baca notis privasi',
        'Jika anda mengalami sesak nafas teruk',
        'Siapa yang mengisi borang?',
        'Saya pesakit',
        'Saya penjaga',
        'Maklumat pesakit',
        'Nama penuh *',
        'Tarikh lahir *',
        'Jenis bayaran *',
        'Semak persetujuan',
        'Semak maklumat anda',
        'Persetujuan & hantar',
        'Hantar Maklumat',
        'Menghantar…',
        'Notis privasi — draf UAT',
        'Sesi pendaftaran akan tamat tidak lama lagi.',
        'Masa sesi mungkin telah tamat.',
        'Beli ubat sahaja',
        'Jumpa doktor',
        'Saya mengesahkan bahawa saya diberi kuasa',
        'Kembali',
        'Ubah',
    ];

    for (const text of strings) {
        assert.ok(
            tight(template(classic)).includes(tight(text)),
            `Classic lacks ${text}`,
        );
    }
});
