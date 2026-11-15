<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * REF-01: staff pages send Referrer-Policy: same-origin so back() and failed-validation redirects can
 * tell which page a request came from; the public QR check-in routes keep no-referrer.
 */
class ReferrerPolicyTest extends ClinicalTestCase
{
    private function policyFor(?string $parameter): string
    {
        $middleware = new PreventSensitiveResponseCaching;
        $next = fn (): Response => new Response('ok');
        $response = $parameter === null
            ? $middleware->handle(Request::create('/x'), $next)
            : $middleware->handle(Request::create('/x'), $next, $parameter);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return (string) $response->headers->get('Referrer-Policy');
    }

    public function test_the_default_is_same_origin_and_the_public_parameter_keeps_no_referrer(): void
    {
        $this->assertSame('same-origin', $this->policyFor(null));
        $this->assertSame('same-origin', $this->policyFor('same-origin'));
        $this->assertSame('no-referrer', $this->policyFor('no-referrer'));
        $this->assertSame('no-referrer', $this->policyFor('unsafe-url'), 'an unknown value fails closed to no-referrer');
    }

    public function test_a_staff_page_sends_same_origin_and_the_public_routes_are_declared_no_referrer(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.work'))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderContains('Cache-Control', 'no-store');

        foreach (['public-checkin.show', 'public-intake.exchange', 'public-intake.submit', 'public-intake.status'] as $name) {
            $this->assertContains(
                'sensitive.no-store:no-referrer',
                app('router')->getRoutes()->getByName($name)->gatherMiddleware(),
                "{$name} must keep no-referrer",
            );
        }
    }
}
