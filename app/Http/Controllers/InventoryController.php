<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryOperationsDirectoryService;
use App\Domain\Organisation\Models\Branch;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    private const RECEIPT_SESSION_SCOPE_KEY = 'inventory.receipts.session_scope.v3';

    private const RECEIPT_SCOPE_PURPOSE = 'kpone.inventory.receipts.remember.v2';

    public function __invoke(Request $request, InventoryDirectoryService $directory, InventoryOperationsDirectoryService $operations, BranchAccessService $branches): Response
    {
        $data = $request->validate([
            'tab' => ['sometimes', Rule::in(['stock', 'batches', 'movements'])],
            'search' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'movement_type' => ['nullable', Rule::in(InventoryDirectoryService::MOVEMENT_TYPES)],
            'batch' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $filters = [
            'tab' => $data['tab'] ?? 'stock',
            'search' => isset($data['search']) ? trim($data['search']) : null,
            'location' => $data['location'] ?? null,
            'status' => $data['status'] ?? null,
            'movement_type' => $data['movement_type'] ?? null,
            'batch' => isset($data['batch']) ? trim($data['batch']) : null,
            'page' => (int) ($data['page'] ?? 1),
        ];

        $actor = $request->user();
        $operationDirectory = $operations->directory($actor);
        $activeBranch = $branches->activeBranch($actor);
        abort_unless($activeBranch && $activeBranch->id === $operationDirectory['expectedBranchId'], 404);

        return Inertia::render('Inventory/Index', [
            'inventory' => $directory->directory($actor, $filters),
            'operations' => $operationDirectory,
            'receiptMemoryContext' => $this->receiptMemoryContext($request, $actor, $activeBranch),
        ]);
    }

    /** @return array{version:2,organisationPublicId:string,actorPublicId:string,sessionNonce:string,branchPublicId:string} */
    private function receiptMemoryContext(Request $request, User $actor, Branch $branch): array
    {
        $historyEpoch = HandleInertiaRequests::currentAuthenticationHistoryEpoch($request);
        abort_unless($historyEpoch !== null, 500);
        $nonce = self::currentReceiptSessionNonce($request, $actor)
            ?? Str::lower((string) Str::uuid());

        $request->session()->put(self::RECEIPT_SESSION_SCOPE_KEY, [
            'actor_id' => $actor->id,
            'authentication_history_epoch' => $historyEpoch,
            'nonce' => $nonce,
        ]);

        return [
            'version' => 2,
            'organisationPublicId' => $this->receiptScopeIdentity('organisation', $actor->organisation_id),
            'actorPublicId' => $this->receiptScopeIdentity('actor', $actor->id),
            'sessionNonce' => $nonce,
            'branchPublicId' => $this->receiptScopeIdentity('branch', $branch->id),
        ];
    }

    public static function currentReceiptSessionNonce(Request $request, User $actor): ?string
    {
        $stored = $request->session()->get(self::RECEIPT_SESSION_SCOPE_KEY);
        $historyEpoch = HandleInertiaRequests::currentAuthenticationHistoryEpoch($request);

        return is_array($stored)
            && ($stored['actor_id'] ?? null) === $actor->id
            && $historyEpoch !== null
            && is_string($stored['authentication_history_epoch'] ?? null)
            && hash_equals($historyEpoch, Str::lower($stored['authentication_history_epoch']))
            && is_string($stored['nonce'] ?? null)
            && Str::isUuid($stored['nonce'])
                ? Str::lower($stored['nonce'])
                : null;
    }

    private function receiptScopeIdentity(string $kind, int $id): string
    {
        $applicationKey = config('app.key');
        abort_unless(is_string($applicationKey) && $applicationKey !== '', 500);

        return hash_hmac('sha256', self::RECEIPT_SCOPE_PURPOSE.':'.$kind.':'.$id, $applicationKey);
    }
}
