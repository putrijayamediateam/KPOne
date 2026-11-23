<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Revokes connector tokens at once (design note section 7): every token of one user, or of everyone. Tokens are only ever
 * marked revoked (Passport keeps the row), and the refresh tokens that belong to them with them. The audit row holds the
 * number of tokens and the user id, never a token.
 */
final class ConnectorRevoker
{
    public function __construct(private AuditRecorder $audit) {}

    public function revoke(?User $user = null, ?string $clientId = null): int
    {
        if ($clientId !== null && $user === null) {
            throw new \InvalidArgumentException('An app can only be disconnected for one person.');
        }

        return DB::transaction(function () use ($user, $clientId): int {
            $tokens = Token::query()->where('revoked', false)->when($user, fn ($query) => $query->where('user_id', $user->id))->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId));
            $ids = $tokens->pluck('id')->all();
            if ($ids !== []) {
                Token::query()->whereIn('id', $ids)->update(['revoked' => true]);
                RefreshToken::query()->whereIn('access_token_id', $ids)->update(['revoked' => true]);
            }
            if ($clientId !== null && $ids === []) {
                return 0;
            }
            $this->audit->record('ai_connector.tokens_revoked', null, array_filter([
                'user_id' => $user?->id,
                'scope' => $clientId !== null ? 'one_app' : ($user ? 'one_user' : 'everyone'),
                'client_id' => $clientId,
                'tokens' => count($ids),
            ], fn (mixed $value): bool => $value !== null), null, null, $user !== null ? $user->organisation_id : User::query()->orderBy('id')->value('organisation_id'));

            return count($ids);
        });
    }
}
