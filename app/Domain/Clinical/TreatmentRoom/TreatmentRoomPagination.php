<?php

namespace App\Domain\Clinical\TreatmentRoom;

final class TreatmentRoomPagination
{
    public const PER_PAGE = 25;

    /** @return array{currentPage:int,lastPage:int,perPage:int,total:int} */
    public static function metadata(int $page, int $total): array
    {
        $last = max(1, (int) ceil($total / self::PER_PAGE));

        return ['currentPage' => min(max(1, $page), $last), 'lastPage' => $last, 'perPage' => self::PER_PAGE, 'total' => $total];
    }
}
