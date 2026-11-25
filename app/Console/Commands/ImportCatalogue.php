<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organisation\Inventory\Services\CatalogueImportRefused;
use App\Domain\Organisation\Inventory\Services\CatalogueImportService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * IMP-1 (owner, 25 Nov 2026): one-off import of the clinic's medicines, SKUs and Self-pay prices, run by the coordinator.
 * Everything goes through the existing domain services as the named actor; all or nothing; --dry-run writes nothing.
 */
class ImportCatalogue extends Command
{
    protected $signature = 'inventory:import-catalogue
        {catalogue : Path to catalogue.csv}
        {--branch-prices= : Path to branch_prices.csv (optional)}
        {--actor= : Email of the named account the import acts as (must hold the medicine, price and inventory reference permissions)}
        {--branch= : Code of the home branch for HQ prices, for example PUCHONG}
        {--dry-run : Check and do everything, then roll it all back}';

    protected $description = 'Import medicines, SKUs and Self-pay prices from CSV, through the existing services (all or nothing).';

    public function handle(CatalogueImportService $import): int
    {
        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if ($actor === null) {
            $this->error('No account has that --actor email.');

            return self::FAILURE;
        }
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->where('code', strtoupper((string) $this->option('branch')))->first();
        if ($branch === null) {
            $this->error('No branch has that --branch code.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        try {
            $report = $import->run($actor, $branch, (string) $this->argument('catalogue'), $this->option('branch-prices') !== null ? (string) $this->option('branch-prices') : null, $dryRun);
        } catch (CatalogueImportRefused $refused) {
            $this->error($refused->getMessage());
            foreach ($refused->problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }
        $this->table(['Medicines', 'HQ Self-pay prices', 'Branch Self-pay prices', 'Marked controlled'], [[$report['medicines'], $report['hqPrices'], $report['branchPrices'], $report['controlled']]]);
        $this->components->info($dryRun ? 'Dry run: everything was checked and rolled back. Nothing was saved.' : 'Imported. Next: Pilot readiness (verify base units), then the opening-stock import per branch.');

        return self::SUCCESS;
    }
}
