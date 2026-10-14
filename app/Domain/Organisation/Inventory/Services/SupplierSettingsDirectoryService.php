<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SupplierSettingsDirectoryService
{
    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority) {}

    /** @return array<string, mixed> */
    public function index(User $actor, string $search, int $page): array
    {
        return DB::transaction(function () use ($actor, $search, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, SupplierSettingsService::PERMISSION);
            $query = InventorySupplier::query()->where('organisation_id', $actor->organisation_id);
            if ($search !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->where(function ($q) use ($needle): void {
                    $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle])
                        ->orWhereRaw("LOWER(contact_name) LIKE ? ESCAPE '!'", [$needle])
                        ->orWhereRaw("LOWER(business_phone) LIKE ? ESCAPE '!'", [$needle])
                        ->orWhereExists(function ($contacts) use ($needle): void {
                            $contacts->selectRaw('1')->from('inventory_supplier_contacts')
                                ->whereColumn('inventory_supplier_id', 'inventory_suppliers.id')
                                ->whereColumn('organisation_id', 'inventory_suppliers.organisation_id')
                                ->where(fn ($c) => $c->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '!'", [$needle]));
                        });
                });
            }
            $paginator = $query->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'page', $page);
            $contacts = DB::table('inventory_supplier_contacts')->where('organisation_id', $actor->organisation_id)
                ->whereIn('inventory_supplier_id', $paginator->getCollection()->pluck('id'))->orderBy('position')->get()->groupBy('inventory_supplier_id');
            $rows = $paginator->getCollection()->map(function (InventorySupplier $supplier) use ($contacts): array {
                $people = [];
                if ($supplier->contact_name !== null || $supplier->business_phone !== null) {
                    $people[] = ['name' => $supplier->contact_name, 'phone' => $supplier->business_phone];
                }
                foreach ($contacts->get($supplier->id, collect()) as $contact) {
                    $people[] = ['name' => $contact->name, 'phone' => $contact->phone];
                }

                return ['publicId' => $supplier->public_id, 'name' => $supplier->name, 'contacts' => $people,
                    'active' => $supplier->is_active, 'lockVersion' => $supplier->lock_version];
            })->all();

            return ['branchId' => $branch->id, 'q' => $search, 'suppliers' => $rows,
                'pagination' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]];
        });
    }
}
