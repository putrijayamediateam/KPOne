<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        $tokens = Token::query()->with('client:id,name')
            ->where('user_id', $user->id)->where('revoked', false)->where('expires_at', '>', now())->get();
        $lastAsked = AuditLog::query()->where('event', 'ai_connector.tool_called')->where('actor_user_id', $user->id)->max('occurred_at');

        /** @var list<array{clientId: string, name: string, connectedAt: string|null, lastAskedAt: string|null}> $apps */
        $apps = $tokens->groupBy('client_id')->map(fn ($group, $clientId): array => [
            'clientId' => (string) $clientId,
            'name' => mb_substr((string) ($group->first()->client->name ?? 'Connected app'), 0, 80),
            'connectedAt' => ($first = $group->min('created_at')) ? Carbon::parse($first)->toIso8601String() : null,
            'lastAskedAt' => $lastAsked ? Carbon::parse($lastAsked)->toIso8601String() : null,
        ])->sortBy('name')->values()->all();

        return $apps;
    }

    /** Disconnects one app for this person only; returns how many tokens it ended (zero when it was already gone). */
    public function disconnect(User $user, string $clientId): int
    {
        return $this->revoker->revoke($user, $clientId);
    }
}
