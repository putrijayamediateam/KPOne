<?php

namespace App\Domain\HR\Messages\Services;

use App\Domain\HR\Messages\Channels\EmailChannel;
use App\Domain\HR\Messages\Channels\StaffChannel;
use App\Domain\HR\Messages\Channels\UnconnectedChannel;
use App\Domain\HR\Messages\MessageTemplates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HR-14: sends one fixed message to one staff member on every channel they chose that is connected and switched on.
 * It runs after the HR change has committed, so a failure here never touches HR data. Each message first takes its
 * place under the channel's monthly cap in a short transaction (a "sending" row), is sent outside any transaction,
 * then gets its outcome. A message over the cap is recorded as skipped and not sent. The text is never stored.
 */
final class MessageDelivery
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    /** @var array<string, true> channels whose mail service failed earlier in this same action (Suresh, HR-14) */
    private array $broken = [];

    public function channel(string $channel): StaffChannel
    {
        return $channel === 'email' ? app(EmailChannel::class) : app(UnconnectedChannel::class);
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, int>  $subjectIds
     */
    public function deliver(int $organisationId, int $userId, string $template, array $params, array $subjectIds): void
    {
        try {
            $user = User::query()->whereKey($userId)->where('organisation_id', $organisationId)->first();
            if ($user === null || ! $user->is_active) {
                return;
            }
            $message = MessageTemplates::render($template, $params);
            $chosen = DB::table('hr_message_preferences')->where('user_id', $userId)->where('opted_in', true)->pluck('channel')->all();
            foreach (array_intersect(self::CHANNELS, $chosen) as $channel) {
                $driver = $this->channel($channel);
                if (! $driver->connected()) {
                    continue;
                }
                $deliveryId = $this->reserve($organisationId, $userId, $channel, $template, $subjectIds);
                if ($deliveryId === null) {
                    continue;
                }
                if (isset($this->broken[$channel])) {
                    // The channel failed earlier in this same action (a payroll lock sends one message per person):
                    // record the rest as not tried instead of waiting on a mail service that is down (Suresh, HR-14).
                    DB::table('hr_message_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'error_code' => 'send_skipped', 'updated_at' => now()->utc()]);

                    continue;
                }
                $this->send($deliveryId, $driver, $user, $message, $channel);
            }
        } catch (\Throwable $e) {
            // Never thrown back into HR: the HR change has already committed.
            Log::warning('HR staff message could not be prepared', ['user_id' => $userId, 'template' => $template, 'error' => $e::class]);
        }
    }

    /**
     * The settings in force for a channel: the newest version, or null when the channel was never set up.
     */
    public static function settings(int $organisationId, string $channel): ?\stdClass
    {
        $row = DB::table('hr_message_channel_settings')->where('organisation_id', $organisationId)->where('channel', $channel)->orderByDesc('version')->first();

        return $row instanceof \stdClass ? $row : null;
    }

    public static function periodMonth(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->startOfMonth()->toDateString();
    }

    /**
     * Takes the message's place under the monthly cap. Returns the delivery id to send, or null when the channel is
     * off, or the cap is reached (recorded as skipped).
     *
     * @param  array<string, int>  $subjectIds
     */
    private function reserve(int $organisationId, int $userId, string $channel, string $template, array $subjectIds): ?int
    {
        return DB::transaction(function () use ($organisationId, $userId, $channel, $template, $subjectIds): ?int {
            $month = self::periodMonth($organisationId);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hr_message_cap:{$organisationId}:{$channel}:{$month}"]);
            $settings = self::settings($organisationId, $channel);
            if ($settings === null || ! $settings->enabled || $settings->monthly_cap === null) {
                return null;
            }
            $used = DB::table('hr_message_deliveries')->where('organisation_id', $organisationId)->where('channel', $channel)->where('period_month', $month)
                ->whereIn('status', ['sending', 'sent'])->count();
            $full = $used >= (int) $settings->monthly_cap;
            $id = DB::table('hr_message_deliveries')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'user_id' => $userId,
                'channel' => $channel, 'template' => $template, 'subject_ids' => json_encode((object) $subjectIds, JSON_THROW_ON_ERROR),
                'status' => $full ? 'skipped_cap' : 'sending', 'period_month' => $month, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);

            return $full ? null : $id;
        }, 3);
    }

    /** @param array{subject:string, body:string} $message */
    private function send(int $deliveryId, StaffChannel $driver, User $user, array $message, string $channel): void
    {
        try {
            $providerId = $driver->send($user, $message['subject'], $message['body']);
            DB::table('hr_message_deliveries')->where('id', $deliveryId)->update(['status' => 'sent', 'provider_message_id' => $providerId, 'updated_at' => now()->utc()]);
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['no_address', 'not_connected'], true) ? $e->getMessage() : 'send_failed';
            if ($code === 'send_failed') {
                $this->broken[$channel] = true;
            }
            DB::table('hr_message_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()->utc()]);
            Log::warning('HR staff message failed', ['delivery_id' => $deliveryId, 'error_code' => $code, 'error' => $e::class]);
        }
    }
}
