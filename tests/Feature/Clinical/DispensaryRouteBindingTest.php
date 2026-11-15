<?php

namespace Tests\Feature\Clinical;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class DispensaryRouteBindingTest extends ClinicalTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function malformedIdRoutes(): iterable
    {
        yield 'show' => ['GET', 'dispensary/1'];
        yield 'labels' => ['GET', 'dispensary/1/labels'];
        yield 'start' => ['POST', 'dispensary/1/start'];
        yield 'return-to-doctor' => ['POST', 'dispensary/1/return-to-doctor'];
        yield 'complete' => ['POST', 'dispensary/1/complete'];
        yield 'exception-acknowledge' => ['POST', 'dispensary-exceptions/1/acknowledge'];
    }

    #[DataProvider('malformedIdRoutes')]
    public function test_a_non_uuid_dispensary_route_parameter_is_a_clean_404(string $method, string $uri): void
    {
        [, $ca] = $this->servingFixture();
        $this->selectBranch($ca);

        $response = $this->actingAs($ca)->call($method, $uri);

        $response->assertNotFound();
    }

    public function test_a_non_uuid_dispensary_item_route_parameter_is_a_clean_404(): void
    {
        [, $ca] = $this->servingFixture();
        $this->selectBranch($ca);

        $this->actingAs($ca)->patch('dispensary/'.Str::uuid().'/items/1')
            ->assertNotFound();
    }
}
