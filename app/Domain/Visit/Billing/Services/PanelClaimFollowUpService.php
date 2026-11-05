<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * PNL-4: the Follow-up list. An open Panel claim (not paid and not rejected) appears once its Panel's
 * follow-up days (default 30) pass with no new action on it: a claim event (prepared, submitted, query,
 * decision, amount change, note), a recorded payment, an uploaded photo or a reversed payment (PNL-6). Read-only; nothing is changed.
 */
final class PanelClaimFollowUpService
{
    public const PERMISSION = 'panel.claims.manage.branch';

    /** Claims that still wait for the Panel or for money. Paid and rejected claims are finished. */
    public const OPEN = ['prepared', 'submitted', 'queried', 'accepted', 'partially_accepted', 'partially_paid'];

    private const ACTIONS = [
        'prepared' => 'Claim prepared',
        'submitted' => 'Submitted to Panel',
        'queried' => 'Panel query',
        'amount_adjusted' => 'Claim amount changed',
        'decision_accepted' => 'Panel accepted claim',
        'decision_partially_accepted' => 'Panel partially accepted claim',
        'decision_rejected' => 'Panel rejected claim',
        'note' => 'Note added',
        'payment' => 'Payment recorded',
        'document' => 'Photo added',
        'reversal' => 'Payment reversed',
    ];

