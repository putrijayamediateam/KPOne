<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_is_displayed(): void
    {
        $user = $this->grantPermissions(User::factory()->create(), 'profile.view.own');

        $this->actingAs($user)->get(route('security.edit'))->assertOk();
    }

    public function test_password_can_be_updated(): void
    {
        $user = $this->grantPermissions(User::factory()->create(), 'profile.view.own');

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_is_required_to_update_password(): void
    {
        $user = $this->grantPermissions(User::factory()->create(), 'profile.view.own');

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('security.edit'));
    }
}
