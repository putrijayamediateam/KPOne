<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Helpers shared by the data sources: which branches a call covers and branch-local dates. */
trait ReadsBranches
{
    /** @return Collection<int, Branch> the given branch, or every active branch of the requester's organisation */
    protected function branchesFor(User $requester, ?Branch $branch): Collection
    {
        if ($branch !== null) {
            return collect([$branch]);
        }

        return $this->branchesForOrganisation($requester->organisation_id, null);
    }

    /** @return Collection<int, Branch> the given branch, or every active branch of the organisation */
    protected function branchesForOrganisation(int $organisationId, ?Branch $branch): Collection
    {
        if ($branch !== null) {
            return collect([$branch]);
        }

        return Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('code')->get();
    }

    protected function localDate(Branch $branch, ?string $date, int $offsetDays = 0): string
    {
        return $date ?? CarbonImmutable::now($branch->timezone)->addDays($offsetDays)->toDateString();
    }

    /** Exact three-decimal quantity text from a database numeric (no floating-point arithmetic). */
    protected function quantity(mixed $value): string
    {
        return $this->fromMilli($this->milli($value));
    }

    /** A numeric(15,3) value as whole thousandths. */
    protected function milli(mixed $value): int
    {
        $text = trim((string) ($value ?? '0'));
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        $milli = (int) $whole * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$milli : $milli;
    }

    protected function fromMilli(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}
