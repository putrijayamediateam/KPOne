<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
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

    // Applicants see English and Bahasa Melayu side by side (owner, 2026-11-07).
    public const ALREADY_SENT = 'This form was already sent. Reload the page to apply again. / Borang ini sudah dihantar. Muat semula halaman untuk memohon lagi.';

    public const NOT_PDF = 'Your CV must be a PDF file. / CV anda mestilah fail PDF.';

    public function __construct(private AuditRecorder $audit, private HiringMessenger $messenger) {}

    public function ensureEnabled(): void
    {
        if (self::state() !== 'on') {
            throw new NotFoundHttpException;
        }
    }

    /**
     * 'off' while HR_APPLICATIONS_ENABLED is false; 'needs_retention' while HR has not saved how long applications are
     * kept, because the privacy notice promises deletion after that period (Daniel, HR-13); otherwise 'on'.
     */
    public static function state(): string
    {
        if (! (bool) config('hr-applications.enabled', false)) {
            return 'off';
        }
        $organisationId = self::organisationId();
        $latest = $organisationId === null ? null : DB::table('hr_job_application_settings')->where('organisation_id', $organisationId)
            ->orderByDesc('version')->value('retention_days');

        return $latest === null ? 'needs_retention' : 'on';
    }

    /**
     * The open positions shown on the form, and a fresh signed form token.
     *
     * @return array{positions:list<array{id:string, title:string, branch:?string, type:string}>, branches:list<array{value:string, label:string}>, education:list<array{value:string, label:string}>, languages:list<array{value:string, label:string}>, formToken:string, privacyNoticeVersion:string}
     */
    public function form(): array
    {
        $this->ensureEnabled();
        $organisationId = self::organisationId();
        $positions = $organisationId === null ? [] : DB::table('hr_job_positions as p')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->where('p.organisation_id', $organisationId)->where('p.is_open', true)
            ->orderBy('p.title')->orderBy('p.id')
            ->get(['p.public_id', 'p.title', 'p.position_type', 'b.name as branch'])
            ->map(fn (object $p): array => ['id' => (string) $p->public_id, 'title' => (string) $p->title, 'branch' => $p->branch === null ? null : (string) $p->branch,
                'type' => (string) $p->position_type])
            ->all();
        $positions = array_values($positions);

        $choices = fn (array $labels): array => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), $labels);

        return ['positions' => $positions, 'branches' => self::branches($organisationId), 'education' => $choices(self::EDUCATION),
            'languages' => $choices(self::LANGUAGES), 'formToken' => self::issueToken(), 'privacyNoticeVersion' => self::privacyVersion()];
    }

    public static function issueToken(): string
    {
        return Crypt::encryptString(json_encode(['n' => bin2hex(random_bytes(16)), 't' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    public static function privacyVersion(): string
    {
        return (string) config('hr-applications.privacy_notice_version', 'draft-hr17-applications-v1');
    }

    /**
     * Files one application. Returns its private link token (HR-17b), shown once on the thank-you page, or null when
     * the honeypot or the timing says robot: the caller shows the same thank-you page and nothing is stored.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(array $input, mixed $cv): ?string
    {
        $this->ensureEnabled();
        $nonce = $this->nonce($input['form_token'] ?? null, $robot);
        if (! empty($input[self::HONEYPOT])) {
            return null;
        }
        // Fields first, so a person who presses Send too soon on an empty form sees what to fill in; a complete form
        // sent within the minimum time is a robot (found in the HR-13 screenshot run).
        // One send names everything to fix, the CV included.
        $errors = [];
        $type = self::positionType($input['position'] ?? null);
        try {
            $fields = self::validated($input, $type);
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
            return null;
        }

        $path = null;
        [$token, $tokenHash] = CandidateLink::issue();
        try {
            DB::transaction(function () use ($fields, $prepared, $nonce, $type, &$path, $token, $tokenHash): void {
                $position = DB::table('hr_job_positions')->where('public_id', $fields['position'])->sharedLock()->first();
                if ($position === null || ! $position->is_open || (int) $position->organisation_id !== self::organisationId()) {
                    throw ValidationException::withMessages(['position' => 'This position is no longer open. Choose another. / Jawatan ini sudah ditutup. Pilih jawatan lain.']);
                }
                if ((string) $position->position_type !== $type) {
                    throw ValidationException::withMessages(['position' => 'This position changed. Reload the page and check your answers. / Jawatan ini telah berubah. Muat semula halaman dan semak jawapan anda.']);
                }
                if (DB::table('hr_job_applications')->where('form_nonce', $nonce)->exists()) {
                    throw ValidationException::withMessages(['form' => self::ALREADY_SENT]);
                }
                $publicId = (string) Str::uuid();
                $path = "hr-applications/{$position->organisation_id}/{$publicId}.bin";
                if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
                    $path = null;
                    throw ValidationException::withMessages(['cv' => 'Your CV could not be saved. Try again. / CV anda tidak dapat disimpan. Cuba lagi.']);
                }
                $now = now()->utc();
                try {
                    $id = DB::table('hr_job_applications')->insertGetId(['public_id' => $publicId, 'organisation_id' => $position->organisation_id,
                        'position_id' => $position->id, 'status' => 'new', 'name_encrypted' => Crypt::encryptString($fields['name']),
                        'phone_encrypted' => Crypt::encryptString($fields['phone']), 'email_encrypted' => Crypt::encryptString($fields['email']),
                        'message_encrypted' => $fields['message'] === null ? null : Crypt::encryptString($fields['message']),
                        'details_encrypted' => Crypt::encryptString(json_encode($fields['details'], JSON_THROW_ON_ERROR)),
                        'talent_pool_consent' => $fields['talent_pool_consent'],
                        'talent_pool_text_version' => $fields['talent_pool_consent'] ? (string) config('hr-applications.pool_text_version', 'draft-hr17e-pool-v1') : null,
                        'cv_path' => $path, 'cv_bytes' => $prepared['bytes'], 'cv_sha256' => $prepared['sha256'],
                        'privacy_notice_version' => self::privacyVersion(), 'form_nonce' => $nonce, 'submitted_at' => $now,
                        'status_token_hash' => $tokenHash, 'stage_entered_at' => $now,
                        'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                } catch (UniqueConstraintViolationException) {
                    // Two sends of one form at once (Suresh, HR-13 minor 1): the second gets the same message as a retry.
                    throw ValidationException::withMessages(['form' => self::ALREADY_SENT]);
                }
                DB::table('hr_job_application_events')->insert(['organisation_id' => $position->organisation_id, 'application_id' => $id,
                    'action' => 'submitted', 'to_status' => 'new', 'created_at' => $now]);
                $this->audit->record('hr.applications.received', null, ['application_id' => $id, 'position_id' => (int) $position->id,
                    'bytes' => $prepared['bytes']], null, null, (int) $position->organisation_id);
                $row = DB::table('hr_job_applications')->where('id', $id)->first();
                if ($row instanceof \stdClass) {
                    $this->messenger->record($row, 'received', 'new', CandidateLink::url($token));
                }
            }, 3);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }

        return $token;
    }

    /**
     * The nonce from a signed form token; $robot is set when it was sent too fast to be a person.
     *
     * @param-out bool $robot
     */
    private function nonce(mixed $token, ?bool &$robot): string
    {
        $robot = false;
        $stale = ValidationException::withMessages(['form' => 'This form has expired. Reload the page and try again. / Borang ini sudah tamat tempoh. Muat semula halaman dan cuba lagi.']);
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
     * @return array{position:string, name:string, phone:string, email:string, message:?string, details:array<string, mixed>, talent_pool_consent:bool}
     */
    public static function validated(array $input, string $type = 'ca'): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input[$key])) : '';
        $errors = [];
        $position = is_string($input['position'] ?? null) ? $input['position'] : '';
        if (! Str::isUuid($position)) {
            $errors['position'] = 'Choose the position you are applying for. / Pilih jawatan yang anda mohon.';
        }
        $name = $text('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter your name (2 to 100 characters). / Masukkan nama anda (2 hingga 100 aksara).';
        }
        $phone = $text('phone');
        if (preg_match('/^\+?[0-9][0-9 \-]{6,18}[0-9]$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a phone number we can call, for example 012-345 6789. / Masukkan nombor telefon yang boleh dihubungi, contohnya 012-345 6789.';
        }
        $email = $text('email');
        if (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter an email address, for example name@example.com. / Masukkan alamat e-mel, contohnya nama@example.com.';
        }
        $raw = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        if (mb_strlen($raw) > 1000) {
            $errors['message'] = 'Use at most 1,000 characters. / Gunakan tidak lebih 1,000 aksara.';
        }
        if (! filter_var($input['privacy_accepted'] ?? false, FILTER_VALIDATE_BOOL)) {
            $errors['privacy_accepted'] = 'Read the privacy notice and tick the box to apply. / Baca notis privasi dan tandakan kotak untuk memohon.';
        }
        $details = self::details($input, $type, $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['position' => $position, 'name' => $name, 'phone' => $phone, 'email' => mb_strtolower($email), 'message' => $raw === '' ? null : $raw,
            // HR-17e: an optional, separate tick, never preset; the candidate can leave the pool on their private page.
            'details' => $details, 'talent_pool_consent' => filter_var($input['talent_pool'] ?? false, FILTER_VALIDATE_BOOL)];
    }

    /**
     * HR-17a: the form's other answers (HR-PLAN 19.4). No IC number, date of birth, photo, salary or wage is asked. A
     * doctor's MMC number and APC are as the candidate states them; KPOne does not check them.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function details(array $input, string $type, array &$errors): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input[$key])) : '';
        $today = CarbonImmutable::now(JobApplicationService::zone((int) self::organisationId()))->startOfDay();
        $date = function (string $key) use ($text): ?CarbonImmutable {
            $value = $text($key);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed !== null && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };
        $details = ['type' => $type];

        $branch = $text('preferred_branch');
        $codes = array_column(self::branches(self::organisationId()), 'value');
        if ($branch !== 'any' && ! in_array($branch, $codes, true)) {
            $errors['preferred_branch'] = 'Choose a branch, or "Any branch". / Pilih cawangan, atau "Mana-mana cawangan".';
        }
        $details['preferred_branch'] = $branch;
        $start = $date('start_date');
        if ($start === null || $start->lt($today) || $start->gt($today->addYear())) {
            $errors['start_date'] = 'Enter the earliest date you can start, within the next year. / Masukkan tarikh terawal anda boleh mula, dalam tempoh setahun.';
        }
        $details['start_date'] = $start?->toDateString();
        $education = $text('education');
        if (! isset(self::EDUCATION[$education])) {
            $errors['education'] = 'Choose your highest education. / Pilih pendidikan tertinggi anda.';
        }
        $details['education'] = $education;
        $years = filter_var($input['years_experience'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        if ($years === false) {
            $errors['years_experience'] = 'Enter your years of relevant work, from 0 to 50. / Masukkan tahun pengalaman kerja berkaitan, dari 0 hingga 50.';
        }
        $details['years_experience'] = $years === false ? null : $years;
        $languages = is_array($input['languages'] ?? null) ? array_values(array_unique(array_filter($input['languages'], 'is_string'))) : [];
        if ($languages === [] || array_diff($languages, array_keys(self::LANGUAGES)) !== []) {
            $errors['languages'] = 'Choose the languages you speak. / Pilih bahasa yang anda tuturkan.';
        }
        $details['languages'] = $languages;

        if ($type === 'intern') {
            foreach (['institution' => 'Enter your college or university. / Masukkan kolej atau universiti anda.', 'programme' => 'Enter your course or programme. / Masukkan kursus atau program anda.'] as $key => $message) {
                $value = $text($key);
                if (mb_strlen($value) < 2 || mb_strlen($value) > 120) {
                    $errors[$key] = $message;
                }
                $details[$key] = $value;
            }
            $from = $date('intern_from');
            $to = $date('intern_to');
            if ($from === null || $to === null || $to->lt($from) || $from->lt($today) || $to->gt($today->addYears(2))) {
                $errors['intern_from'] = 'Enter when your internship starts and ends. / Masukkan tarikh latihan industri anda bermula dan tamat.';
            }
            $details['intern_from'] = $from?->toDateString();
            $details['intern_to'] = $to?->toDateString();
        }
        if ($type === 'locum') {
            $value = $text('availability');
            if (mb_strlen($value) < 5 || mb_strlen($value) > 300) {
                $errors['availability'] = 'Tell us the days and times you are usually free (5 to 300 characters). / Nyatakan hari dan masa anda biasanya lapang (5 hingga 300 aksara).';
            }
            $details['availability'] = $value;
        }
        if ($type === 'resident_doctor') {
            $mmc = $text('mmc_number');
            if (preg_match('/^[0-9]{3,7}$/', $mmc) !== 1) {
                $errors['mmc_number'] = 'Enter your MMC registration number (digits only). / Masukkan nombor pendaftaran MMC anda (nombor sahaja).';
            }
            $details['mmc_number'] = $mmc;
            $year = filter_var($input['apc_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $today->year - 1, 'max_range' => $today->year + 1]]);
            if ($year === false) {
                $errors['apc_year'] = 'Enter the year of your current APC. / Masukkan tahun APC semasa anda.';
            }
            $details['apc_year'] = $year === false ? null : $year;
            $expiry = $date('apc_expiry');
            if ($expiry === null || $expiry->gt($today->addYears(2))) {
                $errors['apc_expiry'] = 'Enter the date your APC expires. / Masukkan tarikh APC anda tamat.';
            }
            $details['apc_expiry'] = $expiry?->toDateString();
        }

        return $details;
    }

    /** @var array<string, string> */
    public const EDUCATION = [
        'spm' => 'SPM',
        'stpm_diploma' => 'STPM, diploma or certificate / STPM, diploma atau sijil',
        'degree' => 'Degree / Ijazah',
        'postgraduate' => 'Master\'s or higher / Sarjana atau lebih tinggi',
        'other' => 'Other / Lain-lain',
    ];

    /** @var array<string, string> */
    public const LANGUAGES = [
        'malay' => 'Bahasa Melayu',
        'english' => 'English',
        'chinese' => 'Chinese / Cina',
        'tamil' => 'Tamil',
        'other' => 'Other / Lain-lain',
    ];

    /** The position's type for choosing which questions to check; a missing or unknown position counts as 'ca'. */
    private static function positionType(mixed $positionId): string
    {
        if (! is_string($positionId) || ! Str::isUuid($positionId)) {
            return 'ca';
        }
        $type = DB::table('hr_job_positions')->where('public_id', $positionId)->value('position_type');

        return is_string($type) && isset(JobApplicationService::TYPES[$type]) ? $type : 'ca';
    }

    /**
     * Active branches the candidate may prefer, by code.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function branches(?int $organisationId): array
    {
        if ($organisationId === null) {
            return [];
        }

        return array_values(DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')
            ->get(['code', 'name'])->map(fn (object $b): array => ['value' => (string) $b->code, 'label' => (string) $b->name])->all());
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string} a real PDF of at most 5 MB, checked by content */
    public static function prepareCv(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['cv' => 'Attach your CV as a PDF. / Lampirkan CV anda dalam bentuk PDF.']);
        }
        if ((string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) !== 'application/pdf') {
            throw ValidationException::withMessages(['cv' => self::NOT_PDF]);
        }
        try {
            $prepared = LeaveEvidenceFiles::prepare($file, 'cv', self::CV_MAX_BYTES);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['cv' => str_contains((string) ($e->errors()['cv'][0] ?? ''), 'MB')
                ? 'Your CV is over 5 MB. / CV anda melebihi 5 MB.'
                : "This PDF can't be opened. Save it again and try once more. / PDF ini tidak dapat dibuka. Simpan semula dan cuba lagi."]);
        }
        if ($prepared['kind'] !== 'pdf') {
            throw ValidationException::withMessages(['cv' => self::NOT_PDF]);
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
