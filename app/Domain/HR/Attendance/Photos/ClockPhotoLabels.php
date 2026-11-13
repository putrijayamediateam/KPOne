<?php

namespace App\Domain\HR\Attendance\Photos;

use App\Domain\HR\Attendance\Models\ClockPhoto;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-PHOTO: what each press's photo looks like on a page. Your own photo carries its addresses and shows straight away;
 * anyone else's carries them too but `needsShow` is true, so the page shows nothing until the reviewer presses Show
 * (and the open is recorded then, decision B). A time added by a correction has no photo and says so.
 */
final class ClockPhotoLabels
{
    public const BY_CORRECTION = 'No photo (added by correction)';

    public const NONE = 'No photo';

    public const TIME_CHANGED = 'Time changed by correction';

    /**
     * @param  Collection<int, ClockSession>  $sessions
     * @return array<string, array{in: array<string, mixed>|null, out: array<string, mixed>|null}> by session public id
     */
    public function forSessions(Collection $sessions, User $viewer): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        $ids = array_values(array_map('intval', $sessions->pluck('id')->all()));
        $photos = ClockPhoto::query()->whereIn('clock_session_id', $ids)->get(['id', 'public_id', 'user_id', 'clock_session_id', 'kind'])
            ->keyBy(fn (ClockPhoto $p): string => $p->clock_session_id.'|'.$p->kind);
        $methods = DB::table('hr_clock_events')->whereIn('clock_session_id', $ids)->orderBy('id')->get(['clock_session_id', 'kind', 'method'])
            ->mapWithKeys(fn ($e): array => [$e->clock_session_id.'|'.$e->kind => (string) $e->method]); // the latest event of each kind wins
        $out = [];
        foreach ($sessions as $session) {
            $one = ['in' => null, 'out' => null];
            foreach (['in', 'out'] as $kind) {
                $at = $kind === 'in' ? $session->clock_in_at : $session->clock_out_at;
                $photo = $photos[$session->id.'|'.$kind] ?? null;
                $method = $methods[$session->id.'|'.$kind] ?? null;
                if ($at === null) {
                    $one[$kind] = null;
                } elseif ($photo !== null) {
                    // A correction that only moved the time keeps the press's photo in view: it is the evidence (Suresh m4).
                    $own = $photo->user_id === $viewer->id;
                    $one[$kind] = ['id' => $photo->public_id, 'own' => $own, 'needsShow' => ! $own,
                        'thumbUrl' => route('hr.attendance.photo', [$photo->public_id, 'thumb'], false),
                        'fullUrl' => route('hr.attendance.photo', [$photo->public_id, 'full'], false),
                        'label' => $method === 'correction' ? self::TIME_CHANGED : null];
                } else {
                    $one[$kind] = ['id' => null, 'own' => $session->user_id === $viewer->id, 'needsShow' => false, 'thumbUrl' => null, 'fullUrl' => null,
                        'label' => $method === 'correction' ? self::BY_CORRECTION : self::NONE];
                }
            }
            $out[$session->public_id] = $one;
        }

        return $out;
    }
}
