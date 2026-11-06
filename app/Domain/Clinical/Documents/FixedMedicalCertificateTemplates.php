<?php

namespace App\Domain\Clinical\Documents;

use Illuminate\Support\Facades\DB;

/** Patient-free fixed revisions only. DOC-02a deliberately has no issuance renderer. */
final class FixedMedicalCertificateTemplates
{
    /**
     * The revision a new certificate uses in each language: the newest one (MC slice 1 adds revision 2). Older
     * revisions stay in the table, immutable, for the certificates issued with them.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(): array
    {
        $current = DB::table('mc_template_revisions')->selectRaw('language, max(revision) as revision')->groupBy('language');

        return array_values(DB::table('mc_template_revisions as t')
            ->joinSub($current, 'current', fn ($join) => $join->on('current.language', '=', 't.language')->on('current.revision', '=', 't.revision'))
            ->orderBy('t.language')->get(['t.*'])->map(fn (object $row): array => [
            'language' => $row->language, 'revision' => $row->revision, 'title' => $row->title,
            'wording' => $row->wording, 'mergeFields' => json_decode($row->merge_fields, true, flags: JSON_THROW_ON_ERROR),
            'rendererVersion' => $row->renderer_version, 'productionApproved' => $row->production_approved,
        ])->all());
    }
}
