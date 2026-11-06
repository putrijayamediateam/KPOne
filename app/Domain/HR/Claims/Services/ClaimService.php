<?php

namespace App\Domain\HR\Claims\Services;

use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-6 staff claims (HR-PLAN 13.3). A claim is sent by its claimant at the active branch, decided as sent by someone
 * else (a Director above the Director level, and the HR Manager and Director rule of TEAM.md decision 3), and recorded
 * as paid outside KPOne by someone who is neither the claimant nor the approver. Limits warn and never refuse. KPOne
 * pays nothing and touches no clinic money. Audit holds ids, the category, the amount and codes only; the purpose,
 * notes and payment references are encrypted and never audited.
 */
final class ClaimService
{
    public const DISK = 'local';

    public const MAX_RECEIPTS = 5;

    public const RECEIPT_BYTES = 5 * 1024 * 1024;

    public const MAX_SEN = 99999999;

    public const SUGGESTED_DAYS_BACK = 30;

    public const SUGGESTED_CATEGORIES = ['Meals', 'Travel and parking', 'Training and courses', 'Supplies bought for the clinic', 'Other'];

    public const REJECT_REASONS = ['no_receipt' => 'No receipt', 'not_work' => 'Not a work expense', 'over_limit' => 'Over the limit',
        'wrong_amount' => 'Wrong amount', 'duplicate' => 'Duplicate', 'other' => 'Other'];

    public const REVERSAL_REASONS = ['wrong_claim' => 'Wrong claim', 'wrong_amount' => 'Wrong amount', 'payment_failed' => 'Payment failed', 'other' => 'Other'];

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    public function __construct(private RecordSupport $support) {}

    /** @return array{content:string, kind:string, bytes:int, sha256:string} a checked receipt (HR-2b pipeline, 5 MB) */
    public static function prepare(mixed $file, string $field): array
    {
        return LeaveEvidenceFiles::prepare($file, $field, self::RECEIPT_BYTES);
    }

