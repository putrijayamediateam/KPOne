<?php

namespace App\Http\Controllers;

use App\Domain\MyDay\MyRoutineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** DAY-1b: a person's own routine. Every action works on the signed-in person's own list; no route takes a user id. */
final class MyRoutineController extends Controller
{
    public function __construct(private MyRoutineService $routine) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']]);
        $this->routine->add($request->user(), $data);

        return to_route('my-day');
    }

    public function rename(Request $request, string $routineItem): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:1']]);
        $this->routine->rename($request->user(), $this->uuid($routineItem), $data);

        return to_route('my-day');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'max:20'], 'order.*' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1']]);
        $this->routine->reorder($request->user(), $data);

        return to_route('my-day');
    }

    public function hide(Request $request, string $routineItem): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $this->routine->hide($request->user(), $this->uuid($routineItem), $data);

        return to_route('my-day');
    }

    public function restore(Request $request, string $routineItem): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']]);
        $this->routine->restore($request->user(), $this->uuid($routineItem), $data);

        return to_route('my-day');
    }

    public function tick(Request $request, string $routineItem): RedirectResponse
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);
        $this->routine->tick($request->user(), $this->uuid($routineItem), (bool) $data['done']);

        return to_route('my-day');
    }

    private function uuid(string $value): string
    {
        abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1, 404);

        return $value;
    }
}
