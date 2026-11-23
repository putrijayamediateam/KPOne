<?php

namespace App\Domain\Approvals\Sources;

use App\Domain\Approvals\ApprovalItem;
use App\Domain\Approvals\ApprovalSource;
use App\Domain\Organisation\Inventory\Services\SupplierInvoiceService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A recorded supplier invoice waits for an approver at the branch (never the recorder). The row shows the invoice number. */
final class SupplierInvoiceSource implements ApprovalSource
{
    public function key(): string
    {
        return 'supplier_invoice';
    }

    public function label(): string
    {
        return 'Supplier invoices to approve';
    }

    public function area(): string
    {
        return 'Stock';
    }

    public function kind(): string
    {
        return self::APPROVAL;
    }

    public function permissions(): array
    {
        return [SupplierInvoiceService::APPROVE];
    }

    public function branchScoped(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'inventory.supplier-invoices';
    }

    public function routeParameters(): array
    {
        return ['tab' => 'waiting'];
    }

    public function hasReferences(): bool
    {
        return true;
    }

    /** The page's own "waiting" tab at the branch, without invoices this person recorded (the service refuses them). */
    public static function waitingFor(User $actor, Branch $branch): Builder
    {
        return DB::table('inventory_supplier_invoices as i')->where('i.organisation_id', $actor->organisation_id)
            ->where('i.branch_id', $branch->id)->where('i.status', 'waiting')->where('i.recorded_by_user_id', '<>', $actor->id);
    }

    public function count(User $actor, ?Branch $branch): int
    {
        return $branch === null ? 0 : self::waitingFor($actor, $branch)->count();
    }

    public function oldest(User $actor, ?Branch $branch): ?CarbonImmutable
    {
        $at = $branch === null ? null : self::waitingFor($actor, $branch)->min('i.recorded_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    public function items(User $actor, ?Branch $branch, int $limit): array
    {
        if ($branch === null) {
            return [];
        }
        $href = route($this->route(), $this->routeParameters(), absolute: false);

        return array_values(self::waitingFor($actor, $branch)->orderBy('i.recorded_at')->orderBy('i.id')->limit($limit)->get(['i.invoice_number', 'i.recorded_at'])
            ->map(fn (object $row): ApprovalItem => new ApprovalItem((string) $row->invoice_number, CarbonImmutable::parse((string) $row->recorded_at, 'UTC'), $href))->all());
    }
}
