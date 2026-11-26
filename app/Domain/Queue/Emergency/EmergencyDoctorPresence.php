<?php

namespace App\Domain\Queue\Emergency;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Cache-only recent poll presence; never a roster count or clinical availability assertion. */
final class EmergencyDoctorPresence
{
    private const WINDOW_SECONDS = 30;

    private const MAX_ENTRIES = 512;

    /** @return array{available:bool,doctorCount:int|null,windowSeconds:int} */
    public function poll(User $actor, int $expectedBranchId): array
    {
        $actor = User::query()->whereKey($actor->id)->where('is_active', true)->first();
        $branch = Branch::query()->whereKey($expectedBranchId)->where('is_active', true)
            ->whereHas('organisation', fn ($q) => $q->where('is_active', true))->first();
        $access = new BranchAccessService;
        abort_unless($actor && $branch && $actor->organisation_id === $branch->organisation_id
            && $access->activeBranch($actor)?->id === $branch->id
            && ($this->responds($actor) || $actor->can(TriagePermissions::RECORD_BRANCH)), 403);

        $key = "queue:emergency:presence:{$branch->organisation_id}:{$branch->id}";
        try {
            $result = Cache::lock($key.':lock', 5)->get(function () use ($key, $actor, $branch, $access): array {
                $now = now()->getTimestamp();
                $stored = Cache::get($key, []);
                if (! is_array($stored) || count($stored) > self::MAX_ENTRIES) {
                    return $this->unavailable();
                }
                $recent = [];
                foreach ($stored as $id => $at) {
                    if (is_int($at) && $at > $now - self::WINDOW_SECONDS && $at <= $now && ctype_digit((string) $id)) {
                        $recent[(int) $id] = $at;
                    }
                }
                if ($this->responds($actor)) {
                    if (! isset($recent[$actor->id]) && count($recent) >= self::MAX_ENTRIES) {
                        return $this->unavailable();
                    }
                    $recent[$actor->id] = $now;
                }
                // Revalidate cached candidates: revoked/inactive/out-of-scope users never remain in the count.
                $eligible = User::query()->whereIn('id', array_keys($recent))->where('organisation_id', $branch->organisation_id)
                    ->where('is_active', true)->with(['roles.permissions', 'permissions', 'staffProfile'])->get();
                foreach ($recent as $id => $at) {
                    $user = $eligible->firstWhere('id', $id);
                    if (! $user || ! $this->responds($user) || ! $access->canSelect($user, $branch)) {
                        unset($recent[$id]);
                    }
                }
                if (! Cache::put($key, $recent, 60)) {
                    return $this->unavailable();
                }

                return ['available' => true, 'doctorCount' => count($recent), 'windowSeconds' => self::WINDOW_SECONDS];
            });

            if (! is_array($result) || ($result['available'] ?? false) !== true || ! is_int($result['doctorCount'] ?? null)) {
                return $this->unavailable();
            }

            return ['available' => true, 'doctorCount' => $result['doctorCount'], 'windowSeconds' => self::WINDOW_SECONDS];
        } catch (Throwable) {
            // Cache outage/lock contention is unknown presence, never a false "no doctors" signal.
            return $this->unavailable();
        }
    }

    private function responds(User $user): bool
    {
        return $user->hasRole('resident_doctor') && $user->can(EmergencyAlertService::PERMISSION);
    }

    /** @return array{available:bool,doctorCount:int|null,windowSeconds:int} */
    private function unavailable(): array
    {
        return ['available' => false, 'doctorCount' => null, 'windowSeconds' => self::WINDOW_SECONDS];
    }
}
