<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneDemoSetupSeeder;
use Database\Seeders\KPOnePresentationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DemoSetupSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const COUNTED_TABLES = [
        'users', 'staff_branch_assignments', 'medicine_catalogue_items', 'clinical_service_catalogue_items',
        'inventory_items', 'inventory_skus', 'inventory_locations', 'inventory_batches', 'stock_movements',
        'charge_definitions', 'price_books', 'price_entries', 'inventory_unit_contracts',
        'inventory_opening_verifications', 'business_detail_commands', 'audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOnePresentationSeeder::class);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $before = $this->counts();
        $this->app['env'] = 'production';

        try {
            // Run directly: db:seed itself would stop at its own production confirmation prompt.
            $this->app->make(KPOneDemoSetupSeeder::class)->run();
            $this->fail('The demo seeder ran in production.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local and testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_it_refuses_a_database_with_a_non_test_email(): void
    {
        User::factory()->create(['email' => 'someone@example.com']);
        $before = $this->counts();

        try {
            $this->seed(KPOneDemoSetupSeeder::class);
            $this->fail('The demo seeder ran with a non-.test email present.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not end in .test', $exception->getMessage());
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_it_moves_the_three_roles_to_cheras_only_through_audited_assignments(): void
    {
        $cheras = $this->cheras();
        $oldAssignments = [];
        foreach (KPOneDemoSetupSeeder::MOVED_EMAILS as $email) {
            $profileId = User::query()->where('email', $email)->firstOrFail()->staffProfile->id;
            $old = StaffBranchAssignment::query()->where('staff_profile_id', $profileId)->where('is_primary', true)->sole();
            $this->assertNotSame($cheras->id, $old->branch_id, "{$email} must start outside CHERAS");
            $oldAssignments[$email] = $old;
        }

        $this->seed(KPOneDemoSetupSeeder::class);

        $director = User::query()->where('email', 'demo.director@kpone.test')->firstOrFail();
        $yesterday = now()->subDay()->toDateString();
        $assignmentType = (new StaffBranchAssignment)->getMorphClass();
        foreach (KPOneDemoSetupSeeder::MOVED_EMAILS as $email) {
            $old = $oldAssignments[$email]->fresh();
            $effective = StaffBranchAssignment::query()
                ->where('staff_profile_id', $old->staff_profile_id)
                ->effectiveAt()
                ->get();

            // Exactly one effective assignment: permanent, primary, open-ended, at CHERAS.
            $this->assertCount(1, $effective, $email);
            $new = $effective->sole();
            $this->assertSame($cheras->id, $new->branch_id, $email);
            $this->assertTrue($new->is_primary, $email);
            $this->assertNull($new->valid_until, $email);
            $this->assertSame('permanent', $new->assignment_type, $email);

            // The old branch assignment was demoted and ended yesterday, by the Director, with an audit record.
            $this->assertFalse($old->is_primary, $email);
            $this->assertSame($yesterday, $old->valid_until?->toDateString(), $email);
            foreach (['staff.branch_assignment.primary.demoted', 'staff.branch_assignment.ended'] as $event) {
                $this->assertSame(1, DB::table('audit_logs')->where('event', $event)
                    ->where('subject_type', $assignmentType)->where('subject_id', $old->id)->where('actor_user_id', $director->id)->count(), "{$email} {$event}");
            }
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'staff.branch_assignment.created')
                ->where('subject_type', $assignmentType)->where('subject_id', $new->id)->where('actor_user_id', $director->id)->count(), $email);
        }

        // Only the three moved accounts had an assignment ended.
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'staff.branch_assignment.ended')->count());
    }

    public function test_it_provisions_a_second_independent_ca_supervisor_at_cheras(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);

        $second = User::query()->where('email', KPOneDemoSetupSeeder::SECOND_SUPERVISOR_EMAIL)->firstOrFail();
        $this->assertTrue($second->is_active);
        $this->assertSame(['ca_supervisor'], $second->getRoleNames()->all());
        $this->assertTrue(password_verify(KPOnePresentationSeeder::PASSWORD, (string) $second->password));
        $this->assertSame($this->cheras()->id, $second->staffProfile->branchAssignments()->effectiveAt()->where('is_primary', true)->value('branch_id'));
        $this->assertTrue(DB::table('audit_logs')->where('event', 'staff.created')->where('subject_id', $second->id)->exists());
    }

    public function test_opening_stock_is_signed_off_by_a_different_person_than_the_recorder(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);

        $recorder = User::query()->where('email', 'demo.ca_supervisor@kpone.test')->firstOrFail();
        $verifier = User::query()->where('email', KPOneDemoSetupSeeder::SECOND_SUPERVISOR_EMAIL)->firstOrFail();
        $openings = StockMovement::query()->where('movement_type', StockMovement::TYPE_OPENING)->get();

        $this->assertCount(count(KPOneDemoSetupSeeder::MEDICINES), $openings);
        foreach ($openings as $opening) {
            $verification = DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->first();
            $this->assertNotNull($verification);
            $this->assertSame($recorder->id, $opening->actor_user_id);
            $this->assertSame($verifier->id, (int) $verification->actor_user_id);
            $this->assertNotSame((int) $opening->actor_user_id, (int) $verification->actor_user_id);
            $this->assertTrue(DB::table('inventory_unit_contracts')->where('inventory_sku_id', $opening->inventory_sku_id)->exists());
            $expiry = DB::table('inventory_batches')->where('id', $opening->inventory_batch_id)->value('expiry_date');
            $this->assertGreaterThan(now()->addYear()->toDateString(), (string) $expiry);
        }
    }

    public function test_it_sets_business_details_footer_catalogue_and_prices(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);
        $cheras = $this->cheras();

        $this->assertSame(KPOneDemoSetupSeeder::FOOTER_TEXT, BranchDocumentFooter::query()->where('branch_id', $cheras->id)->value('text'));
        $this->assertSame('Klinik Putrijaya Cheras (Demo)', DB::table('branch_business_profiles')->where('branch_id', $cheras->id)->value('clinic_name'));
        $this->assertSame(count(KPOneDemoSetupSeeder::MEDICINES), DB::table('medicine_catalogue_items')->count());
        $this->assertSame(count(KPOneDemoSetupSeeder::SERVICES), DB::table('clinical_service_catalogue_items')->count());

        foreach (DB::table('inventory_skus')->get() as $sku) {
            $this->assertSame($sku->stock_unit, $sku->dispensing_unit);
        }

        $chargeCount = count(KPOneDemoSetupSeeder::MEDICINES) + count(KPOneDemoSetupSeeder::SERVICES) + 1;
        $this->assertSame($chargeCount, DB::table('charge_definitions')->count());
        foreach (['organisation', 'panel:default'] as $scope) {
            $bookId = DB::table('price_books')->where('scope_key', $scope)->value('id');
            $this->assertSame($chargeCount, DB::table('price_entries')->where('price_book_id', $bookId)->count(), $scope);
        }
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seed(KPOneDemoSetupSeeder::class);
        $afterFirst = $this->counts();

        $this->seed(KPOneDemoSetupSeeder::class);

        $this->assertSame($afterFirst, $this->counts());
    }

    private function cheras(): Branch
    {
        return Branch::query()->where('code', 'CHERAS')->firstOrFail();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
