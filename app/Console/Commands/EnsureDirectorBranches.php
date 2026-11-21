<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Console\Command;

class EnsureDirectorBranches extends Command
{
    protected $signature = 'kpone:director-branches';

    protected $description = 'Give every active Director an assignment at every active branch (run once, and again after a new branch opens).';

    public function handle(BranchAssignmentService $assignments): int
    {
        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->where('is_active', true)->first();
        if ($organisation === null) {
            $this->error('The active KLINIK_PUTRIJAYA organisation was not found.');

            return self::FAILURE;
        }

        $directors = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_active', true)
            ->role(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
            ->orderBy('id')
            ->get();

        if ($directors->isEmpty()) {
            $this->error('No active Director was found. Run kpone:bootstrap-director first.');

            return self::FAILURE;
        }

        $created = 0;
        foreach ($directors as $director) {
            $created += $assignments->ensureDirectorCoverage($director);
        }

        $this->components->info($created === 0
            ? 'Every Director already covers every active branch.'
            : "Added {$created} branch assignment(s) for {$directors->count()} Director(s).");

        return self::SUCCESS;
    }
}
