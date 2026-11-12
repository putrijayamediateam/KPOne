<?php

namespace App\Domain\HR\Attendance\Photos;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockPhoto;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Services\AttendanceSupport;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-PHOTO (owner, 2026-11-12). A capture token is taken when the camera opens and is good for one press of that kind
 * for five minutes. At the press the photo is stored encrypted under a generated name, inside the clock transaction,
 * so a refused press leaves nothing behind. Your own photos open directly; anyone else's open only for a reviewer, and
 * each open is recorded (photo id and size) before a byte is sent ("Show", decision B).
 */
final class ClockPhotoService
{
    public const DISK = 'local';

    public const TOKEN_MINUTES = 5;

    public const SIZES = ['full', 'thumb'];

    public const TOO_LONG = 'The camera was open too long. Take the photo again.';

    public const OPEN_AGAIN = 'Open the camera again and take a new photo.';

    public function __construct(private AttendanceSupport $support) {}

    /** @return array{token:string, expiresAt:string} */
    public function issueToken(User $actor, string $kind): array
    {
        if (! in_array($kind, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose clock in or clock out.']);
        }

        return DB::transaction(function () use ($actor, $kind): array {
            $actor = $this->support->lock($actor, ClockPermissions::CLOCK_OWN);
            $token = Str::random(48);
            $now = CarbonImmutable::now('UTC');
            $expires = $now->addMinutes(self::TOKEN_MINUTES);
            DB::table('hr_clock_capture_tokens')->insert(['organisation_id' => $actor->organisation_id, 'user_id' => $actor->id, 'kind' => $kind,
                'token_hash' => hash('sha256', $token), 'issued_at' => $now, 'expires_at' => $expires]);

            return ['token' => $token, 'expiresAt' => $expires->toIso8601String()];
        }, 3);
    }

    /**
     * Inside the clock transaction, after the session row is written. The caller passes the list $written so a retried
     * or failed transaction can delete the bytes it wrote.
     *
     * @param  array{content:string, thumb:string, width:int, height:int, bytes:int, thumb_bytes:int, sha256:string}|(\Closure(): array{content:string, thumb:string, width:int, height:int, bytes:int, thumb_bytes:int, sha256:string})|null  $prepared
     *                                                                                                                                                                                                                                                    a closure is called here, so the press's own checks come first
     * @param  list<string>  $written
     */
    public function attach(User $actor, ClockSession $session, string $kind, array|\Closure|null $prepared, mixed $token, array &$written): ClockPhoto
    {
        if ($prepared instanceof \Closure) {
            $prepared = $prepared();
        }
        if ($prepared === null) {
            throw ValidationException::withMessages(['photo' => ClockPhotoFiles::NEEDED]);
        }
        $now = CarbonImmutable::now('UTC');
        $row = is_string($token) && $token !== '' ? DB::table('hr_clock_capture_tokens')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() : null;
        if ($row === null || (int) $row->user_id !== $actor->id || $row->kind !== $kind || $row->used_at !== null) {
            throw ValidationException::withMessages(['photo' => self::OPEN_AGAIN]);
        }
        if (CarbonImmutable::parse((string) $row->expires_at)->lessThan($now)) {
            throw ValidationException::withMessages(['photo' => self::TOO_LONG]);
        }
        if (ClockPhoto::query()->where('user_id', $actor->id)->where('sha256', $prepared['sha256'])->exists()) {
            throw ValidationException::withMessages(['photo' => 'This photo was used before. Take a new one.']);
        }
        DB::table('hr_clock_capture_tokens')->where('id', $row->id)->update(['used_at' => $now]);
        $publicId = (string) Str::uuid();
        $base = "hr-clock-photos/{$actor->organisation_id}/{$publicId}";
        foreach (["{$base}.bin" => $prepared['content'], "{$base}-thumb.bin" => $prepared['thumb']] as $path => $bytes) {
            if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes))) {
                throw ValidationException::withMessages(['photo' => 'The photo could not be saved. Try again.']);
            }
            $written[] = $path;
        }
        $photo = new ClockPhoto;
        $photo->forceFill(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'user_id' => $actor->id,
            'clock_session_id' => $session->id, 'kind' => $kind, 'capture_token_id' => (int) $row->id, 'stored_path' => "{$base}.bin",
            'bytes' => $prepared['bytes'], 'thumb_path' => "{$base}-thumb.bin", 'thumb_bytes' => $prepared['thumb_bytes'],
            'width' => $prepared['width'], 'height' => $prepared['height'], 'sha256' => $prepared['sha256'], 'created_at' => $now])->save();

        return $photo;
    }

    /** @param list<string> $paths */
    public function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
        $paths = [];
    }

    /** @return array{content:string, own:bool} */
    public function open(User $actor, string $photoId, string $size): array
    {
        abort_unless(in_array($size, self::SIZES, true) && Str::isUuid($photoId), 404);
        $photo = DB::transaction(function () use ($actor, $photoId, $size): ClockPhoto {
            $photo = ClockPhoto::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $photoId)->first();
            abort_if($photo === null, 404);
            $own = $photo->user_id === $actor->id;
            $actor = $this->support->lock($actor, $own ? ClockPermissions::CLOCK_OWN : ClockPermissions::REVIEW);
            if (! $own) {
                // Recorded before any byte is sent; if this row cannot be written, nothing is shown.
                $branch = Branch::query()->whereKey((int) ClockSession::query()->whereKey($photo->clock_session_id)->value('branch_id'))->first();
                $this->support->audit->record('hr.attendance.photo_shown', $photo, ['photo_id' => $photo->id, 'size' => $size], $actor, $branch);
            }

            return $photo;
        }, 3);
        $path = $size === 'thumb' ? $photo->thumb_path : $photo->stored_path;
        $stored = Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->get($path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR clock photo could not be decrypted', ['photo_id' => $photo->id]);
            abort(404);
        }

        return ['content' => $content, 'own' => $photo->user_id === $actor->id];
    }
}
