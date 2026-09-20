<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\DevTools\DevTools;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class PublicCheckInLinkTest extends VisitTestCase
{
    public function test_director_can_issue_digest_only_branch_bound_link_and_public_landing_is_minimal(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $response = $this->postJson(route('public-checkin-links.store'), [
            'branch_id' => $this->branch->id,
            'label' => 'Front desk QR',
        ])->assertCreated();

        $issued = $response->json('issuedLink');
        $this->assertIsArray($issued);
        $this->assertMatchesRegularExpression('#/check-in\#[A-Za-z0-9_-]{43}$#', $issued['url']);
        preg_match('#/check-in\#([A-Za-z0-9_-]{43})$#', $issued['url'], $matches);
        $rawToken = $matches[1];
        $this->assertSame('/check-in', parse_url($issued['url'], PHP_URL_PATH));
        $this->assertNull(parse_url($issued['url'], PHP_URL_QUERY));
        $this->assertSame($rawToken, parse_url($issued['url'], PHP_URL_FRAGMENT));
        $link = PublicCheckInLink::query()->sole();

        $this->assertSame(hash('sha256', $rawToken), $link->token_hash);
        $this->assertStringNotContainsString($rawToken, json_encode($link->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertSame($this->organisation->id, $link->organisation_id);
        $this->assertSame($this->branch->id, $link->branch_id);

        $publicResponse = $this->get(parse_url($issued['url'], PHP_URL_PATH))->assertOk();
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));
        $publicResponse->assertInertia(fn (Assert $page) => $page
            ->component('PublicCheckIn/Show')
            ->where('clinicName', 'Klinik Putrijaya')
            ->where('branch', null)
            ->where('intakeSession', null)
            ->where('auth', null)->where('branchContext', null)->where('workspace', null)
            ->missing('patient')->missing('organisationId')->missing('branch.id'));

        $this->postJson(route('public-intake.exchange'), ['link_token' => $rawToken])
            ->assertCreated()
            ->assertJsonPath('branch.name', $this->branch->name)
            ->assertJsonMissing(['link_token' => $rawToken]);

        $audit = AuditLog::query()->where('event', 'public_checkin_link.created')->sole();
        $this->assertStringNotContainsString($rawToken, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_issued_url_is_not_recoverable_from_the_management_page(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $response = $this->postJson(route('public-checkin-links.store'), ['branch_id' => $this->branch->id, 'label' => 'Once'])->assertCreated();
        $rawUrl = $response->json('issuedLink.url');

        $this->get(route('public-checkin-links.index'))->assertInertia(fn (Assert $page) => $page->missing('issuedLink'));
        $this->assertStringNotContainsString($rawUrl, json_encode(PublicCheckInLink::query()->sole()->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_rotation_invalidates_old_token_and_revoke_invalidates_current_token(): void
    {
        $director = $this->actor('director');
        $first = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'QR');
        $rotated = app(PublicCheckInLinkService::class)->rotate($director, $first['link']);
        $this->postJson(route('public-intake.exchange'), ['link_token' => $first['rawToken']])->assertNotFound();
        $this->postJson(route('public-intake.exchange'), ['link_token' => $rotated['rawToken']])->assertCreated();
        $this->assertSame($first['link']->id, $rotated['link']->rotated_from_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'public_checkin_link.rotated']);

        app(PublicCheckInLinkService::class)->revoke($director, $rotated['link']);
        $this->postJson(route('public-intake.exchange'), ['link_token' => $rotated['rawToken']])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['event' => 'public_checkin_link.revoked']);
    }

    public function test_random_malformed_and_revoked_tokens_fail_with_the_same_public_status(): void
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'QR');
        app(PublicCheckInLinkService::class)->revoke($director, $issued['link']);

        $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])->assertNotFound();
        $this->get('/check-in/'.str_repeat('x', 43))->assertNotFound();
        $this->get('/check-in/malformed')->assertNotFound();
    }

    public function test_one_active_link_per_branch_is_enforced(): void
    {
        $director = $this->actor('director');
        app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'First');

        $this->expectException(ValidationException::class);
        app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Second');
    }

    public function test_permission_and_tenant_boundaries_are_server_enforced(): void
    {
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->actingAs($actor)->get(route('public-checkin-links.index'))->assertForbidden();
        }

        $director = $this->actor('director');
        $foreignOrganisation = Organisation::query()->create(['code' => 'FOREIGN', 'name' => 'Foreign Synthetic Org', 'is_active' => true]);
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $foreignOrganisation->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $this->actingAs($director)->post(route('public-checkin-links.store'), ['branch_id' => $foreignBranch->id, 'label' => 'No'])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseCount('public_checkin_links', 0);
    }

    public function test_public_link_schema_contains_no_patient_or_intake_fields(): void
    {
        foreach (['patient_id', 'full_name', 'identity', 'nric', 'passport', 'phone', 'date_of_birth', 'sex', 'coverage', 'panel_id', 'visit_reason'] as $column) {
            $this->assertFalse(Schema::hasColumn('public_checkin_links', $column), $column.' must not enter Q1-B1.');
        }
    }

    public function test_public_landing_rate_limit_is_active_without_exposing_token_state(): void
    {
        for ($request = 1; $request <= 300; $request++) {
            $this->get(route('public-checkin.show'))->assertOk();
        }

        $this->get(route('public-checkin.show'))->assertTooManyRequests();
    }

    public function test_distinct_tokens_cannot_bypass_the_global_source_ip_limit(): void
    {
        for ($request = 1; $request <= 20; $request++) {
            $token = str_pad((string) $request, 43, '0', STR_PAD_LEFT);

            $this->postJson(route('public-intake.exchange'), ['link_token' => $token])->assertNotFound();
        }

        $this->postJson(route('public-intake.exchange'), ['link_token' => str_pad('21', 43, '0', STR_PAD_LEFT)])
            ->assertTooManyRequests();
    }

    public function test_link_wide_limit_remains_active_across_distinct_source_ips(): void
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Link-wide rate test');
        for ($request = 1; $request <= 100; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.$request])
                ->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])
                ->assertCreated();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::101'])
            ->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])
            ->assertTooManyRequests();
    }

    public function test_token_bearing_routes_are_excluded_from_inertia_devtools_recording(): void
    {
        config()->set('inertia.devtools.enabled', true);
        $token = str_repeat('A', 43);
        $publicId = '01993b72-fec0-70e4-a429-101703e7aa20';

        foreach ([
            Request::create('/public-checkin-links', 'POST'),
            Request::create('/public-checkin-links/'.$publicId.'/rotate', 'POST'),
            Request::create('/public-checkin-links/'.$publicId, 'DELETE'),
            Request::create('/check-in', 'GET'),
            Request::create('/check-in/exchange', 'POST', ['link_token' => $token]),
            Request::create('/queue/search', 'POST'),
        ] as $sensitiveRequest) {
            $this->assertFalse(DevTools::enabledForRequest($sensitiveRequest));
            $this->assertNull(DevTools::recorder($sensitiveRequest));
        }

        $ordinaryRequest = Request::create('/dashboard', 'GET');
        $this->assertTrue(DevTools::enabledForRequest($ordinaryRequest));
        $this->assertNotNull(DevTools::recorder($ordinaryRequest));
    }
}
