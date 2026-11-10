<?php

namespace App\Domain\HR\Messages\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-14 one-time codes (HR-PLAN 15.3), the foundation for HR-15's locum sign-in. Nothing in KPOne asks for a code
 * yet. Six digits, stored only as a keyed hash, valid 5 minutes, at most 5 tries, at most 3 codes per 15 minutes per
 * account and purpose. The code is returned to the caller once, to send to the person's own account; it is never
 * logged, stored in plain form, audited or shown to staff.
 */
final class OneTimeCodeService
{
    public const TTL_MINUTES = 5;

    public const MAX_TRIES = 5;

    public const MAX_SENDS = 3;

    public const WINDOW_MINUTES = 15;

    /** @return string the six-digit code, for the caller to send once */
    public function issue(User $user, string $purpose): string
    {
        $purpose = self::purpose($purpose);

        return DB::transaction(function () use ($user, $purpose): string {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["hr_message_otp:{$user->id}:{$purpose}"]);
            $recent = DB::table('hr_message_otps')->where('user_id', $user->id)->where('purpose', $purpose)
                ->where('created_at', '>=', now()->utc()->subMinutes(self::WINDOW_MINUTES))->count();
            if ($recent >= self::MAX_SENDS) {
                throw ValidationException::withMessages(['code' => 'Too many codes were asked for. Wait 15 minutes and try again.']);
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('hr_message_otps')->insert(['organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'purpose' => $purpose,
                'code_hash' => self::hash($user, $purpose, $code), 'expires_at' => now()->utc()->addMinutes(self::TTL_MINUTES), 'attempts' => 0,
                'created_at' => now()->utc()]);

            return $code;
        }, 3);
    }

    /** True once, for the newest live code of this account and purpose; every wrong try counts. */
    public function verify(User $user, string $purpose, string $code): bool
    {
        $purpose = self::purpose($purpose);

        return DB::transaction(function () use ($user, $purpose, $code): bool {
            $row = DB::table('hr_message_otps')->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
                ->where('expires_at', '>', now()->utc())->orderByDesc('id')->lockForUpdate()->first();
            if ($row === null || (int) $row->attempts >= self::MAX_TRIES) {
                return false;
            }
            $ok = preg_match('/\A\d{6}\z/', $code) === 1 && hash_equals((string) $row->code_hash, self::hash($user, $purpose, $code));
            DB::table('hr_message_otps')->where('id', $row->id)->update(['attempts' => (int) $row->attempts + 1, 'consumed_at' => $ok ? now()->utc() : null]);

            return $ok;
        }, 3);
    }

    private static function purpose(string $purpose): string
    {
        if (preg_match('/\A[a-z_]{3,20}\z/', $purpose) !== 1) {
            throw new \InvalidArgumentException('Unknown one-time code purpose.');
        }

        return $purpose;
    }

    private static function hash(User $user, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$user->id}|{$purpose}|{$code}", 'kpone-hr-otp|'.(string) config('app.key'));
    }
}
