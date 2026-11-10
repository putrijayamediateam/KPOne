<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-11a page props for /clinic-compliance: Overview, Licences and Settings (licence types) for holders of
 * `compliance.licences.manage.organisation`. Read only. Statuses are worked out here, when the page opens, and never
 * stored. Later slices add the Fridge log and Audits tabs.
 */
final class CompliancePageService
{
    public function __construct(private ComplianceSupport $support) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canLicences = $actor->can(CompliancePermissions::LICENCES_MANAGE);
        $tabs = $canLicences ? ['overview', 'licences', 'settings'] : [];
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'overview');
        $orgId = $actor->organisation_id;
        $today = $this->support->today($orgId);
        $types = $canLicences ? $this->types($orgId) : [];
        $rows = $canLicences ? $this->rows($orgId, $types, $today) : [];

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'today' => $today,
            'canManageLicences' => $canLicences,
            'setUp' => collect($types)->where('isActive', true)->isNotEmpty(),
            'options' => [
                'branches' => DB::table('branches')->where('organisation_id', $orgId)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($b): array => ['id' => (int) $b->id, 'name' => (string) $b->name])->all(),
                'correctionReasons' => collect(LicenceService::CORRECTION_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'suggestedWarnDays' => LicenceService::SUGGESTED_WARN_DAYS,
                'suggestedTypes' => ['Clinic registration', 'X-ray licence', 'Clinical waste licence', 'Calibration certificate'],
            ],
            'overview' => $canLicences && $tab === 'overview' ? $this->overview($types, $rows) : null,
            'licences' => $canLicences && $tab === 'licences' ? ['rows' => $rows, 'types' => collect($types)->where('isActive', true)->values()->all()] : null,
            'settings' => $canLicences && $tab === 'settings' ? ['types' => $types] : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function types(int $orgId): array
    {
        return DB::table('compliance_licence_types')->where('organisation_id', $orgId)->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn ($t): array => ['id' => (string) $t->public_id, 'name' => (string) $t->name, 'expires' => (bool) $t->expires,
                'warnFromDays' => $t->warn_from_days === null ? null : (int) $t->warn_from_days, 'isActive' => (bool) $t->is_active, 'lockVersion' => (int) $t->lock_version])->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $types
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $orgId, array $types, string $today): array
    {
        $typeById = collect($types)->keyBy('id');
        $series = DB::table('compliance_licences as l')->join('compliance_licence_types as t', 't.id', '=', 'l.type_id')->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->where('l.organisation_id', $orgId)->orderBy('t.name')->orderBy('b.name')
            ->get(['l.id', 'l.public_id', 'l.lock_version', 'l.branch_id', 'b.name as branch_name', 't.public_id as type_public_id']);
        if ($series->isEmpty()) {
            return [];
        }
        $versions = DB::table('compliance_licence_versions as v')->leftJoin('compliance_licence_corrections as c', 'c.version_id', '=', 'v.id')
            ->whereIn('v.licence_id', $series->pluck('id'))->orderByDesc('v.version')
            ->get(['v.id', 'v.public_id', 'v.licence_id', 'v.version', 'v.reference_encrypted', 'v.issued_on', 'v.expires_on', 'v.note_encrypted', 'v.entered_by_user_id', 'v.created_at',
                'c.reason_code as error_reason'])->groupBy('licence_id');
        $files = DB::table('compliance_licence_files')->whereIn('version_id', $versions->flatten(1)->pluck('id'))->orderBy('label')->get(['public_id', 'version_id', 'label', 'kind', 'bytes'])->groupBy('version_id');
        $names = User::query()->whereIn('id', $versions->flatten(1)->pluck('entered_by_user_id')->unique())->pluck('name', 'id');

        return $series->map(function ($s) use ($typeById, $versions, $files, $names, $today): array {
            $type = $typeById->get((string) $s->type_public_id) ?? [];
            $history = ($versions->get($s->id) ?? collect())->values();
            $inForce = $history->first(fn ($v): bool => $v->error_reason === null);

            return [
                'id' => (string) $s->public_id,
                'lockVersion' => (int) $s->lock_version,
                'typeId' => (string) $s->type_public_id,
                'typeName' => (string) ($type['name'] ?? ''),
                'expires' => (bool) ($type['expires'] ?? false),
                'typeActive' => (bool) ($type['isActive'] ?? false),
                'branchId' => $s->branch_id === null ? null : (int) $s->branch_id,
                'branchName' => $s->branch_id === null ? 'Whole clinic' : (string) $s->branch_name,
                'status' => ComplianceStatus::licence((bool) ($type['expires'] ?? false), $type['warnFromDays'] ?? null, $inForce, $today),
                'expiresOn' => $inForce === null ? null : ComplianceStatus::day($inForce->expires_on),
                'history' => $history->map(fn ($v): array => [
                    'id' => (string) $v->public_id, 'version' => (int) $v->version, 'inForce' => $inForce !== null && $inForce->id === $v->id,
                    'reference' => $this->decrypt($v->reference_encrypted), 'issuedOn' => ComplianceStatus::day($v->issued_on), 'expiresOn' => ComplianceStatus::day($v->expires_on),
                    'note' => $this->decrypt($v->note_encrypted), 'enteredBy' => (string) ($names[$v->entered_by_user_id] ?? 'Staff member'),
                    'enteredAt' => CarbonImmutable::parse((string) $v->created_at)->utc()->toIso8601String(),
                    'errorReason' => $v->error_reason === null ? null : (LicenceService::CORRECTION_REASONS[$v->error_reason] ?? 'Other'),
                    'files' => ($files->get($v->id) ?? collect())->map(fn ($f): array => ['id' => (string) $f->public_id, 'label' => (string) $f->label, 'kind' => (string) $f->kind, 'bytes' => (int) $f->bytes])->values()->all(),
                ])->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $types
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function overview(array $types, array $rows): array
    {
        $counts = ['expired' => 0, 'expiring' => 0, 'valid' => 0, 'no_expiry' => 0, 'not_recorded' => 0];
        $attention = [];
        foreach ($rows as $row) {
            $code = $row['status']['code'];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            if (in_array($code, ['expired', 'expiring'], true) && $row['typeActive']) {
                $attention[] = ['id' => $row['id'], 'typeName' => $row['typeName'], 'branchName' => $row['branchName'], 'status' => $row['status'], 'expiresOn' => $row['expiresOn']];
            }
        }
        usort($attention, fn (array $a, array $b): int => strcmp((string) $a['expiresOn'], (string) $b['expiresOn']));
        $recordedTypes = collect($rows)->pluck('typeId')->unique()->all();
        $noRecord = collect($types)->where('isActive', true)->filter(fn (array $t): bool => ! in_array($t['id'], $recordedTypes, true))->pluck('name')->values()->all();

        return ['counts' => $counts, 'attention' => $attention, 'typesWithoutRecord' => $noRecord];
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
