<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHENTICATION_HISTORY_SESSION_KEY = 'inertia.authentication_history_boundary.v2';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', HandleInertiaRequests::class])
            ->get('/_tests/inertia-authentication-history', fn () => Inertia::render('Welcome'))
            ->name('tests.inertia-authentication-history');
    }

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('workspace', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_inertia_history_epoch_fails_closed_and_stays_stable_within_one_principal_session(): void
    {
        $first = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $epoch = $first['props']['authHistoryBoundary']['epoch'];

        $this->assertTrue($first['clearHistory']);
        $this->assertTrue($first['encryptHistory']);
        $this->assertTrue(Str::isUuid($epoch));
        $this->assertSame(2, $first['props']['authHistoryBoundary']['version']);
        $this->assertSame('7', $epoch[14]);
        $firstMarker = session(self::AUTHENTICATION_HISTORY_SESSION_KEY);

        $second = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertSame($firstMarker, session(self::AUTHENTICATION_HISTORY_SESSION_KEY), 'The stable guest marker changed unexpectedly.');
        $this->assertFalse($second['clearHistory'] ?? false);
        $this->assertSame($epoch, $second['props']['authHistoryBoundary']['epoch']);

        session([self::AUTHENTICATION_HISTORY_SESSION_KEY => ['version' => 0, 'epoch' => 'request-controlled']]);
        $malformed = $this->get('/_tests/inertia-authentication-history?'.http_build_query([
            self::AUTHENTICATION_HISTORY_SESSION_KEY => ['version' => 1, 'epoch' => (string) Str::uuid()],
        ]))->assertOk()->inertiaPage();

        $this->assertTrue($malformed['clearHistory']);
        $this->assertNotSame($epoch, $malformed['props']['authHistoryBoundary']['epoch']);

        $serialized = json_encode($malformed['props'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::AUTHENTICATION_HISTORY_SESSION_KEY, $serialized);
        $this->assertStringNotContainsString($this->app['session']->getId(), $serialized);
        $this->assertStringNotContainsString((string) config('app.key'), $serialized);
    }

    public function test_password_login_direct_logout_session_loss_and_principal_change_rotate_the_history_epoch(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $guestEpoch = $this->get('/_tests/inertia-authentication-history')->inertiaPage()['props']['authHistoryBoundary']['epoch'];

        $this->post(route('login.store'), ['email' => $userA->email, 'password' => 'password'])
            ->assertRedirect(route('workspace', absolute: false));
        $authenticatedA = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $epochA = $authenticatedA['props']['authHistoryBoundary']['epoch'];
        $this->assertTrue($authenticatedA['clearHistory']);
        $this->assertNotSame($guestEpoch, $epochA);

        $stableA = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertFalse($stableA['clearHistory'] ?? false);
        $this->assertSame($epochA, $stableA['props']['authHistoryBoundary']['epoch']);

        $this->actingAs($userB);
        $authenticatedB = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($authenticatedB['clearHistory']);
        $this->assertNotSame($epochA, $authenticatedB['props']['authHistoryBoundary']['epoch']);

        $this->post(route('logout'))->assertRedirect(route('home'));
        $guestAfterLogout = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($guestAfterLogout['clearHistory']);
        $this->assertNotSame($authenticatedB['props']['authHistoryBoundary']['epoch'], $guestAfterLogout['props']['authHistoryBoundary']['epoch']);

        $this->post(route('login.store'), ['email' => $userA->email, 'password' => 'password'])
            ->assertRedirect(route('workspace', absolute: false));
        $authenticatedANewSession = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($authenticatedANewSession['clearHistory']);
        $this->assertNotSame($epochA, $authenticatedANewSession['props']['authHistoryBoundary']['epoch']);

        $this->app['session']->invalidate();
        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $guestAfterLoss = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($guestAfterLoss['clearHistory']);
        $this->assertNotSame($authenticatedANewSession['props']['authHistoryBoundary']['epoch'], $guestAfterLoss['props']['authHistoryBoundary']['epoch']);
    }

    public function test_google_login_and_inactive_account_logout_use_the_same_central_history_boundary(): void
    {
        Config::set('services.google', [
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]);
        $googleUser = User::factory()->create(['email' => 'history.google@kpone.test']);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'synthetic-history-subject',
            'email' => $googleUser->email,
            'email_verified' => true,
        ]));
        $guestEpoch = $this->get('/_tests/inertia-authentication-history')->inertiaPage()['props']['authHistoryBoundary']['epoch'];

        $googleCallback = $this->get(route('google.callback'));
        $this->assertTrue($googleCallback->isRedirect(), $googleCallback->getContent());
        $googleCallback->assertRedirect(route('workspace', absolute: false));
        $googlePage = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($googlePage['clearHistory']);
        $this->assertNotSame($guestEpoch, $googlePage['props']['authHistoryBoundary']['epoch']);

        $googleUser->forceFill(['is_active' => false])->save();
        $this->actingAs($googleUser->fresh());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $inactiveGuest = $this->get('/_tests/inertia-authentication-history')->assertOk()->inertiaPage();
        $this->assertTrue($inactiveGuest['clearHistory']);
        $this->assertNotSame($googlePage['props']['authHistoryBoundary']['epoch'], $inactiveGuest['props']['authHistoryBoundary']['epoch']);
    }
}
