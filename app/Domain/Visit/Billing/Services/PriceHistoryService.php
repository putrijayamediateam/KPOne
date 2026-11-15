<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * CAT-3: read-only price history of one charge.
 *
 * Lists every published price version of the charge in every price book, newest first, with who published it and
 * when it took effect. "In use" marks the version billing picks in that book now (PriceResolutionService: the
 * highest version already in effect, in an active book); a version whose effective time is still ahead is "Scheduled";
 * the newest version in effect in an inactive book is "Not in use". Publisher names
 * follow the staff-directory visibility rule (as BIL-2 receipts and cash closing do); others read "Staff member".
 * It writes nothing.
 */
final class PriceHistoryService
{
    public const PER_PAGE = 25;

    public function __construct(private readonly StaffAccessService $staff) {}

    /**
     * @return array{
     *     charge: array{publicId: string, code: string, displayName: string, unit: ?string, isActive: bool},
     *     books: array<int, array{publicId: string, label: string, isActive: bool}>,
     *     book: ?string,
     *     rows: array<int, array<string, mixed>>,
     *     total: int, currentPage: int, lastPage: int
     * }
     */
    public function history(User $actor, ChargeDefinition $charge, ?Branch $branch, ?string $bookPublicId, int $page): array
    {
        if (! $actor->can(PricingReferenceAdministrationService::PERMISSION)) {
            throw new AuthorizationException;
        }
        $organisationId = (int) $actor->organisation_id;
        abort_unless((int) $charge->organisation_id === $organisationId, 404);

        $entries = PriceEntry::query()
            ->where('organisation_id', $organisationId)
            ->where('charge_definition_id', $charge->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get(['id', 'price_book_id', 'version', 'unit_price_sen', 'effective_at', 'published_by_user_id']);

        /** @var Collection<int, PriceBook> $books */
        $books = PriceBook::query()
            ->where('organisation_id', $organisationId)
            // Every active book can be chosen (so "no price in this book" is visible), plus any inactive book that
            // still holds a version of this charge.
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $entries->pluck('price_book_id')->unique()->values()))
            ->get(['id', 'public_id', 'price_tier', 'branch_id', 'panel_id', 'is_active'])
            ->keyBy('id');
        $branchNames = Branch::query()->where('organisation_id', $organisationId)
            ->whereIn('id', $books->pluck('branch_id')->filter()->unique()->values())->pluck('name', 'id');
        $panelNames = Panel::query()->where('organisation_id', $organisationId)
            ->whereIn('id', $books->pluck('panel_id')->filter()->unique()->values())->pluck('name', 'id');
        $label = fn (PriceBook $book): string => match (true) {
            $book->price_tier === 'self_pay' && $book->branch_id === null => 'Self-pay (organisation)',
            $book->price_tier === 'self_pay' => 'Self-pay · '.($branchNames[$book->branch_id] ?? 'Branch'),
            $book->panel_id === null => 'Default Panel',
            default => 'Panel · '.($panelNames[$book->panel_id] ?? 'Panel'),
        };

        $bookOptions = $books
            ->map(fn (PriceBook $book): array => ['publicId' => (string) $book->public_id, 'label' => $label($book), 'isActive' => (bool) $book->is_active])
            ->sortBy(fn (array $book): string => mb_strtolower($book['label']))
            ->values();

        // The version billing would use in each book now: the highest version already in effect.
        $now = CarbonImmutable::now('UTC');
        // Billing reads active books only, so a version in an inactive book is never in use.
        $inUse = $entries
            ->filter(fn (PriceEntry $entry): bool => (bool) $books->get($entry->price_book_id)?->is_active
                && self::effectiveAt($entry)->lessThanOrEqualTo($now))
            ->groupBy('price_book_id')
            ->map(fn (Collection $versions): int => (int) $versions->sortByDesc('version')->first()->id);

        $selected = $bookPublicId === null ? null
            : $books->first(fn (PriceBook $book): bool => (string) $book->public_id === $bookPublicId);
        $filtered = $selected === null ? $entries : $entries->where('price_book_id', $selected->id)->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $currentPage = min(max(1, $page), $lastPage);
        $pageEntries = $filtered->forPage($currentPage, self::PER_PAGE)->values();

        $names = $this->staff->visibleUsers($actor)
            ->whereIn('id', $pageEntries->pluck('published_by_user_id')->unique()->values())
            ->pluck('name', 'id');
        $timezone = $branch !== null ? $branch->timezone : (string) config('app.timezone');

        return [
            'charge' => [
                'publicId' => (string) $charge->public_id,
                'code' => (string) $charge->code,
                'displayName' => (string) $charge->display_name,
                'unit' => $charge->unit,
                'isActive' => (bool) $charge->is_active,
            ],
            'books' => $bookOptions->all(),
            // The filter actually applied: an unknown book shows every version, so the page shows "All".
            'book' => $selected === null ? null : (string) $selected->public_id,
            'rows' => $pageEntries->map(function (PriceEntry $entry) use ($books, $entries, $label, $inUse, $now, $names, $timezone): array {
                $book = $books->get($entry->price_book_id);

                return [
                    'id' => (int) $entry->id,
                    'book' => $book === null ? 'Price book' : $label($book),
                    'bookActive' => (bool) $book?->is_active,
                    'version' => (int) $entry->version,
                    'amountSen' => (int) $entry->unit_price_sen,
                    'effectiveAt' => self::effectiveAt($entry)->setTimezone($timezone)->format('j M Y, g:i A'),
                    'effectiveAtIso' => self::effectiveAt($entry)->toIso8601String(),
                    'publishedBy' => $names[$entry->published_by_user_id] ?? 'Staff member',
                    'status' => match (true) {
                        self::effectiveAt($entry)->greaterThan($now) => 'scheduled',
                        ($inUse[$entry->price_book_id] ?? null) === (int) $entry->id => 'in_use',
                        ! $book?->is_active && $this->isLatestInEffect($entries, $entry, $now) => 'not_in_use',
                        default => 'replaced',
                    },
                ];
            })->all(),
            'total' => $total,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
        ];
    }

    /**
     * The newest version already in effect in its book (what billing would use if the book were active).
     *
     * @param  Collection<int, PriceEntry>  $entries
     */
    private function isLatestInEffect(Collection $entries, PriceEntry $entry, CarbonImmutable $now): bool
    {
        $latest = $entries
            ->where('price_book_id', $entry->price_book_id)
            ->filter(fn (PriceEntry $candidate): bool => self::effectiveAt($candidate)->lessThanOrEqualTo($now))
            ->sortByDesc('version')
            ->first();

        return $latest !== null && (int) $latest->id === (int) $entry->id;
    }

    private static function effectiveAt(PriceEntry $entry): CarbonImmutable
    {
        // Timestamps are stored in UTC (AGENTS.md); read the stored value as UTC.
        return CarbonImmutable::parse((string) $entry->getRawOriginal('effective_at'), 'UTC');
    }
}
