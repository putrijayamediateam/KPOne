<?php

namespace App\Domain\HR\Letters\Services;

use App\Domain\Clinical\Documents\DocumentLetterheadCapture;
use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\PrintedPhone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-10a letters (HR-PLAN 20.3). HR enters templates (EN and BM, versioned; KPOne ships none) and issues a letter to a
 * staff file: the placeholders are filled from the file, and an encrypted snapshot (the filled text, the letterhead
 * text and logo, the template version) is stored under HRL/{year}/{6-digit} from a locked yearly counter. A letter is
 * never edited: a correction replaces it (the old one becomes void, "replaced"), and a void keeps it with its reason.
 * Nothing is emailed: the letter is printed and signed by hand, and HR may record the day it was given. Audit holds ids
 * and codes only, never the text.
 */
final class LetterService
{
    public const TYPES = ['confirmation' => 'Confirmation', 'warning' => 'Warning', 'show_cause' => 'Show cause', 'other' => 'Other'];

    public const PLACEHOLDERS = ['name', 'position', 'branch', 'date', 'reference', 'start_date', 'details'];

    /** Only warning and show-cause letters carry a paragraph HR writes for that letter. */
    public const DETAILS_TYPES = ['warning', 'show_cause'];

    public const VOID_REASONS = ['wrong_person' => 'Wrong person', 'wrong_content' => 'Wrong content', 'issued_in_error' => 'Issued in error', 'other' => 'Other'];

    public const MAX_BODY = 5000;

    public const MAX_DETAILS = 2000;

    private const MONTHS_BM = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

    public function __construct(private RecordSupport $support, private DocumentLetterheadCapture $letterheads) {}

