<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Ask\AskRefused;
use App\Domain\Agents\Ask\AskRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Ask through Claude" (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md): JSON endpoints for the Ask KPOne panel. Each person sees only their own requests. */
class AskThroughClaudeController extends Controller
{
    public function index(Request $request, AskRequestService $asks): JsonResponse
    {
        return $this->run(fn (): array => $asks->recent($request->user()));
    }

    public function store(Request $request, AskRequestService $asks): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000'], 'key' => ['sometimes', 'nullable', 'string', 'max:64']]);

        return $this->run(fn (): array => $asks->create($request->user(), $data['text'], $data['key'] ?? null), 201);
    }

    public function show(Request $request, AskRequestService $asks, string $code): JsonResponse
    {
        return $this->run(fn (): array => $asks->show($request->user(), $code));
    }

    public function cancel(Request $request, AskRequestService $asks, string $code): JsonResponse
    {
        return $this->run(fn (): array => $asks->cancel($request->user(), $code));
    }

    /** @param callable(): array<string, mixed> $work */
    private function run(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status);
        } catch (AskRefused $refused) {
            return response()->json(['code' => $refused->reason, 'message' => $refused->getMessage()], $refused->status);
        }
    }
}
