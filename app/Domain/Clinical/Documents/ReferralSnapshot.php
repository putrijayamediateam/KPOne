<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReferralSnapshot
{
    public const TEXT_LIMITS = ['institution' => 200, 'department' => 200, 'clinician' => 200, 'reason' => 1000, 'history' => 4000, 'findings' => 4000, 'management' => 4000];

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Branch $branch, array $source, array $input, string $number, string $at): array
    {
        $template = DB::table('referral_template_revisions')->where('language', $input['language'])->where('revision', 1)->sole();
        $w = json_decode($template->wording, true, flags: JSON_THROW_ON_ERROR);
        $sections = [[$w['recipient'], implode("\n", array_filter([$input['institution'], $input['department'] ?? '', $input['clinician'] ?? '']))], [$w['urgency'], $w[$input['urgency']]], [$w['reason'], $input['reason']]];
        foreach (['history', 'findings', 'management'] as $key) {
            if (($input[$key] ?? '') !== '') {
                $sections[] = [$w[$key], $input[$key]];
            }
        }
        $selected = [];
        foreach (['diagnoses', 'medicines', 'allergies'] as $key) {
            $ids = array_map('intval', $input[$key] ?? []);
            $chosen = array_values(array_filter($source[$key], fn (array $row): bool => in_array($row['id'], $ids, true)));
            if (count($ids) !== count($chosen)) {
                throw ValidationException::withMessages([$key => 'Choose only current saved source records. Reload and review.']);
            }
            if ($key === 'allergies' && ! $input['include_allergies'] && $ids !== []) {
                throw ValidationException::withMessages(['allergies' => 'Enable the allergy section before selecting records.']);
            }
            $selected[$key] = $chosen;
            $text = implode("\n", array_column($chosen, 'text'));
            if ($key === 'allergies' && $input['include_allergies']) {
                $text = $w[$source['allergyStatus']].($text !== '' ? "\n".$text : '');
            }
            if ($text !== '') {
                $sections[] = [$w[$key], $text];
            }
        }
        $sections[] = ['Consent / Persetujuan', $w['consent']];
        $snapshot = ['schemaVersion' => 1, 'rendererVersion' => $template->renderer_version, 'templateId' => $template->id, 'templateRevision' => 1, 'templateWording' => $w, 'productionApproved' => false, 'language' => $input['language'], 'title' => $w['title'],
            'number' => $number, 'issuedAtUtc' => $at, 'issueDate' => CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('d/m/Y'), 'timezone' => $branch->timezone, 'patient' => $source['patient'], 'visit' => $source['visit'], 'examDate' => $source['examDate'], 'doctor' => $source['doctor'], 'letterhead' => $source['letterhead'],
            'recipient' => array_intersect_key($input, array_flip(['institution', 'department', 'clinician'])), 'consent' => true, 'selected' => $selected, 'sourceToken' => $source['token'], 'evidence' => 'pre-signature', 'pages' => app(ReferralPagination::class)->pages($sections)];
        $snapshot['html'] = view('referrals.content', ['snapshot' => $snapshot])->render();

        return $snapshot;
    }
}