    /** @param array<string, mixed> $input type, title, body_en, body_bm, idempotency_key */
    public function createTemplate(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        if (! array_key_exists($type, self::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Choose the kind of letter.']);
        }
        [$title, $titleBm, $en, $bm] = self::validatedTemplate($type, $input);

        return DB::transaction(function () use ($actor, $key, $type, $title, $titleBm, $en, $bm): string {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE);
            $hash = $this->support->hash([$actor->id, 'letter_template_create', $type, $title, $titleBm, $en, $bm]);
            if (($replay = $this->support->replay($actor, $key, 'letter_template_create', $hash)) !== null) {
                return $replay;
            }
            $now = now()->utc();
            $publicId = (string) Str::uuid();
            $id = DB::table('hr_letter_templates')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'type' => $type,
                'current_version' => 1, 'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('hr_letter_template_versions')->insert(['organisation_id' => $actor->organisation_id, 'template_id' => $id, 'version' => 1, 'title' => $title,
                'title_bm' => $titleBm, 'body_en' => $en, 'body_bm' => $bm, 'created_by_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'letter_template_create', $hash, $publicId);
            $this->support->audit->record('hr.letters.template_created', null, ['template_id' => $id, 'type' => $type], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input title, body_en, body_bm, expected_lock_version, idempotency_key */
    public function reviseTemplate(User $actor, string $templateId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $templateId, $input, $key): void {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE);
            $template = $this->template($actor, $templateId, true);
            [$title, $titleBm, $en, $bm] = self::validatedTemplate((string) $template->type, $input);
            $hash = $this->support->hash([$actor->id, 'letter_template_revise', $templateId, $title, $titleBm, $en, $bm]);
            if ($this->support->replay($actor, $key, 'letter_template_revise', $hash) !== null) {
                return;
            }
            $this->support->assertLock($template, $input);
            if ($template->retired_at !== null) {
                throw ValidationException::withMessages(['template' => 'This template was retired. Make a new one.']);
            }
            $current = DB::table('hr_letter_template_versions')->where('template_id', $template->id)->where('version', $template->current_version)->first();
            if ($current !== null && $current->title === $title && $current->title_bm === $titleBm && $current->body_en === $en && $current->body_bm === $bm) {
                throw ValidationException::withMessages(['template' => 'Nothing was changed.']);
            }
            $now = now()->utc();
            $version = (int) $template->current_version + 1;
            DB::table('hr_letter_templates')->where('id', $template->id)->update(['current_version' => $version, 'lock_version' => (int) $template->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_letter_template_versions')->insert(['organisation_id' => $actor->organisation_id, 'template_id' => $template->id, 'version' => $version,
                'title' => $title, 'title_bm' => $titleBm, 'body_en' => $en, 'body_bm' => $bm, 'created_by_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'letter_template_revise', $hash, $templateId);
            $this->support->audit->record('hr.letters.template_revised', null, ['template_id' => (int) $template->id, 'version' => $version], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function retireTemplate(User $actor, string $templateId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $templateId, $input, $key): void {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE);
            $hash = $this->support->hash([$actor->id, 'letter_template_retire', $templateId]);
            if ($this->support->replay($actor, $key, 'letter_template_retire', $hash) !== null) {
                return;
            }
            $template = $this->template($actor, $templateId, true);
            $this->support->assertLock($template, $input);
            if ($template->retired_at !== null) {
                throw ValidationException::withMessages(['template' => 'This template was already retired.']);
            }
            DB::table('hr_letter_templates')->where('id', $template->id)->update(['retired_at' => now()->utc(), 'retired_by_user_id' => $actor->id,
                'lock_version' => (int) $template->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'letter_template_retire', $hash, $templateId);
            $this->support->audit->record('hr.letters.template_retired', null, ['template_id' => (int) $template->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * The filled text for the issue preview. Nothing is stored or numbered; the reference reads "HRL/<year>/……".
     *
     * @param  array<string, mixed>  $input  worker_id, template_id, language, details, replaces
     * @return array{title:string, body:string, language:string, templateVersion:int}
     */
    public function preview(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            $actor = $this->support->authority->lock($actor, LetterPermissions::ISSUE);
            $worker = $this->worker($actor, $input['worker_id'] ?? null, false);
            [$version, $language, $details] = $this->issueChoices($actor, $input, false);
            $today = CarbonImmutable::now($this->zone($actor->organisation_id));
            // The preview shows the legal name from the staff file, so it is recorded as a Show, as HR-4a does (Suresh, B1).
            if ($this->legalName($worker) !== null) {
                $this->support->audit->record('hr.records.value_shown', $worker, ['field' => 'legal_name', 'via' => 'letter_preview'], $actor, null, $actor->organisation_id);
            }

            return ['title' => self::title($version, $language), 'language' => $language, 'templateVersion' => (int) $version->version,
                'body' => $this->fill($worker, $version, $language, $details, 'HRL/'.$today->year.'/……', $today)];
        });
    }

    /**
     * Issues a letter, or a replacement when "replaces" names a current letter of the same staff file.
     *
     * @param  array<string, mixed>  $input  worker_id, template_id, language, details, replaces, expected_template_version, idempotency_key
     */
    public function issue(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $workerId = is_string($input['worker_id'] ?? null) ? $input['worker_id'] : '';
        $subjectUserId = $this->support->workerUserId($actor, $workerId);

        return DB::transaction(function () use ($actor, $input, $key, $workerId, $subjectUserId): string {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE, [$subjectUserId]);
            $hash = $this->support->hash([$actor->id, 'letter_issue', $workerId, $input['template_id'] ?? null, $input['language'] ?? null,
                $input['details'] ?? null, $input['replaces'] ?? null]);
            if (($replay = $this->support->replay($actor, $key, 'letter_issue', $hash)) !== null) {
                return $replay;
            }
            $worker = $this->worker($actor, $workerId, true);
            if ($worker->user !== null && ! $worker->user->is_active) {
                throw ValidationException::withMessages(['worker_id' => 'This staff member\'s account is inactive. Letters go to current staff only.']);
            }
            [$version, $language, $details] = $this->issueChoices($actor, $input, true);
            if (filter_var($input['expected_template_version'] ?? null, FILTER_VALIDATE_INT) !== (int) $version->version) {
                throw ValidationException::withMessages(['template_id' => 'This template was changed since you opened the preview. Check the preview again.']);
            }
            $replaces = $this->replaced($actor, $worker, $input['replaces'] ?? null);
            if ($replaces !== null && $replaces->type !== $version->type) {
                throw ValidationException::withMessages(['replaces' => 'A replacement is the same kind of letter as the one it replaces.']);
            }
            $branch = $this->branch($worker);
            $letterhead = $this->letterheads->capture($branch);
            $zone = $this->zone($actor->organisation_id);
            $now = CarbonImmutable::now($zone);
            $year = (int) $now->year;
            $number = $this->nextNumber($actor->organisation_id, $year);
            $reference = sprintf('HRL/%d/%06d', $year, $number);
            $snapshot = [
                'version' => 1,
                'reference' => $reference,
                'type' => (string) $version->type,
                'language' => $language,
                'title' => self::title($version, $language),
                'templateVersion' => (int) $version->version,
                'recipient' => $this->recipientName($worker),
                'date' => $this->date($now, $language),
                'body' => $this->fill($worker, $version, $language, $details, $reference, $now),
                'letterhead' => $this->letterheadBlock($letterhead),
                'replaces' => $replaces?->reference,
            ];
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $publicId = (string) Str::uuid();
            $utc = $now->utc();
            $letterId = DB::table('hr_letters')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'worker_id' => $worker->id,
                'template_version_id' => $version->id, 'type' => $version->type, 'language' => $language, 'year' => $year, 'number' => $number,
                'reference' => $reference, 'snapshot_encrypted' => Crypt::encryptString($json), 'snapshot_hash' => hash('sha256', $json),
                'replaces_letter_id' => $replaces?->id, 'issued_by_user_id' => $actor->id, 'issued_at' => $utc, 'lock_version' => 1,
                'created_at' => $utc, 'updated_at' => $utc]);
            if ($replaces !== null) {
                DB::table('hr_letters')->where('id', $replaces->id)->update(['voided_at' => $utc, 'voided_by_user_id' => $actor->id, 'void_reason' => 'replaced',
                    'lock_version' => (int) $replaces->lock_version + 1, 'updated_at' => $utc]);
            }
            $this->support->remember($actor, $key, 'letter_issue', $hash, $publicId);
            // No worker or letter type in the audit (Suresh, HR-10c): a show-cause or warning letter to a named file would reveal a
            // discipline case to audit viewers. The letter id is enough evidence; only its managers can resolve it.
            $this->support->audit->record($replaces === null ? 'hr.letters.issued' : 'hr.letters.replaced', null, ['letter_id' => $letterId,
                'template_version_id' => (int) $version->id, 'replaces_letter_id' => $replaces?->id], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input reason, expected_lock_version, idempotency_key */
    public function void(User $actor, string $letterId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        if (! array_key_exists($reason, self::VOID_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why you are voiding it.']);
        }
        $subjectUserId = $this->letterSubject($actor, $letterId);
        DB::transaction(function () use ($actor, $letterId, $input, $key, $reason, $subjectUserId): void {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE, [$subjectUserId]);
            $hash = $this->support->hash([$actor->id, 'letter_void', $letterId, $reason]);
            if ($this->support->replay($actor, $key, 'letter_void', $hash) !== null) {
                return;
            }
            $letter = $this->managedLetter($actor, $letterId);
            $this->support->assertLock($letter, $input);
            if ($letter->voided_at !== null) {
                throw ValidationException::withMessages(['letter' => 'This letter is already void.']);
            }
            DB::table('hr_letters')->where('id', $letter->id)->update(['voided_at' => now()->utc(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason,
                'lock_version' => (int) $letter->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'letter_void', $hash, $letterId);
            $this->support->audit->record('hr.letters.voided', null, ['letter_id' => (int) $letter->id, 'reason' => $reason], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input given_on (Y-m-d), expected_lock_version, idempotency_key */
    public function recordGiven(User $actor, string $letterId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $subjectUserId = $this->letterSubject($actor, $letterId);
        DB::transaction(function () use ($actor, $letterId, $input, $key, $subjectUserId): void {
            $actor = $this->support->lock($actor, LetterPermissions::ISSUE, [$subjectUserId]);
            $hash = $this->support->hash([$actor->id, 'letter_given', $letterId, $input['given_on'] ?? null]);
            if ($this->support->replay($actor, $key, 'letter_given', $hash) !== null) {
                return;
            }
            $letter = $this->managedLetter($actor, $letterId);
            $this->support->assertLock($letter, $input);
            if ($letter->voided_at !== null) {
                throw ValidationException::withMessages(['letter' => 'A void letter is not given.']);
            }
            if ($letter->given_on !== null) {
                throw ValidationException::withMessages(['letter' => 'When this letter was given is already recorded.']);
            }
            $zone = $this->zone($actor->organisation_id);
            $given = is_string($input['given_on'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $input['given_on']) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $input['given_on'], $zone) : null;
            $issuedOn = CarbonImmutable::parse((string) $letter->issued_at, 'UTC')->setTimezone($zone)->startOfDay();
            if (! $given instanceof CarbonImmutable || $given->format('Y-m-d') !== $input['given_on'] || $given->lt($issuedOn) || $given->gt(CarbonImmutable::now($zone)->startOfDay())) {
                throw ValidationException::withMessages(['given_on' => 'Enter a date from the day it was issued to today.']);
            }
            DB::table('hr_letters')->where('id', $letter->id)->update(['given_on' => $given->toDateString(), 'given_recorded_by_user_id' => $actor->id,
                'lock_version' => (int) $letter->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'letter_given', $hash, $letterId);
            $this->support->audit->record('hr.letters.given', null, ['letter_id' => (int) $letter->id], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Records the open, then returns the snapshot. A manager opens any letter they may manage; anyone else opens only
     * their own.
     *
     * @return array<string, mixed>
     */
    public function open(User $actor, string $letterId): array
    {
        return DB::transaction(function () use ($actor, $letterId): array {
            abort_if(! Str::isUuid($letterId), 404);
            $letter = DB::table('hr_letters as l')->join('hr_workers as w', 'w.id', '=', 'l.worker_id')
                ->where('l.organisation_id', $actor->organisation_id)->where('l.public_id', $letterId)->first(['l.*', 'w.user_id as subject_user_id']);
            abort_if(! $letter instanceof \stdClass, 404);
            $own = $letter->subject_user_id !== null && (int) $letter->subject_user_id === $actor->id && $actor->can(RecordPermissions::VIEW_OWN);
            if (! $own) {
                if (! $actor->can(LetterPermissions::ISSUE)) {
                    abort(404);
                }
                $refusal = $this->refusal($actor, $letter->subject_user_id === null ? null : (int) $letter->subject_user_id);
                if ($refusal !== null) {
                    throw new AuthorizationException($refusal);
                }
            }
            DB::table('hr_letter_opens')->insert(['organisation_id' => $actor->organisation_id, 'letter_id' => $letter->id, 'opened_by_user_id' => $actor->id,
                'own' => $own, 'created_at' => now()->utc()]);
            $this->support->audit->record('hr.letters.opened', null, ['letter_id' => (int) $letter->id, 'own' => $own], $actor, null, $actor->organisation_id);
            try {
                $json = Crypt::decryptString((string) $letter->snapshot_encrypted);
            } catch (DecryptException) {
                abort(409);
            }
            abort_if(! hash_equals((string) $letter->snapshot_hash, hash('sha256', $json)), 409);
            $snapshot = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

            return ['id' => (string) $letter->public_id, 'own' => $own, ...(is_array($snapshot) ? $snapshot : []),
                'status' => $letter->voided_at === null ? 'current' : ($letter->void_reason === 'replaced' ? 'replaced' : 'void')];
        });
    }

    /**
     * Nobody issues a letter to themselves; a Director's letters only by a Director, and an HR Manager's only by a
     * Director (HR-PLAN 20.3). Null when the actor may.
     */
    public function refusal(User $actor, ?int $subjectUserId): ?string
    {
        if ($subjectUserId === null) {
            return null; // a locum's file has no account
        }
        if ($subjectUserId === $actor->id) {
            return 'You may not issue letters to yourself. Ask someone else.';
        }
        $subject = User::query()->find($subjectUserId);

        return $subject === null ? 'This staff file cannot take letters.' : $this->refusalFor($actor, $subject);
    }

    /** The same rule for an already loaded account (roles eager-loaded), so a list makes no query per person. */
    public function refusalFor(User $actor, User $subject): ?string
    {
        if ($subject->id === $actor->id) {
            return 'You may not issue letters to yourself. Ask someone else.';
        }
        if (($subject->hasRole('director') || $subject->hasRole('hr_manager')) && ! $actor->hasRole('director')) {
            return $subject->hasRole('director') ? "Only a Director may issue a Director's letters." : "Only a Director may issue an HR Manager's letters.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:string, 1:string, 2:string, 3:string}
     */
    public static function validatedTemplate(string $type, array $input): array
    {
        $titles = [];
        foreach (['title' => 'English', 'title_bm' => 'Malay'] as $field => $label) {
            $title = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || preg_match('/[<>{}\r\n]/', $title) === 1) {
                throw ValidationException::withMessages([$field => "Write the {$label} title: 3 to 80 characters, without < > { } or line breaks."]);
            }
            $titles[] = $title;
        }
        $bodies = [];
        foreach (['body_en' => 'English', 'body_bm' => 'Malay'] as $field => $label) {
            $body = is_string($input[$field] ?? null) ? str_replace("\r\n", "\n", trim($input[$field])) : '';
            if (mb_strlen($body) < 20 || mb_strlen($body) > self::MAX_BODY) {
                throw ValidationException::withMessages([$field => "Write the {$label} text: 20 to ".number_format(self::MAX_BODY).' characters.']);
            }
            if (preg_match('/[<>]/', $body) === 1) {
                throw ValidationException::withMessages([$field => 'Remove < and >: the letter is plain text.']);
            }
            preg_match_all('/\{([^{}]*)\}/', $body, $found);
            foreach ($found[1] as $name) {
                if (! in_array($name, self::PLACEHOLDERS, true)) {
                    throw ValidationException::withMessages([$field => "Unknown field {{$name}}. Use only: ".implode(', ', array_map(fn (string $p): string => '{'.$p.'}', self::PLACEHOLDERS)).'.']);
                }
                if ($name === 'details' && ! in_array($type, self::DETAILS_TYPES, true)) {
                    throw ValidationException::withMessages([$field => '{details} is only for warning and show-cause letters.']);
                }
            }
            if (preg_match('/[{}]/', (string) preg_replace('/\{('.implode('|', self::PLACEHOLDERS).')\}/', '', $body)) === 1) {
                throw ValidationException::withMessages([$field => 'A { or } is not part of a field. Remove it.']);
            }
            $bodies[] = $body;
        }

        return [$titles[0], $titles[1], $bodies[0], $bodies[1]];
    }

    /** The letter's title in its own language. */
    public static function title(\stdClass $version, string $language): string
    {
        return $language === 'bm' ? (string) $version->title_bm : (string) $version->title;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:\stdClass, 1:string, 2:string} the current template version, the language and the checked details
     */
    private function issueChoices(User $actor, array $input, bool $lock): array
    {
        // Issuing holds the template row (FOR SHARE), so a revise or retire waits until the letter is in (Suresh, M3).
        $template = $this->template($actor, $input['template_id'] ?? null, $lock ? 'share' : false);
        if ($template->retired_at !== null) {
            throw ValidationException::withMessages(['template_id' => 'This template was retired. Choose another.']);
        }
        $version = DB::table('hr_letter_template_versions')->where('template_id', $template->id)->where('version', $template->current_version)->first();
        abort_if(! $version instanceof \stdClass, 404);
        $version->type = $template->type;
        $language = is_string($input['language'] ?? null) && in_array($input['language'], ['en', 'bm'], true) ? $input['language'] : null;
        if ($language === null) {
            throw ValidationException::withMessages(['language' => 'Choose English or Malay.']);
        }
        $body = $language === 'en' ? (string) $version->body_en : (string) $version->body_bm;
        $details = is_string($input['details'] ?? null) ? str_replace("\r\n", "\n", trim($input['details'])) : '';
        if (str_contains($body, '{details}')) {
            if (mb_strlen($details) < 10 || mb_strlen($details) > self::MAX_DETAILS) {
                throw ValidationException::withMessages(['details' => 'Write the paragraph for this letter: 10 to '.number_format(self::MAX_DETAILS).' characters.']);
            }
            if (preg_match('/[<>{}]/', $details) === 1) {
                throw ValidationException::withMessages(['details' => 'Remove < > { and }: the letter is plain text.']);
            }
        } elseif ($details !== '') {
            throw ValidationException::withMessages(['details' => 'This template has no paragraph to fill. Leave it empty.']);
        }

        return [$version, $language, $details];
    }

    private function fill(Worker $worker, \stdClass $version, string $language, string $details, string $reference, CarbonImmutable $today): string
    {
        $position = DB::table('hr_positions')->where('worker_id', $worker->id)->where('from_date', '<=', $today->toDateString())->orderByDesc('from_date')->value('title');
        $start = DB::table('hr_employment_periods')->where('worker_id', $worker->id)->where('starts_on', '<=', $today->toDateString())->orderByDesc('starts_on')->value('starts_on');
        $branch = $this->branch($worker);
        $values = [
            '{name}' => $this->recipientName($worker),
            '{position}' => is_string($position) && $position !== '' ? $position : ($language === 'en' ? '(no position recorded)' : '(tiada jawatan direkodkan)'),
            '{branch}' => (string) $branch->name,
            '{date}' => $this->date($today, $language),
            '{reference}' => $reference,
            '{start_date}' => $start === null ? ($language === 'en' ? '(no start date recorded)' : '(tiada tarikh mula direkodkan)')
                : $this->date(CarbonImmutable::parse((string) $start), $language),
            '{details}' => $details,
        ];

        return strtr($language === 'en' ? (string) $version->body_en : (string) $version->body_bm, $values);
    }

    private function date(CarbonImmutable $day, string $language): string
    {
        return $language === 'en' ? $day->format('j F Y') : $day->day.' '.self::MONTHS_BM[(int) $day->month].' '.$day->year;
    }

    private function recipientName(Worker $worker): string
    {
        return $this->legalName($worker) ?? $worker->name();
    }

    /** The legal name from the staff file, or null when none is set or it cannot be read. Only letters read it. */
    private function legalName(Worker $worker): ?string
    {
        $raw = $worker->personal?->getRawOriginal('legal_name_encrypted');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $legal = Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null; // an unreadable value falls back to the account name (Suresh, B1)
        }

        return trim($legal) !== '' ? trim($legal) : null;
    }

    /** The worker's primary branch today, else the organisation's first active branch (a locum has no assignment). */
    private function branch(Worker $worker): Branch
    {
        $today = CarbonImmutable::now($this->zone($worker->organisation_id))->toDateString();
        $branchId = $worker->user_id === null ? null : DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')
            ->where('p.user_id', $worker->user_id)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $today))->orderByDesc('a.valid_from')->value('a.branch_id');
        $branch = Branch::query()->where('organisation_id', $worker->organisation_id)->whereKey($branchId)->first()
            ?? Branch::query()->where('organisation_id', $worker->organisation_id)->where('is_active', true)->orderBy('id')->first();
        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages(['worker_id' => 'No branch is set up for this clinic.']);
        }

        return $branch;
    }

    /**
     * The letterhead as printed: the clinic name, the address on one line, then "Tel: …" through PrintedPhone and the
     * email, with the logo bytes frozen in.
     *
     * @param  array<string, mixed>  $captured
     * @return array<string, mixed>
     */
    private function letterheadBlock(array $captured): array
    {
        $d = $captured['details'];
        $address = implode(', ', array_filter([$d['address_line_1'] ?? null, $d['address_line_2'] ?? null, trim(($d['postcode'] ?? '').' '.($d['city'] ?? '')), $d['state'] ?? null],
            fn (?string $v): bool => $v !== null && trim($v) !== ''));
        $phones = implode(' / ', array_filter([PrintedPhone::format($d['phone'] ?? null), PrintedPhone::format($d['phone_secondary'] ?? null)], fn (?string $v): bool => $v !== null));
        $contact = implode(' · ', array_filter([$phones === '' ? null : 'Tel: '.$phones, trim((string) ($d['email'] ?? '')) === '' ? null : trim((string) $d['email'])],
            fn (?string $v): bool => $v !== null));
        $logo = is_array($captured['logo'] ?? null) ? ['src' => (string) $captured['logo']['url'], 'width' => $captured['logo']['width'] ?? null, 'height' => $captured['logo']['height'] ?? null] : null;

        return ['clinicName' => (string) $d['clinic_name'], 'address' => $address, 'contact' => $contact, 'logo' => $logo];
    }

    private function nextNumber(int $organisationId, int $year): int
    {
        DB::table('hr_letter_counters')->insertOrIgnore(['organisation_id' => $organisationId, 'year' => $year, 'last_number' => 0]);
        $counter = DB::table('hr_letter_counters')->where('organisation_id', $organisationId)->where('year', $year)->lockForUpdate()->first();
        abort_if(! $counter instanceof \stdClass, 500);
        if ((int) $counter->last_number >= 999999) {
            throw ValidationException::withMessages(['letter' => 'No letter numbers are left for this year.']);
        }
        $next = (int) $counter->last_number + 1;
        DB::table('hr_letter_counters')->where('id', $counter->id)->update(['last_number' => $next]);

        return $next;
    }

    private function template(User $actor, mixed $templateId, bool|string $lock): \stdClass
    {
        if (! is_string($templateId) || ! Str::isUuid($templateId)) {
            throw ValidationException::withMessages(['template_id' => 'Choose a template.']);
        }
        $query = DB::table('hr_letter_templates')->where('organisation_id', $actor->organisation_id)->where('public_id', $templateId);
        $template = ($lock === 'share' ? $query->sharedLock() : ($lock === true ? $query->lockForUpdate() : $query))->first();
        if (! $template instanceof \stdClass) {
            throw ValidationException::withMessages(['template_id' => 'Choose a template.']);
        }

        return $template;
    }

    private function worker(User $actor, mixed $workerId, bool $lock): Worker
    {
        if (! is_string($workerId) || ! Str::isUuid($workerId)) {
            throw ValidationException::withMessages(['worker_id' => 'Choose a staff file.']);
        }
        $query = Worker::query()->with(['user', 'personal'])->where('organisation_id', $actor->organisation_id)->where('public_id', $workerId);
        $worker = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $worker instanceof Worker) {
            throw ValidationException::withMessages(['worker_id' => 'Choose a staff file.']);
        }
        $refusal = $this->refusal($actor, $worker->user_id);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        return $worker;
    }

    private function replaced(User $actor, Worker $worker, mixed $letterId): ?\stdClass
    {
        if ($letterId === null || $letterId === '') {
            return null;
        }
        if (! is_string($letterId) || ! Str::isUuid($letterId)) {
            throw ValidationException::withMessages(['replaces' => 'Choose the letter to replace.']);
        }
        $old = DB::table('hr_letters')->where('organisation_id', $actor->organisation_id)->where('public_id', $letterId)->lockForUpdate()->first();
        if (! $old instanceof \stdClass || (int) $old->worker_id !== $worker->id) {
            throw ValidationException::withMessages(['replaces' => 'Choose a letter of this staff file.']);
        }
        if ($old->voided_at !== null) {
            throw ValidationException::withMessages(['replaces' => 'This letter is void, so it cannot be replaced. Issue a new letter.']);
        }

        return $old;
    }

    /** The letter's subject account, read before any lock so it can join the id-ordered account lock. */
    private function letterSubject(User $actor, string $letterId): ?int
    {
        abort_if(! Str::isUuid($letterId), 404);
        $row = DB::table('hr_letters as l')->join('hr_workers as w', 'w.id', '=', 'l.worker_id')
            ->where('l.organisation_id', $actor->organisation_id)->where('l.public_id', $letterId)->first(['w.user_id']);
        abort_if(! $row instanceof \stdClass, 404);

        return $row->user_id === null ? null : (int) $row->user_id;
    }

    private function managedLetter(User $actor, string $letterId): \stdClass
    {
        $letter = DB::table('hr_letters as l')->join('hr_workers as w', 'w.id', '=', 'l.worker_id')
            ->where('l.organisation_id', $actor->organisation_id)->where('l.public_id', $letterId)->lock('for update of l')->first(['l.*', 'w.user_id as subject_user_id']);
        abort_if(! $letter instanceof \stdClass, 404);
        $refusal = $this->refusal($actor, $letter->subject_user_id === null ? null : (int) $letter->subject_user_id);
        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        return $letter;
    }

    public function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}
