<?php

namespace App\Domain\HR\Memos\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Sops\Services\SopService;
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
 * HR-8 announcements and memos. Director and HR Manager publish a plain-text memo (encrypted at rest) with an optional PDF to
 * everyone, chosen branches, chosen roles, or both. A memo is never edited; a correction is a new memo that replaces the
 * old one. Each person opens it (recorded before any text or byte is returned) and then presses "I have read this".
 * Withdrawing keeps every read. Audit holds ids, counts and codes only, never the title, text or file.
 */
final class MemoService
{
    public const MAX_BODY = 4000;

    public const WITHDRAW_REASONS = ['wrong_audience' => 'Wrong audience', 'wrong_content' => 'Wrong content', 'no_longer_needed' => 'No longer needed', 'other' => 'Other'];

    public function __construct(private RecordSupport $support, private MemoAudience $audience, private BranchAccessService $branches) {}

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}|null  $prepared  a checked PDF, if there is one
     * @param  array<string, mixed>  $input
     */
    public function publish(User $actor, ?array $prepared, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$title, $body] = self::validatedText($input);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $prepared, $input, $key, $title, $body, &$written): string {
                $this->forget($written);
                $actor = $this->support->lock($actor, MemoPermissions::PUBLISH);
                // The retry check comes first, on the raw input, so a retry of a publish that worked returns its result.
                $hash = $this->support->hash([$actor->id, 'memo_publish', $title, $body, $prepared['sha256'] ?? null, $input['branches'] ?? [], $input['roles'] ?? [],
                    $input['open_until'] ?? null, $input['replaces'] ?? null]);
                if (($replay = $this->support->replay($actor, $key, 'memo_publish', $hash)) !== null) {
                    return $replay;
                }
                [$branchIds, $roles] = $this->validatedAudience($actor, $input);
                $expiresAt = $this->expiry($actor, $input['open_until'] ?? null);
                $replaces = $this->replaced($actor, $input['replaces'] ?? null);
                $audience = $this->audience->users($actor->organisation_id, $branchIds, $roles);
                if ($audience->isEmpty()) {
                    throw ValidationException::withMessages(['audience' => 'Nobody matches this audience. Change the branches or roles.']);
                }
                $publicId = (string) Str::uuid();
                $pdf = ['pdf_path' => null, 'pdf_bytes' => null, 'pdf_sha256' => null];
                if ($prepared !== null) {
                    $path = "hr-memos/{$actor->organisation_id}/".Str::uuid().'.bin';
                    if (! Storage::disk(SopService::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                        throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
                    }
                    $written[] = $path;
                    $pdf = ['pdf_path' => $path, 'pdf_bytes' => $prepared['bytes'], 'pdf_sha256' => $prepared['sha256']];
                }
                $memoId = DB::table('hr_memos')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'title' => $title,
                    'body' => Crypt::encryptString($body), ...$pdf, 'published_by_user_id' => $actor->id, 'published_at' => now()->utc(),
                    'expires_at' => $expiresAt?->utc(), 'replaces_memo_id' => $replaces?->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                if ($branchIds !== []) {
                    DB::table('hr_memo_branches')->insert(array_map(fn (int $id): array => ['organisation_id' => $actor->organisation_id, 'memo_id' => $memoId, 'branch_id' => $id], $branchIds));
                }
                if ($roles !== []) {
                    DB::table('hr_memo_roles')->insert(array_map(fn (string $role): array => ['memo_id' => $memoId, 'role' => $role], $roles));
                }
                $this->support->remember($actor, $key, 'memo_publish', $hash, $publicId);
                $this->support->audit->record('hr.memos.published', null, ['memo_id' => $memoId, 'branches' => count($branchIds), 'roles' => count($roles),
                    'expected' => $audience->count(), 'has_pdf' => $prepared !== null, 'replaces_memo_id' => $replaces?->id], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, string $memoId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        if (! array_key_exists($reason, self::WITHDRAW_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why you are withdrawing it.']);
        }
        DB::transaction(function () use ($actor, $memoId, $input, $key, $reason): void {
            $actor = $this->support->lock($actor, MemoPermissions::PUBLISH);
            $hash = $this->support->hash([$actor->id, 'memo_withdraw', $memoId, $reason]);
            if ($this->support->replay($actor, $key, 'memo_withdraw', $hash) !== null) {
                return;
            }
            $memo = $this->memo($actor, $memoId, true);
            $this->support->assertLock($memo, $input);
            if ($memo->withdrawn_at !== null) {
                throw ValidationException::withMessages(['memo' => 'This memo was already withdrawn. Reload the page.']);
            }
            if ($memo->replaces_memo_id !== null && DB::table('hr_memos')->where('replaces_memo_id', $memo->id)->whereNull('withdrawn_at')->exists()) {
                throw ValidationException::withMessages(['memo' => 'Withdraw the newer memo first.']);
            }
            DB::table('hr_memos')->where('id', $memo->id)->update(['withdrawn_at' => now()->utc(), 'withdrawn_by_user_id' => $actor->id, 'withdrawn_reason' => $reason,
                'lock_version' => (int) $memo->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'memo_withdraw', $hash, $memoId);
            $this->support->audit->record('hr.memos.withdrawn', null, ['memo_id' => (int) $memo->id, 'reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Records the open, then returns the text. A manager opens any memo; anyone else opens a memo they are expected to
     * read that is not withdrawn.
     *
     * @return array{body:string, hasPdf:bool}
     */
    public function openBody(User $actor, string $memoId): array
    {
        $memo = $this->recordOpen($actor, $memoId, 'memo');

        return ['body' => $this->decryptBody($memo), 'hasPdf' => $memo->pdf_path !== null];
    }

    /** @return array{content:string, filename:string} */
    public function openPdf(User $actor, string $memoId): array
    {
        $memo = $this->recordOpen($actor, $memoId, 'pdf');
        $stored = $memo->pdf_path !== null && Storage::disk(SopService::DISK)->exists($memo->pdf_path) ? Storage::disk(SopService::DISK)->get($memo->pdf_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR memo PDF could not be decrypted', ['memo_id' => $memo->id]);
            abort(404);
        }

        return ['content' => $content, 'filename' => 'memo-'.substr((string) $memo->public_id, 0, 8).'.pdf'];
    }

    /**
     * "I have read this": once per person, only for an open memo the person is expected to read, and only after the person
     * opened it (and its PDF, if it has one).
     *
     * @param  array<string, mixed>  $input
     */
    public function acknowledge(User $actor, string $memoId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $memoId, $key): void {
            $actor = $this->support->lock($actor, MemoPermissions::READ_OWN);
            $hash = $this->support->hash([$actor->id, 'memo_ack', $memoId]);
            if ($this->support->replay($actor, $key, 'memo_ack', $hash) !== null) {
                return;
            }
            // A withdrawal or replacement waits for this acknowledgement, and an acknowledgement after it sees the change.
            $memo = $this->memo($actor, $memoId, false, true);
            $this->assertOpen($memo);
            $this->assertExpected($actor, $memo);
            if (DB::table('hr_memo_acknowledgements')->where('memo_id', $memo->id)->where('user_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['memo' => 'You already read this memo.']);
            }
            $opened = DB::table('hr_memo_opens')->where('memo_id', $memo->id)->where('user_id', $actor->id)->pluck('kind')->all();
            if (! in_array('memo', $opened, true)) {
                throw ValidationException::withMessages(['memo' => 'Open the memo first.']);
            }
            if ($memo->pdf_path !== null && ! in_array('pdf', $opened, true)) {
                throw ValidationException::withMessages(['memo' => 'Open the PDF first.']);
            }
            DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $actor->organisation_id, 'memo_id' => $memo->id, 'user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'memo_ack', $hash, $memoId);
            $this->support->audit->record('hr.memos.acknowledged', null, ['memo_id' => (int) $memo->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:string, 1:string}
     */
    public static function validatedText(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['title'])) : '';
        $body = is_string($input['body'] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $input['body'])) : '';
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
            $errors['title'] = 'Enter a title of 3 to 120 characters.';
        }
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            $errors['body'] = 'Write the memo, up to '.number_format(self::MAX_BODY).' characters.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$title, $body];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:list<int>, 1:list<string>}
     */
    public function validatedAudience(User $actor, array $input): array
    {
        $roles = $input['roles'] ?? [];
        $branches = $input['branches'] ?? [];
        if (! is_array($roles) || ! is_array($branches) || ! collect($roles)->every(fn ($r): bool => is_string($r))
            || array_diff($roles, RosterPermissions::HUMAN_ROLES) !== [] || array_diff($roles, array_keys(RosterRules::ROLE_LABELS)) !== []) {
            throw ValidationException::withMessages(['audience' => 'Choose roles and branches from the lists.']);
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($branches, fn ($b): bool => is_int($b) || (is_string($b) && ctype_digit($b))))));
        if (count($ids) !== count($branches)
            || DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['audience' => 'Choose roles and branches from the lists.']);
        }
        sort($ids);
        $roles = array_values(array_unique(array_map('strval', $roles)));
        sort($roles);

        return [$ids, $roles];
    }

    /** The end of the chosen day in the publisher's active branch time zone, once, in UTC. */
    private function expiry(User $actor, mixed $date): ?CarbonImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }
        $zone = $this->zone($actor);
        $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone) : false;
        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['open_until' => 'Enter a valid date.']);
        }
        $today = CarbonImmutable::now($zone)->startOfDay();
        if ($day->lt($today) || $day->gt($today->addYear())) {
            throw ValidationException::withMessages(['open_until' => 'Choose a date from today to one year ahead.']);
        }

        return $day->addDay()->utc();
    }

    private function zone(User $actor): string
    {
        $branch = $this->branches->activeBranch($actor);
        $zone = $branch !== null ? $branch->timezone
            : DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private function replaced(User $actor, mixed $publicId): ?\stdClass
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            throw ValidationException::withMessages(['replaces' => 'Choose a memo from the list.']);
        }
        $old = DB::table('hr_memos')->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->lockForUpdate()->first();
        if (! $old instanceof \stdClass) {
            throw ValidationException::withMessages(['replaces' => 'Choose a memo from the list.']);
        }
        if ($old->withdrawn_at !== null) {
            throw ValidationException::withMessages(['replaces' => 'A withdrawn memo cannot be replaced.']);
        }
        if (DB::table('hr_memos')->where('replaces_memo_id', $old->id)->whereNull('withdrawn_at')->exists()) {
            throw ValidationException::withMessages(['replaces' => 'This memo was already replaced.']);
        }

        return $old;
    }

    private function recordOpen(User $actor, string $memoId, string $kind): \stdClass
    {
        return DB::transaction(function () use ($actor, $memoId, $kind): \stdClass {
            $manager = $actor->can(MemoPermissions::PUBLISH);
            $actor = $this->support->authority->lock($actor, $manager ? MemoPermissions::PUBLISH : MemoPermissions::READ_OWN);
            $memo = $this->memo($actor, $memoId, false, true);
            if ($kind === 'pdf' && $memo->pdf_path === null) {
                abort(404);
            }
            if (! $manager) {
                if ($memo->withdrawn_at !== null) {
                    throw ValidationException::withMessages(['memo' => 'This memo was withdrawn.']);
                }
                $this->assertExpected($actor, $memo);
            }
            DB::table('hr_memo_opens')->insertOrIgnore(['organisation_id' => $actor->organisation_id, 'memo_id' => $memo->id, 'user_id' => $actor->id, 'kind' => $kind,
                'created_at' => now()->utc()]);
            $this->support->audit->record('hr.memos.opened', null, ['memo_id' => (int) $memo->id, 'kind' => $kind], $actor, null, $actor->organisation_id);

            return $memo;
        }, 3);
    }

    private function memo(User $actor, string $memoId, bool $lock, bool $share = false): \stdClass
    {
        if (! Str::isUuid($memoId)) {
            abort(404);
        }
        $query = DB::table('hr_memos')->where('organisation_id', $actor->organisation_id)->where('public_id', $memoId);
        $memo = ($lock ? $query->lockForUpdate() : ($share ? $query->sharedLock() : $query))->first();
        abort_if(! $memo instanceof \stdClass, 404);

        return $memo;
    }

    /** Not withdrawn, not replaced, not closed. */
    private function assertOpen(\stdClass $memo): void
    {
        if ($memo->withdrawn_at !== null) {
            throw ValidationException::withMessages(['memo' => 'This memo was withdrawn. Reload the page.']);
        }
        if (DB::table('hr_memos')->where('replaces_memo_id', $memo->id)->whereNull('withdrawn_at')->exists()) {
            throw ValidationException::withMessages(['memo' => 'This memo was replaced and no longer takes reads.']);
        }
        if ($memo->expires_at !== null && CarbonImmutable::parse((string) $memo->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['memo' => 'This memo is closed.']);
        }
    }

    private function assertExpected(User $actor, \stdClass $memo): void
    {
        $branchIds = array_map('intval', DB::table('hr_memo_branches')->where('memo_id', $memo->id)->pluck('branch_id')->all());
        $roles = array_map('strval', DB::table('hr_memo_roles')->where('memo_id', $memo->id)->pluck('role')->all());
        if (! $this->audience->includes($actor, $branchIds, $roles)) {
            throw new AuthorizationException('This memo is not for you.');
        }
    }

    private function decryptBody(\stdClass $memo): string
    {
        try {
            return Crypt::decryptString((string) $memo->body);
        } catch (DecryptException) {
            Log::error('HR memo could not be decrypted', ['memo_id' => $memo->id]);
            abort(404);
        }
    }

    /** @param list<string> $paths */
    private function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(SopService::DISK)->delete($path);
        }
        $paths = [];
    }
}