    /** RM text ("45.60") to whole sen with integer arithmetic only (the STK-10B rule). Null when empty. */
    public static function sen(mixed $value, string $field, bool $optional): ?int
    {
        if ($optional && ($value === null || (is_string($value) && trim($value) === ''))) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'Enter an amount in RM, for example 45.60.']);
        }
        if (preg_match('/\A(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?\z/', trim((string) $value), $m) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an amount in RM, for example 45.60.']);
        }
        $sen = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($sen < 1 || $sen > self::MAX_SEN) {
            throw ValidationException::withMessages([$field => 'Enter an amount from RM 0.01 to RM 999,999.99.']);
        }

        return $sen;
    }

    /** @param array<string, mixed> $input */
    public function saveSettings(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $level = self::sen($input['director_level'] ?? null, 'director_level', true);
        $rawDays = $input['days_back'] ?? null;
        $days = $rawDays === null || $rawDays === '' ? null : filter_var($rawDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
        if ($days === false) {
            throw ValidationException::withMessages(['days_back' => 'Enter a whole number of days from 1 to 366, or leave it empty for no limit.']);
        }
        DB::transaction(function () use ($actor, $input, $key, $level, $days): void {
            $actor = $this->support->lock($actor, ClaimPermissions::MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_claim_settings:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'claim_settings', $level, $days]);
            if ($this->support->replay($actor, $key, 'claim_settings', $hash) !== null) {
                return;
            }
            $current = (int) DB::table('hr_claim_settings')->where('organisation_id', $actor->organisation_id)->max('version');
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== $current) {
                throw ValidationException::withMessages(['claims' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            DB::table('hr_claim_settings')->insert(['organisation_id' => $actor->organisation_id, 'version' => $current + 1, 'director_level_sen' => $level,
                'days_back' => $days, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'claim_settings', $hash, (string) ($current + 1));
            $this->support->audit->record('hr.claims.settings_saved', null, ['version' => $current + 1, 'director_level_sen' => $level, 'days_back' => $days],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Adds a category, or saves a new version of one (name, receipt rule, limits, active). Every version is kept.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveCategory(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $name = is_string($input['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['name'])) : '';
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $errors['name'] = 'Enter a name of 2 to 60 characters.';
        }
        $limits = ['per_claim_limit' => null, 'monthly_limit' => null];
        foreach (['per_claim_limit', 'monthly_limit'] as $field) {
            try {
                $limits[$field] = self::sen($input[$field] ?? null, $field, true);
            } catch (ValidationException $e) {
                $errors[$field] = $e->errors()[$field][0];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $needsReceipt = filter_var($input['needs_receipt'] ?? false, FILTER_VALIDATE_BOOL);
        $active = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOL);
        $categoryId = $input['category_id'] ?? null;

        return DB::transaction(function () use ($actor, $input, $key, $name, $limits, $needsReceipt, $active, $categoryId): string {
            $actor = $this->support->lock($actor, ClaimPermissions::MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_claim_categories:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'claim_category', $categoryId, $name, $needsReceipt, $limits, $active]);
            if (($replay = $this->support->replay($actor, $key, 'claim_category', $hash)) !== null) {
                return $replay;
            }
            $category = null;
            if ($categoryId !== null) {
                if (! is_string($categoryId) || ! Str::isUuid($categoryId)) {
                    abort(404);
                }
                $category = DB::table('hr_claim_categories')->where('organisation_id', $actor->organisation_id)->where('public_id', $categoryId)->lockForUpdate()->first();
                abort_if(! $category instanceof \stdClass, 404);
                $this->support->assertLock($category, $input);
            }
            $taken = DB::table('hr_claim_category_versions as v')->join('hr_claim_categories as c', function ($join): void {
                $join->on('c.id', '=', 'v.category_id')->on('c.current_version', '=', 'v.version');
            })->where('c.organisation_id', $actor->organisation_id)->whereRaw('lower(v.name) = lower(?)', [$name])
                ->when($category !== null, fn ($q) => $q->where('c.id', '<>', $category->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['name' => 'Another category already has this name.']);
            }
            if ($category === null) {
                $publicId = (string) Str::uuid();
                $id = DB::table('hr_claim_categories')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'current_version' => 1,
                    'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                $version = 1;
            } else {
                $publicId = (string) $category->public_id;
                $id = (int) $category->id;
                $version = (int) $category->current_version + 1;
            }
            DB::table('hr_claim_category_versions')->insert(['organisation_id' => $actor->organisation_id, 'category_id' => $id, 'version' => $version, 'name' => $name,
                'needs_receipt' => $needsReceipt, 'per_claim_limit_sen' => $limits['per_claim_limit'], 'monthly_limit_sen' => $limits['monthly_limit'],
                'is_active' => $active, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            if ($category !== null) {
                DB::table('hr_claim_categories')->where('id', $id)->update(['current_version' => $version, 'lock_version' => (int) $category->lock_version + 1, 'updated_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'claim_category', $hash, $publicId);
            $this->support->audit->record('hr.claims.category_saved', null, ['category_id' => $id, 'version' => $version, 'needs_receipt' => $needsReceipt,
                'per_claim_limit_sen' => $limits['per_claim_limit'], 'monthly_limit_sen' => $limits['monthly_limit'], 'is_active' => $active],
                $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Sends a claim at the active branch. Receipts are checked before this is called; a "Send a new claim" after a Wrong
     * amount rejection copies that claim's receipts in.
     *
     * @param  list<array{content:string, kind:string, bytes:int, sha256:string}>  $receipts
     * @param  array<string, mixed>  $input
     */
    public function send(User $actor, ?Branch $branch, array $receipts, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$categoryId, $spentOn, $amount, $purpose] = self::validated($input);
        if ($branch === null || $branch->organisation_id !== $actor->organisation_id) {
            throw ValidationException::withMessages(['claims' => 'Choose your branch first.']);
        }
        $copyFrom = $input['copy_from'] ?? null;
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $branch, $receipts, $key, $categoryId, $spentOn, $amount, $purpose, $copyFrom, &$written): string {
                $this->forget($written);
                $actor = $this->support->lock($actor, ClaimPermissions::SUBMIT_OWN);
                $hash = $this->support->hash([$actor->id, 'claim_send', $branch->id, $categoryId, $spentOn, $amount, $purpose, $copyFrom,
                    array_map(fn (array $r): string => $r['sha256'], $receipts)]);
                if (($replay = $this->support->replay($actor, $key, 'claim_send', $hash)) !== null) {
                    return $replay;
                }
                $category = $this->currentCategory($actor, $categoryId);
                if (! $category->is_active) {
                    throw ValidationException::withMessages(['category_id' => 'This category is no longer used. Choose another.']);
                }
                $settings = $this->settings($actor->organisation_id);
                $today = CarbonImmutable::now($branch->timezone)->toDateString();
                if ($spentOn > $today) {
                    throw ValidationException::withMessages(['spent_on' => 'Choose a date that is not in the future.']);
                }
                if ($settings !== null && $settings->days_back !== null && $spentOn < CarbonImmutable::parse($today)->subDays((int) $settings->days_back)->toDateString()) {
                    throw ValidationException::withMessages(['spent_on' => "Claims go back at most {$settings->days_back} days."]);
                }
                $copied = [];
                $copyId = null;
                if ($copyFrom !== null && $copyFrom !== '') {
                    $source = $this->claim($actor, (string) $copyFrom, false);
                    if ((int) $source->claimant_user_id !== $actor->id || $source->status !== 'rejected' || $source->reject_reason !== 'wrong_amount') {
                        throw ValidationException::withMessages(['claims' => 'Only your own claim rejected for a wrong amount is sent again.']);
                    }
                    $copyId = (int) $source->id;
                    $copied = DB::table('hr_staff_claim_receipts')->where('claim_id', $source->id)->orderBy('position')->get()->all();
                }
                if (count($copied) + count($receipts) > self::MAX_RECEIPTS) {
                    throw ValidationException::withMessages(['receipts' => 'Up to 5 receipts.']);
                }
                if ($category->needs_receipt && count($copied) + count($receipts) === 0) {
                    throw ValidationException::withMessages(['receipts' => "{$category->name} needs a receipt."]);
                }
                $hashes = [...array_map(fn (object $r): string => (string) $r->sha256, $copied), ...array_map(fn (array $r): string => $r['sha256'], $receipts)];
                if (count($hashes) !== count(array_unique($hashes))) {
                    throw ValidationException::withMessages(['receipts' => 'The same receipt is attached twice.']);
                }
                // Numbers per organisation, gapless: the counter row is the lock.
                DB::table('hr_claim_counters')->insertOrIgnore(['organisation_id' => $actor->organisation_id, 'last_number' => 0]);
                $counter = DB::table('hr_claim_counters')->where('organisation_id', $actor->organisation_id)->lockForUpdate()->value('last_number');
                $next = (int) $counter + 1;
                DB::table('hr_claim_counters')->where('organisation_id', $actor->organisation_id)->update(['last_number' => $next]);
                $publicId = (string) Str::uuid();
                $claimId = DB::table('hr_staff_claims')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id,
                    'number' => sprintf('SC-%06d', $next), 'claimant_user_id' => $actor->id, 'branch_id' => $branch->id, 'category_id' => $category->id,
                    'category_version' => $category->version, 'settings_version' => $settings?->version, 'spent_on' => $spentOn, 'amount_sen' => $amount,
                    'purpose_encrypted' => Crypt::encryptString($purpose), 'status' => 'waiting', 'copied_from_claim_id' => $copyId, 'lock_version' => 1,
                    'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                $position = 0;
                foreach ($copied as $old) {
                    $content = $this->read($old);
                    $this->storeReceipt($actor, $claimId, ++$position, ['content' => $content, 'kind' => (string) $old->kind, 'bytes' => (int) $old->bytes,
                        'sha256' => (string) $old->sha256], $written);
                }
                foreach ($receipts as $receipt) {
                    $this->storeReceipt($actor, $claimId, ++$position, $receipt, $written);
                }
                $this->action($actor, $claimId, 'sent', null, null, $settings?->version, (int) $category->version);
                $this->support->remember($actor, $key, 'claim_send', $hash, $publicId);
                $this->support->audit->record('hr.claims.sent', null, ['claim_id' => $claimId, 'category_id' => (int) $category->id, 'amount_sen' => $amount,
                    'branch_id' => $branch->id, 'receipts' => $position, 'copied_from_claim_id' => $copyId], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, string $claimId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $claimId, $input, $key): void {
            $actor = $this->support->lock($actor, ClaimPermissions::SUBMIT_OWN);
            $hash = $this->support->hash([$actor->id, 'claim_withdraw', $claimId]);
            if ($this->support->replay($actor, $key, 'claim_withdraw', $hash) !== null) {
                return;
            }
            $claim = $this->claim($actor, $claimId, true);
            if ((int) $claim->claimant_user_id !== $actor->id) {
                throw new AuthorizationException('Only the person who sent a claim withdraws it.');
            }
            $this->support->assertLock($claim, $input);
            if ($claim->status !== 'waiting') {
                throw ValidationException::withMessages(['claims' => 'Only a claim waiting for approval is withdrawn.']);
            }
            DB::table('hr_staff_claims')->where('id', $claim->id)->update(['status' => 'withdrawn', 'lock_version' => (int) $claim->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->action($actor, (int) $claim->id, 'withdrawn', null, null, null, null);
            $this->support->remember($actor, $key, 'claim_withdraw', $hash, $claimId);
            $this->support->audit->record('hr.claims.withdrawn', null, ['claim_id' => (int) $claim->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Approve or reject, as sent. Never the claimant; an HR Manager's claim only by a Director, a Director's by the HR
     * Manager or another Director; above the Director level only by a Director. Limits only warn.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, string $claimId, string $decision, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = null;
        $note = null;
        if ($decision === 'reject') {
            $reason = $input['reason'] ?? null;
            if (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS)) {
                throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
            }
            $note = $this->support->note($input['note'] ?? null);
            if ($reason === 'other' && $note === null) {
                throw ValidationException::withMessages(['note' => 'Say why in a few words.']);
            }
        } elseif ($decision !== 'approve') {
            abort(404);
        }
        DB::transaction(function () use ($actor, $claimId, $decision, $input, $key, $reason, $note): void {
            $actor = $this->support->lock($actor, ClaimPermissions::APPROVE, [$this->claimantId($actor, $claimId)]);
            $hash = $this->support->hash([$actor->id, 'claim_decide', $claimId, $decision, $reason, $note]);
            if ($this->support->replay($actor, $key, 'claim_decide', $hash) !== null) {
                return;
            }
            $claim = $this->claim($actor, $claimId, true);
            $this->support->assertLock($claim, $input);
            if ($claim->status !== 'waiting') {
                throw ValidationException::withMessages(['claims' => 'This claim was already decided or withdrawn. Reload the page.']);
            }
            $refusal = $this->deciderRefusal($actor, $claim);
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            DB::table('hr_staff_claims')->where('id', $claim->id)->update(['status' => $status, 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'reject_reason' => $reason, 'lock_version' => (int) $claim->lock_version + 1, 'updated_at' => now()->utc()]);
            $settings = $this->settings($actor->organisation_id);
            $categoryVersion = (int) DB::table('hr_claim_categories')->where('id', $claim->category_id)->value('current_version');
            $this->action($actor, (int) $claim->id, $status, $reason, $note, $settings?->version, $categoryVersion);
            $this->support->remember($actor, $key, 'claim_decide', $hash, $claimId);
            $this->support->audit->record('hr.claims.'.$status, null, ['claim_id' => (int) $claim->id, 'amount_sen' => (int) $claim->amount_sen, 'reason_code' => $reason,
                'settings_version' => $settings?->version, 'category_version' => $categoryVersion], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function pay(User $actor, string $claimId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $method = $input['method'] ?? null;
        $errors = [];
        if (! is_string($method) || ! array_key_exists($method, self::METHODS)) {
            $errors['method'] = 'Choose how it was paid.';
        }
        $paidOn = $input['paid_on'] ?? null;
        if (! is_string($paidOn) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $paidOn) !== 1 || ! self::realDate($paidOn)) {
            $errors['paid_on'] = 'Choose the date it was paid.';
        }
        $reference = is_string($input['reference'] ?? null) ? trim($input['reference']) : '';
        if (mb_strlen($reference) > 60) {
            $errors['reference'] = 'Use at most 60 characters.';
        } elseif ($method !== 'cash' && $reference === '') {
            $errors['reference'] = 'Enter the payment reference.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($actor, $claimId, $input, $key, $method, $paidOn, $reference): void {
            $actor = $this->support->lock($actor, ClaimPermissions::PAY, [$this->claimantId($actor, $claimId)]);
            $hash = $this->support->hash([$actor->id, 'claim_pay', $claimId, $method, $paidOn, $reference]);
            if ($this->support->replay($actor, $key, 'claim_pay', $hash) !== null) {
                return;
            }
            $claim = $this->claim($actor, $claimId, true);
            $this->support->assertLock($claim, $input);
            if ($claim->status !== 'approved') {
                throw ValidationException::withMessages(['claims' => 'Only an approved claim is paid. Reload the page.']);
            }
            $this->assertPayer($actor, $claim);
            $zone = (string) DB::table('branches')->where('id', $claim->branch_id)->value('timezone');
            if ($paidOn > CarbonImmutable::now($zone !== '' ? $zone : 'UTC')->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'Choose a date that is not in the future.']);
            }
            DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'claim_id' => $claim->id,
                'kind' => 'payment', 'method' => $method, 'paid_on' => $paidOn, 'reference_encrypted' => $reference === '' ? null : Crypt::encryptString($reference),
                'reference_last4' => $reference === '' ? null : RecordSupport::last4($reference), 'recorded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            DB::table('hr_staff_claims')->where('id', $claim->id)->update(['status' => 'paid', 'lock_version' => (int) $claim->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->action($actor, (int) $claim->id, 'paid', null, null, null, null);
            $this->support->remember($actor, $key, 'claim_pay', $hash, $claimId);
            $this->support->audit->record('hr.claims.paid', null, ['claim_id' => (int) $claim->id, 'amount_sen' => (int) $claim->amount_sen, 'method' => $method,
                'paid_on' => $paidOn], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function reversePayment(User $actor, string $claimId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, self::REVERSAL_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the payment is reversed.']);
        }
        DB::transaction(function () use ($actor, $claimId, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, ClaimPermissions::PAY, [$this->claimantId($actor, $claimId)]);
            $hash = $this->support->hash([$actor->id, 'claim_reverse', $claimId, $reason]);
            if ($this->support->replay($actor, $key, 'claim_reverse', $hash) !== null) {
                return;
            }
            $claim = $this->claim($actor, $claimId, true);
            $this->support->assertLock($claim, $input);
            if ($claim->status !== 'paid') {
                throw ValidationException::withMessages(['claims' => 'Only a paid claim has its payment reversed. Reload the page.']);
            }
            $this->assertPayer($actor, $claim);
            $payment = DB::table('hr_staff_claim_payments as p')->where('p.claim_id', $claim->id)->where('p.kind', 'payment')
                ->whereNotExists(fn ($q) => $q->from('hr_staff_claim_payments as r')->whereColumn('r.reverses_payment_id', 'p.id'))->orderByDesc('p.id')->first(['p.id']);
            abort_if($payment === null, 409);
            DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'claim_id' => $claim->id,
                'kind' => 'reversal', 'reverses_payment_id' => $payment->id, 'reason_code' => $reason, 'recorded_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            DB::table('hr_staff_claims')->where('id', $claim->id)->update(['status' => 'approved', 'lock_version' => (int) $claim->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->action($actor, (int) $claim->id, 'payment_reversed', $reason, null, null, null);
            $this->support->remember($actor, $key, 'claim_reverse', $hash, $claimId);
            $this->support->audit->record('hr.claims.payment_reversed', null, ['claim_id' => (int) $claim->id, 'reason_code' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Records the open, then returns the decrypted receipt. The claimant opens their own; an approver opens any; a payer
     * opens the receipts of approved and paid claims.
     *
     * @return array{content:string, mime:string, inline:bool, filename:string}
     */
    public function openReceipt(User $actor, string $receiptId): array
    {
        $receipt = DB::transaction(function () use ($actor, $receiptId): \stdClass {
            if (! Str::isUuid($receiptId)) {
                abort(404);
            }
            $receipt = DB::table('hr_staff_claim_receipts')->where('organisation_id', $actor->organisation_id)->where('public_id', $receiptId)->first();
            abort_if(! $receipt instanceof \stdClass, 404);
            $claim = DB::table('hr_staff_claims')->where('id', $receipt->claim_id)->first(['id', 'claimant_user_id', 'status']);
            abort_if(! $claim instanceof \stdClass, 404);
            $permission = match (true) {
                (int) $claim->claimant_user_id === $actor->id => ClaimPermissions::SUBMIT_OWN,
                $actor->can(ClaimPermissions::APPROVE) => ClaimPermissions::APPROVE,
                $actor->can(ClaimPermissions::PAY) && in_array($claim->status, ['approved', 'paid'], true) => ClaimPermissions::PAY,
                default => throw new AuthorizationException('You may not open this receipt.'),
            };
            $actor = $this->support->authority->lock($actor, $permission);
            $this->action($actor, (int) $claim->id, 'receipt_opened', null, null, null, null);
            $this->support->audit->record('hr.claims.receipt_opened', null, ['claim_id' => (int) $claim->id, 'receipt_position' => (int) $receipt->position],
                $actor, null, $actor->organisation_id);

            return $receipt;
        }, 3);
        $content = $this->read($receipt);
        $mime = match ($receipt->kind) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        return ['content' => $content, 'mime' => $mime, 'inline' => $receipt->kind !== 'pdf',
            'filename' => 'receipt-'.substr((string) $receipt->public_id, 0, 8).'.'.($receipt->kind === 'jpeg' ? 'jpg' : $receipt->kind)];
    }

    /** Null when the actor may decide this claim; otherwise the reason in words (also used by the page). */
    public function deciderRefusal(User $actor, \stdClass $claim): ?string
    {
        if ((int) $claim->claimant_user_id === $actor->id) {
            return 'Someone else approves this.';
        }
        $claimant = User::query()->with('roles')->find((int) $claim->claimant_user_id);
        $isDirector = $actor->hasRole('director');
        if ($claimant !== null && $claimant->hasRole('hr_manager') && ! $claimant->hasRole('director') && ! $isDirector) {
            return "Only a Director approves an HR Manager's claim.";
        }
        $level = $this->settingsVersion($actor->organisation_id, $claim->settings_version)?->director_level_sen;
        $current = $this->settings($actor->organisation_id)?->director_level_sen;
        // The lower of the level when it was sent and the level now: lowering the level never lets a claim past it.
        $levels = array_filter([$level, $current], fn ($v): bool => $v !== null);
        if ($levels !== [] && (int) $claim->amount_sen > min(array_map('intval', $levels)) && ! $isDirector) {
            return 'Waiting for the Director.';
        }

        return null;
    }

    public function today(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->toDateString();
    }

    public function settings(int $organisationId): ?\stdClass
    {
        $row = DB::table('hr_claim_settings')->where('organisation_id', $organisationId)->orderByDesc('version')->first();

        return $row instanceof \stdClass ? $row : null;
    }

    private function settingsVersion(int $organisationId, mixed $version): ?\stdClass
    {
        if ($version === null) {
            return null;
        }
        $row = DB::table('hr_claim_settings')->where('organisation_id', $organisationId)->where('version', (int) $version)->first();

        return $row instanceof \stdClass ? $row : null;
    }

    private function assertPayer(User $actor, \stdClass $claim): void
    {
        if ((int) $claim->claimant_user_id === $actor->id) {
            throw new AuthorizationException('Someone else records the payment of your own claim.');
        }
        if ((int) $claim->decided_by_user_id === $actor->id) {
            throw new AuthorizationException('The person who approved a claim does not record its payment.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:string, 1:string, 2:int, 3:string}
     */
    public static function validated(array $input): array
    {
        $errors = [];
        $category = $input['category_id'] ?? null;
        if (! is_string($category) || ! Str::isUuid($category)) {
            $errors['category_id'] = 'Choose a category.';
        }
        $spentOn = $input['spent_on'] ?? null;
        if (! is_string($spentOn) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $spentOn) !== 1 || ! self::realDate($spentOn)) {
            $errors['spent_on'] = 'Choose the date you paid.';
        }
        $amount = null;
        try {
            $amount = self::sen($input['amount'] ?? null, 'amount', false);
        } catch (ValidationException $e) {
            $errors['amount'] = $e->errors()['amount'][0];
        }
        $purpose = is_string($input['purpose'] ?? null) ? trim($input['purpose']) : '';
        if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 200) {
            $errors['purpose'] = 'Say what it was for in 3 to 200 characters.';
        }
        if ($errors !== [] || ! is_string($category) || ! is_string($spentOn) || $amount === null) {
            throw ValidationException::withMessages($errors);
        }

        return [$category, $spentOn, $amount, $purpose];
    }

    private static function realDate(string $value): bool
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value;
    }

    private function currentCategory(User $actor, string $categoryId): \stdClass
    {
        $row = DB::table('hr_claim_categories as c')->join('hr_claim_category_versions as v', function ($join): void {
            $join->on('v.category_id', '=', 'c.id')->on('v.version', '=', 'c.current_version');
        })->where('c.organisation_id', $actor->organisation_id)->where('c.public_id', $categoryId)->sharedLock()
            ->first(['c.id', 'v.version', 'v.name', 'v.needs_receipt', 'v.is_active']);
        if (! $row instanceof \stdClass) {
            throw ValidationException::withMessages(['category_id' => 'Choose a category.']);
        }

        return $row;
    }

    private function claimantId(User $actor, string $claimId): int
    {
        if (! Str::isUuid($claimId)) {
            abort(404);
        }
        $id = DB::table('hr_staff_claims')->where('organisation_id', $actor->organisation_id)->where('public_id', $claimId)->value('claimant_user_id');
        abort_if($id === null, 404);

        return (int) $id;
    }

    private function claim(User $actor, string $claimId, bool $lock): \stdClass
    {
        if (! Str::isUuid($claimId)) {
            abort(404);
        }
        $query = DB::table('hr_staff_claims')->where('organisation_id', $actor->organisation_id)->where('public_id', $claimId);
        $claim = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if(! $claim instanceof \stdClass, 404);

        return $claim;
    }

    private function action(User $actor, int $claimId, string $action, ?string $reason, ?string $note, ?int $settingsVersion, ?int $categoryVersion): void
    {
        DB::table('hr_staff_claim_actions')->insert(['organisation_id' => $actor->organisation_id, 'claim_id' => $claimId, 'action' => $action,
            'actor_user_id' => $actor->id, 'reason_code' => $reason, 'note_encrypted' => $note === null ? null : Crypt::encryptString($note),
            'settings_version' => $settingsVersion, 'category_version' => $categoryVersion, 'created_at' => now()->utc()]);
    }

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $receipt
     * @param  list<string>  $written
     */
    private function storeReceipt(User $actor, int $claimId, int $position, array $receipt, array &$written): void
    {
        $publicId = (string) Str::uuid();
        $path = "hr-claims/{$actor->organisation_id}/{$publicId}.bin";
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($receipt['content']))) {
            throw ValidationException::withMessages(['receipts' => 'A receipt could not be saved. Try again.']);
        }
        $written[] = $path;
        DB::table('hr_staff_claim_receipts')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'claim_id' => $claimId,
            'position' => $position, 'kind' => $receipt['kind'], 'bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256'], 'stored_path' => $path,
            'created_at' => now()->utc()]);
    }

    private function read(\stdClass $receipt): string
    {
        $path = (string) $receipt->stored_path;
        $stored = Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->get($path) : null;
        abort_if(! is_string($stored), 404);
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR staff claim receipt could not be decrypted', ['receipt_id' => $receipt->id]);
            abort(404);
        }
    }

    /** @param list<string> $paths */
    private function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
        $paths = [];
    }
}
