<?php

namespace App\Domain\HR\Messages\Services;

use App\Domain\HR\Messages\MessagePermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-14 writes (HR-PLAN 15.3): a staff member's own channel choices, and a channel's settings (Director, HR Manager).
 * Nothing is on by default. Choosing a channel needs it connected; turning a channel off always works. A channel can
 * be switched on only once it is connected and has a monthly cap; its price per message is the clinic's own figure,
 * shown only (KPOne ships no number). Every write: a fresh permission check under lock, DB::transaction, row locks,
 * lock_version or an expected version, an idempotency key and audit with ids and codes only.
 */
final class MessageSettingsService
{
    public const LABELS = ['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    public function __construct(private RecordSupport $support, private MessageDelivery $delivery) {}

    /** @param array<string, mixed> $input */
    public function choose(User $actor, string $channel, array $input): void
    {
        $this->assertChannel($channel);
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $on = filter_var($input['opted_in'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($on === null) {
            throw ValidationException::withMessages(['opted_in' => 'Choose on or off.']);
        }
        DB::transaction(function () use ($actor, $channel, $input, $key, $on): void {
            $actor = $this->support->lock($actor, MessagePermissions::RECEIVE_OWN);
            $hash = $this->support->hash([$actor->id, 'message_choose', $channel, $on]);
            if ($this->support->replay($actor, $key, 'message_choose', $hash) !== null) {
                return;
            }
            if ($on && ! $this->delivery->channel($channel)->connected()) {
                throw ValidationException::withMessages(['messages' => self::LABELS[$channel].' is not connected yet, so it cannot be chosen.']);
            }
            $row = DB::table('hr_message_preferences')->where('user_id', $actor->id)->where('channel', $channel)->lockForUpdate()->first();
            $current = $row !== null && (bool) $row->opted_in;
            if (filter_var($input['expected_lock_version'] ?? null, FILTER_VALIDATE_INT) !== ($row === null ? 0 : (int) $row->lock_version)) {
                throw ValidationException::withMessages(['records' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            if ($current === $on) {
                throw ValidationException::withMessages(['messages' => 'Nothing was changed.']);
            }
            if ($row === null) {
                DB::table('hr_message_preferences')->insert(['organisation_id' => $actor->organisation_id, 'user_id' => $actor->id, 'channel' => $channel,
                    'opted_in' => $on, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            } else {
                DB::table('hr_message_preferences')->where('id', $row->id)->update(['opted_in' => $on, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now()->utc()]);
            }
            DB::table('hr_message_preference_events')->insert(['organisation_id' => $actor->organisation_id, 'user_id' => $actor->id, 'channel' => $channel,
                'opted_in' => $on, 'source' => 'in_kpone', 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'message_choose', $hash, $channel);
            $this->support->audit->record($on ? 'hr.messages.opted_in' : 'hr.messages.opted_out', null, ['channel' => $channel], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function configure(User $actor, string $channel, array $input): void
    {
        $this->assertChannel($channel);
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $enabled = filter_var($input['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $cap = self::whole($input['monthly_cap'] ?? null, 1, 100000);
        $price = self::sen($input['price'] ?? null);
        $errors = [];
        if ($enabled === null) {
            $errors['enabled'] = 'Choose on or off.';
        }
        if ($cap === false) {
            $errors['monthly_cap'] = 'Enter 1 to 100,000 messages, or leave it empty.';
        }
        if ($price === false) {
            $errors['price'] = 'Enter a price such as 0.15, up to RM 1,000.00, or leave it empty.';
        }
        if ($enabled === true && $cap === null) {
            $errors['monthly_cap'] = 'Enter a monthly cap before switching this channel on.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($actor, $channel, $input, $key, $enabled, $cap, $price): void {
            $actor = $this->support->lock($actor, MessagePermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'message_settings', $channel, $enabled, $cap, $price]);
            if ($this->support->replay($actor, $key, 'message_settings', $hash) !== null) {
                return;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hr_message_settings:{$actor->organisation_id}:{$channel}"]);
            $current = MessageDelivery::settings($actor->organisation_id, $channel);
            if (filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT) !== ($current === null ? 0 : (int) $current->version)) {
                throw ValidationException::withMessages(['records' => 'This was changed by someone else. Reload to see the latest version.']);
            }
            if ($enabled && ! $this->delivery->channel($channel)->connected()) {
                throw ValidationException::withMessages(['enabled' => self::LABELS[$channel].' is not connected yet, so it cannot be switched on.']);
            }
            if ($current !== null && (bool) $current->enabled === $enabled && ($current->monthly_cap === null ? null : (int) $current->monthly_cap) === $cap
                && ($current->price_sen === null ? null : (int) $current->price_sen) === $price) {
                throw ValidationException::withMessages(['messages' => 'Nothing was changed.']);
            }
            $version = $current === null ? 1 : (int) $current->version + 1;
            DB::table('hr_message_channel_settings')->insert(['organisation_id' => $actor->organisation_id, 'channel' => $channel, 'version' => $version,
                'enabled' => $enabled, 'price_sen' => $price, 'monthly_cap' => $cap, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'message_settings', $hash, (string) $version);
            $this->support->audit->record('hr.messages.settings_saved', null, ['channel' => $channel, 'version' => $version, 'enabled' => $enabled,
                'monthly_cap' => $cap], $actor, null, $actor->organisation_id);
        }, 3);
    }

    private function assertChannel(string $channel): void
    {
        if (! in_array($channel, MessageDelivery::CHANNELS, true)) {
            abort(404);
        }
    }

    private static function whole(mixed $value, int $min, int $max): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $int === false ? false : $int;
    }

    /** A ringgit price such as "0.15" to whole sen, with whole-number arithmetic only. */
    private static function sen(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        if (preg_match('/\A(\d{1,4})(?:\.(\d{1,2}))?\z/', trim((string) $value), $m) !== 1) {
            return false;
        }
        $sen = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $sen > 100000 ? false : $sen;
    }
}
