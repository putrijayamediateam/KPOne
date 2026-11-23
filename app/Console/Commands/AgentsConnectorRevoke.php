<?php

namespace App\Console\Commands;

use App\Domain\Agents\Connector\ConnectorRevoker;
use App\Models\User;
use Illuminate\Console\Command;

/** CN-1: revokes KPOne connector tokens at once, for one user (`--user=<email>`) or everyone. Never prints a token. */
final class AgentsConnectorRevoke extends Command
{
    protected $signature = 'agents:connector-revoke {--user= : The email of one user; leave out to revoke everyone\'s}';

    protected $description = 'Revoke KPOne connector tokens now (one user, or everyone).';

    public function handle(ConnectorRevoker $revoker): int
    {
        $email = $this->option('user');
        $user = null;
        if (is_string($email) && $email !== '') {
            $matches = User::query()->where('email', $email)->limit(2)->get();
            if ($matches->count() !== 1) {
                $this->error('No single user has that email.');

                return self::FAILURE;
            }
            $user = $matches->first();
        }

        $this->info('Revoked '.$revoker->revoke($user).' token(s).');

        return self::SUCCESS;
    }
}
