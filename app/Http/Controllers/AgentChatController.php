<?php

namespace App\Http\Controllers;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Chat\AiChatMessage;
use App\Domain\Agents\Chat\AiChatThread;
use App\Domain\Agents\Chat\ChatRefused;
use App\Domain\Agents\Chat\ChatService;
use App\Domain\Agents\Chat\ChatSpend;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The AI chatbox (docs/agents/AI-CHATBOX-DESIGN.md): JSON endpoints used by the "Ask KPOne" drawer. Each person sees only their own history. */
class AgentChatController extends Controller
{
    public const EXAMPLES = ['How many patients were registered today, including the unfinished ones?', 'What were yesterday’s sales and receipts?', 'Which Panel claims are overdue?'];

    public function state(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'canViewOthers' => $user->can(AgentPermissions::CHAT_HISTORY),
            'limitReached' => ChatSpend::reached($user->organisation_id),
            'resetsOn' => ChatSpend::resetsOn(),
            'maxChars' => (int) config('ai_chat.max_prompt_chars'),
            'examples' => self::EXAMPLES,
            'threads' => $this->threads($user, $user->id),
        ]);
    }

    public function ask(Request $request, ChatService $chat): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000'], 'thread_id' => ['sometimes', 'nullable', 'integer']]);

        try {
            return response()->json($chat->ask($request->user(), $data['thread_id'] ?? null, $data['text']));
        } catch (ChatRefused $refused) {
            return response()->json(['code' => $refused->reason, 'message' => $refused->getMessage(), 'resetsOn' => ChatSpend::resetsOn()], $refused->status);
        }
    }

    public function thread(Request $request, int $thread): JsonResponse
    {
        $found = AiChatThread::query()->where('organisation_id', $request->user()->organisation_id)->where('user_id', $request->user()->id)->findOrFail($thread);

        return response()->json($this->conversation($found));
    }

    public function archive(Request $request, int $thread): JsonResponse
    {
        $found = AiChatThread::query()->where('organisation_id', $request->user()->organisation_id)->where('user_id', $request->user()->id)->findOrFail($thread);
        $found->forceFill(['is_archived' => true])->save();

        return response()->json(['ok' => true]);
    }

    /** Other people's conversations: the permitted role only; the list shows names and dates, never text. */
    public function others(Request $request): JsonResponse
    {
        $user = $request->user();
        $showNames = $user->can('staff.view.organisation');
        $threads = AiChatThread::query()->where('organisation_id', $user->organisation_id)->where('user_id', '!=', $user->id)
            ->orderByDesc('last_used_at')->limit(100)->get();
        $names = User::query()->whereIn('id', $threads->pluck('user_id')->unique())->pluck('name', 'id');
        app(AuditRecorder::class)->record('ai_chat.history_list_opened', null, ['threads' => $threads->count()], $user, null, $user->organisation_id);

        return response()->json(['threads' => $threads->map(fn (AiChatThread $thread): array => [
            'id' => $thread->id,
            'person' => $showNames ? (string) ($names[$thread->user_id] ?? 'Staff member') : 'Staff member',
            'date' => $thread->last_used_at->toIso8601String(),
        ])->values()->all()]);
    }

    public function otherThread(Request $request, int $thread): JsonResponse
    {
        $user = $request->user();
        $found = AiChatThread::query()->where('organisation_id', $user->organisation_id)->where('user_id', '!=', $user->id)->findOrFail($thread);
        // Every open is recorded first: if the audit row cannot be written, nothing is shown.
        abort_if(app(AuditRecorder::class)->record('ai_chat.history_opened', null, ['thread_id' => $found->id, 'owner_user_id' => $found->user_id], $user, null, $user->organisation_id) === null, 503, 'Unable to record this view. Try again later.');

        return response()->json($this->conversation($found));
    }

    /** @return list<array{id: int, title: string, date: string}> */
    private function threads(User $user, int $ownerId): array
    {
        $threads = AiChatThread::query()->where('organisation_id', $user->organisation_id)->where('user_id', $ownerId)->where('is_archived', false)
            ->orderByDesc('last_used_at')->limit(50)->get();

        return array_values($threads->map(fn (AiChatThread $thread): array => ['id' => $thread->id, 'title' => $thread->title, 'date' => $thread->last_used_at->toIso8601String()])->all());
    }

    /** @return array<string, mixed> */
    private function conversation(AiChatThread $thread): array
    {
        return [
            'thread' => ['id' => $thread->id, 'title' => $thread->title, 'date' => $thread->created_at->toIso8601String()],
            'messages' => AiChatMessage::query()->where('thread_id', $thread->id)->orderBy('id')->get()->map(fn (AiChatMessage $message): array => ChatService::present($message))->values()->all(),
        ];
    }
}
