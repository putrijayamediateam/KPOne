<?php

namespace Tests\Feature\Inventory;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * STK-41: approving an agent transfer suggestion prepares a stock request, in two real PostgreSQL sessions at the same moment.
 * Two approvers on one suggestion make exactly one stock request; an approval racing a hand-made request for the same stock
 * never sends more than the branch can spare. Synthetic data only.
 */
class PostgresProposalPrepareTest extends TestCase
{
    public function test_two_approvers_preparing_one_suggestion_make_one_stock_request(): void
    {
        $this->race(fn (array $s): array => [['prepare', $s['first']], ['prepare', $s['second']]], 'ai_proposals', function (array $s, array $outcomes, array $outputs): void {
            $this->assertEqualsCanonicalizing(['recorded', 'refused'], array_values($outcomes));
            foreach ($outputs as $output) {
                if (str_contains($output, 'REFUSED')) {
                    $this->assertStringContainsString('This proposal has already been decided.', $output);
                }
            }
            $this->assertSame(1, DB::table('inventory_stock_requests')->count());
            $proposal = DB::table('ai_proposals')->where('id', $s['proposal'])->sole();
            $this->assertSame(['approved', 'inventory.stock_request'], [$proposal->status, $proposal->prepared_record_type]);
            $this->assertSame((int) $proposal->decided_by_user_id, (int) DB::table('inventory_stock_requests')->value('requested_by_user_id'));
        });
    }

    public function test_an_approval_racing_a_hand_made_request_never_sends_more_than_can_be_spared(): void
    {
        $this->race(fn (array $s): array => [['prepare', $s['first']], ['manual', $s['second']]], 'inventory_locations', function (array $s, array $outcomes, array $outputs): void {
            // 10 on hand, 6 asked for twice: whoever goes second finds only 4 to spare and is refused; nothing is half made.
            $this->assertEqualsCanonicalizing(['recorded', 'refused'], array_values($outcomes));
            foreach ($outputs as $output) {
                if (str_contains($output, 'REFUSED')) {
                    $this->assertStringContainsString('This branch can spare 4 of this item. Lower the quantity.', $output);
                }
            }
            $this->assertSame(1, DB::table('inventory_stock_requests')->count());
            $proposal = DB::table('ai_proposals')->where('id', $s['proposal'])->sole();
            $prepared = $outcomes['prepare'] === 'recorded';
            $this->assertSame([$prepared ? 'approved' : 'pending', $prepared ? 'inventory.stock_request' : null], [$proposal->status, $proposal->prepared_record_type]);
            $this->assertSame($prepared ? (int) $s['proposal'] : null, DB::table('inventory_stock_requests')->value('ai_proposal_id') === null ? null : (int) DB::table('inventory_stock_requests')->value('ai_proposal_id'));
        });
    }