    private const STATUSES = [
        'prepared' => 'Prepared (not yet submitted)',
        'submitted' => 'Submitted',
        'queried' => 'Panel query',
        'accepted' => 'Accepted, unpaid',
        'partially_accepted' => 'Partially accepted, unpaid',
        'partially_paid' => 'Partially paid',
    ];

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query, int $page = 1): array
    {
        $filters = Validator::make($query, ['panel' => ['sometimes', 'nullable', 'string', 'max:80']])->valid();

        return DB::transaction(function () use ($actor, $filters, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $now = CarbonImmutable::now();
            $org = $actor->organisation_id;
            $branchId = $branch->id;

            // Every action on an open claim, from the three append-only evidence tables. Only open claims
            // are read, so paid and rejected history does not slow the page down.
            $open = DB::table('panel_claims')->select('id')->where('organisation_id', $org)->where('branch_id', $branchId)
                ->whereIn('status', self::OPEN);
            $actions = DB::table('panel_claim_events')
                ->where('organisation_id', $org)->where('branch_id', $branchId)->whereIn('panel_claim_id', $open)
                ->select(['panel_claim_id', 'occurred_at as acted_at', 'event_type as kind', 'actor_user_id as user_id', DB::raw('0 as source'), 'id'])
                ->unionAll(DB::table('panel_claim_payments')->where('organisation_id', $org)->where('branch_id', $branchId)->whereIn('panel_claim_id', $open)
                    ->select(['panel_claim_id', 'created_at as acted_at', DB::raw("'payment' as kind"), 'recorded_by_user_id as user_id', DB::raw('1 as source'), 'id']))
                ->unionAll(DB::table('panel_claim_documents')->where('organisation_id', $org)->where('branch_id', $branchId)->whereIn('panel_claim_id', $open)
                    ->select(['panel_claim_id', 'created_at as acted_at', DB::raw("'document' as kind"), 'uploaded_by_user_id as user_id', DB::raw('2 as source'), 'id']))
                // PNL-6: reversing a wrong payment is an action too, so the claim's clock restarts from it.
                ->unionAll(DB::table('panel_claim_payment_reversals')->where('organisation_id', $org)->where('branch_id', $branchId)->whereIn('panel_claim_id', $open)
                    ->select(['panel_claim_id', 'created_at as acted_at', DB::raw("'reversal' as kind"), 'reversed_by_user_id as user_id', DB::raw('3 as source'), 'id']));
            // The latest action per claim (PostgreSQL DISTINCT ON). Actions in the same second are ordered as
            // recorded: by id within a table, and a payment or photo after an event.
            $latest = DB::query()->fromSub($actions, 'a')
                ->selectRaw('DISTINCT ON (a.panel_claim_id) a.panel_claim_id, a.acted_at, a.kind, a.user_id')
                ->orderBy('a.panel_claim_id')->orderByDesc('a.acted_at')->orderByDesc('a.source')->orderByDesc('a.id');

            $received = '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = c.id and p.organisation_id = c.organisation_id and p.branch_id = c.branch_id and '.PanelClaimPaymentReversal::NOT_REVERSED_P.')';
            $base = DB::table('panel_claims as c')
                ->join('panels as pn', function ($join): void {
                    $join->on('pn.id', '=', 'c.panel_id')->on('pn.organisation_id', '=', 'c.organisation_id');
                })
                ->joinSub($latest, 'l', 'l.panel_claim_id', '=', 'c.id')
                ->leftJoin('users as u', function ($join): void {
                    $join->on('u.id', '=', 'l.user_id')->on('u.organisation_id', '=', 'c.organisation_id');
                })
                ->where('c.organisation_id', $org)->where('c.branch_id', $branchId)
                ->whereIn('c.status', self::OPEN)
                ->whereRaw('l.acted_at <= CAST(? AS timestamp) - make_interval(days => pn.claim_follow_up_days)', [$now->utc()->toDateTimeString()]);
            if (($filters['panel'] ?? '') !== '') {
                $base->where('pn.code', (string) $filters['panel']);
            }

            $rows = (clone $base)
                ->select(['c.id', 'c.public_id', 'c.status', 'c.patient_name_snapshot', 'c.invoice_number', 'c.claim_amount_sen',
                    'c.accepted_amount_sen', 'pn.name as panel_name', 'pn.claim_follow_up_days', 'l.acted_at', 'l.kind', 'u.name as actor_name'])
                ->selectRaw("{$received} as received_sen")
                ->orderBy('l.acted_at')->orderBy('c.id')
                ->paginate(20, ['*'], 'page', $page);

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name],
                'filters' => ['panel' => (string) ($filters['panel'] ?? '')],
                'panels' => DB::table('panels')->where('organisation_id', $org)->orderBy('name')
                    ->get(['code', 'name', 'claim_follow_up_days'])
                    ->map(static fn ($row): array => ['code' => (string) $row->code, 'name' => (string) $row->name, 'followUpDays' => (int) $row->claim_follow_up_days])->all(),
                'claims' => [
                    'data' => $rows->getCollection()->map(function ($row) use ($branch, $now): array {
                        $actedAt = CarbonImmutable::parse((string) $row->acted_at, 'UTC');
                        // Before a decision nothing is owed yet (as in the Panel summary): show the claimed amount.
                        $decided = $row->accepted_amount_sen !== null;

                        return [
                            'publicId' => (string) $row->public_id,
                            'claimNo' => (string) $row->id,
                            'patientName' => (string) $row->patient_name_snapshot,
                            'invoiceNumber' => (string) $row->invoice_number,
                            'panelName' => (string) $row->panel_name,
                            'status' => self::STATUSES[(string) $row->status] ?? 'Open',
                            'amountLabel' => $decided ? 'Unpaid' : 'Claimed, awaiting decision',
                            'amountSen' => $decided ? max(0, (int) $row->accepted_amount_sen - (int) $row->received_sen) : (int) $row->claim_amount_sen,
                            'lastAction' => self::ACTIONS[(string) $row->kind] ?? 'Updated',
                            'lastActionDate' => $actedAt->setTimezone($branch->timezone)->format('j M Y'),
                            'lastActionBy' => $row->actor_name === null ? '' : (string) $row->actor_name,
                            'daysSinceAction' => (int) $actedAt->diffInDays($now, true),
                            'followUpDays' => (int) $row->claim_follow_up_days,
                            'showUrl' => route('panel-claims.show', ['claim' => (string) $row->public_id]),
                        ];
                    })->all(),
                    'total' => $rows->total(),
                    'currentPage' => $rows->currentPage(),
                    'lastPage' => $rows->lastPage(),
                ],
            ];
        });
    }
}
