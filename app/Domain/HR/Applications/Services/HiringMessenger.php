<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\HiringTemplates;
use App\Mail\HiringMessageMail;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * HR-17b: records a fixed hiring message for one application and stage, inside the caller's transaction, and emails it
 * right after the commit (no queue, no worker; owner, 2026-11-10). With hiring email switched off, or no hiring mailbox
 * set for a reminder, the row is "off" and the message is shown in KPOne only. A message is written at most once for
 * each application, template and stage, so a retry or a second run sends nothing more. A failed send is recorded on
 * the row and never undoes the hiring change. The text is never stored.
 */
final class HiringMessenger
{
    public static function emailEnabled(): bool
    {
        return (bool) config('hr-applications.email_enabled', false);
    }

    public static function mailbox(): ?string
    {
        $address = config('hr-applications.hiring_mailbox');

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /**
     * Call inside the transaction that made the change. $link is the full private link, given only with "received".
     *
     * @param  array<string, string>  $extra  the reminder's reference and stage
     */
    public function record(\stdClass $application, string $template, string $stage, ?string $link = null, array $extra = []): void
    {
        $toMailbox = $template === 'auto_reject_reminder';
        $params = ['position' => self::positionTitle((int) $application->position_id), ...$extra];
        if ($template === 'received') {
            $params['link'] = $link ?? HiringTemplates::LINK_SHOWN_ONCE;
        }
        try {
            $message = HiringTemplates::render($template, $params);
        } catch (\Throwable $e) {
            Log::warning('Hiring message not recorded: bad template call', ['template' => $template, 'application_id' => $application->id, 'error' => $e::class]);

            return;
        }
        $off = ! self::emailEnabled() || ($toMailbox && self::mailbox() === null);
        $now = now()->utc();
        $inserted = DB::table('hr_job_application_messages')->insertOrIgnore(['organisation_id' => $application->organisation_id,
            'application_id' => $application->id, 'template' => $template, 'recipient' => $toMailbox ? 'mailbox' : 'candidate', 'stage' => $stage,
            'status' => $off ? 'off' : 'sending', 'created_at' => $now, 'updated_at' => $now]);
        if ($inserted === 0 || $off) {
            return;
        }
        $id = (int) DB::table('hr_job_application_messages')->where('application_id', $application->id)->where('template', $template)->where('stage', $stage)->value('id');
        $applicationId = (int) $application->id;
        DB::afterCommit(function () use ($id, $applicationId, $toMailbox, $message): void {
            $this->send($id, $applicationId, $toMailbox, $message);
        });
    }

    /**
     * The messages for one application, newest first, with the text rebuilt (the private link is never shown again).
     *
     * @return list<array{template:string, label:string, recipient:string, status:string, subject:string, body:string, at:string}>
     */
    public static function listFor(\stdClass $application, bool $candidateOnly): array
    {
        $query = DB::table('hr_job_application_messages')->where('application_id', $application->id)->orderByDesc('created_at')->orderByDesc('id');
        if ($candidateOnly) {
            $query->where('recipient', 'candidate');
        }
        $title = self::positionTitle((int) $application->position_id);

        return array_values(array_map(fn (\stdClass $row): array => self::shown($row, (string) $application->public_id, $title), $query->get()->all()));
    }

    /**
     * One message row as shown, its text rebuilt from the fixed template.
     *
     * @return array{template:string, label:string, recipient:string, status:string, subject:string, body:string, at:string}
     */
    public static function shown(\stdClass $row, string $publicId, string $title): array
    {
        $template = (string) $row->template;
        $params = ['position' => $title];
        if ($template === 'received') {
            $params['link'] = HiringTemplates::LINK_SHOWN_ONCE;
        }
        if ($template === 'auto_reject_reminder') {
            $params += ['reference' => self::reference($publicId), 'stage' => (string) (JobApplicationService::STATUSES[$row->stage] ?? 'New')];
        }
        try {
            $text = HiringTemplates::render($template, $params);
        } catch (\Throwable) {
            $text = ['subject' => HiringTemplates::label($template), 'body' => ''];
        }

        return ['template' => $template, 'label' => HiringTemplates::label($template), 'recipient' => (string) $row->recipient,
            'status' => (string) $row->status, 'subject' => $text['subject'], 'body' => $text['body'],
            'at' => CarbonImmutable::parse($row->created_at)->toIso8601String()];
    }

    public static function reference(string $publicId): string
    {
        return strtoupper(substr(str_replace('-', '', $publicId), 0, 8));
    }

    /** The position title as a message may carry it: no line breaks or markup characters. */
    public static function positionTitle(int $positionId): string
    {
        $title = (string) DB::table('hr_job_positions')->where('id', $positionId)->value('title');
        $clean = trim((string) preg_replace('/[\r\n<>{}]+/u', ' ', $title));

        return mb_strlen($clean) >= 3 ? mb_substr($clean, 0, 80) : 'the position';
    }

    /** @param array{subject:string, body:string} $message */
    private function send(int $id, int $applicationId, bool $toMailbox, array $message): void
    {
        try {
            $address = $toMailbox ? self::mailbox() : $this->candidateEmail($applicationId);
            if ($address === null) {
                throw new \RuntimeException('no_address');
            }
            $sent = Mail::to($address)->send(new HiringMessageMail($message['subject'], $message['body']));
            $providerId = $sent?->getMessageId();
            DB::table('hr_job_application_messages')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent',
                'provider_message_id' => is_string($providerId) && $providerId !== '' ? mb_substr($providerId, 0, 200) : null, 'updated_at' => now()->utc()]);
        } catch (\Throwable $e) {
            $code = $e->getMessage() === 'no_address' ? 'no_address' : 'send_failed';
            try {
                DB::table('hr_job_application_messages')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()->utc()]);
            } catch (\Throwable) {
                // Recorded in the log below; the hiring change has already committed.
            }
            Log::warning('Hiring message failed', ['message_id' => $id, 'error_code' => $code, 'error' => $e::class]);
        }
    }

    private function candidateEmail(int $applicationId): ?string
    {
        $encrypted = DB::table('hr_job_applications')->where('id', $applicationId)->whereNull('purged_at')->value('email_encrypted');
        if (! is_string($encrypted)) {
            return null;
        }
        try {
            $email = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
