<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Ask\AskRefused;
use App\Domain\Agents\Ask\AskRequestService;
use App\Domain\Agents\Ask\AskSwitch;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * The connector's only write (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md section 4): saves the answer text to one pending
 * "Ask through Claude" request of the signed-in Director. It cannot write anything else. Offered only while the Ask switch is on,
 * to a person who may use it, and (by default) to a token that carries the mcp:answer scope.
 */
#[Name('save_answer')]
#[Description('Saves your answer to a question the Director asked in the KPOne panel ("Ask through Claude"). Give the request id from the prompt and a short plain-text answer. Only works for that Director\'s own pending request, once. It changes nothing else in KPOne. Never include patient names, identity numbers, phone numbers or emails.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class SaveAnswerMcpTool extends Tool
{
    public function shouldRegister(): bool
    {
        $user = auth()->user();

        return AskSwitch::enabled() && $user instanceof User && $this->mayAnswer($user);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->string()->required()->description('The request id from the prompt, for example K7Q2M9XPTA.'),
            'answer_text' => $schema->string()->required()->description('Your short plain-text answer, at most '.AskRequestService::MAX_ANSWER.' characters.'),
        ];
    }

    public function handle(Request $request, AskRequestService $asks): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in again.');
        }
        if (! $this->mayAnswer($user)) {
            return Response::error('Reconnect KPOne in Claude to allow saving answers.');
        }

        try {
            $asks->saveAnswer($user, (string) $request->get('request_id', ''), (string) $request->get('answer_text', ''));
        } catch (AskRefused $refused) {
            return Response::error($refused->getMessage());
        }

        return Response::text('Saved. The Director will see your answer in the KPOne panel.');
    }

    private function mayAnswer(User $user): bool
    {
        if (! $user->can(AgentPermissions::CONNECTOR_USE) || ! $user->can(AgentPermissions::CHAT_USE)) {
            return false;
        }

        return ! AskSwitch::requiresScope() || $user->tokenCan(AskSwitch::SCOPE);
    }
}
