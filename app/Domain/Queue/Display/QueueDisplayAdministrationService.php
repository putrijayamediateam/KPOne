<?php

namespace App\Domain\Queue\Display;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueueDisplayAdministrationService
{
    public const MAX_ROOMS_PER_BRANCH = 20;

    public const POSTER_DISK = 'local';

    public function __construct(
        private QueueDisplayAccess $access,
        private AuditRecorder $audit,
    ) {}

    /** @param array{kind: string, name: string, sort_order: int} $attributes */
    public function createRoom(User $actor, Branch $branch, array $attributes): BranchRoom
    {
        $this->access->authorizeManage($actor, $branch);
        $kind = $this->kind($attributes['kind']);
        $name = $this->roomName($attributes['name']);

        return DB::transaction(function () use ($actor, $branch, $attributes, $kind, $name): BranchRoom {
            $this->lockBranch($actor, $branch);
            $rooms = BranchRoom::query()->where('branch_id', $branch->id)->lockForUpdate()->get();
            if ($rooms->count() >= self::MAX_ROOMS_PER_BRANCH) {
                $this->invalid('name', 'This branch already has the maximum number of rooms.');
            }
            $this->assertUniqueName($rooms->all(), $name);

            $room = new BranchRoom;
            $room->forceFill([
                'organisation_id' => $branch->organisation_id,
                'branch_id' => $branch->id,
                'kind' => $kind,
                'name' => $name,
                'sort_order' => (int) $attributes['sort_order'],
                'is_active' => true,
                'lock_version' => 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record('queue_display.room.created', $room, [
                'kind' => $kind,
                'name' => $name,
                'record_version' => 1,
            ], $actor, $branch, $branch->organisation_id);

            return $room;
        }, 3);
    }

    /** @param array{name: string, sort_order: int, lock_version: int} $attributes */
    public function updateRoom(User $actor, BranchRoom $room, array $attributes): BranchRoom
    {
        $branch = $room->branch;
        $this->access->authorizeManage($actor, $branch);
        $name = $this->roomName($attributes['name']);

        return DB::transaction(function () use ($actor, $branch, $room, $attributes, $name): BranchRoom {
            $this->lockBranch($actor, $branch);
            $locked = $this->lockRoom($room, (int) $attributes['lock_version']);
            $others = BranchRoom::query()->where('branch_id', $branch->id)->whereKeyNot($locked->id)->get();
            $this->assertUniqueName($others->all(), $name);

            $values = ['name' => $name, 'sort_order' => (int) $attributes['sort_order']];
            $changed = array_keys(array_filter($values, fn (mixed $value, string $field): bool => $locked->{$field} !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $locked;
            }

            $locked->forceFill([
                ...$values,
                'lock_version' => $locked->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record('queue_display.room.updated', $locked, [
                'changed_fields' => $changed,
                'record_version' => $locked->lock_version,
            ], $actor, $branch, $branch->organisation_id);

            return $locked;
        }, 3);
    }

    public function setRoomActive(User $actor, BranchRoom $room, bool $active, int $lockVersion): BranchRoom
    {
        $branch = $room->branch;
        $this->access->authorizeManage($actor, $branch);

        return DB::transaction(function () use ($actor, $branch, $room, $active, $lockVersion): BranchRoom {
            $this->lockBranch($actor, $branch);
            $locked = $this->lockRoom($room, $lockVersion);
            if ($locked->is_active === $active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => $active,
                'lock_version' => $locked->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record($active ? 'queue_display.room.activated' : 'queue_display.room.deactivated', $locked, [
                'record_version' => $locked->lock_version,
            ], $actor, $branch, $branch->organisation_id);

            return $locked;
        }, 3);
    }

    /** @param array{ticker_text: string|null, youtube_url: string|null, poster_seconds: int, lock_version: int} $attributes */
    public function updateSettings(User $actor, Branch $branch, array $attributes): BranchDisplaySetting
    {
        $this->access->authorizeManage($actor, $branch);
        $values = [
            'ticker_text' => $this->ticker($attributes['ticker_text']),
            'youtube_video_id' => self::youtubeVideoId($attributes['youtube_url']),
            'poster_seconds' => (int) $attributes['poster_seconds'],
        ];

        return DB::transaction(function () use ($actor, $branch, $attributes, $values): BranchDisplaySetting {
            $this->lockBranch($actor, $branch);
            $settings = $this->lockSettings($branch, (int) $attributes['lock_version']);
            $changed = array_keys(array_filter($values, fn (mixed $value, string $field): bool => $settings->{$field} !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === [] && $settings->exists) {
                return $settings;
            }

            $settings->forceFill([
                ...$values,
                'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record('queue_display.settings.updated', $settings, [
                'changed_fields' => $changed,
                'record_version' => $settings->lock_version,
            ], $actor, $branch, $branch->organisation_id);

            return $settings;
        }, 3);
    }

    public function addPoster(User $actor, Branch $branch, UploadedFile $file, int $lockVersion): BranchDisplaySetting
    {
        $this->access->authorizeManage($actor, $branch);
        $mime = (string) $file->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => $this->invalid('poster', 'A poster must be a JPG, PNG or WebP image.'),
        };
        $id = (string) Str::uuid();
        $path = "queue-display/{$branch->id}/{$id}.{$extension}";
        Storage::disk(self::POSTER_DISK)->putFileAs("queue-display/{$branch->id}", $file, "{$id}.{$extension}");

        try {
            return DB::transaction(function () use ($actor, $branch, $lockVersion, $id, $path, $mime): BranchDisplaySetting {
                $this->lockBranch($actor, $branch);
                $settings = $this->lockSettings($branch, $lockVersion);
                $posters = $settings->posters ?? [];
                if (count($posters) >= BranchDisplaySetting::MAX_POSTERS) {
                    $this->invalid('poster', 'A branch can have at most '.BranchDisplaySetting::MAX_POSTERS.' posters.');
                }

                $settings->forceFill([
                    'posters' => [...$posters, ['id' => $id, 'path' => $path, 'mime' => $mime]],
                    'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1,
                    'updated_by_user_id' => $actor->id,
                ])->save();
                $this->audit->record('queue_display.poster.added', $settings, [
                    'poster_id' => $id,
                    'record_version' => $settings->lock_version,
                ], $actor, $branch, $branch->organisation_id);

                return $settings;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk(self::POSTER_DISK)->delete($path);

            throw $exception;
        }
    }

    public function removePoster(User $actor, Branch $branch, string $posterId, int $lockVersion): BranchDisplaySetting
    {
        $this->access->authorizeManage($actor, $branch);
        $removedPath = null;

        $settings = DB::transaction(function () use ($actor, $branch, $posterId, $lockVersion, &$removedPath): BranchDisplaySetting {
            $this->lockBranch($actor, $branch);
            $settings = $this->lockSettings($branch, $lockVersion);
            $posters = collect($settings->posters ?? []);
            $poster = $posters->firstWhere('id', $posterId);
            if ($poster === null) {
                $this->invalid('poster', 'This poster no longer exists. Reload the page.');
            }

            $settings->forceFill([
                'posters' => $posters->reject(fn (array $item): bool => $item['id'] === $posterId)->values()->all(),
                'lock_version' => $settings->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record('queue_display.poster.removed', $settings, [
                'poster_id' => $posterId,
                'record_version' => $settings->lock_version,
            ], $actor, $branch, $branch->organisation_id);
            $removedPath = $poster['path'];

            return $settings;
        }, 3);

        if (is_string($removedPath)) {
            Storage::disk(self::POSTER_DISK)->delete($removedPath);
        }

        return $settings;
    }

    public static function youtubeVideoId(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https' || ! in_array($host, [
            'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com',
        ], true)) {
            self::rejectYoutube();
        }

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
        } elseif (preg_match('#\A/(?:embed|shorts|live)/([^/]+)/?\z#', $path, $matches) === 1) {
            $candidate = $matches[1];
        }

        if (! is_string($candidate) || preg_match('/\A[A-Za-z0-9_-]{11}\z/', $candidate) !== 1) {
            self::rejectYoutube();
        }

        return $candidate;
    }

    private static function rejectYoutube(): never
    {
        throw ValidationException::withMessages([
            'youtube_url' => 'Enter a valid YouTube video link (https://www.youtube.com/watch?v=… or https://youtu.be/…).',
        ]);
    }

    private function lockBranch(User $actor, Branch $branch): void
    {
        Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
        $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $this->access->authorizeManage($lockedActor, $branch);
    }

    private function lockRoom(BranchRoom $room, int $lockVersion): BranchRoom
    {
        $locked = BranchRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
        if ($locked->lock_version !== $lockVersion) {
            $this->stale();
        }

        return $locked;
    }

    private function lockSettings(Branch $branch, int $lockVersion): BranchDisplaySetting
    {
        $settings = BranchDisplaySetting::query()->where('branch_id', $branch->id)->lockForUpdate()->first();
        if (($settings?->lock_version ?? 0) !== $lockVersion) {
            $this->stale();
        }

        if ($settings === null) {
            $settings = new BranchDisplaySetting;
            $settings->forceFill([
                'organisation_id' => $branch->organisation_id,
                'branch_id' => $branch->id,
                'ticker_text' => null,
                'youtube_video_id' => null,
                'poster_seconds' => 10,
                'posters' => [],
                'lock_version' => 0,
            ]);
        }

        return $settings;
    }

    /** @param list<BranchRoom> $rooms */
    private function assertUniqueName(array $rooms, string $name): void
    {
        foreach ($rooms as $room) {
            if (Str::lower($room->name) === Str::lower($name)) {
                $this->invalid('name', 'This branch already has a room with this name.');
            }
        }
    }

    private function kind(string $kind): string
    {
        if (! in_array($kind, BranchRoom::KINDS, true)) {
            $this->invalid('kind', 'Choose a valid room type.');
        }

        return $kind;
    }

    private function roomName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) {
            $this->invalid('name', 'A room name is required (60 characters at most).');
        }

        return $name;
    }

    private function ticker(?string $text): ?string
    {
        $text = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', (string) $text));
        if (mb_strlen($text) > 500) {
            $this->invalid('ticker_text', 'The scrolling text can be 500 characters at most.');
        }

        return $text === '' ? null : $text;
    }

    private function stale(): never
    {
        throw ValidationException::withMessages([
            'lock_version' => 'These settings changed after you opened them. Reload and review the latest version.',
        ]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
