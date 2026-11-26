<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organisation\Inventory\Services\CatalogueImportRefused;
use App\Domain\Organisation\Inventory\Services\CatalogueImportService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        {--panel-proposer= : Email of the named Panel Officer who files the hq_panel_rm values as Panel price proposals}
        {--panel-reference= : The Panel agreement reference those proposals carry}
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
        $proposer = null;
        if ($this->option('panel-proposer') !== null) {
            $proposer = User::query()->where('organisation_id', $actor->organisation_id)->where('email', (string) $this->option('panel-proposer'))->first();
            if ($proposer === null) {
                $this->error('No account has that --panel-proposer email.');

                return self::FAILURE;
            }
        }
        $dryRun = (bool) $this->option('dry-run');
        try {
            $report = $import->run($actor, $branch, (string) $this->argument('catalogue'), $this->option('branch-prices') !== null ? (string) $this->option('branch-prices') : null, $dryRun, $proposer, $this->option('panel-reference') !== null ? (string) $this->option('panel-reference') : null);
        } catch (CatalogueImportRefused $refused) {
            $this->error($refused->getMessage());
            foreach ($refused->problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        } catch (ValidationException $invalid) {
            $this->error('A service refused the import. Nothing was saved.');
            foreach ($invalid->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->line('  - '.$message);
                }
            }

            return self::FAILURE;
        } catch (HttpException $refused) {
            $this->error('The import was refused ('.$refused->getStatusCode().'): '.$refused->getMessage().' Nothing was saved.');

            return self::FAILURE;
        }
        $this->table(['Medicines', 'HQ Self-pay prices', 'Branch Self-pay prices', 'Marked controlled', 'Panel price proposals'], [[$report['medicines'], $report['hqPrices'], $report['branchPrices'], $report['controlled'], $report['panelProposals']]]);
        if ($report['panelProposals'] > 0 && ! $dryRun) {
            $this->line('Panel prices are waiting for a Director or Finance Officer on the Panel price changes page.');
        }
        $this->components->info($dryRun ? 'Dry run: everything was checked and rolled back. Nothing was saved.' : 'Imported. Next: Pilot readiness (verify base units), then the opening-stock import per branch.');

        return self::SUCCESS;
    }
}
