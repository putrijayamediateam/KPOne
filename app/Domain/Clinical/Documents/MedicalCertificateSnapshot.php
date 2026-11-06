<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class MedicalCertificateSnapshot
{
    public function __construct(private BranchLetterheadAssembler $letterheads) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Branch $branch, Visit $visit, ClinicalEncounter $encounter, ClinicianProfessionalProfile $credential, array $input, string $number, string $issuedAt): array
    {
        $patient = Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $visit->organisation_id)->lockForUpdate()->firstOrFail();
        $identity = $patient->identifiers()->whereNull('retired_at')->orderBy('id')->lockForUpdate()->first();
        if (! $identity) {
            throw ValidationException::withMessages(['certificate' => 'An active patient identity document is required.']);
        }
        // New certificates use the newest fixed revision for the language; issued ones keep their frozen snapshot.
        $template = DB::table('mc_template_revisions')->where('language', $input['language'])->orderByDesc('revision')->firstOrFail();
        $head = $this->letterheads->assemble($branch, function (string $publicId) use ($branch): string {
            $asset = BusinessDetailAsset::query()->where('organisation_id', $branch->organisation_id)->where('public_id', $publicId)->whereNull('retired_at')->lockForUpdate()->sole();
            $bytes = Storage::disk('local')->get($asset->path);
            if ($bytes === null || ! hash_equals($asset->sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['certificate' => 'The letterhead logo is unavailable. Restore it before issuing.']);
            }

            return 'data:'.$asset->mime.';base64,'.base64_encode($bytes);
        })->toArray();
        // Only document-relevant details; no registration/tax fields, arbitrary footer or private paths.
        $letterhead = ['details' => $head['details'], 'logo' => $head['logo'], 'organisationVersion' => $head['organisationVersion'], 'branchVersion' => $head['branchVersion']];
        $start = CarbonImmutable::parse($input['start_date']);
        $end = CarbonImmutable::parse($input['end_date']);
        $days = (int) $start->diffInDays($end) + 1;
        $fields = ['patient_name' => $patient->full_name, 'identification' => $identity->normalized_value,
            'examination_date' => $encounter->started_at->setTimezone($branch->timezone)->format('d/m/Y'), 'issue_date' => now()->setTimezone($branch->timezone)->format('d/m/Y'),
            'start_date' => $start->format('d/m/Y'), 'end_date' => $end->format('d/m/Y'), 'days_number' => (string) $days,
            'days_words' => $this->daysWords($days, $input['language']), 'doctor_name' => $credential->professional_name, 'mmc_number' => $credential->mmc_number, 'mc_number' => $number,
            'issue_date_iso' => now()->setTimezone($branch->timezone)->format('Y-m-d')];
        $wording = $template->wording;
        foreach ($fields as $key => $value) {
            $wording = str_replace('{{'.$key.'}}', $value, $wording);
        }
        $snapshot = ['schemaVersion' => 1, 'rendererVersion' => $template->renderer_version, 'templateId' => $template->id, 'templateRevision' => (int) $template->revision,
            'productionApproved' => false, 'language' => $input['language'], 'title' => $template->title, 'wording' => $wording, 'templateWording' => $template->wording,
            'letterhead' => $letterhead, 'fields' => $fields, 'reason' => $input['reason'] ?? null, 'reasonConsent' => (bool) ($input['reason_consent'] ?? false),
            'issuedAtUtc' => $issuedAt, 'credentialVersion' => $credential->lock_version, 'evidence' => 'pre-signature',
            'wordingParts' => $this->wordingParts($template->wording, $fields)];
        $view = match ($template->renderer_version) {
            'mc-a4-v1' => 'medical-certificates.content',
            'mc-a4-v2' => 'medical-certificates.content-v2',
            default => throw new \LogicException('Unknown MC renderer'),
        };
        $snapshot['html'] = view($view, ['snapshot' => $snapshot])->render();

        return $snapshot;
    }

    /** Fields the v2 layout prints bold, or bold and underlined, as on the clinic's paper certificate. */
    private const EMPHASIS = ['patient_name' => 'strong', 'identification' => 'strong', 'days_number' => 'strong-u', 'start_date' => 'strong-u', 'end_date' => 'strong-u'];

    /**
     * The template wording as plain text pieces and merged values, so the renderer can emphasise values without
     * any HTML in the stored wording. Every piece is escaped by Blade.
     *
     * @param  array<string, string>  $fields
     * @return list<array{text: string, style: ?string}>
     */
    private function wordingParts(string $wording, array $fields): array
    {
        $parts = [];
        foreach (preg_split('/({{[a-z_]+}})/', $wording, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (preg_match('/^{{([a-z_]+)}}$/', $piece, $match) === 1 && array_key_exists($match[1], $fields)) {
                $parts[] = ['text' => $fields[$match[1]], 'style' => self::EMPHASIS[$match[1]] ?? null];
            } else {
                $parts[] = ['text' => $piece, 'style' => null];
            }
        }

        return $parts;
    }

    private function daysWords(int $days, string $language): string
    {
        $en = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $bm = ['sifar', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'lapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($language === 'en') {
            return $en[$days] ?? ($days === 30 ? 'thirty' : 'twenty'.($days === 20 ? '' : '-'.($en[$days - 20] ?? throw new \LogicException('Invalid MC duration'))));
        }
        if ($days <= 11) {
            return $bm[$days];
        }
        if ($days < 20) {
            return $bm[$days - 10].' belas';
        }

        return $bm[intdiv($days, 10)].' puluh'.($days % 10 === 0 ? '' : ' '.$bm[$days % 10]);
    }
}
