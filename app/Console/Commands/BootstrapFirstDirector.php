<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapFirstDirector extends Command
{
    protected $signature = 'kpone:bootstrap-director';

    protected $description = 'Interactively create the one-time first Director account.';

    public function handle(DirectorBootstrapService $bootstrap): int
    {
        if (! $this->input->isInteractive()
            || (! app()->runningUnitTests() && (! defined('STDIN') || ! stream_isatty(STDIN)))) {
            $this->error('This command requires an interactive terminal. Credentials are not accepted as command arguments.');

            return self::FAILURE;
        }

        $organisation = Organisation::query()
            ->where('code', 'KLINIK_PUTRIJAYA')
            ->where('is_active', true)
            ->first();

        if ($organisation === null) {
            $this->error('The active KLINIK_PUTRIJAYA organisation was not found. Run the reference seeder first.');

            return self::FAILURE;
        }

        if (User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_active', true)
            ->role(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
            ->exists()) {
            $this->error('An active Director already exists for this organisation.');

            return self::FAILURE;
        }

        $branches = Branch::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($branches->isEmpty()) {
            $this->error('No active branches were found for this organisation.');

            return self::FAILURE;
        }

        $branchLabels = $branches->mapWithKeys(
            fn (Branch $branch): array => ["{$branch->code} — {$branch->name}" => $branch->id],
        );

        $this->components->info('Bootstrap is available only while this organisation has no active Director.');
        $name = $this->ask('Director full name');
        $email = $this->ask('Director email address');
        $staffNumber = $this->ask('Director staff number');
        $branchLabel = $this->choice('Primary branch', $branchLabels->keys()->all());
        if (! is_string($branchLabel) || ! $branchLabels->has($branchLabel)) {
            $this->error('A valid primary branch selection is required.');

            return self::FAILURE;
        }

        $credentialChoice = $this->choice(
            'Sign-in method',
            ['Google-only sign-in', 'Password'],
            0,
        );
        if (! is_string($credentialChoice) || ! in_array($credentialChoice, ['Google-only sign-in', 'Password'], true)) {
            $this->error('A valid sign-in method is required.');

            return self::FAILURE;
        }

        $credentialStrategy = $credentialChoice === 'Password' ? 'password' : 'google_only';
        $attributes = [
            'name' => $name,
            'email' => $email,
            'staff_number' => $staffNumber,
            'job_title' => 'Director',
            'branch_id' => $branchLabels->get($branchLabel),
            'credential_strategy' => $credentialStrategy,
        ];

        if ($credentialStrategy === 'password') {
            $attributes['password'] = $this->secret('Director password', false);
            $attributes['password_confirmation'] = $this->secret('Confirm Director password', false);
        } else {
            $this->line('Google sign-in requires a verified Google email that exactly matches this account email.');
        }

        try {
            $director = $bootstrap->bootstrap($attributes);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error("{$field}: {$message}");
                }
            }

            return self::FAILURE;
        }

        $this->components->info(
            "First Director created for {$director->staffProfile->branchAssignments->first()->branch->name}.",
        );

        return self::SUCCESS;
    }
}
