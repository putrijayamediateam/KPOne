<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Internal read projection. The caller supplies the already-authorized branch and filtered claim query. */
final class PanelWaitingPaymentProjection
{
    /**
     * @param  Builder<PanelClaim>  $query
     * @return Builder<PanelClaim>
     */
    public function eligible(Builder $query): Builder
    {
        return $query->whereIn('status', ['accepted', 'partially_accepted', 'partially_paid'])
            ->whereNotNull('accepted_amount_sen')
            ->whereRaw('accepted_amount_sen > '.$this->receivedSql());
    }

    /** @return literal-string */
    private function receivedSql(): string
    {
        return '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = panel_claims.id and p.organisation_id = panel_claims.organisation_id and p.branch_id = panel_claims.branch_id and '.PanelClaimPaymentReversal::NOT_REVERSED_P.')';
    }

    /**
     * One statement snapshot supplies rows and matching totals, including an empty or out-of-range page.
     *
     * @param  Builder<PanelClaim>  $scoped
     * @return array{claims: LengthAwarePaginator<int, PanelClaim>, totals: array{count: int, acceptedSen: int, receivedSen: int, outstandingSen: int}}
     */
    public function read(Builder $scoped, int $requestedPage): array
    {
        $query = $scoped->reorder()->select('panel_claims.*')->selectRaw($this->receivedSql().' as received_sen')
            ->whereIn('status', ['accepted', 'partially_accepted', 'partially_paid'])->whereNotNull('accepted_amount_sen');
        $sql = 'WITH balances AS MATERIALIZED ('.$query->toSql().'), eligible AS MATERIALIZED (
            SELECT *, accepted_amount_sen - received_sen AS outstanding_sen FROM balances WHERE accepted_amount_sen > received_sen
        ), totals AS (
            SELECT count(*) AS count, coalesce(sum(accepted_amount_sen),0) AS accepted_sen,
                coalesce(sum(received_sen),0) AS received_sen, coalesce(sum(outstanding_sen),0) AS outstanding_sen FROM eligible
        ), bounds AS (
            SELECT *, greatest(1, least(?::bigint, greatest(1, (count + 19) / 20))) AS page FROM totals
        ) SELECT bounds.*, (
            SELECT coalesce(jsonb_agg(to_jsonb(selected) ORDER BY selected.id DESC), \'[]\'::jsonb)
            FROM (SELECT * FROM eligible ORDER BY id DESC LIMIT 20 OFFSET (bounds.page - 1) * 20) selected
        ) AS rows FROM bounds';
        $result = DB::selectOne($sql, [...$query->getBindings(), max(1, $requestedPage)]);
        if ($result === null) {
            throw new \LogicException('Waiting-payment aggregate metadata is missing.');
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) $result->rows, true, flags: JSON_THROW_ON_ERROR);
        $count = (int) $result->count;

        return [
            'claims' => new LengthAwarePaginator(PanelClaim::hydrate($rows), $count, 20, (int) $result->page, ['pageName' => 'claims_page']),
            'totals' => ['count' => $count, 'acceptedSen' => (int) $result->accepted_sen,
                'receivedSen' => (int) $result->received_sen, 'outstandingSen' => (int) $result->outstanding_sen],
        ];
    }
}
