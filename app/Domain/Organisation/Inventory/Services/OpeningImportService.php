<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * STK-35 (approved by B.E.R.N.I.S.S for the owner, inside the 2026-11-11 inventory authorisation): record a branch's
 * opening stock from one CSV for the pilot set-up. Synthetic data only; not a Yezza data migration.
 *
 * Check first (writes nothing), then record every row or none. Each batch and opening goes through the existing
 * InventoryReferenceAdministrationService::createBatch and InventoryMovementService::openingBalance, so their rules,
 * locks and audit apply per row. The file is never stored; only its SHA-256 is kept with the import's idempotency key.
 * Every imported opening still needs the STK-1 independent sign-off on Pilot readiness, never by the importer.
 */
final class OpeningImportService
{
    public const PERMISSION = 'inventory.opening_balance.branch';

    public const MAX_ROWS = 500;

    public const MAX_BYTES = 1048576;

    /** The columns, in this order. unit_cost is optional (RM per stock unit, the existing opening cost field). */
    public const COLUMNS = ['location_code', 'sku_code', 'batch_number', 'expiry_date', 'quantity', 'unit_cost'];

    public function __construct(private AuditRecorder $audit) {}

    /**
     * Checks a file and says, row by row, what would be recorded. Writes nothing.
     *
     * @return array{hash: string, rows: list<array<string, mixed>>, errors: int}
     */
    public function check(User $actor, mixed $expectedBranchId, mixed $file): array
    {
        [$actor, $branch] = $this->context($actor, $expectedBranchId);
        [$hash, $rows] = $this->parse($file);

        return $this->review($actor, $branch, $hash, $rows);
    }

