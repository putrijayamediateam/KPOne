<?php

namespace App\Domain\HR\Credentials\Services;

use Illuminate\Support\Facades\DB;

/**
 * HR-21 foreign worker permits (owner, 18 Nov 2026): the accepted records of work permit types that are expiring (inside
 * the type's clinic-set warning days) or expired, as the plain line the staff file shows and the count the HR dashboard
 * shows. The same status rule as the credential register (CredentialStatus). Read only; no reference leaves here.
 */
final class WorkPermits
{
    /**
     * @return list<array{workerId: string, type: string, expired: bool, line: string}>
     */
    public static function due(int $organisationId, string $today, ?int $workerId = null): array
    {
        $rows = DB::table('hr_credential_records as r')
            ->join('hr_credential_types as t', 't.id', '=', 'r.type_id')
            ->join('hr_credential_type_versions as v', fn ($j) => $j->on('v.type_id', '=', 't.id')->on('v.version', '=', 't.current_version'))
            ->join('hr_workers as w', 'w.id', '=', 'r.worker_id')
            ->where('r.organisation_id', $organisationId)->where('r.status', 'accepted')
            ->where('t.work_permit', true)->where('t.is_active', true)
            ->when($workerId !== null, fn ($q) => $q->where('r.worker_id', $workerId))
            ->orderBy('r.expires_on')->orderBy('r.id')
            ->get(['r.worker_id', 'w.public_id as worker', 'v.name', 'v.warn_from_days', 'r.issued_on', 'r.expires_on']);
        // People who have left are not counted, as on the register: the latest employment period has ended before today.
        $left = DB::table('hr_employment_periods as p')->whereIn('p.worker_id', $rows->pluck('worker_id')->unique()->all())
            ->orderBy('p.worker_id')->orderByDesc('p.starts_on')->get(['p.worker_id', 'p.ends_on'])
            ->unique('worker_id')->filter(fn (object $p): bool => $p->ends_on !== null && (string) $p->ends_on < $today)
            ->pluck('worker_id')->map(fn ($id): int => (int) $id)->all();
        $due = [];
        foreach ($rows as $row) {
            if (in_array((int) $row->worker_id, $left, true)) {
                continue;
            }
            $status = CredentialStatus::of(false, true, (int) $row->warn_from_days, $row, $today);
            if (! in_array($status['code'], ['expiring', 'expired'], true)) {
                continue;
            }
            $due[] = ['workerId' => (string) $row->worker, 'type' => (string) $row->name, 'expired' => $status['code'] === 'expired',
                'line' => CredentialsPageService::permitLine((string) CredentialStatus::day($row->expires_on), $status)];
        }

        return $due;
    }
}
