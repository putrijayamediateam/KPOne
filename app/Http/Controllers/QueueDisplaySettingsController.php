<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueDisplayAccess;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\BranchRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QueueDisplaySettingsController extends Controller
{
    public function index(Request $request, QueueDisplayAccess $access, QueueDisplayFeedService $feed): Response
    {
        $branches = $access->manageableBranches($request->user());
        $branch = $branches->firstWhere('id', (int) $request->query('branch')) ?? $branches->first();
        abort_if($branch === null, 403);

        return Inertia::render('QueueDisplay/Settings', [
            'branches' => $branches->map->only(['id', 'name'])->values(),
            'branch' => $branch->only(['id', 'name']),
            'rooms' => BranchRoom::query()
                ->where('branch_id', $branch->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (BranchRoom $room): array => [
                    'id' => $room->id,
                    'kind' => $room->kind,
                    'name' => $room->name,
                    'sortOrder' => $room->sort_order,
                    'isActive' => $room->is_active,
                    'lockVersion' => $room->lock_version,
                ])
                ->values(),
            'settings' => $feed->settings($branch),
            'youtubeUrl' => $this->youtubeUrl($branch),
            'maxPosters' => BranchDisplaySetting::MAX_POSTERS,
            'screenUrl' => route('queue-display.screen', ['branch' => $branch->id], absolute: false),
        ]);
    }

    public function storeRoom(Request $request, Branch $branch, QueueDisplayAdministrationService $service): RedirectResponse
    {
        $this->owned($request, $branch);
        $validated = $this->validatedFor($request, $branch, [
            'kind' => ['required', 'string', Rule::in(BranchRoom::KINDS)],
            'name' => ['required', 'string', 'max:60'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
        $this->stay($branch, fn () => $service->createRoom($request->user(), $branch, $validated));

        return $this->done($branch, 'Room added.');
    }

    public function updateRoom(Request $request, BranchRoom $room, QueueDisplayAdministrationService $service): RedirectResponse
    {
        $this->owned($request, $room->branch);
        $validated = $this->validatedFor($request, $room->branch, [
            'name' => ['required', 'string', 'max:60'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->stay($room->branch, fn () => $service->updateRoom($request->user(), $room, $validated));

        return $this->done($room->branch, 'Room updated.');
    }

    public function activateRoom(Request $request, BranchRoom $room, QueueDisplayAdministrationService $service): RedirectResponse
    {
        return $this->toggle($request, $room, $service, true);
    }

    public function deactivateRoom(Request $request, BranchRoom $room, QueueDisplayAdministrationService $service): RedirectResponse
    {
        return $this->toggle($request, $room, $service, false);
    }

    public function updateSettings(Request $request, Branch $branch, QueueDisplayAdministrationService $service): RedirectResponse
    {
        $this->owned($request, $branch);
        $validated = $this->validatedFor($request, $branch, [
            'ticker_text' => ['nullable', 'string', 'max:500'],
            'youtube_url' => ['nullable', 'string', 'max:300'],
            'poster_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        $this->stay($branch, fn () => $service->updateSettings($request->user(), $branch, [
            'ticker_text' => $validated['ticker_text'] ?? null,
            'youtube_url' => $validated['youtube_url'] ?? null,
            'poster_seconds' => (int) $validated['poster_seconds'],
            'lock_version' => (int) $validated['lock_version'],
        ]));

        return $this->done($branch, 'Display settings saved.');
    }

    public function storePoster(Request $request, Branch $branch, QueueDisplayAdministrationService $service): RedirectResponse
    {
        $this->owned($request, $branch);
        $validated = $this->validatedFor($request, $branch, [
            'poster' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        $this->stay($branch, fn () => $service->addPoster(
            $request->user(), $branch, $validated['poster'], (int) $validated['lock_version'],
        ));

        return $this->done($branch, 'Poster added.');
    }

    public function destroyPoster(Request $request, Branch $branch, string $poster, QueueDisplayAdministrationService $service): RedirectResponse
    {
        $this->owned($request, $branch);
        $validated = $this->validatedFor($request, $branch, [
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->stay($branch, fn () => $service->removePoster(
            $request->user(), $branch, $poster, (int) $validated['lock_version'],
        ));

        return $this->done($branch, 'Poster removed.');
    }

    private function toggle(Request $request, BranchRoom $room, QueueDisplayAdministrationService $service, bool $active): RedirectResponse
    {
        $this->owned($request, $room->branch);
        $validated = $this->validatedFor($request, $room->branch, [
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->stay($room->branch, fn () => $service->setRoomActive(
            $request->user(), $room, $active, (int) $validated['lock_version'],
        ));

        return $this->done($room->branch, $active ? 'Room activated.' : 'Room deactivated.');
    }

    private function youtubeUrl(Branch $branch): ?string
    {
        $videoId = BranchDisplaySetting::query()->where('branch_id', $branch->id)->value('youtube_video_id');

        return is_string($videoId) ? 'https://www.youtube.com/watch?v='.$videoId : null;
    }

    private function owned(Request $request, Branch $branch): void
    {
        abort_unless($branch->organisation_id === $request->user()->organisation_id, 404);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request, Branch $branch, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo($this->target($branch));
        }

        return $validator->validated();
    }

    private function stay(Branch $branch, callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($this->target($branch));
        }
    }

    private function done(Branch $branch, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return redirect($this->target($branch));
    }

    private function target(Branch $branch): string
    {
        return route('queue-display.settings', ['branch' => $branch->id]);
    }
}
