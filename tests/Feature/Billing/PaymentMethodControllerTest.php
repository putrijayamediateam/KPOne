<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use Tests\Feature\Clinical\ClinicalTestCase;

class PaymentMethodControllerTest extends ClinicalTestCase
{
    public function test_payment_method_routes_are_restricted_to_authorised_roles(): void
    {
        foreach (['ca_supervisor', 'ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->get(route('payment-methods.index'))->assertForbidden();
            $this->post(route('payment-methods.store'), $this->attributes('DENY-'.$role))->assertForbidden();
        }

        foreach (['director', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->get(route('payment-methods.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('PaymentMethods/Index')
                    ->where('methods', []));
        }

        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_authorised_user_can_create_edit_publish_and_deactivate_a_method(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        $this->post(route('payment-methods.store'), $this->attributes('UAT_CASH'))
            ->assertRedirect(route('payment-methods.index'));
        $method = PaymentMethod::query()->where('code', 'UAT_CASH')->sole();
        $this->assertFalse($method->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'payment_method.created',
            'subject_id' => $method->id,
            'actor_user_id' => $finance->id,
        ]);

        $this->patch(route('payment-methods.update', $method), [
            'name' => 'Updated cash',
            'description' => 'Updated synthetic details',
            'requires_reference' => false,
            'sort_order' => 15,
        ])->assertRedirect(route('payment-methods.index'));
        $this->assertSame('UAT_CASH', $method->refresh()->code);
        $this->assertSame('Updated cash', $method->name);

        $this->patch(route('payment-methods.update', $method), [
            ...$this->attributes('IGNORED_CODE'),
            'code' => 'MUTATED',
        ])->assertRedirect(route('payment-methods.index'))
            ->assertSessionHasErrors('code');
        $this->assertSame('UAT_CASH', $method->refresh()->code);

        $this->post(route('payment-methods.publish', $method))
            ->assertRedirect(route('payment-methods.index'));
        $this->assertTrue($method->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'payment_method.published',
            'subject_id' => $method->id,
            'actor_user_id' => $finance->id,
        ]);

        $this->post(route('payment-methods.deactivate', $method))
            ->assertRedirect(route('payment-methods.index'));
        $this->assertFalse($method->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'payment_method.deactivated',
            'subject_id' => $method->id,
            'actor_user_id' => $finance->id,
        ]);
    }

    public function test_validation_failures_redirect_to_the_setup_page_and_show_errors(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        $this->from(route('pricing.index'))
            ->post(route('payment-methods.store'), [
                ...$this->attributes('INVALID CODE'),
                'name' => '',
            ])
            ->assertRedirect(route('payment-methods.index'))
            ->assertSessionHasErrors(['code', 'name']);

        app(PaymentMethodAdministrationService::class)->create($finance, [
            'code' => 'EXISTING',
            'name' => 'Existing Method',
        ]);

        $this->from(route('pricing.index'))
            ->post(route('payment-methods.store'), $this->attributes('existing'))
            ->assertRedirect(route('payment-methods.index'))
            ->assertSessionHasErrors('code');
    }

    /** @return array<string, mixed> */
    private function attributes(string $code, string $name = 'Synthetic Payment Method'): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'description' => '',
            'requires_reference' => true,
            'sort_order' => 10,
        ];
    }
}
