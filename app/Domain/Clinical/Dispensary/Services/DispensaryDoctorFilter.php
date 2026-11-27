<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Case-derived selectors only; this is not a staff directory. */
final class DispensaryDoctorFilter
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return array{User, Branch} */
    public function context(User $actor): array
    {
        $actor = User::query()->findOrFail($actor->id);
        $actor->load('roles.permissions', 'permissions');
        $branch = $this->branches->activeBranch($actor);
        abort_unless($actor->is_active && $branch && $branch->is_active && $branch->organisation_id === $actor->organisation_id
            && DispensaryDirectoryService::canView($actor)
            && ($actor->can('branch_context.switch.organisation') || $this->branches->hasEffectiveAssignment($actor, $branch)), 404);

        return [$actor, $branch];
    }

    /** @return Builder<User> */
    private function doctors(User $actor, Branch $branch): Builder
    {
        return User::query()->where('users.organisation_id', $actor->organisation_id)->whereIn('users.id',
            DispensaryCase::query()->select('visits.assigned_doctor_user_id')
                ->join('visits', 'visits.id', '=', 'dispensary_cases.visit_id')
                ->where('dispensary_cases.organisation_id', $actor->organisation_id)
                ->where('dispensary_cases.branch_id', $branch->id)
                ->where('visits.organisation_id', $actor->organisation_id)->where('visits.branch_id', $branch->id)
                ->where('dispensary_cases.case_type', DispensaryCase::TYPE_CONSULTATION)
                ->whereIn('dispensary_cases.status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING]));
    }

    /** @return array{value: string, label: string, id: int|null, reset: bool} */
    public function selection(User $actor, Branch $branch, string $value): array
    {
        if (in_array($value, ['all', 'otc'], true)) {
            return ['value' => $value, 'label' => $value === 'otc' ? 'OTC counter' : 'All doctors', 'id' => null, 'reset' => false];
        }
        $id = null;
        if (strlen($value) <= 1024) {
            try {
                $decoded = json_decode(Crypt::decryptString($value), true);
                if (is_array($decoded) && ($decoded['branch'] ?? null) === $branch->id && ($decoded['organisation'] ?? null) === $actor->organisation_id && is_int($decoded['doctor'] ?? null)) {
                    $id = $decoded['doctor'];
                }
            } catch (DecryptException) {
                // Invalid input must not resolve a label or broaden a staff directory.
            }
        }
        $doctor = $id === null ? null : $this->doctors($actor, $branch)->whereKey($id)->first(['id', 'name']);

        return $doctor === null
            ? ['value' => 'all', 'label' => 'All doctors', 'id' => null, 'reset' => true]
            : ['value' => $value, 'label' => $doctor->name, 'id' => (int) $doctor->id, 'reset' => false];
    }

    /** @return array<string, mixed> */
    public function options(User $actor, int $page, string $search, string $selected): array
    {
        [$actor, $branch] = $this->context($actor);
        $selection = $this->selection($actor, $branch, $selected);
        $search = Str::limit(trim($search), 80, '');
        $query = $this->doctors($actor, $branch);
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search));
            $query->whereRaw("LOWER(users.name) LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
        }
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 25));
        $page = min(max(1, $page), $lastPage);
        $rows = $query->orderBy('users.name')->orderBy('users.id')->forPage($page, 25)->get(['users.id', 'users.name']);

        return [
            'data' => $rows->map(fn (User $doctor): array => [
                'value' => $doctor->id === $selection['id'] ? $selection['value'] : Crypt::encryptString(json_encode(['organisation' => $actor->organisation_id, 'branch' => $branch->id, 'doctor' => $doctor->id], JSON_THROW_ON_ERROR)),
                'label' => $doctor->name,
            ])->all(),
            'page' => $page, 'lastPage' => $lastPage, 'total' => $total, 'search' => $search,
            'selected' => ['value' => $selection['value'], 'label' => $selection['label']], 'reset' => $selection['reset'],
        ];
    }
}
