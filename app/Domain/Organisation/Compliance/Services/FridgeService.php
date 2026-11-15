<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11b vaccine fridge log (docs/teams/hr/HR-11-DESIGN.md section 6). The clinic enters its own fridges and allowed
 * range; KPOne ships none. A reading is append-only, in whole tenths of a degree, with the server's time, and keeps the
 * range that applied when it was taken. An out-of-range reading shows as an alert until a review (what was done) is
 * recorded; the reviewer may be the person who took the reading. A wrong reading is marked entered in error, never
 * changed. A record of what a person typed, not a certification. Audit holds ids and codes only: never a temperature
 * or a note.
 */
final class FridgeService
{
    public const REASONS = ['door_open' => 'Door left open', 'defrost' => 'Defrost', 'power_cut' => 'Power cut', 'delivery' => 'Delivery', 'other' => 'Other'];

    public const ACTIONS = ['rechecked_ok' => 'Rechecked, now in range', 'moved_stock' => 'Moved stock', 'called_supplier' => 'Called the supplier', 'discarded_stock' => 'Discarded stock', 'other' => 'Other (add a short note)'];

    public const CORRECTION_REASONS = ['wrong_number' => 'Wrong number', 'wrong_fridge' => 'Wrong fridge', 'duplicate' => 'Entered twice', 'other' => 'Other'];

    public function __construct(private ComplianceSupport $support, private BranchAccessService $branches) {}

    /** "2.0" or "-1.5" to whole tenths; null when it is not a number with at most one decimal between -30.0 and 30.0. */
    public static function tenths(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (! preg_match('/^(-?)(\d{1,2})(?:\.(\d))?$/', $text, $m)) {
            return null;
        }
        $tenths = ((int) $m[2]) * 10 + (int) ($m[3] ?? 0);
        $tenths = $m[1] === '-' ? -$tenths : $tenths;

        return $tenths >= -300 && $tenths <= 300 ? $tenths : null;
    }

