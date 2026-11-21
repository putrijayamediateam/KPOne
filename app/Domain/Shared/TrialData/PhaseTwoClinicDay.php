<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Models\BranchDisplaySetting;

/**
 * TRIAL-DATA phase 2: a clinic day at every branch, through the existing services as the fictional staff. Built in
 * steps; every step first looks for its own result, so a second run adds nothing.
 */
final class PhaseTwoClinicDay
{
    public const POSTERS_PER_BRANCH = 2;

    /** @var array<string, int> */
    private array $created = [];

    private PlaceholderFiles $files;

    public function __construct(private readonly TrialDataContext $context)
    {
        $this->files = new PlaceholderFiles;
    }

    /** @return array<string, int> what was created, by kind */
    public function run(): array
    {
        try {
            foreach ($this->context->branches as $code => $branch) {
                $this->posters($code, $branch);
            }
        } finally {
            $this->files->cleanUp();
        }

        return $this->created;
    }

    /** Waiting-room TV posters, added by the branch's first CA Supervisor. */
    private function posters(string $code, Branch $branch): void
    {
        $service = app(QueueDisplayAdministrationService::class);
        for ($number = count($this->settings($branch)?->posters ?? []) + 1; $number <= self::POSTERS_PER_BRANCH; $number++) {
            $actor = $this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch);
            $file = $this->files->image('Waiting room poster '.$number, 1280, 720);
            $service->addPoster($actor, $branch, $file, (int) ($this->settings($branch)->lock_version ?? 0));
            $this->count('posters');
        }
    }

    private function settings(Branch $branch): ?BranchDisplaySetting
    {
        return BranchDisplaySetting::query()->where('branch_id', $branch->id)->first();
    }

    private function count(string $kind, int $by = 1): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + $by;
    }
}
