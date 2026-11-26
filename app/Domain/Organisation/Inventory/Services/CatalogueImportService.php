<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PanelPriceTarget;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * IMP-1 (owner, 25 Nov 2026, for the Puchong live test): a one-off import of the clinic's medicines, SKUs and Self-pay
 * prices from two CSV files, run from the console by the coordinator. Every write goes through the existing domain
 * services (UnifiedCatalogueSetupService for the medicine, its SKU and the HQ Self-pay price; MedicineAdministrationService
 * for the controlled flag; PricingReferenceAdministrationService and PricePublicationService for branch Self-pay prices),
 * so every permission check, lock, version and audit row is the usual one, under one named actor.
 *
 * All or nothing: both files are checked completely first and refused with every problem listed; then everything is
 * written in one transaction, or nothing is. A dry run does the whole write and rolls it back, so it reports exactly
 * what a real run would do. An existing code is refused (so running the same file twice changes nothing), branch prices are for this file's
 * medicines only, and an inactive branch book is refused rather than switched back on.
 * Panel prices are never published here: since owner decision 27 they are filed as proposals for someone to approve.
 */
/** Not final, so the console test can stand in a service that a domain rule refuses. */
class CatalogueImportService
{
    public const CATALOGUE_COLUMNS = ['code', 'display_name', 'generic_name', 'strength', 'dosage_form', 'category', 'sku_code', 'barcode', 'purchase_unit', 'pack_size', 'stock_unit', 'dispensing_unit', 'controlled', 'hq_self_pay_rm', 'hq_panel_rm'];

    public const BRANCH_PRICE_COLUMNS = ['code', 'branch_code', 'self_pay_rm'];

    /** The actor must hold all of these (Administrator or CA Supervisor on main). */
    public const PERMISSIONS = [MedicineAdministrationService::PERMISSION, 'prices.publish.organisation', PricingReferenceAdministrationService::PERMISSION, InventoryReferenceAdministrationService::PERMISSION];

    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 5242880;

    private const ERROR_LIMIT = 100;

    public function __construct(
        private UnifiedCatalogueSetupService $setup,
        private MedicineAdministrationService $medicines,
        private PricingReferenceAdministrationService $pricing,
        private PricePublicationService $publication,
        private BranchAccessService $branches,
        private AuditRecorder $audit,
        private PanelPriceProposalService $proposals,
    ) {}

    /**
     * Panel prices (owner, 25 Nov 2026): an `hq_panel_rm` value is never published. It is filed as a proposal for the
     * default Panel book through PanelPriceProposalService, as the named Panel Officer, with one agreement reference and
     * today's date; a Director or Finance Officer approves it on the Panel price changes page (owner decision 27).
     *
     * @return array{medicines:int, hqPrices:int, branchPrices:int, controlled:int, panelProposals:int, dryRun:bool}
     *
     * @throws CatalogueImportRefused when either file has a problem; nothing is written
     */
    public function run(User $actor, Branch $homeBranch, string $cataloguePath, ?string $branchPricesPath, bool $dryRun, ?User $panelProposer = null, ?string $panelReference = null): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if ($actor === null || ! $actor->is_active || $homeBranch->organisation_id !== $actor->organisation_id || ! $homeBranch->is_active) {
            throw new CatalogueImportRefused(['The actor must be an active account in the same organisation as an active home branch.']);
        }
        $missing = array_values(array_filter(self::PERMISSIONS, fn (string $p): bool => ! $actor->can($p)));
        if ($missing !== []) {
            throw new CatalogueImportRefused(['The actor lacks: '.implode(', ', $missing).'. Use a named Administrator or CA Supervisor account.']);
        }
        if (! $this->branches->hasEffectiveAssignment($actor, $homeBranch)) {
            throw new CatalogueImportRefused(["The actor is not assigned to the home branch {$homeBranch->code}."]);
        }

        [$rows, $errors] = $this->readCatalogue($actor, $cataloguePath);
        [$branchRows, $branchErrors] = $branchPricesPath === null ? [[], []] : $this->readBranchPrices($actor, $branchPricesPath, $rows);
        $errors = [...$errors, ...$branchErrors, ...$this->panelProblems($rows, $homeBranch, $panelProposer, $panelReference)];
        if ($errors !== []) {
            throw new CatalogueImportRefused($errors);
        }