    public static function outOfRange(object $reading): bool
    {
        $min = (int) data_get($reading, 'range_min_tenths');
        $max = (int) data_get($reading, 'range_max_tenths');
        foreach (['temp_tenths', 'low_tenths', 'high_tenths'] as $field) {
            $n = data_get($reading, $field);
            if ($n !== null && ((int) $n < $min || (int) $n > $max)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a fridge (branch fixed when added) or changes the name, range and expected readings of one.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveFridge(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $fridgeId = $this->uuid($input['fridge_id'] ?? null);
        $name = $this->support->text($input['name'] ?? null, 'name', 80, true);
        if (mb_strlen((string) $name) < 2) {
            throw ValidationException::withMessages(['name' => 'Enter the name (2 to 80 characters).']);
        }
        $min = self::tenths($input['min'] ?? null);
        $max = self::tenths($input['max'] ?? null);
        if ($min === null) {
            throw ValidationException::withMessages(['min' => 'Enter the lowest allowed temperature, for example 2.0.']);
        }
        if ($max === null) {
            throw ValidationException::withMessages(['max' => 'Enter the highest allowed temperature, for example 8.0.']);
        }
        if ($min >= $max) {
            throw ValidationException::withMessages(['max' => 'The highest temperature must be above the lowest.']);
        }
        $perDay = filter_var($input['readings_per_day'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 6]]);
        if ($perDay === false) {
            throw ValidationException::withMessages(['readings_per_day' => 'Enter how many readings a day, from 1 to 6.']);
        }
        $expectBy = $input['expect_by'] ?? null;
        $expectBy = is_string($expectBy) && trim($expectBy) !== '' ? trim($expectBy) : null;
        if ($expectBy !== null && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $expectBy)) {
            throw ValidationException::withMessages(['expect_by' => 'Enter the time as hh:mm, for example 10:00, or leave it empty.']);
        }
        $branchInput = $input['branch_id'] ?? null;

        return DB::transaction(function () use ($actor, $input, $key, $fridgeId, $name, $min, $max, $perDay, $expectBy, $branchInput): string {
            $actor = $this->support->lock($actor, CompliancePermissions::FRIDGE_MANAGE);
            $hash = $this->support->hash([$actor->id, 'fridge', $fridgeId, $branchInput, $name, $min, $max, $perDay, $expectBy]);
            if (($replay = $this->support->replay($actor, $key, 'fridge', $hash)) !== null) {
                return $replay;
            }
            $fridge = null;
            if ($fridgeId !== null) {
                $fridge = DB::table('compliance_fridges')->where('organisation_id', $actor->organisation_id)->where('public_id', $fridgeId)->lockForUpdate()->first();
                abort_if($fridge === null, 404);
                $this->support->assertLock($fridge, $input);
                if (! $fridge->is_active) {
                    throw ValidationException::withMessages(['compliance' => 'This fridge was archived. Reload the page.']);
                }
                $branchId = (int) $fridge->branch_id;
            } else {
                $branchId = filter_var($branchInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $ok = $branchId !== false && DB::table('branches')->where('id', $branchId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists();
                if (! $ok) {
                    throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
                }
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_fridges:'.$branchId]);
            $clash = DB::table('compliance_fridges')->where('branch_id', $branchId)->where('is_active', true)->whereRaw('lower(name) = lower(?)', [$name])
                ->when($fridge !== null, fn ($q) => $q->where('id', '<>', $fridge->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['name' => 'Another fridge at this branch has this name.']);
            }
            if ($fridge === null) {
                $publicId = (string) Str::uuid();
                DB::table('compliance_fridges')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branchId, 'name' => $name,
                    'min_tenths' => $min, 'max_tenths' => $max, 'readings_per_day' => $perDay, 'expect_by' => $expectBy, 'is_active' => true, 'created_by_user_id' => $actor->id,
                    'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                $id = (int) DB::table('compliance_fridges')->where('public_id', $publicId)->value('id');
            } else {
                DB::table('compliance_fridges')->where('id', $fridge->id)->update(['name' => $name, 'min_tenths' => $min, 'max_tenths' => $max, 'readings_per_day' => $perDay,
                    'expect_by' => $expectBy, 'lock_version' => $fridge->lock_version + 1, 'updated_at' => now()->utc()]);
                $publicId = (string) $fridge->public_id;
                $id = (int) $fridge->id;
            }
            $this->support->remember($actor, $key, 'fridge', $hash, $publicId);
            $this->support->audit->record('compliance.fridge_saved', null, ['fridge_id' => $id, 'branch_id' => $branchId, 'created' => $fridge === null], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function archiveFridge(User $actor, string $fridgeId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $fridgeId = $this->uuid($fridgeId) ?? abort(404);

        return DB::transaction(function () use ($actor, $fridgeId, $input, $key): string {
            $actor = $this->support->lock($actor, CompliancePermissions::FRIDGE_MANAGE);
            $hash = $this->support->hash([$actor->id, 'fridge_archive', $fridgeId]);
            if (($replay = $this->support->replay($actor, $key, 'fridge_archive', $hash)) !== null) {
                return $replay;
            }
            $fridge = DB::table('compliance_fridges')->where('organisation_id', $actor->organisation_id)->where('public_id', $fridgeId)->lockForUpdate()->first();
            abort_if($fridge === null, 404);
            $this->support->assertLock($fridge, $input);
            if (! $fridge->is_active) {
                throw ValidationException::withMessages(['compliance' => 'This fridge is already archived.']);
            }
            DB::table('compliance_fridges')->where('id', $fridge->id)->update(['is_active' => false, 'lock_version' => $fridge->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'fridge_archive', $hash, (string) $fridge->public_id);
            $this->support->audit->record('compliance.fridge_archived', null, ['fridge_id' => $fridge->id], $actor, null, $actor->organisation_id);

            return (string) $fridge->public_id;
        }, 3);
    }

    /**
     * One reading, at the server's time. Needs the record permission and an effective assignment at the fridge's branch.
     *
     * @param  array<string, mixed>  $input
     */
    public function record(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $fridgeId = $this->uuid($input['fridge_id'] ?? null) ?? throw ValidationException::withMessages(['fridge_id' => 'Choose a fridge.']);
        $temp = self::tenths($input['temp'] ?? null);
        if ($temp === null) {
            throw ValidationException::withMessages(['temp' => 'Enter the temperature now, from -30.0 to 30.0 with at most one decimal.']);
        }
        $low = $this->optionalTenths($input['low'] ?? null, 'low');
        $high = $this->optionalTenths($input['high'] ?? null, 'high');
        if ($low !== null && $low > $temp) {
            throw ValidationException::withMessages(['low' => 'The lowest cannot be above the temperature now.']);
        }
        if ($high !== null && $high < $temp) {
            throw ValidationException::withMessages(['high' => 'The highest cannot be below the temperature now.']);
        }
        $reason = $input['reason'] ?? null;
        $reason = is_string($reason) && $reason !== '' ? $reason : null;
        if ($reason !== null && ! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason from the list.']);
        }

        return DB::transaction(function () use ($actor, $key, $fridgeId, $temp, $low, $high, $reason): string {
            $actor = $this->support->lock($actor, CompliancePermissions::FRIDGE_RECORD);
            $hash = $this->support->hash([$actor->id, 'reading', $fridgeId, $temp, $low, $high, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'reading', $hash)) !== null) {
                return $replay;
            }
            $fridge = DB::table('compliance_fridges')->where('organisation_id', $actor->organisation_id)->where('public_id', $fridgeId)->lockForUpdate()->first();
            abort_if($fridge === null, 404);
            $this->assertBranch($actor, (int) $fridge->branch_id);
            if (! $fridge->is_active) {
                throw ValidationException::withMessages(['fridge_id' => 'This fridge was archived. Choose another.']);
            }
            $publicId = (string) Str::uuid();
            DB::table('compliance_fridge_readings')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'fridge_id' => $fridge->id, 'branch_id' => $fridge->branch_id,
                'taken_at' => now()->utc(), 'temp_tenths' => $temp, 'low_tenths' => $low, 'high_tenths' => $high, 'range_min_tenths' => $fridge->min_tenths, 'range_max_tenths' => $fridge->max_tenths,
                'reason_code' => $reason, 'entered_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $id = (int) DB::table('compliance_fridge_readings')->where('public_id', $publicId)->value('id');
            $out = $temp < $fridge->min_tenths || $temp > $fridge->max_tenths
                || ($low !== null && ($low < $fridge->min_tenths || $low > $fridge->max_tenths)) || ($high !== null && ($high < $fridge->min_tenths || $high > $fridge->max_tenths));
            $this->support->remember($actor, $key, 'reading', $hash, $publicId);
            $this->support->audit->record('compliance.fridge_reading_recorded', null, ['reading_id' => $id, 'fridge_id' => $fridge->id, 'out_of_range' => $out], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * What was done about an out-of-range reading. The reviewer may be the person who took it.
     *
     * @param  array<string, mixed>  $input
     */
    public function review(User $actor, string $readingId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $readingId = $this->uuid($readingId) ?? abort(404);
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if (! array_key_exists($action, self::ACTIONS)) {
            throw ValidationException::withMessages(['action' => 'Choose what was done.']);
        }
        $note = $this->support->text($input['note'] ?? null, 'note', 200, $action === 'other');

        return DB::transaction(function () use ($actor, $key, $readingId, $action, $note): string {
            $actor = $this->support->lock($actor, CompliancePermissions::FRIDGE_REVIEW);
            $hash = $this->support->hash([$actor->id, 'review', $readingId, $action, $note]);
            if (($replay = $this->support->replay($actor, $key, 'review', $hash)) !== null) {
                return $replay;
            }
            $reading = DB::table('compliance_fridge_readings')->where('organisation_id', $actor->organisation_id)->where('public_id', $readingId)->first();
            abort_if($reading === null, 404);
            $this->assertBranch($actor, (int) $reading->branch_id);
            DB::table('compliance_fridges')->where('id', $reading->fridge_id)->lockForUpdate()->first();
            if (! self::outOfRange($reading)) {
                throw ValidationException::withMessages(['compliance' => 'This reading was in range, so it needs no action.']);
            }
            if (DB::table('compliance_fridge_corrections')->where('reading_id', $reading->id)->exists()) {
                throw ValidationException::withMessages(['compliance' => 'This reading was marked as entered in error.']);
            }
            if (DB::table('compliance_fridge_reviews')->where('reading_id', $reading->id)->exists()) {
                throw ValidationException::withMessages(['compliance' => 'The action for this reading was already recorded.']);
            }
            $publicId = (string) Str::uuid();
            DB::table('compliance_fridge_reviews')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'reading_id' => $reading->id, 'action_code' => $action,
                'note_encrypted' => $note === null ? null : Crypt::encryptString($note), 'reviewed_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'review', $hash, $publicId);
            $this->support->audit->record('compliance.fridge_reading_reviewed', null, ['reading_id' => $reading->id, 'action' => $action], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Marks a wrong reading entered in error: it leaves the alert and the daily count but stays readable.
     *
     * @param  array<string, mixed>  $input
     */
    public function markInError(User $actor, string $readingId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $readingId = $this->uuid($readingId) ?? abort(404);
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        if (! array_key_exists($reason, self::CORRECTION_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return DB::transaction(function () use ($actor, $key, $readingId, $reason): string {
            $actor = $this->support->lock($actor, CompliancePermissions::FRIDGE_MANAGE);
            $hash = $this->support->hash([$actor->id, 'reading_error', $readingId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'reading_error', $hash)) !== null) {
                return $replay;
            }
            $reading = DB::table('compliance_fridge_readings')->where('organisation_id', $actor->organisation_id)->where('public_id', $readingId)->first();
            abort_if($reading === null, 404);
            DB::table('compliance_fridges')->where('id', $reading->fridge_id)->lockForUpdate()->first();
            if (DB::table('compliance_fridge_corrections')->where('reading_id', $reading->id)->exists()) {
                throw ValidationException::withMessages(['compliance' => 'This reading was already marked as entered in error.']);
            }
            DB::table('compliance_fridge_corrections')->insert(['organisation_id' => $actor->organisation_id, 'reading_id' => $reading->id, 'reason_code' => $reason,
                'corrected_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'reading_error', $hash, (string) $reading->public_id);
            $this->support->audit->record('compliance.fridge_reading_marked_in_error', null, ['reading_id' => $reading->id, 'reason' => $reason], $actor, null, $actor->organisation_id);

            return (string) $reading->public_id;
        }, 3);
    }

    /** The holder must be assigned to the fridge's branch today (branch-local), as for other branch permissions. */
    private function assertBranch(User $actor, int $branchId): void
    {
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($branchId)->first();
        if ($branch === null || ! $this->branches->hasEffectiveAssignment($actor, $branch)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
    }

    private function optionalTenths(mixed $value, string $field): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $tenths = self::tenths($value);
        if ($tenths === null) {
            throw ValidationException::withMessages([$field => 'Enter a temperature from -30.0 to 30.0 with at most one decimal, or leave it empty.']);
        }

        return $tenths;
    }

    private function uuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['compliance' => 'Refresh this page and try again.']);
        }

        return $value;
    }
}
