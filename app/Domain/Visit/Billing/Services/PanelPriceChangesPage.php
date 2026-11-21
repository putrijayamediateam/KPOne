<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the Panel price changes page: what each Panel pays today, what is waiting for a decision and what
 * was decided. Reads only; every write goes through PanelPriceProposalService.
 */
final class PanelPriceChangesPage
{
    public const PER_PAGE = 25;

    public function __construct(
        private BranchAccessService $branches,
        private StaffAccessService $staff,
    ) {}

    /** @return array<string, mixed> */
    public function page(User $actor, ?string $bookPublicId, ?string $chargePublicId, int $decidedPage = 1): array
    {
        return DB::transaction(function () use ($actor, $bookPublicId, $chargePublicId, $decidedPage): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $canPropose = $actor->can(PanelPriceProposalService::PROPOSE_PERMISSION);
            $canApprove = $actor->can(PanelPriceProposalService::APPROVE_PERMISSION);
            abort_unless($canPropose || $canApprove, 403);
            $org = $actor->organisation_id;
            $tz = (string) $branch->timezone;
            $label = static fn (?CarbonImmutable $at): ?string => $at?->setTimezone($tz)->format('j M Y, g:i A');

            $books = DB::table('price_books as b')->leftJoin('panels as p', 'p.id', '=', 'b.panel_id')
                ->where('b.organisation_id', $org)->where('b.price_tier', 'panel')->whereNull('b.branch_id')->where('b.currency', 'MYR')->where('b.is_active', true)
                ->orderBy('b.name')->get(['b.id', 'b.public_id', 'b.name', 'b.panel_id', 'p.name as panel_name']);
            $charges = DB::table('charge_definitions')->where('organisation_id', $org)->where('is_active', true)
                ->orderBy('display_name')->get(['id', 'public_id', 'display_name', 'code', 'type']);

            $selection = null;
            if ($canPropose && $bookPublicId !== null && $chargePublicId !== null) {
                $book = $books->firstWhere('public_id', $bookPublicId);
                $charge = $charges->firstWhere('public_id', $chargePublicId);
                if ($book && $charge) {
                    $entries = DB::table('price_entries')->where('organisation_id', $org)->where('price_book_id', $book->id)
                        ->where('charge_definition_id', $charge->id)->orderByDesc('version')->limit(3)->get(['unit_price_sen', 'version', 'effective_at']);
                    $now = CarbonImmutable::now('UTC');
                    $rows = $entries->map(static fn ($e): array => [
                        'version' => (int) $e->version,
                        'amountSen' => (int) $e->unit_price_sen,
                        'effectiveAt' => CarbonImmutable::parse((string) $e->effective_at, 'UTC'),
                    ]);
                    $current = $rows->first(static fn (array $r): bool => $r['effectiveAt']->lte($now));
                    $selection = [
                        'version' => (int) (DB::table('price_entries')->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version') ?? 0),
                        'currentAmountSen' => $current['amountSen'] ?? null,
                        'history' => $rows->map(static fn (array $r): array => [
                            'version' => $r['version'], 'amountSen' => $r['amountSen'],
                            'effectiveLabel' => $r['effectiveAt']->setTimezone($tz)->format('j M Y, g:i A'),
                            'scheduled' => $r['effectiveAt']->gt($now),
                        ])->all(),
                        'openProposal' => PriceChangeProposal::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->where('status', 'proposed')->exists(),
                    ];
                }
            }

            $base = DB::table('price_change_proposals as c')
                ->join('price_books as b', 'b.id', '=', 'c.price_book_id')->leftJoin('panels as p', 'p.id', '=', 'b.panel_id')
                ->join('charge_definitions as d', 'd.id', '=', 'c.charge_definition_id')
                ->where('c.organisation_id', $org)
                ->select(['c.*', 'b.public_id as book_public_id', 'b.name as book_name', 'p.name as panel_name', 'd.public_id as charge_public_id', 'd.display_name as charge_name']);
            $waiting = (clone $base)->where('c.status', 'proposed')->orderBy('c.proposed_at')->orderBy('c.id')->limit(100)->get();
            $waitingCount = DB::table('price_change_proposals')->where('organisation_id', $org)->where('status', 'proposed')->count();
            $decided = (clone $base)->where('c.status', '<>', 'proposed')->orderByDesc('c.updated_at')->orderByDesc('c.id')->paginate(self::PER_PAGE, ['*'], 'decided_page', $decidedPage);

            $all = $waiting->concat($decided->items());
            $userIds = $all->pluck('proposed_by_user_id')->merge($all->pluck('decided_by_user_id'))->filter()->unique()->all();
            $names = $this->staff->visibleUsers($actor)->whereIn('id', $userIds)->pluck('name', 'id');
            // Previous price per proposal: the entry before the version it was based on.
            $baseAmounts = [];
            foreach ($all as $row) {
                $baseAmounts[$row->id] = $row->base_version > 0
                    ? DB::table('price_entries')->where('price_book_id', $row->price_book_id)->where('charge_definition_id', $row->charge_definition_id)->where('version', $row->base_version)->value('unit_price_sen')
                    : null;
            }
            $map = function ($row) use ($names, $label, $actor, $baseAmounts): array {
                $effective = CarbonImmutable::parse((string) $row->effective_at, 'UTC');
                $name = static fn ($id): ?string => $id === null ? null : ($id === $actor->id ? $actor->name : ($names[$id] ?? 'Staff member'));

                return [
                    'id' => $row->public_id,
                    'panelName' => $row->panel_name ?? 'Default Panel price',
                    'bookId' => $row->book_public_id,
                    'chargeId' => $row->charge_public_id,
                    'chargeName' => $row->charge_name,
                    'oldAmountSen' => $baseAmounts[$row->id] === null ? null : (int) $baseAmounts[$row->id],
                    'newAmountSen' => (int) $row->proposed_unit_price_sen,
                    'reference' => $row->agreement_reference,
                    'effectiveLabel' => $label($effective),
                    'effectivePassed' => $effective->lte(CarbonImmutable::now('UTC')),
                    'status' => $row->status,
                    'proposedBy' => $name($row->proposed_by_user_id),
                    'proposedAtLabel' => $label(CarbonImmutable::parse((string) $row->proposed_at, 'UTC')),
                    'decidedBy' => $name($row->decided_by_user_id),
                    'decidedAtLabel' => $row->decided_at ? $label(CarbonImmutable::parse((string) $row->decided_at, 'UTC')) : null,
                    'reasonLabel' => $row->reason_code ? (PriceChangeProposal::REJECT_REASONS[$row->reason_code] ?? null) : null,
                    'appliedLabel' => $row->applied_effective_at ? $label(CarbonImmutable::parse((string) $row->applied_effective_at, 'UTC')) : null,
                    'mine' => (int) $row->proposed_by_user_id === $actor->id,
                    'lockVersion' => (int) $row->lock_version,
                ];
            };

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $tz],
                'canPropose' => $canPropose,
                'canApprove' => $canApprove,
                'books' => $books->map(static fn ($b): array => ['id' => $b->public_id, 'name' => $b->panel_id === null ? 'Default Panel price' : (string) ($b->panel_name ?? $b->name)])->all(),
                'charges' => $charges->map(static fn ($c): array => ['id' => $c->public_id, 'name' => (string) $c->display_name, 'code' => (string) $c->code, 'type' => (string) $c->type])->all(),
                'selection' => $selection,
                'waiting' => $waiting->map($map)->all(),
                'waitingCount' => $waitingCount,
                'decided' => collect($decided->items())->map($map)->all(),
                'decidedPagination' => ['page' => $decided->currentPage(), 'lastPage' => $decided->lastPage(), 'total' => $decided->total()],
                'rejectReasons' => PriceChangeProposal::REJECT_REASONS,
            ];
        });
    }
}
