<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\StaffAccessService;
use App\Models\User;
use Closure;

/**
 * TIDY-4: the person behind an Inventory record, in words. Same staff-directory visibility rule the owner approved for
 * Billing (BIL-2): the name if the viewer may already see that person in the staff directory, otherwise
 * "Staff member"; the viewer's own records read "You". No name reaches anyone who cannot already see it.
 */
final class InventoryStaffNames
{
    public function __construct(private StaffAccessService $staff) {}

    /**
     * @param  iterable<mixed>  $userIds
     * @return Closure(mixed): ?string
     */
    public function resolver(User $viewer, iterable $userIds): Closure
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        unset($ids[$viewer->id]);

        $names = $ids === [] ? [] : $this->staff->visibleUsers($viewer)->whereIn('id', array_keys($ids))->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)->all();

        return static function (mixed $id) use ($viewer, $names): ?string {
            if ($id === null || (int) $id <= 0) {
                return null;
            }

            return (int) $id === $viewer->id ? 'You' : ($names[(int) $id] ?? 'Staff member');
        };
    }
}
