<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

/**
 * CN-4: what one person sees on "Connected apps": their own connections only (never anyone else's), when each was made
 * and when the connector last answered them. Reads Passport's token rows and the connector's audit rows; changes nothing.
 */
final class ConnectedApps
{
    /** Plain-English names of the questions, so "totals only" can be checked on the page. */
    private const QUESTIONS = [
        'list_branches' => 'Which of my branches can I ask about',
        'clinic_day_summary' => 'Clinic day: visits registered, served and waiting, average waiting time',
        'money_day_summary' => 'Money day: sales, receipts by method, Panel and pay-later totals',
        'sales_summary' => 'Sales over a period, compared with the period before',
        'panel_claims_outstanding' => 'Panel claims: claimed, unclaimed, overdue and unpaid per Panel',
        'panel_claims_made' => 'Panel claims created in a period',
        'stock_alerts' => 'Stock alerts: low stock and expiry counts',
        'stock_balances_and_counts' => 'Stock balances and counts due',
        'system_health' => 'System health counts',
        'visits_by_state' => 'Visits by state: unfinished, completed, cancelled',
        'queue_now' => 'The queue right now: waiting, serving, on hold',
    ];

    public function __construct(private ConnectorRevoker $revoker) {}

    /** @return list<string> */
    public function questions(): array
    {
        return array_values(self::QUESTIONS);
    }

    /** @return list<array{clientId: string, name: string, connectedAt: string|null, lastAskedAt: string|null}> */
    public function for(User $user): array
    {
        // Live means the app could still get an access token: an unexpired access token, or a refresh token that is not revoked
        // and not expired (access tokens last an hour, refresh tokens 30 days). Revoked tokens never count.
        $tokens = Token::query()->with('client:id,name')
            ->where('user_id', $user->id)->where('revoked', false)
            ->where(fn ($live) => $live->where('expires_at', '>', now())->orWhereExists(
                fn ($refresh) => $refresh->selectRaw('1')->from('oauth_refresh_tokens as r')
                    ->whereColumn('r.access_token_id', 'oauth_access_tokens.id')->where('r.revoked', false)->where('r.expires_at', '>', now())
            ))->get();
        // "Connected on" is the first time this person allowed the app (the earliest token, revoked ones included), because
        // every renewal replaces the access token.
        $firstSeen = Token::query()->where('user_id', $user->id)->whereIn('client_id', $tokens->pluck('client_id')->unique())
            ->selectRaw('client_id, min(created_at) as first_at')->groupBy('client_id')->pluck('first_at', 'client_id');
        // The connector records which app asked; rows without one are not attributed to any app.
        $lastAsked = AuditLog::query()->where('event', 'ai_connector.tool_called')->where('actor_user_id', $user->id)
            ->where('occurred_at', '>=', now()->subDays(31))
            ->whereIn(DB::raw("metadata->>'client_id'"), $tokens->pluck('client_id')->map(fn ($id): string => (string) $id)->unique()->all())
            ->selectRaw("metadata->>'client_id' as cid, max(occurred_at) as last_at")->groupBy('cid')->pluck('last_at', 'cid');

        /** @var list<array{clientId: string, name: string, connectedAt: string|null, lastAskedAt: string|null}> $apps */
        $apps = $tokens->groupBy('client_id')->map(fn ($group, $clientId): array => [
            'clientId' => (string) $clientId,
            'name' => mb_substr((string) ($group->first()->client->name ?? 'Connected app'), 0, 80),
            'connectedAt' => ($first = $firstSeen[$clientId] ?? null) ? Carbon::parse($first)->toIso8601String() : null,
            'lastAskedAt' => ($asked = $lastAsked[(string) $clientId] ?? null) ? Carbon::parse($asked)->toIso8601String() : null,
        ])->sortBy('name')->values()->all();

        return $apps;
    }

    /** Disconnects one app for this person only; returns how many tokens it ended (zero when it was already gone). */
    public function disconnect(User $user, string $clientId): int
    {
        return $this->revoker->revoke($user, $clientId);
    }
}
