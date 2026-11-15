<?php

namespace App\Domain\Clinical\Documents;

final class AttendanceWording
{
    /** @return array<string, array<string, string>> */
    public static function revisions(): array
    {
        return [
            'en' => ['title' => 'Attendance confirmation', 'recipient' => 'Intended recipient', 'statement' => 'This is to confirm that :patient attended :clinic on :date.', 'limitation' => 'This document confirms attendance only. It does not certify illness, fitness or leave. It is not a medical certificate.', 'consent' => 'Patient agreement to disclosure to the intended recipient was acknowledged by the issuing doctor.'],
            'bm' => ['title' => 'Pengesahan kehadiran', 'recipient' => 'Penerima yang dimaksudkan', 'statement' => 'Dengan ini disahkan bahawa :patient telah hadir di :clinic pada :date.', 'limitation' => 'Dokumen ini mengesahkan kehadiran sahaja. Dokumen ini tidak mengesahkan penyakit, tahap kecergasan atau kelayakan cuti. Dokumen ini bukan sijil sakit.', 'consent' => 'Persetujuan pesakit terhadap pendedahan kepada penerima yang dimaksudkan telah diakui oleh doktor yang mengeluarkan dokumen.'],
        ];
    }
}
