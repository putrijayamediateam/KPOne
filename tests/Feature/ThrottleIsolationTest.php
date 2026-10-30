<?php

namespace Tests\Feature;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Visit;
use App\Http\Middleware\RouteIsolatedThrottle;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingTestCase;

class ThrottleIsolationTest extends BillingTestCase
{
    public function test_exhausted_route_retains_429_retry_after_without_exhausting_another_route_or_user(): void
    {
        $this->freezeTime();
        Route::get('/throttle-test/a/{key}', fn () => 'ok')->middleware('throttle:2,1')->name('throttle-test.a');
        Route::get('/throttle-test/b', fn () => 'ok')->middleware('throttle:3,1')->name('throttle-test.b');
        $this->actingAs($this->actor());
        $this->getJson('/throttle-test/a/one')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/throttle-test/a/two?filter=changed')->assertOk();
        $this->getJson('/throttle-test/a/three')->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->getJson('/throttle-test/b')->assertOk()->assertHeader('X-RateLimit-Remaining', '2');
        $this->actingAs($this->actor());
        $this->getJson('/throttle-test/a/one')->assertOk();
        $this->travel(61)->seconds();
        $this->getJson('/throttle-test/a/one')->assertOk();
    }

    public function test_unnamed_routes_use_method_and_uri_pattern_and_keep_guest_ip_isolation(): void
    {
        Route::get('/throttle-test/guest/{key}', fn () => 'ok')->middleware('throttle:2,1');
        Route::post('/throttle-test/guest/{key}', fn () => 'ok')->middleware('throttle:2,1');
        Route::get('/throttle-test/other', fn () => 'ok')->middleware('throttle:2,1');
        $this->getJson('/throttle-test/guest/one')->assertOk();
        $this->getJson('/throttle-test/guest/two')->assertOk();
        $this->getJson('/throttle-test/guest/three?filter=other')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/throttle-test/guest/one')->assertOk();
        $this->getJson('/throttle-test/other')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->getJson('/throttle-test/guest/one')->assertOk();
    }

    public function test_every_registered_numeric_throttle_resolves_to_the_isolating_middleware(): void
    {
        $this->get('/login')->assertOk();
        $router = app('router');
        $this->assertSame(RouteIsolatedThrottle::class, $router->getMiddleware()['throttle']);
        $count = 0;
        foreach ($router->getRoutes() as $route) {
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                [$class, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');
                if (is_a($class, ThrottleRequests::class, true) && is_numeric(explode(',', $parameters)[0])) {
                    $this->assertSame(RouteIsolatedThrottle::class, $class, $route->uri());
                    $count++;
                }
            }
        }
        $this->assertGreaterThan(100, $count);
    }

    public function test_named_limiters_keep_exact_logical_keys_limits_and_framework_cache_keys(): void
    {
        $actor = $this->actor();
        $actor->email = 'numeric-isolation@throttle.test';
        $request = Request::create('/throttle-test/named', 'POST', ['email' => $actor->email], server: ['REMOTE_ADDR' => '192.0.2.1']);
        $request->setUserResolver(fn () => $actor);
        $digest = hash('sha256', '');
        $session = 'intake-session:invalid:'.$digest;
        $expected = [
            'login' => [[5, $actor->email.'|192.0.2.1']],
            'patient-report-views' => [[60, 'patient-reports:'.$actor->organisation_id.':'.$actor->id]],
            'patient-whatsapp' => [[20, 'patient-whatsapp:'.$actor->id]],
            'reports-downloads' => [[10, 'reports:'.$actor->organisation_id.':'.$actor->id]],
            'public-checkin-view' => [[300, '192.0.2.1']],
            'public-intake-exchange' => [[20, '192.0.2.1'], [10, '192.0.2.1|'.$digest], [100, 'invalid:'.$digest]],
            'public-intake-submit' => [[10, '192.0.2.1'], [5, '192.0.2.1|'.$session], [60, 'invalid:'.$digest], [3, 'submission:'.$session]],
            'public-intake-status' => [[120, '192.0.2.1'], [30, '192.0.2.1|'.$session], [120, $session], [1000, 'invalid:'.$digest]],
        ];
        $limiter = app(RateLimiter::class);
        foreach ($expected as $name => $entries) {
            $limits = ($limiter->limiter($name))($request);
            $limits = is_array($limits) ? $limits : [$limits];
            $this->assertSame($entries, array_map(fn ($limit) => [$limit->maxAttempts, $limit->key], $limits), $name);
            foreach ($limits as $limit) {
                $this->assertSame(60, $limit->decaySeconds);
                $limiter->clear(md5($name.$limit->key));
            }
            $next = fn () => new Response('ok');
            (new ThrottleRequests($limiter))->handle($request, $next, $name);
            (new RouteIsolatedThrottle($limiter))->handle($request, $next, $name);
            foreach ($limits as $limit) {
                $this->assertSame(2, $limiter->attempts(md5($name.$limit->key)), $name);
            }
        }
        $this->assertSame(ThrottleRequests::class, (new \ReflectionMethod(RouteIsolatedThrottle::class, 'handleRequestUsingNamedLimiter'))->getDeclaringClass()->getName());
    }

    public function test_sixty_seconds_of_three_second_polling_does_not_block_start_or_call_in(): void
    {
        $this->freezeTime();
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $second = $this->consultationVisit($ca, $doctor);
        $secondQueue = $this->send($ca, $second);
        $this->selectBranch($doctor);
        // First hit at t=3: the original shared bucket is still live at t=60.
        for ($i = 0; $i < 20; $i++) {
            $this->travel(3)->seconds();
            $this->postJson(route('queue.search'), ['query' => ''])->assertOk();
            $this->getJson(route('queue.readiness.updates', ['expected_branch_id' => $this->branch->id]))->assertOk();
            $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))->assertOk();
        }
        $this->post(route('encounters.store', $visit), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version])->assertRedirect(route('encounters.show', $visit))->assertSessionHasNoErrors();
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->sole();
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->patch(route('queue.call', $second), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $second->refresh()->lock_version, 'queue_lock_version' => $secondQueue->refresh()->lock_version])->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $this->assertSame('serving', $secondQueue->refresh()->status);
    }

    public function test_ten_registrations_and_lookups_do_not_block_each_other_or_a_payment(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic Cash'])->save();
        $patients = [];
        for ($i = 0; $i < 10; $i++) {
            $patients[] = $this->patient($ca, ['full_name' => 'Throttle Synthetic Patient '.$i, 'email' => 'patient'.$i.'@throttle.test']);
        }
        $this->selectBranch($ca);
        foreach ($patients as $patient) {
            $this->postJson(route('patients.search'), ['search_type' => 'name', 'query' => $patient->full_name])->assertOk();
            $this->getJson(route('visit-reasons.index', ['query' => 'Synthetic']))->assertOk();
            $this->postJson(route('queue.search'), ['query' => ''])->assertOk();
            $this->post(route('registration.store'), $this->visitAttributes($patient))->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(11, Visit::query()->count());
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['amount_sen' => 1000]);
    }
}