        $report = ['medicines' => 0, 'hqPrices' => 0, 'branchPrices' => 0, 'controlled' => 0, 'panelProposals' => 0, 'dryRun' => $dryRun];
        try {
            DB::transaction(function () use ($actor, $homeBranch, $rows, $branchRows, $dryRun, $panelProposer, $panelReference, &$report): void {
                $this->branches->select($actor, $homeBranch);
                $created = [];
                foreach ($rows as $row) {
                    $medicine = $this->setup->createMedicine($actor, [
                        'code' => $row['code'], 'display_name' => $row['display_name'], 'generic_name' => $row['generic_name'],
                        'strength_text' => $row['strength'], 'dosage_form' => $row['dosage_form'], 'category' => $row['category'],
                        'order_unit' => $row['dispensing_unit'], 'expected_branch_id' => $homeBranch->id,
                        'sku' => ['sku_code' => $row['sku_code'], 'barcode' => $row['barcode'], 'pack_size' => $row['pack_size'], 'purchase_unit' => $row['purchase_unit'],
                            'stock_unit' => $row['stock_unit'], 'dispensing_unit' => $row['dispensing_unit'], 'unit_conversion' => '1'],
                        'prices' => $row['hq_self_pay_sen'] === null ? [] : ['self_pay_sen' => $row['hq_self_pay_sen']],
                    ]);
                    $report['medicines']++;
                    $report['hqPrices'] += $row['hq_self_pay_sen'] === null ? 0 : 1;
                    if ($row['controlled']) {
                        $this->medicines->setControlled($actor, $medicine, true, false);
                        $report['controlled']++;
                    }
                    $created[Str::lower($row['code'])] = $medicine;
                }
                if ($panelProposer !== null) {
                    $this->branches->select($panelProposer, $homeBranch);
                    $today = now($homeBranch->timezone)->toDateString();
                    foreach ($rows as $row) {
                        if ($row['hq_panel_sen'] === null) {
                            continue;
                        }
                        $this->proposals->propose($panelProposer, PanelPriceTarget::forPanel(null), $this->charge($actor, $created[Str::lower($row['code'])]), [
                            'expected_branch_id' => $homeBranch->id, 'expected_version' => 0, 'amount_sen' => $row['hq_panel_sen'],
                            'agreement_reference' => (string) $panelReference, 'effective_date' => $today, 'idempotency_key' => (string) Str::uuid(),
                        ]);
                        $report['panelProposals']++;
                    }
                }
                foreach ($this->groupByBranch($branchRows) as $branchId => $prices) {
                    $branch = Branch::query()->whereKey($branchId)->firstOrFail();
                    $this->branches->select($actor, $branch);
                    $book = $this->branchBook($actor, $branch);
                    foreach ($prices as $price) {
                        $medicine = $created[Str::lower($price['code'])];
                        $charge = $this->charge($actor, $medicine);
                        $version = (int) PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
                        $this->publication->publish($actor, $book, $charge, $price['self_pay_sen'], $version, $branch->id);
                        $report['branchPrices']++;
                    }
                }
                $this->branches->select($actor, $homeBranch);
                abort_if($this->audit->record('inventory.catalogue_import.recorded', null, ['medicines' => $report['medicines'], 'hq_prices' => $report['hqPrices'], 'branch_prices' => $report['branchPrices'], 'controlled' => $report['controlled'], 'panel_proposals' => $report['panelProposals'], 'dry_run' => $dryRun], $actor, $homeBranch) === null, 503, 'The import could not be audited. Nothing was saved.');
                if ($dryRun) {
                    throw new CatalogueDryRunComplete;
                }
            });
        } catch (CatalogueDryRunComplete) {
            // The dry run did every write and rolled them all back.
        }

