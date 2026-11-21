<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Shared\TrialData\PhaseOneFoundation;
use App\Domain\Shared\TrialData\TrialDataContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * TRIAL-DATA: fills a trial server with fictional demo data, one phase at a time, through the existing domain
 * services. Locked behind KPONE_TRIAL_DATA_ALLOWED, a typed host-name confirmation and an interactive terminal; never
 * called from a seeder, migration, scheduler or deploy. See docs/design/TRIAL-DATA-COMMAND.md.
 */
class TrialData extends Command
{
    protected $signature = 'kpone:trial-data {--phase=1 : The phase to run (only phase 1 is built so far)}';

    protected $description = 'Fill a trial server with fictional demo data (locked; read docs/design/TRIAL-DATA-COMMAND.md first)';

    /** @var list<string> */
    private const PHASES = ['1'];

    public function handle(AuditRecorder $audit): int
    {
        if (config('trial-data.allowed') !== true) {
            return $this->refuse('Trial data is switched off. Set KPONE_TRIAL_DATA_ALLOWED=true on the trial server only, run this command, then switch it off again.');
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('This command must be run by a person in an interactive terminal.');
        }
        $phase = (string) $this->option('phase');
        if (! in_array($phase, self::PHASES, true)) {
            return $this->refuse('Unknown phase. Built so far: '.implode(', ', self::PHASES).'.');
        }

        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->where('is_active', true)->first();
        $branch = $organisation === null ? null : Branch::query()
            ->where('organisation_id', $organisation->id)->where('code', TrialDataContext::BRANCH_CODE)->where('is_active', true)->first();
        if ($organisation === null || $branch === null) {
            return $this->refuse('The organisation or the CHERAS branch is missing. Run the migrations and the reference seeder first.');
        }

        $directors = User::role('director')->where('organisation_id', $organisation->id)->where('is_active', true)->get();
        if ($directors->count() !== 1) {
            return $this->refuse('Exactly one active Director is needed. Found '.$directors->count().'.');
        }
        $director = $directors->first();

        $others = User::query()->whereKeyNot($director->id)->whereRaw("LOWER(email) NOT LIKE '%.test'")->count();
        if ($others > 0) {
            return $this->refuse("Stopped: {$others} account(s) other than the Director have an email that does not end in .test. This command runs only on a database with fictional staff.");
        }

        $context = new TrialDataContext($organisation, $branch, $director);
        if (! $context->directorCoversBranch()) {
            return $this->refuse('The Director has no current assignment at CHERAS. Run `php artisan kpone:director-branches` first, then try again.');
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $typed = strtolower(trim((string) $this->ask('Type this site\'s host name to confirm (for example trial.example.com)')));
        if ($host === '' || $typed !== $host) {
            return $this->refuse('The host name does not match this site. Nothing was changed.');
        }

        $password = null;
        $missing = $context->missingAccounts();
        if ($missing !== []) {
            $this->info(count($missing).' fictional account(s) will be created. They all share one password.');
            $password = (string) $this->secret('Shared password for the fictional accounts (hidden)');
            $confirmation = (string) $this->secret('Type the shared password again (hidden)');
            $check = Validator::make(
                ['password' => $password, 'password_confirmation' => $confirmation],
                ['password' => ['required', 'string', Password::min(12)->mixedCase()->letters()->numbers()->symbols(), 'confirmed']],
            );
            if ($check->fails()) {
                unset($password, $confirmation);

                return $this->refuse('The password must be at least 12 characters with upper and lower case letters, a number and a symbol, typed the same twice.');
            }
            unset($confirmation);
        }

        $this->info("Running phase {$phase}…");
        try {
            $created = (new PhaseOneFoundation($context))->run($password);
        } catch (ValidationException $exception) {
            return $this->refuse('A step was refused: '.implode(' ', $exception->validator->errors()->all()).' Fix it and run the command again; finished steps are kept and not repeated.');
        } catch (Throwable $exception) {
            return $this->refuse('A step failed ('.class_basename($exception).'): '.$exception->getMessage().' Run the command again; finished steps are kept and not repeated.');
        } finally {
            unset($password);
        }

        if ($created === []) {
            // A rerun that adds nothing writes nothing, not even an audit row.
            $this->info("Phase {$phase} was already complete. Nothing was added.");
        } else {
            $audit->record('trial_data.phase_completed', null, ['phase' => (int) $phase, 'created' => $created], null, $branch, $organisation->id);
            $this->table(['Record', 'Created'], collect($created)->map(fn (int $count, string $kind): array => [$kind, $count])->values()->all());
            $this->info("Phase {$phase} complete. Accounts use the @".TrialDataContext::EMAIL_DOMAIN.' emails shown on the Staff page.');
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
