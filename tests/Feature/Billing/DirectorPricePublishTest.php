<?php

namespace Tests\Feature\Billing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * BIL-38 (owner decision 24 Nov 2026, "Benarkan Director"): the Director publishes prices through the existing
 * publication path, audited as for any publisher.
 */
class DirectorPricePublishTest extends ClinicalTestCase
{
    public function test_the_director_publishes_a_price_and_it_is_audited(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $charge = app(PricingReferenceAdministrationService::class)->createConsultationCharge($director, ['code' => 'BIL38-CONSULT', 'display_name' => 'Synthetic BIL-38 consultation']);
        $book = app(PricingReferenceAdministrationService::class)->createPriceBook($director, null, ['name' => 'Synthetic BIL-38 book']);

        $this->get(route('pricing.index'))->assertOk()->assertInertia(fn ($page) => $page->where('canPublish', true));
        $this->post(route('pricing.charges.publish', $charge), ['price_book_public_id' => $book->public_id, 'amount_sen' => 3500, 'expected_version' => 0, 'expected_branch_id' => $this->branch->id])
            ->assertRedirect(route('pricing.index'))->assertSessionHasNoErrors();

        $entry = PriceEntry::query()->where('charge_definition_id', $charge->id)->sole();
        $this->assertSame([3500, 1, $director->id], [$entry->unit_price_sen, $entry->version, $entry->published_by_user_id]);
        $log = AuditLog::query()->where('event', 'billing.price_published')->sole();
        $this->assertSame($director->id, $log->actor_user_id);
    }

    public function test_the_migration_grants_additively_and_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_25_200200_grant_director_price_publish_permission.php');
        Role::findByName('director', 'web')->revokePermissionTo('prices.publish.organisation');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $kept = Role::findByName('director', 'web')->permissions()->count();
        $migration->up();
        $migration->up();
        $migration->down();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(Role::findByName('director', 'web')->hasPermissionTo('prices.publish.organisation'));
        $this->assertSame($kept + 1, Role::findByName('director', 'web')->permissions()->count());
        $this->assertSame(1, DB::table('permissions')->where('name', 'prices.publish.organisation')->count());
    }
}
