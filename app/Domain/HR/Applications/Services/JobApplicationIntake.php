<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HR-13 public job-application form (HR-PLAN 15.2), KPOne's first public intake that needs no link. Off unless
 * HR_APPLICATIONS_ENABLED is true. The server is the only judge: a short-lived signed form token with a one-time nonce,
 * a honeypot field and a minimum fill time keep robots out (a robot gets the thank-you page and nothing is stored),
 * the CV must be a real PDF of at most 5 MB, and every personal value is encrypted. The original file name is never
 * kept, nothing is ever shown back to the applicant, and no account is created. Audit holds ids and sizes only.
 */
final class JobApplicationIntake
{
    public const DISK = 'local';

    public const CV_MAX_BYTES = 5 * 1024 * 1024;

    public const HONEYPOT = 'website';

    public function __construct(private AuditRecorder $audit) {}

    public function ensureEnabled(): void
    {
        if (! (bool) config('hr-applications.enabled', false)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * The open positions shown on the form, and a fresh signed form token.
     *
     * @return array{positions:list<array{id:string, title:string, branch:?string}>, formToken:string, privacyNoticeVersion:string}
     */
    public function form(): array
    {
        $this->ensureEnabled();
        $organisationId = self::organisationId();
        $positions = $organisationId === null ? [] : DB::table('hr_job_positions as p')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->where('p.organisation_id', $organisationId)->where('p.is_open', true)
            ->orderBy('p.title')->orderBy('p.id')
            ->get(['p.public_id', 'p.title', 'b.name as branch'])
            ->map(fn (object $p): array => ['id' => (string) $p->public_id, 'title' => (string) $p->title, 'branch' => $p->branch === null ? null : (string) $p->branch])
            ->all();
        $positions = array_values($positions);

        return ['positions' => $positions, 'formToken' => self::issueToken(), 'privacyNoticeVersion' => self::privacyVersion()];
    }

    public static function issueToken(): string
    {
        return Crypt::encryptString(json_encode(['n' => bin2hex(random_bytes(16)), 't' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    public static function privacyVersion(): string
    {
        return (string) config('hr-applications.privacy_notice_version', 'draft-hr13-applications-v1');
    }

    /**
     * Files one application. Returns false when the honeypot or the timing says robot: the caller shows the same
     * thank-you page and nothing is stored.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(array $input, mixed $cv): bool
    {
        $this->ensureEnabled();
        $nonce = $this->nonce($input['form_token'] ?? null, $robot);
        if (! empty($input[self::HONEYPOT])) {
            return false;
        }
        // Fields first, so a person who presses Send too soon on an empty form sees what to fill in; a complete form
        // sent within the minimum time is a robot (found in the HR-13 screenshot run).
        // One send names everything to fix, the CV included.
        $errors = [];
        try {
            $fields = self::validated($input);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        try {
            $prepared = self::prepareCv($cv);
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors()];
        }
        if ($errors !== [] || ! isset($fields, $prepared)) {
            throw ValidationException::withMessages($errors);
        }
        if ($robot) {
            return false;
        }

        $path = null;
        try {
            DB::transaction(function () use ($fields, $prepared, $nonce, &$path): void {
                $position = DB::table('hr_job_positions')->where('public_id', $fields['position'])->sharedLock()->first();
                if ($position === null || ! $position->is_open || (int) $position->organisation_id !== self::organisationId()) {
                    throw ValidationException::withMessages(['position' => 'This position is no longer open. Choose another.']);
                }
                if (DB::table('hr_job_applications')->where('form_nonce', $nonce)->exists()) {
                    throw ValidationException::withMessages(['form' => 'This form was already sent. Reload the page to apply again.']);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-applications/{$position->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                    $path = null;
                    throw ValidationException::withMessages(['cv' => 'Your CV could not be saved. Try again.']);
                }
                $now = now()->utc();
                try {
                    $id = DB::table('hr_job_applications')->insertGetId(['public_id' => $publicId, 'organisation_id' => $position->organisation_id,
                        'position_id' => $position->id, 'status' => 'new', 'name_encrypted' => Crypt::encryptString($fields['name']),
                        'phone_encrypted' => Crypt::encryptString($fields['phone']), 'email_encrypted' => Crypt::encryptString($fields['email']),
                        'message_encrypted' => $fields['message'] === null ? null : Crypt::encryptString($fields['message']),
                        'cv_path' => $path, 'cv_bytes' => $prepared['bytes'], 'cv_sha256' => $prepared['sha256'],
                        'privacy_notice_version' => self::privacyVersion(), 'form_nonce' => $nonce, 'submitted_at' => $now,
                        'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                } catch (UniqueConstraintViolationException) {
                    // Two sends of one form at once (Suresh, HR-13 minor 1): the second gets the same message as a retry.
                    throw ValidationException::withMessages(['form' => 'This form was already sent. Reload the page to apply again.']);
                }
                DB::table('hr_job_application_events')->insert(['organisation_id' => $position->organisation_id, 'application_id' => $id,
                    'action' => 'submitted', 'to_status' => 'new', 'created_at' => $now]);
                $this->audit->record('hr.applications.received', null, ['application_id' => $id, 'position_id' => (int) $position->id,
                    'bytes' => $prepared['bytes']], null, null, (int) $position->organisation_id);
            }, 3);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }

        return true;
    }

    /**
     * The nonce from a signed form token; $robot is set when it was sent too fast to be a person.
     *
     * @param-out bool $robot
     */
    private function nonce(mixed $token, ?bool &$robot): string
    {
        $robot = false;
        $stale = ValidationException::withMessages(['form' => 'This form has expired. Reload the page and try again.']);
        if (! is_string($token) || $token === '' || strlen($token) > 1024) {
            throw $stale;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $stale;
        }
        if (! is_array($data) || ! is_string($data['n'] ?? null) || preg_match('/^[0-9a-f]{32}$/', $data['n']) !== 1 || ! is_int($data['t'] ?? null)) {
            throw $stale;
        }
        $age = now()->getTimestamp() - $data['t'];
        if ($age < 0 || $age > (int) config('hr-applications.form_ttl_minutes', 60) * 60) {
            throw $stale;
        }
        $robot = $age < (int) config('hr-applications.minimum_fill_seconds', 5);

        return $data['n'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{position:string, name:string, phone:string, email:string, message:?string}
     */
    public static function validated(array $input): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input[$key])) : '';
        $errors = [];
        $position = is_string($input['position'] ?? null) ? $input['position'] : '';
        if (! Str::isUuid($position)) {
            $errors['position'] = 'Choose the position you are applying for.';
        }
        $name = $text('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter your name (2 to 100 characters).';
        }
        $phone = $text('phone');
        if (preg_match('/^\+?[0-9][0-9 \-]{6,18}[0-9]$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a phone number we can call, for example 012-345 6789.';
        }
        $email = $text('email');
        if (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter an email address, for example name@example.com.';
        }
        $raw = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        if (mb_strlen($raw) > 1000) {
            $errors['message'] = 'Use at most 1,000 characters.';
        }
        if (! filter_var($input['privacy_accepted'] ?? false, FILTER_VALIDATE_BOOL)) {
            $errors['privacy_accepted'] = 'Read the privacy notice and tick the box to apply.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['position' => $position, 'name' => $name, 'phone' => $phone, 'email' => mb_strtolower($email), 'message' => $raw === '' ? null : $raw];
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string} a real PDF of at most 5 MB, checked by content */
    public static function prepareCv(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['cv' => 'Attach your CV as a PDF.']);
        }
        if ((string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) !== 'application/pdf') {
            throw ValidationException::withMessages(['cv' => 'Your CV must be a PDF file.']);
        }
        $prepared = LeaveEvidenceFiles::prepare($file, 'cv', self::CV_MAX_BYTES);
        if ($prepared['kind'] !== 'pdf') {
            throw ValidationException::withMessages(['cv' => 'Your CV must be a PDF file.']);
        }

        return $prepared;
    }

    /** One organisation per installation; the form files into the first. */
    public static function organisationId(): ?int
    {
        $id = Organisation::query()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