        return $report;
    }

    /** @return array{0:list<array<string,mixed>>,1:list<string>} */
    private function readCatalogue(User $actor, string $path): array
    {
        [$records, $errors] = $this->readCsv($path, self::CATALOGUE_COLUMNS, 'catalogue');
        if ($errors !== []) {
            return [[], $errors];
        }
        $rows = [];
        $seen = ['code' => [], 'sku_code' => [], 'barcode' => []];
        foreach ($records as $line => $r) {
            $where = "catalogue line {$line}";
            $row = ['code' => Str::upper($r['code']), 'display_name' => $r['display_name'], 'generic_name' => $this->nullable($r['generic_name']), 'strength' => $this->nullable($r['strength']),
                'dosage_form' => $this->nullable($r['dosage_form']), 'category' => $this->nullable($r['category']), 'sku_code' => Str::upper($r['sku_code'] !== '' ? $r['sku_code'] : $r['code']),
                'barcode' => $this->nullable($r['barcode']), 'purchase_unit' => $r['purchase_unit'], 'pack_size' => $r['pack_size'], 'stock_unit' => $r['stock_unit'],
                'dispensing_unit' => $r['dispensing_unit'], 'controlled' => false, 'hq_self_pay_sen' => null, 'hq_panel_sen' => null];
            foreach (['code' => 64, 'display_name' => 500, 'purchase_unit' => 100, 'stock_unit' => 100, 'dispensing_unit' => 100] as $field => $max) {
                if ($r[$field] === '' || mb_strlen($r[$field]) > $max) {
                    $errors[] = "{$where}: {$field} is required (at most {$max} characters).";
                }
            }
            foreach (['generic_name' => 300, 'strength' => 100, 'dosage_form' => 100, 'category' => 120, 'sku_code' => 64, 'barcode' => 100] as $field => $max) {
                if (mb_strlen($r[$field]) > $max) {
                    $errors[] = "{$where}: {$field} is longer than {$max} characters.";
                }
            }
            if ($r['stock_unit'] !== '' && Str::lower($r['stock_unit']) !== Str::lower($r['dispensing_unit'])) {
                $errors[] = "{$where}: stock_unit and dispensing_unit must be the same unit (STK-1), or dispensing is refused.";
            }
            if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $r['pack_size']) !== 1 || (float) $r['pack_size'] <= 0) {
                $errors[] = "{$where}: pack_size must be a number above 0, with up to 3 decimals.";
            }
            $controlled = Str::lower($r['controlled']);
            if (! in_array($controlled, ['', 'yes', 'no'], true)) {
                $errors[] = "{$where}: controlled must be yes, no or blank.";
            }
            $row['controlled'] = $controlled === 'yes';
            $row['hq_self_pay_sen'] = $this->sen($r['hq_self_pay_rm'], "{$where}: hq_self_pay_rm", $errors);
            $row['hq_panel_sen'] = $this->sen($r['hq_panel_rm'], "{$where}: hq_panel_rm", $errors);
            foreach (['code', 'sku_code', 'barcode'] as $key) {
                $value = $row[$key] === null ? null : Str::lower((string) $row[$key]);
                if ($value === null) {
                    continue;
                }
                if (isset($seen[$key][$value])) {
                    $errors[] = "{$where}: {$key} {$row[$key]} is also on line {$seen[$key][$value]}.";
                } else {
                    $seen[$key][$value] = $line;
                }
            }
            $rows[$line] = $row;
        }
        $org = $actor->organisation_id;
        foreach ($this->existing(MedicineCatalogueItem::query()->where('organisation_id', $org), 'code', array_keys($seen['code'])) as $code) {
            $errors[] = "catalogue line {$seen['code'][$code]}: medicine code ".Str::upper($code).' already exists in KPOne.';
        }
        // The medicine setup would silently reuse an inventory item with the same code (possibly another product).
        foreach ($this->existing(InventoryItem::query()->where('organisation_id', $org), 'code', array_keys($seen['code'])) as $code) {
            $errors[] = "catalogue line {$seen['code'][$code]}: an inventory item with code ".Str::upper($code).' already exists in KPOne.';
        }
        foreach ($this->existing(InventorySku::query()->where('organisation_id', $org), 'sku_code', array_keys($seen['sku_code'])) as $code) {
            $errors[] = "catalogue line {$seen['sku_code'][$code]}: SKU code ".Str::upper($code).' already exists in KPOne.';
        }
        foreach ($this->existing(InventorySku::query()->where('organisation_id', $org), 'barcode', array_keys($seen['barcode'])) as $code) {
            $errors[] = "catalogue line {$seen['barcode'][$code]}: barcode {$code} already belongs to another SKU.";
        }

        return [array_values($rows), $this->limit($errors)];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function panelProblems(array $rows, Branch $homeBranch, ?User $proposer, ?string $reference): array
    {
        if (array_filter($rows, fn (array $row): bool => $row['hq_panel_sen'] !== null) === []) {
            return $proposer === null ? [] : ['--panel-proposer was given, but no row has an hq_panel_rm value.'];
        }
        $proposer = $proposer?->fresh()?->load(['roles.permissions', 'permissions']);
        if ($proposer === null) {
            return ['hq_panel_rm values are filed as Panel price proposals: give --panel-proposer (a named Panel Officer) and --panel-reference.'];
        }
        $errors = [];
        if (! $proposer->is_active || $proposer->organisation_id !== $homeBranch->organisation_id || ! $proposer->can(PanelPriceProposalService::PROPOSE_PERMISSION)) {
            $errors[] = 'The --panel-proposer account must be an active account that may propose Panel prices.';
        } elseif (! $this->branches->hasEffectiveAssignment($proposer, $homeBranch)) {
            $errors[] = "The --panel-proposer account is not assigned to the home branch {$homeBranch->code}.";
        }
        $reference = trim((string) preg_replace('/\s+/u', ' ', (string) $reference));
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 120 || preg_match('/[\p{Cc}\p{Cf}<>]/u', $reference) === 1) {
            $errors[] = 'Give --panel-reference, the Panel agreement reference, 3 to 120 characters of plain text.';
        }

        return $errors;
    }

    /**
     * @param  list<array<string,mixed>>  $catalogue
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function readBranchPrices(User $actor, string $path, array $catalogue): array
    {
        [$records, $errors] = $this->readCsv($path, self::BRANCH_PRICE_COLUMNS, 'branch prices');
        if ($errors !== []) {
            return [[], $errors];
        }
        $inFile = array_flip(array_map(fn (array $row): string => Str::lower($row['code']), $catalogue));
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->get()->keyBy(fn (Branch $b): string => Str::upper((string) $b->code));
        $rows = [];
        $seen = [];
        $checked = [];
        foreach ($records as $line => $r) {
            $where = "branch prices line {$line}";
            $code = Str::lower($r['code']);
            if ($code === '' || ! isset($inFile[$code])) {
                $errors[] = "{$where}: code {$r['code']} is not in the catalogue file. This import prices new medicines only; change a live price in Pricing.";
            }
            $branch = $branches[Str::upper($r['branch_code'])] ?? null;
            if ($branch === null) {
                $errors[] = "{$where}: branch_code {$r['branch_code']} is not an active branch (use ".implode(', ', $branches->keys()->all()).').';
            } elseif (! isset($checked[$branch->id])) {
                // Fix 1 and 2: every branch problem is listed before any write.
                $checked[$branch->id] = true;
                if (! $this->branches->hasEffectiveAssignment($actor, $branch)) {
                    $errors[] = "{$where}: the actor is not assigned to branch {$branch->code}, so cannot publish its prices.";
                }
                $book = PriceBook::query()->where('organisation_id', $actor->organisation_id)->where('scope_key', 'branch:'.$branch->id)->first();
                if ($book !== null && ! $book->is_active) {
                    $errors[] = "{$where}: the branch price book for {$branch->code} is inactive; reactivate it in Pricing first.";
                }
            }
            $sen = $this->sen($r['self_pay_rm'], "{$where}: self_pay_rm", $errors);
            if ($sen === null) {
                $errors[] = "{$where}: self_pay_rm is required.";
            }
            $key = $code.'|'.Str::upper($r['branch_code']);
            if (isset($seen[$key])) {
                $errors[] = "{$where}: this code and branch are also on line {$seen[$key]}.";
            }
            $seen[$key] = $line;
            if ($branch !== null && $sen !== null) {
                $rows[] = ['code' => $r['code'], 'branch_id' => $branch->id, 'self_pay_sen' => $sen];
            }
        }

        return [$rows, $this->limit($errors)];
    }

    /**
     * @param  list<string>  $columns
     * @return array{0:array<int,array<string,string>>,1:list<string>}
     */
    private function readCsv(string $path, array $columns, string $label): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [[], ["The {$label} file was not found or can't be read."]];
        }
        if (filesize($path) > self::MAX_BYTES) {
            return [[], ["The {$label} file is larger than 5 MB."]];
        }
        $content = (string) file_get_contents($path);
        if (! mb_check_encoding($content, 'UTF-8')) {
            return [[], ["The {$label} file must be UTF-8."]];
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        $header = is_array($header) ? array_map(fn (mixed $cell): string => Str::lower(trim((string) $cell)), $header) : [];
        if ($header !== $columns) {
            fclose($handle);

            return [[], ["The first row of the {$label} file must be exactly: ".implode(',', $columns)]];
        }
        $records = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(fn (mixed $c): string => trim((string) $c), $cells)) === '') {
                continue;
            }
            if (count($cells) !== count($columns)) {
                $errors[] = "{$label} line {$line}: expected ".count($columns).' columns, found '.count($cells).'.';

                continue;
            }
            $cleaned = array_map(fn (mixed $c): string => trim((string) $c), $cells);
            foreach ($cleaned as $cell) {
                if (preg_match('/[\p{Cc}\p{Cf}<>]/u', $cell) === 1) {
                    $errors[] = "{$label} line {$line}: use plain text only (no control characters or < >).";

                    continue 2;
                }
            }
            $records[$line] = array_combine($columns, $cleaned);
        }
        fclose($handle);
        if ($records === [] && $errors === []) {
            $errors[] = "The {$label} file has no rows.";
        }
        if (count($records) > self::MAX_ROWS) {
            $errors[] = "The {$label} file has more than ".self::MAX_ROWS.' rows.';
        }

        return [$records, $errors];
    }

    /**
     * RM with up to 2 decimals to whole sen, with no floating point. Blank is null.
     *
     * @param  list<string>  $errors
     */
    private function sen(string $value, string $where, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            $errors[] = "{$where} must be ringgit with up to 2 decimals, for example 12.50.";

            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupByBranch(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['branch_id']][] = $row;
        }
        ksort($grouped);

        return $grouped;
    }

    private function branchBook(User $actor, Branch $branch): PriceBook
    {
        $book = PriceBook::query()->where('organisation_id', $actor->organisation_id)->where('scope_key', 'branch:'.$branch->id)->first();
        if ($book === null) {
            return $this->pricing->createPriceBook($actor, $branch, ['name' => $branch->name.' Self-pay', 'currency' => 'MYR', 'price_tier' => 'self_pay']);
        }

        // An inactive book is refused while checking; never switched back on here.
        return $book;
    }

    private function charge(User $actor, MedicineCatalogueItem $medicine): ChargeDefinition
    {
        // Every branch-priced medicine was created in this run, so its charge is new or was just made by the setup.
        return ChargeDefinition::query()->where('organisation_id', $actor->organisation_id)->where('medicine_catalogue_item_id', $medicine->id)->first()
            ?? $this->pricing->createMedicineCharge($actor, $medicine, ['code' => $medicine->code, 'display_name' => $medicine->display_name]);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $lowered
     * @return list<string>
     */
    private function existing($query, string $column, array $lowered): array
    {
        $found = [];
        $lower = match ($column) {
            'code' => DB::raw('LOWER(code)'),
            'sku_code' => DB::raw('LOWER(sku_code)'),
            'barcode' => DB::raw('LOWER(barcode)'),
            default => throw new RuntimeException('Unknown column.'),
        };
        foreach (array_chunk(array_values(array_filter($lowered, fn (string $v): bool => $v !== '')), 500) as $chunk) {
            foreach ($query->clone()->whereIn($lower, $chunk)->pluck($column) as $value) {
                $found[] = Str::lower((string) $value);
            }
        }

        return array_values(array_unique($found));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function limit(array $errors): array
    {
        return count($errors) > self::ERROR_LIMIT ? [...array_slice($errors, 0, self::ERROR_LIMIT), (count($errors) - self::ERROR_LIMIT).' more problems not shown.'] : $errors;
    }
}
