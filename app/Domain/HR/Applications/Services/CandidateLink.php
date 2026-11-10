<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Applications\HiringTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-17b candidate private link (HR-PLAN 19.5). The token is 32 random bytes, shown once on the thank-you page and in
 * the "received" email, and travels in the URL fragment (/careers/status#token) so it never reaches a server log; the
 * page exchanges it for an httpOnly cookie. Only a keyed hash is stored, and the purge clears it, so the link lapses
 * with the application's values. There is no account, sign-up or password. The candidate sees the stage in plain
 * words, the clinic's messages and a "Withdraw my application" button, never HR's rating, note or checklist.
 */
final class CandidateLink
{
    /** @var array<string, string> what the candidate sees for each stage (never HR's working words) */
    public const CANDIDATE_STAGES = [
        'new' => 'We have your application',
        'shortlisted' => 'We are looking at your application',
        'phone_screening' => 'We will call you',
        'scheduling' => 'Invited to an interview',
        'interview' => 'Interview',
        'offered' => 'Closed',
        'passed' => 'Passed the interview',
        'failed' => 'Not taken forward',
        'not_taken_forward' => 'Not taken forward',
        'auto_rejected' => 'Not taken forward',
        'withdrawn' => 'Withdrawn',
    ];

    /** @var array<string, string> */
    public const CANDIDATE_STAGES_MS = [
        'new' => 'Permohonan anda telah diterima',
        'shortlisted' => 'Permohonan anda sedang disemak',
        'phone_screening' => 'Kami akan menghubungi anda',
        'scheduling' => 'Dijemput ke temu duga',
        'interview' => 'Temu duga',
        'offered' => 'Ditutup',
        'passed' => 'Lulus temu duga',
        'failed' => 'Tidak diteruskan',
        'not_taken_forward' => 'Tidak diteruskan',
        'auto_rejected' => 'Tidak diteruskan',
        'withdrawn' => 'Ditarik balik',
    ];

    public const INVALID = 'This link is not valid any more. / Pautan ini tidak sah lagi.';

    public function __construct(private HiringMessenger $messenger, private AuditRecorder $audit) {}

    /** @return array{0:string, 1:string} the raw token (shown once) and its keyed hash (stored) */
    public static function issue(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, 'hr-application-link:'.(string) config('app.key'));
    }

    public static function valid(mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    public static function url(string $token): string
    {
        return route('hr-applications.status').'#'.$token;
    }

    public function resolve(mixed $token, bool $lock = false): ?\stdClass
    {
        if (! self::valid($token)) {
            return null;
        }
        $query = DB::table('hr_job_applications')->where('status_token_hash', self::hash((string) $token))->whereNull('purged_at');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * What the status page shows. No name, phone, email or answer the candidate sent is shown back.
     *
     * @return array<string, mixed>
     */
    public function view(\stdClass $application): array
    {
        $zone = JobApplicationService::zone((int) $application->organisation_id);

        return [
            'reference' => HiringMessenger::reference((string) $application->public_id),
            'position' => HiringMessenger::positionTitle((int) $application->position_id),
            'stage' => (string) $application->status,
            'stageLabel' => self::CANDIDATE_STAGES[$application->status] ?? 'Closed',
            'stageLabelMs' => self::CANDIDATE_STAGES_MS[$application->status] ?? 'Ditutup',
            'open' => in_array($application->status, JobApplicationService::OPEN, true),
            'interview' => self::interviewView($application),
            // HR-17d: the clinic's documents for a confirmed interview (title and size only).
            'documents' => InterviewDocuments::candidateList($application),
            // HR-17e: whether they ticked the talent pool and can still leave it.
            'pool' => TalentPool::candidateView($application),
            'submittedOn' => CarbonImmutable::parse($application->submitted_at)->setTimezone($zone)->toDateString(),
            'messages' => array_map(fn (array $m): array => ['label' => HiringTemplates::candidateLabel($m['template']), 'subject' => $m['subject'], 'body' => $m['body'], 'at' => $m['at']],
                HiringMessenger::listFor($application, true)),
        ];
    }

    /**
     * HR-17c: at Scheduling, the slots to pick or the picks waiting for the clinic; at Interview, the confirmed time.
     *
     * @return array<string, mixed>|null
     */
    public static function interviewView(\stdClass $application): ?array
    {
        $slots = app(InterviewSlots::class);
        $branch = $slots->branchFor($application);
        $zone = $branch['zone'] ?? 'UTC';
        $label = fn (string $at): string => CarbonImmutable::parse($at)->setTimezone($zone)->format('D j M Y, g:i A');
        if ($application->status === 'scheduling') {
            $pick = DB::table('hr_job_interview_picks')->where('application_id', $application->id)->where('round', $application->interview_round)->first();

            return ['branch' => $branch['name'] ?? null, 'picked' => $pick === null ? [] : array_map($label, (array) json_decode((string) $pick->starts, true)),
                'days' => $pick === null ? $slots->available($application) : [], 'picks' => InterviewSlots::PICKS,
                // "Kuala Lumpur time" (Daniel, HR-17c): the times are the branch's.
                'zone' => str_replace('_', ' ', (string) last(explode('/', $zone)))];
        }
        if ($application->status === 'interview') {
            $interview = DB::table('hr_job_interviews as i')->join('branches as b', 'b.id', '=', 'i.branch_id')->where('i.application_id', $application->id)
                ->where('i.status', 'confirmed')->first(['i.starts_at', 'b.name', 'b.timezone']);

            return $interview === null ? null : ['confirmed' => CarbonImmutable::parse($interview->starts_at)->setTimezone((string) ($interview->timezone ?: 'UTC'))
                ->format('D j M Y, g:i A'), 'branch' => (string) $interview->name];
        }

        return null;
    }

    /** The candidate withdraws an open application through their private link. */
    public function withdraw(mixed $token): void
    {
        DB::transaction(function () use ($token): void {
            $row = $this->resolve($token, true);
            if ($row === null) {
                throw ValidationException::withMessages(['link' => self::INVALID]);
            }
            if (! in_array($row->status, JobApplicationService::OPEN, true)) {
                throw ValidationException::withMessages(['withdraw' => 'This application is already closed. / Permohonan ini sudah ditutup.']);
            }
            $now = now()->utc();
            InterviewSlots::releaseOnWithdraw((int) $row->id, null);
            DB::table('hr_job_applications')->where('id', $row->id)->update(['status' => 'withdrawn', 'closed_at' => $now, 'stage_entered_at' => $now,
                'lock_version' => (int) $row->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert([
                ['organisation_id' => $row->organisation_id, 'application_id' => $row->id, 'action' => 'candidate_withdrew', 'from_status' => null,
                    'to_status' => null, 'actor_user_id' => null, 'created_at' => $now],
                ['organisation_id' => $row->organisation_id, 'application_id' => $row->id, 'action' => 'status_changed', 'from_status' => $row->status,
                    'to_status' => 'withdrawn', 'actor_user_id' => null, 'created_at' => $now],
            ]);
            $this->audit->record('hr.applications.withdrawn_by_candidate', null, ['application_id' => (int) $row->id, 'from' => $row->status], null, null, (int) $row->organisation_id);
            $this->messenger->record($row, 'withdrawn', 'withdrawn');
        }, 3);
    }
}
