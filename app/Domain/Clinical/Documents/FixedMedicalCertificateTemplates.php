<?php

namespace App\Domain\Clinical\Documents;

use Illuminate\Support\Facades\DB;

/** Patient-free fixed revisions only. DOC-02a deliberately has no issuance renderer. */
final class FixedMedicalCertificateTemplates
{
    /** @return list<array<string, mixed>> */
    public function revisions(): array
    {
        return array_values(DB::table('mc_template_revisions')->orderBy('language')->orderBy('revision')->get()->map(fn (object $row): array => [
            'language' => $row->language, 'revision' => $row->revision, 'title' => $row->title,
            'wording' => $row->wording, 'mergeFields' => json_decode($row->merge_fields, true, flags: JSON_THROW_ON_ERROR),
            'rendererVersion' => $row->renderer_version, 'productionApproved' => $row->production_approved,
        ])->all());
    }
}
