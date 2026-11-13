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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
     * @return array{hash: string, rows: list<array<string, mixed>>, errors: int, newBatches: int}
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
                    throw ValidationException::withMessages(['idempotency_key' => 'This import key was already used. Reload the page and try again.']);
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
            /** @var array<string, InventoryBatch> $created a new batch is made once and reused by later rows (Mei Ling) */
            $created = [];
            // Rows are written in location and SKU order, so concurrent writers take their locks in the same order.
            $ordered = $review['rows'];
            usort($ordered, fn (array $a, array $b): int => [$a['location_id'], $a['sku_id'], $a['line']] <=> [$b['location_id'], $b['sku_id'], $b['line']]);
            foreach ($ordered as $row) {
                try {
                    $sku = InventorySku::query()->whereKey($row['sku_id'])->where('organisation_id', $actor->organisation_id)->firstOrFail();
                    $batch = $row['batch_id'] === null ? null : InventoryBatch::query()->whereKey($row['batch_id'])->where('organisation_id', $actor->organisation_id)->firstOrFail();
                    $batchKey = $row['sku_id'].'|'.Str::lower($row['batch_number']).'|'.$row['expiry_date'];
                    if ($batch === null && isset($created[$batchKey])) {
                        $batch = $created[$batchKey];
                    } elseif ($batch === null) {
                        $batch = $created[$batchKey] = $references->createBatch($actor, $sku, ['batch_number' => $row['batch_number'], 'expiry_date' => $row['expiry_date'], 'status' => InventoryBatch::STATUS_AVAILABLE]);
                        $newBatches++;
                    }
                    $movements->openingBalance($actor, ['expected_branch_id' => $branch->id, 'location_public_id' => $row['location_public_id'], 'sku_public_id' => $sku->public_id,
                        'batch_public_id' => $batch->public_id, 'quantity' => $row['quantity'], 'unit_cost_sen' => $row['unit_cost_sen']]);
                } catch (ValidationException $exception) {
                    // The whole import rolls back; say which row the existing service refused.
                    throw ValidationException::withMessages(['file' => 'Row '.$row['line'].': '.collect($exception->errors())->flatten()->first().' Nothing was recorded.']);
                } catch (AuthorizationException|ModelNotFoundException|HttpException) {
                    throw ValidationException::withMessages(['file' => 'Row '.$row['line'].': this row can no longer be recorded (a location, SKU or batch changed, or your access did). Check the file again. Nothing was recorded.']);
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
        return implode(',', self::COLUMNS)."\r\n".'DISP-01,SKU-0001,BATCH-001,2030-12-31,100,0.25'."\r\n";
    }

    /** @return array{0: User, 1: Branch} */
    public function context(User $actor, mixed $expectedBranchId): array
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
        $width = count($header);
        // Keep each row's line number in the file (the header is line 1), even when blank lines are skipped.
        $numbered = [];
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $numbered[$index + 2] = $line;
            }
        }
        $lines = $numbered;
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file has no rows under the header.']);
        }
        if (count($lines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']);
        }
        $rows = [];
        foreach ($lines as $number => $line) {
            $cells = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, ',', '"', ''));
            // An unquoted "1,000" would shift every later cell: refuse a row whose width differs from the header's.
            if (count($cells) !== $width) {
                throw ValidationException::withMessages(['file' => 'Row '.$number.' has '.count($cells).' cells; expected '.$width.'. Put quotes around numbers with commas, or remove the commas.']);
            }
            foreach ($cells as $cell) {
                // A spreadsheet formula is never read as data.
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                    throw ValidationException::withMessages(['file' => 'Row '.$number.' holds a formula or a negative number. Save plain values only.']);
                }
            }
            $rows[] = ['line' => (string) $number, ...array_combine(self::COLUMNS, array_pad($cells, count(self::COLUMNS), ''))];
        }

        return [$hash, $rows];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{hash: string, rows: list<array<string, mixed>>, errors: int, newBatches: int}
     */
    private function review(User $actor, Branch $branch, string $hash, array $rows): array
    {
        $organisationId = (int) $actor->organisation_id;
        $today = CarbonImmutable::now($branch->timezone)->toDateString();
        $locations = InventoryLocation::query()->where('organisation_id', $organisationId)
            ->whereIn(DB::raw('LOWER(code)'), array_map(fn (array $row): string => Str::lower($row['location_code']), $rows))->get()->keyBy(fn (InventoryLocation $l): string => Str::lower($l->code));
        $skus = InventorySku::query()->where('organisation_id', $organisationId)
            ->whereIn(DB::raw('LOWER(sku_code)'), array_map(fn (array $row): string => Str::lower($row['sku_code']), $rows))->get()->keyBy(fn (InventorySku $s): string => Str::lower($s->sku_code));
        // Two queries for the whole file instead of two per row (Mei Ling): the SKUs' batches, and the openings
        // already recorded at these locations.
        $skuIds = $skus->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $batches = $skuIds === [] ? collect() : InventoryBatch::query()->where('organisation_id', $organisationId)->whereIn('inventory_sku_id', $skuIds)
            ->whereIn(DB::raw('LOWER(batch_number)'), array_map(fn (array $row): string => Str::lower($row['batch_number']), $rows))->get()
            ->groupBy(fn (InventoryBatch $b): string => $b->inventory_sku_id.'|'.Str::lower($b->batch_number));
        $locationIds = $locations->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $openings = $locationIds === [] ? [] : array_flip(StockMovement::query()->where('organisation_id', $organisationId)->where('movement_type', StockMovement::TYPE_OPENING)
            ->whereIn('destination_location_id', $locationIds)->whereIn('inventory_sku_id', $skuIds)->get(['destination_location_id', 'inventory_sku_id', 'inventory_batch_id'])
            ->map(fn (StockMovement $m): string => $m->destination_location_id.'|'.$m->inventory_sku_id.'|'.$m->inventory_batch_id)->all());
        $seen = [];
        $expiryInFile = [];
        $out = [];
        $errors = 0;
        $canCreateBatches = $actor->can(InventoryReferenceAdministrationService::PERMISSION);
        foreach ($rows as $row) {
            $line = (int) $row['line'];
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
                $existing = $batches[$sku->id.'|'.Str::lower($row['batch_number'])] ?? collect();
                $batch = $existing->first(fn (InventoryBatch $b): bool => CarbonImmutable::parse($b->expiry_date)->format('Y-m-d') === $expiry);
                if ($batch === null && $existing->isNotEmpty()) {
                    $problems[] = 'This batch already exists with expiry '.CarbonImmutable::parse($existing->first()->expiry_date)->format('Y-m-d').'. Check the date.';
                }
                if ($batch !== null && $location !== null && isset($openings[$location->id.'|'.$sku->id.'|'.$batch->id])) {
                    $problems[] = 'An opening is already recorded for this location, SKU and batch.';
                }
                // One batch number has one expiry, in the file as in the database (the same rule both ways).
                $numberKey = $sku->id.'|'.Str::lower($row['batch_number']);
                if (isset($expiryInFile[$numberKey]) && $expiryInFile[$numberKey][0] !== $expiry) {
                    $problems[] = 'This batch has a different expiry on row '.$expiryInFile[$numberKey][1].'. Check the date.';
                } elseif (! isset($expiryInFile[$numberKey])) {
                    $expiryInFile[$numberKey] = [$expiry, $line];
                }
                if ($batch === null && ! $canCreateBatches) {
                    $problems[] = 'This is a new batch, and you may not create batches. Ask someone who manages Inventory references.';
                }
            }
            // A batch is its number and expiry, as InventoryBatch is unique on both.
            $identity = Str::lower($row['location_code'].'|'.$row['sku_code'].'|'.$row['batch_number'].'|'.$row['expiry_date']);
            if (isset($seen[$identity])) {
                $problems[] = 'The same location, SKU and batch are already on row '.$seen[$identity].'.';
            } else {
                $seen[$identity] = $line;
            }
            $errors += $problems === [] ? 0 : 1;
            $out[] = [
                'line' => $line,
                'location' => $location !== null ? $location->name : $row['location_code'],
                'location_public_id' => $location?->public_id, 'location_id' => $location?->id,
                'sku' => $row['sku_code'], 'sku_id' => $sku?->id, 'stock_unit' => $sku?->stock_unit,
                'batch_number' => $row['batch_number'], 'batch_id' => $batch?->id, 'new_batch' => $batch === null,
                'expiry_date' => $row['expiry_date'], 'quantity' => $quantity ?? $row['quantity'], 'unit_cost_sen' => $cost,
                'problems' => $problems,
            ];
        }

        $newBatches = count(array_unique(array_map(fn (array $row): string => $row['sku'].'|'.Str::lower($row['batch_number']).'|'.$row['expiry_date'], array_filter($out, fn (array $row): bool => $row['new_batch']))));

        return ['hash' => $hash, 'rows' => $out, 'errors' => $errors, 'newBatches' => $newBatches];
    }
}
