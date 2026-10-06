<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueDisplayAccess;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Models\BranchDisplaySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QueueDisplayController extends Controller
{
    public function screen(Request $request, QueueDisplayFeedService $feed): Response
    {
        return Inertia::render('QueueDisplay/Screen', [
            'feed' => $feed->feed($this->branch($request)),
            'feedUrl' => route('queue-display.feed', $this->branchQuery($request), absolute: false),
        ]);
    }

    public function feed(Request $request, QueueDisplayFeedService $feed): JsonResponse
    {
        return response()->json($feed->feed($this->branch($request)))
            ->header('Cache-Control', 'no-store, private');
    }

    public function poster(Request $request, Branch $branch, string $poster, QueueDisplayAccess $access): StreamedResponse
    {
        abort_unless($branch->organisation_id === $request->user()->organisation_id, 404);
        abort_unless($access->canView($request->user(), $branch), 403);
        $settings = BranchDisplaySetting::query()->where('branch_id', $branch->id)->first();
        $entry = collect($settings?->posters ?? [])->firstWhere('id', $poster);
        abort_unless(is_array($entry), 404);
        $disk = Storage::disk(QueueDisplayAdministrationService::POSTER_DISK);
        abort_unless($disk->exists($entry['path']), 404);

        return $disk->response($entry['path'], null, [
            'Content-Type' => $entry['mime'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A manager may preview another branch's screen with ?branch=; a TV account always shows its own. */
    private function branch(Request $request): Branch
    {
        $access = app(QueueDisplayAccess::class);
        $user = $request->user();
        $requested = $request->query('branch');
        if ($requested !== null) {
            $branch = Branch::query()
                ->where('organisation_id', $user->organisation_id)
                ->whereKey((int) $requested)
                ->firstOrFail();
            abort_unless($access->canManage($user, $branch), 403);

            return $branch;
        }

        $branch = app(BranchAccessService::class)->activeBranch($user);
        abort_unless($branch && $access->canView($user, $branch), 403);

        return $branch;
    }

    /** @return array<string, int> */
    private function branchQuery(Request $request): array
    {
        return $request->query('branch') !== null ? ['branch' => (int) $request->query('branch')] : [];
    }
}
