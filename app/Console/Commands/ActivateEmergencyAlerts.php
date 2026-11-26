<?php

namespace App\Console\Commands;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use Illuminate\Console\Command;

final class ActivateEmergencyAlerts extends Command
{
    protected $signature = 'queue:activate-emergency-alerts {--organisation=} {--branch=} {--execute}';

    protected $description = 'Explicit fixed-scope emergency activation; default dry-run, no clinical timestamp backfill';

    public function handle(EmergencyAlertService $alerts): int
    {
        $org = filter_var($this->option('organisation'), FILTER_VALIDATE_INT);
        $id = filter_var($this->option('branch'), FILTER_VALIDATE_INT);
        if (! $org || ! $id) {
            $this->error('Provide positive organisation and branch IDs.');

            return self::FAILURE;
        }
        $branch = Branch::query()->where('organisation_id', $org)->where('is_active', true)->whereHas('organisation', fn ($q) => $q->where('is_active', true))->find($id);
        if (! $branch) {
            $this->error('Active branch does not match the organisation.');

            return self::FAILURE;
        }
        $result = $alerts->activate($branch, (bool) $this->option('execute'));
        $this->line(json_encode(['mode' => $this->option('execute') ? 'execute' : 'dry-run', ...$result], JSON_THROW_ON_ERROR));

        return $this->option('execute') && ($result['remaining'] > 0 || $result['invalidOpen'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
