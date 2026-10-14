<?php

namespace Tests\Feature\Visit;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Services\PanelSettingsService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PanelSettingsTest extends VisitTestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'syn-care', 'name' => 'Synthetic  Care', 'panel_type' => 'tpa',
            'company_registration_number' => '202201234567', 'phone' => '+60 3-1234 5678', 'email' => 'claims@syn-care.test',
            'verification_mode' => 'url', 'verification_url' => 'https://verify.syn-care.test/login',
            'claim_due_rule' => 'days_after_visit', 'claim_due_days' => 14, 'income_tax_number' => 'C1234567',
            ...$overrides,
        ];
    }

    public function test_only_director_finance_officer_and_panel_officer_hold_the_permission(): void
    {
        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'panels.manage.organisation'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['director', 'finance_officer', 'panel_officer'], $holders);

        $this->selectBranch($this->actor('ca'));
        $this->get(route('panel-settings.index'))->assertForbidden();
        $this->selectBranch($this->actor('technical_admin'));
        $this->get(route('panel-settings.index'))->assertForbidden();
    }

    public function test_panel_officer_adds_edits_archives_and_restores_a_panel_with_audit(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);

        $this->post(route('panel-settings.store'), $this->payload())->assertRedirect(route('panel-settings.index'));
        $panel = Panel::query()->where('code', 'SYN-CARE')->sole();
        $this->assertSame('Synthetic Care', $panel->name);
        $this->assertSame(14, $panel->claim_due_days);
        $this->assertSame(1, $panel->lock_version);
        $this->assertTrue($panel->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel.created', 'subject_id' => $panel->id]);

        $this->get(route('panel-settings.edit', $panel->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelSettings/Form')->where('panel.code', 'SYN-CARE')->where('panel.lockVersion', 1));

        $this->patch(route('panel-settings.update', $panel->id), $this->payload(['code' => 'IGNORED', 'phone' => '0312345678', 'lock_version' => 1]))
            ->assertRedirect(route('panel-settings.index'));
        $panel->refresh();
        $this->assertSame('SYN-CARE', $panel->code);
        $this->assertSame('0312345678', $panel->phone);
        $this->assertSame(2, $panel->lock_version);
        $audit = \DB::table('audit_logs')->where('event', 'panel.updated')->where('subject_id', $panel->id)->value('metadata');
        $this->assertStringContainsString('phone', (string) $audit);
        $this->assertStringNotContainsString('0312345678', (string) $audit);

        $this->post(route('panel-settings.active', $panel->id), ['active' => false, 'lock_version' => 2])->assertRedirect(route('panel-settings.index'));
        $this->assertFalse($panel->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel.archived', 'subject_id' => $panel->id]);
        $this->post(route('panel-settings.active', $panel->id), ['active' => true, 'lock_version' => 3])->assertRedirect();
        $this->assertTrue($panel->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel.restored', 'subject_id' => $panel->id]);
    }

    public function test_stale_version_duplicate_code_and_unsafe_values_are_refused(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        $service = app(PanelSettingsService::class);
        $panel = $service->create($officer, $this->payload());

        $this->assertValidationFails(fn () => $service->create($officer, $this->payload(['code' => 'SYN-CARE'])), 'code');
        $this->assertValidationFails(fn () => $service->create($officer, $this->payload(['code' => 'HAS SPACE'])), 'code');
        $this->assertValidationFails(fn () => $service->update($officer, $panel, $this->payload(['lock_version' => 9])), 'panel');
        $this->assertValidationFails(fn () => $service->update($officer, $panel, $this->payload(['lock_version' => 1, 'verification_url' => 'javascript:alert(1)'])), 'verification_url');
        $this->assertValidationFails(fn () => $service->update($officer, $panel, $this->payload(['lock_version' => 1, 'verification_url' => 'http://plain.test'])), 'verification_url');
        $this->assertValidationFails(fn () => $service->update($officer, $panel, $this->payload(['lock_version' => 1, 'company_registration_number' => '123'])), 'company_registration_number');
        $this->assertValidationFails(fn () => $service->update($officer, $panel, $this->payload(['lock_version' => 1, 'claim_due_days' => 400])), 'claim_due_days');
        $this->assertSame(1, $panel->refresh()->lock_version);
    }

    public function test_scheduled_due_rules_are_saved_and_validated(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        $service = app(PanelSettingsService::class);
        $panel = $service->create($officer, $this->payload(['claim_due_rule' => 'weekly', 'claim_due_time' => '17:30', 'claim_due_offset' => 5, 'claim_due_days' => null]));
        $this->assertSame(['weekly', '17:30', 5], [$panel->claim_due_rule, $panel->claim_due_time, $panel->claim_due_offset]);

        $back = $service->update($officer, $panel, $this->payload(['lock_version' => 1, 'claim_due_rule' => 'days_after_visit', 'claim_due_days' => 10]));
        $this->assertSame([null, null, 10], [$back->claim_due_time, $back->claim_due_offset, $back->claim_due_days]);

        $this->assertValidationFails(fn () => $service->update($officer, $back, $this->payload(['lock_version' => 2, 'claim_due_rule' => 'weekly', 'claim_due_time' => '17:30', 'claim_due_offset' => 9])), 'claim_due_offset');
        $this->assertValidationFails(fn () => $service->update($officer, $back, $this->payload(['lock_version' => 2, 'claim_due_rule' => 'daily', 'claim_due_time' => '25:00', 'claim_due_offset' => 1])), 'claim_due_time');
        $this->assertValidationFails(fn () => $service->update($officer, $back, $this->payload(['lock_version' => 2, 'claim_due_rule' => 'monthly', 'claim_due_time' => '10:00'])), 'claim_due_offset');
    }

    public function test_claim_message_template_is_saved_versioned_validated_and_audited_without_its_text(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);

        $this->get(route('panel-settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('claimMessage.body', '')->where('claimMessage.lockVersion', 0)->has('claimMessage.fields', 9));

        $this->patch(route('panel-settings.claim-message'), ['body' => "Hi {panel_name},\r\nclaim {claim_number} {claimed_amount}", 'lock_version' => 0])
            ->assertRedirect(route('panel-settings.index'));
        $row = \DB::table('panel_claim_message_templates')->sole();
        $this->assertSame("Hi {panel_name},\nclaim {claim_number} {claimed_amount}", $row->body);
        $this->assertSame(1, $row->lock_version);
        $audit = (string) \DB::table('audit_logs')->where('event', 'panel_claim_message_template.updated')->value('metadata');
        $this->assertStringContainsString('claim_number', $audit);
        $this->assertStringNotContainsString('Hi ', $audit);

        $this->patch(route('panel-settings.claim-message'), ['body' => 'Stale {panel_name}', 'lock_version' => 0])->assertSessionHasErrors('body');
        $this->patch(route('panel-settings.claim-message'), ['body' => 'Typo {patient_nrics}', 'lock_version' => 1])->assertSessionHasErrors('body');
        $this->patch(route('panel-settings.claim-message'), ['body' => str_repeat('a', 2001), 'lock_version' => 1])->assertSessionHasErrors('body');
        $this->assertSame(1, \DB::table('panel_claim_message_templates')->value('lock_version'));

        $this->patch(route('panel-settings.claim-message'), ['body' => '', 'lock_version' => 1])->assertRedirect(route('panel-settings.index'));
        $this->assertSame(2, \DB::table('panel_claim_message_templates')->value('lock_version'));

        $this->selectBranch($this->actor('ca'));
        $this->patch(route('panel-settings.claim-message'), ['body' => 'x', 'lock_version' => 2])->assertForbidden();
    }

    public function test_manual_remark_mode_stores_no_link_and_another_organisations_panel_is_not_reachable(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        $service = app(PanelSettingsService::class);
        $panel = $service->create($officer, $this->payload(['verification_mode' => 'remark', 'verification_remark' => 'Call the hotline', 'verification_url' => 'https://ignored.test']));
        $this->assertNull($panel->verification_url);
        $this->assertSame('Call the hotline', $panel->verification_remark);

        $otherOrg = Organisation::query()->create(['code' => 'OTHER_ORG', 'name' => 'Other Org', 'is_active' => true]);
        $foreign = Panel::factory()->create(['organisation_id' => $otherOrg->id]);
        $this->get(route('panel-settings.edit', $foreign->id))->assertNotFound();
        $this->post(route('panel-settings.active', $foreign->id), ['active' => false, 'lock_version' => 1])->assertNotFound();
    }

    public function test_list_filters_by_tab_and_search_and_shows_default_panel_tier(): void
    {
        $officer = $this->actor('director');
        $this->selectBranch($officer);
        Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Alpha Care', 'code' => 'ALPHA', 'is_active' => true]);
        Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Beta Care', 'code' => 'BETA', 'is_active' => false]);

        $this->get(route('panel-settings.index', ['tab' => 'archived']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelSettings/Index')->has('panels', 1)->where('panels.0.name', 'Beta Care')
            ->where('panels.0.priceTiers', ['PANEL'])->where('counts.all', 2)->where('counts.archived', 1));
        $this->get(route('panel-settings.index', ['q' => 'alp']))->assertInertia(fn (Assert $page) => $page->has('panels', 1)->where('panels.0.code', 'ALPHA'));
    }

    public function test_migration_grants_the_permission_additively(): void
    {
        foreach (['director', 'finance_officer', 'panel_officer'] as $name) {
            Role::findByName($name)->revokePermissionTo('panels.manage.organisation');
        }
        Permission::query()->where('name', 'panels.manage.organisation')->delete();

        $migration = require base_path('database/migrations/2026_10_13_000700_grant_panel_settings_permission_additively.php');
        $migration->up();
        $migration->up();

        foreach (['director', 'finance_officer', 'panel_officer'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('panels.manage.organisation'));
        }
        $this->assertFalse(Role::findByName('ca')->hasPermissionTo('panels.manage.organisation'));
    }

    private function assertValidationFails(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }
}
