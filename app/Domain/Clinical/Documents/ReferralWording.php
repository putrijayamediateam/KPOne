<?php

namespace App\Domain\Clinical\Documents;

final class ReferralWording
{
    /** @return array<string, array<string, string>> */
    public static function revisions(): array
    {
        return [
            'en' => ['title' => 'Referral letter', 'recipient' => 'Referred to', 'reason' => 'Referral reason', 'urgency' => 'Urgency', 'history' => 'Relevant history', 'findings' => 'Relevant findings', 'management' => 'Management and referral question', 'diagnoses' => 'Selected diagnoses', 'medicines' => 'Selected prescribed medicines (not proof of supply)', 'allergies' => 'Selected allergy information', 'routine' => 'Routine', 'urgent' => 'Urgent', 'unknown' => 'Allergy status unknown', 'no_known_allergies' => 'No known allergies recorded', 'has_allergies' => 'Recorded allergies', 'consent' => 'Doctor acknowledged patient agreement to disclosure to the stated recipient. This does not prove compliance with written-consent or guardian procedures.'],
            'bm' => ['title' => 'Surat rujukan', 'recipient' => 'Dirujuk kepada', 'reason' => 'Sebab rujukan', 'urgency' => 'Keutamaan', 'history' => 'Sejarah berkaitan', 'findings' => 'Penemuan berkaitan', 'management' => 'Pengurusan dan persoalan rujukan', 'diagnoses' => 'Diagnosis terpilih', 'medicines' => 'Ubat dipreskripsi terpilih (bukan bukti pembekalan)', 'allergies' => 'Maklumat alahan terpilih', 'routine' => 'Biasa', 'urgent' => 'Segera', 'unknown' => 'Status alahan tidak diketahui', 'no_known_allergies' => 'Tiada alahan diketahui yang direkodkan', 'has_allergies' => 'Alahan yang direkodkan', 'consent' => 'Doktor mengakui persetujuan pesakit untuk pendedahan kepada penerima yang dinyatakan. Ini tidak membuktikan pematuhan prosedur persetujuan bertulis atau penjaga.'],
        ];
    }
}