    /**
     * @param  callable(array<string, mixed>): list<array{0: string, 1: User}>  $workersFor
     * @param  callable(array<string, mixed>, array<string, string>, list<string>): void  $assert
     */
    private function race(callable $workersFor, string $heldTable, callable $assert): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk_prepare_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.stk_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_race'], 'database.connections.stk_blocker' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_blocker']]);
        $workers = [];
        $inputs = [];
        try {
            DB::setDefaultConnection('stk_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stk_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->seed(KPOneAgentSeeder::class);
            $s = $this->scenario();

            // Hold the row both writers need first, so neither checks anything before the other is waiting too.
            $held = $heldTable === 'ai_proposals' ? $s['proposal'] : $s['sourceId'];
            DB::connection('stk_blocker')->beginTransaction();
            DB::connection('stk_blocker')->select("select id from {$heldTable} where id=? for update", [$held]);
            $pids = [];
            $modes = [];
            foreach ($workersFor($s) as [$mode, $actor]) {
                $input = new InputStream;
                $inputs[] = $input;
                $modes[] = $mode;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresProposalPrepareWorker.php'), $database, $mode, (string) $actor->id, (string) $s['branch']->id, (string) $s['proposal'],
                    $s['source'], $s['destination'], $s['sku'], '6'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                        'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
                $worker->setInput($input)->setTimeout(60)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $readyDeadline = microtime(true) + 30;
                while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $readyDeadline) {
                    usleep(20000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $worker->getOutput(), $worker->getErrorOutput());
                preg_match('/READY (\d+)/', $worker->getOutput(), $match);
                $pids[] = (int) $match[1];
            }
            foreach ($inputs as $input) {
                $input->write("GO\n");
            }
            $deadline = microtime(true) + 30;
            do {
                foreach ($workers as $worker) {
                    $worker->getOutput();
                }
                $waiting = (int) DB::selectOne("select count(*) as total from pg_stat_activity where pid in (?,?) and wait_event_type='Lock'", $pids)->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both writers must wait on the held row.');
            DB::connection('stk_blocker')->commit();

            $outcomes = [];
            $outputs = [];
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outputs[] = $worker->getOutput();
                $key = $modes[$index] === 'prepare' && isset($outcomes['prepare']) ? 'prepare2' : $modes[$index];
                $outcomes[$key] = str_contains($worker->getOutput(), 'RECORDED') ? 'recorded' : 'refused';
            }
            $assert($s, $outcomes, $outputs);
        } finally {
            if (DB::connection('stk_blocker')->transactionLevel()) {
                DB::connection('stk_blocker')->rollBack();
            }
            foreach ($inputs as $input) {
                if (! $input->isClosed()) {
                    $input->close();
                }
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('stk_blocker');
            DB::purge('stk_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Two CA Supervisors at the sending branch, 10 usable on hand there, and one transfer suggestion of 6 to another branch. */
    private function scenario(): array
    {
        $sender = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $receiver = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        $actor = function () use ($sender): User {
            $user = User::factory()->create(['organisation_id' => $sender->organisation_id, 'name' => 'Synthetic stock supervisor', 'email' => 'stk-'.Str::uuid().'@kpone.test']);
            $user->assignRole('ca_supervisor');
            $profile = new StaffProfile;
            $profile->forceFill(['user_id' => $user->id, 'department_id' => Department::where('organisation_id', $sender->organisation_id)->value('id')])->save();
            StaffBranchAssignmentBootstrapper::create($profile, $sender, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

            return $user;
        };
        $organisation = $sender->organisation_id;
        $item = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-PREP', 'generic_name' => 'Synthetic stock item', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sku = (string) Str::uuid();
        $skuId = DB::table('inventory_skus')->insertGetId(['public_id' => $sku, 'organisation_id' => $organisation, 'inventory_item_id' => $item, 'sku_code' => 'SYN-PREP-SKU', 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $batch = DB::table('inventory_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_sku_id' => $skuId, 'batch_number' => 'SYN-PREP-BATCH', 'expiry_date' => now($sender->timezone)->addYear()->toDateString(), 'received_at' => now(), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $source = (string) Str::uuid();
        $sourceId = DB::table('inventory_locations')->insertGetId(['public_id' => $source, 'organisation_id' => $organisation, 'branch_id' => $sender->id, 'code' => 'SYN-PREP-SRC', 'name' => 'Synthetic sending store', 'type' => 'branch_store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $destination = (string) Str::uuid();
        DB::table('inventory_locations')->insert(['public_id' => $destination, 'organisation_id' => $organisation, 'branch_id' => $receiver->id, 'code' => 'SYN-PREP-DST', 'name' => 'Synthetic receiving dispensary', 'type' => 'dispensary', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $organisation, 'inventory_location_id' => $sourceId, 'inventory_sku_id' => $skuId, 'inventory_batch_id' => $batch, 'quantity' => '10.000', 'created_at' => now(), 'updated_at' => now()]);
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $sender);
        $payload = ['from_branch_code' => 'CHERAS', 'from_current_quantity' => '10.000', 'from_reorder_level' => '0.000', 'quantity' => '6.000',
            'sku_public_id' => $sku, 'to_branch_code' => 'PUCHONG', 'to_current_quantity' => '0.000', 'to_reorder_level' => '5.000'];
        // The sender-branch shape of the agent platform's step 2b.
        $proposal = AiProposal::query()->create(['organisation_id' => $organisation, 'branch_id' => $sender->id, 'ai_agent_run_id' => $run->id, 'ai_agent_id' => $run->ai_agent_id,
            'type' => 'inventory.transfer_suggestion', 'payload' => $payload, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'status' => 'pending', 'lock_version' => 0, 'idempotency_key' => 'race-transfer-0001']);

        return ['branch' => $sender, 'first' => $actor(), 'second' => $actor(), 'proposal' => $proposal->id, 'source' => $source, 'sourceId' => $sourceId, 'destination' => $destination, 'sku' => $sku];
    }
}