    /**
     * Records every row, or none. A repeat with the same key and file returns the earlier import.
     *
     * @param  array<string, mixed>  $input
     * @return array{import_id: int, recorded: int, replayed: bool}
     */
    public function record(User $actor, array $input, mixed $file): array
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The import key is missing. Reload the page and try again.']);
        }
        $key = Str::lower($key);
        [$actor, $branch] = $this->context($actor, $input['expected_branch_id'] ?? null);
        [$hash, $rows] = $this->parse($file);
        if (($input['file_hash'] ?? null) !== $hash) {
            throw ValidationException::withMessages(['file' => 'The file changed since it was checked. Check it again.']);
        }

        return DB::transaction(function () use ($actor, $branch, $key, $hash, $rows): array {
            // Serialise imports per organisation, so one key is used once and two imports never race on a batch.
            Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $earlier = DB::table('inventory_opening_imports')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
            if ($earlier !== null) {
                if ((int) $earlier->branch_id !== $branch->id || (int) $earlier->actor_user_id !== $actor->id || $earlier->file_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This import key was already used with a different file.']);
                }

                return ['import_id' => (int) $earlier->id, 'recorded' => (int) $earlier->row_count, 'replayed' => true];
            }
            $review = $this->review($actor, $branch, $hash, $rows);
            if ($review['errors'] > 0) {
                throw ValidationException::withMessages(['file' => 'Some rows have problems. Check the file again and fix them; nothing was recorded.']);
            }

            $references = app(InventoryReferenceAdministrationService::class);
            $movements = app(InventoryMovementService::class);
            $newBatches = 0;
            foreach ($review['rows'] as $index => $row) {
                $sku = InventorySku::query()->whereKey($row['sku_id'])->where('organisation_id', $actor->organisation_id)->firstOrFail();
                $batch = $row['batch_id'] === null ? null : InventoryBatch::query()->whereKey($row['batch_id'])->firstOrFail();
                try {
                    if ($batch === null) {
                        $batch = $references->createBatch($actor, $sku, ['batch_number' => $row['batch_number'], 'expiry_date' => $row['expiry_date'], 'status' => InventoryBatch::STATUS_AVAILABLE]);
                        $newBatches++;
                    }
                    $movements->openingBalance($actor, ['expected_branch_id' => $branch->id, 'location_public_id' => $row['location_public_id'], 'sku_public_id' => $sku->public_id,
                        'batch_public_id' => $batch->public_id, 'quantity' => $row['quantity'], 'unit_cost_sen' => $row['unit_cost_sen']]);
                } catch (ValidationException $exception) {
                    // The whole import rolls back; say which row the existing service refused.
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 2).': '.collect($exception->errors())->flatten()->first().' Nothing was recorded.']);
                }
            }
            $id = DB::table('inventory_opening_imports')->insertGetId(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'actor_user_id' => $actor->id,
                'idempotency_key' => $key, 'file_hash' => $hash, 'row_count' => count($review['rows']), 'created_at' => now()->utc()]);
            $this->audit->record('inventory.opening_import.recorded', null, ['import_id' => $id, 'row_count' => count($review['rows']), 'new_batch_count' => $newBatches], $actor, $branch);

            return ['import_id' => $id, 'recorded' => count($review['rows']), 'replayed' => false];
        }, 3);
    }

    /** The template a person fills in, with one synthetic example row. */
    public function template(): string
    {
        return implode(',', self::COLUMNS)."\r\n".'DISP-01,SKU-0001,BATCH-001,'.now()->addYear()->format('Y').'-12-31,100,0.25'."\r\n";
    }

    /** @return array{0: User, 1: Branch} */
    private function context(User $actor, mixed $expectedBranchId): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can(self::PERMISSION), 403);
        // The existing opening-balance rule: only a CA Supervisor records opening stock.
        if (! $actor->hasRole('ca_supervisor')) {
            throw new AuthorizationException('Opening stock import requires CA Supervisor authority.');
        }
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branches->hasEffectiveAssignment($actor, $branch), 403);
        if ((int) $expectedBranchId !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Review and retry.']);
        }

        return [$actor, $branch];
    }

    /**
     * Reads the CSV in memory. Refuses anything that is not a small UTF-8 text file with exactly the expected header.
     *
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private function parse(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a CSV file.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'The file is larger than 1 MB.']);
        }
        $content = (string) file_get_contents($file->getRealPath());
        $hash = hash('sha256', $content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if ($content === '' || str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'This is not a UTF-8 CSV file.']);
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $header = array_map(fn (?string $cell): string => Str::lower(trim((string) $cell)), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if ($header !== self::COLUMNS && $header !== array_slice(self::COLUMNS, 0, 5)) {
            throw ValidationException::withMessages(['file' => 'The first row must be: '.implode(',', self::COLUMNS).' (unit_cost may be left out). Download the template.']);
        }
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file has no rows under the header.']);
        }
        if (count($lines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']);
        }
        $rows = [];
        foreach ($lines as $index => $line) {
            $cells = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, ',', '"', ''));
            foreach ($cells as $cell) {
                // A spreadsheet formula is never read as data.
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 2).' holds a formula or a negative number. Save plain values only.']);
                }
            }
            $rows[] = array_combine(self::COLUMNS, array_pad(array_slice($cells, 0, count(self::COLUMNS)), count(self::COLUMNS), ''));
        }

        return [$hash, $rows];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{hash: string, rows: list<array<string, mixed>>, errors: int}
     */
    private function review(User $actor, Branch $branch, string $hash, array $rows): array
    {
        $organisationId = (int) $actor->organisation_id;
        $today = CarbonImmutable::now($branch->timezone)->toDateString();
        $locations = InventoryLocation::query()->where('organisation_id', $organisationId)
            ->whereIn(DB::raw('LOWER(code)'), array_map(fn (array $row): string => Str::lower($row['location_code']), $rows))->get()->keyBy(fn (InventoryLocation $l): string => Str::lower($l->code));
        $skus = InventorySku::query()->where('organisation_id', $organisationId)
            ->whereIn(DB::raw('LOWER(sku_code)'), array_map(fn (array $row): string => Str::lower($row['sku_code']), $rows))->get()->keyBy(fn (InventorySku $s): string => Str::lower($s->sku_code));
        $seen = [];
        $out = [];
        $errors = 0;
        foreach ($rows as $row) {
            $problems = [];
            $location = $locations[Str::lower($row['location_code'])] ?? null;
            $sku = $skus[Str::lower($row['sku_code'])] ?? null;
            if ($location === null || ! $location->is_active || $location->branch_id !== $branch->id) {
                $problems[] = 'No active location with this code at this branch.';
            } elseif ($location->type === InventoryLocation::TYPE_DISPENSARY_RETURNS) {
                $problems[] = 'Opening stock never goes into a patient returns location.';
            }
            if ($sku === null || ! $sku->is_active) {
                $problems[] = 'No active SKU with this code.';
            }
            if ($row['batch_number'] === '' || mb_strlen($row['batch_number']) > 100) {
                $problems[] = 'Enter a batch number (up to 100 characters).';
            }
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['expiry_date']) === 1 && checkdate((int) substr($row['expiry_date'], 5, 2), (int) substr($row['expiry_date'], 8, 2), (int) substr($row['expiry_date'], 0, 4))
                ? CarbonImmutable::createFromFormat('!Y-m-d', $row['expiry_date']) : null;
            $expiry = $date !== null && $date->format('Y-m-d') === $row['expiry_date'] ? $row['expiry_date'] : null;
            if ($expiry === null) {
                $problems[] = 'Enter the expiry date as YYYY-MM-DD.';
            } elseif ($expiry <= $today) {
                $problems[] = 'The expiry date must be after today.';
            }
            $quantity = null;
            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $row['quantity']) === 1 && StockUnitQuantity::scaled($row['quantity'], true) > 0) {
                $quantity = StockUnitQuantity::format(StockUnitQuantity::scaled($row['quantity'], true));
            } else {
                $problems[] = 'Enter a quantity above 0, with up to 3 decimals.';
            }
            $cost = null;
            if ($row['unit_cost'] !== '') {
                if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $row['unit_cost'], $parts) === 1) {
                    $cost = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
                } else {
                    $problems[] = 'Enter the unit cost in RM with up to 2 decimals, or leave it empty.';
                }
            }
            $batch = null;
            if ($sku !== null && $expiry !== null && $row['batch_number'] !== '') {
                $existing = InventoryBatch::query()->where('organisation_id', $organisationId)->where('inventory_sku_id', $sku->id)
                    ->whereRaw('LOWER(batch_number) = ?', [Str::lower($row['batch_number'])])->get();
                $batch = $existing->first(fn (InventoryBatch $b): bool => CarbonImmutable::parse($b->expiry_date)->format('Y-m-d') === $expiry);
                if ($batch === null && $existing->isNotEmpty()) {
                    $problems[] = 'This batch already exists with expiry '.CarbonImmutable::parse($existing->first()->expiry_date)->format('Y-m-d').'. Check the date.';
                }
                if ($batch !== null && $location !== null && StockMovement::query()->where('organisation_id', $organisationId)->where('movement_type', StockMovement::TYPE_OPENING)
                    ->where('destination_location_id', $location->id)->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)->exists()) {
                    $problems[] = 'An opening is already recorded for this location, SKU and batch.';
                }
            }
            // A batch is its number and expiry, as InventoryBatch is unique on both.
            $identity = Str::lower($row['location_code'].'|'.$row['sku_code'].'|'.$row['batch_number'].'|'.$row['expiry_date']);
            if (isset($seen[$identity])) {
                $problems[] = 'The same location, SKU and batch are already on row '.$seen[$identity].'.';
            } else {
                $seen[$identity] = count($out) + 2;
            }
            $errors += $problems === [] ? 0 : 1;
            $out[] = [
                'line' => count($out) + 2,
                'location' => $location !== null ? $location->name : $row['location_code'],
                'location_public_id' => $location?->public_id,
                'sku' => $row['sku_code'], 'sku_id' => $sku?->id, 'stock_unit' => $sku?->stock_unit,
                'batch_number' => $row['batch_number'], 'batch_id' => $batch?->id, 'new_batch' => $batch === null,
                'expiry_date' => $row['expiry_date'], 'quantity' => $quantity ?? $row['quantity'], 'unit_cost_sen' => $cost,
                'problems' => $problems,
            ];
        }

        return ['hash' => $hash, 'rows' => $out, 'errors' => $errors];
    }
}
