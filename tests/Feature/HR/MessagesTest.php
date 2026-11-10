<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Messages\MessagePermissions;
use App\Domain\HR\Messages\MessageTemplates;
use App\Domain\HR\Messages\Services\StaffMessenger;
use App\Mail\StaffMessageMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-14 staff messages (owner, 2026-11-10: "start"; email now, SMS and WhatsApp later; the four messages; HR-PLAN
 * 15.3). Synthetic `.test` data only. Email is faked; nothing leaves the test.
 */
class MessagesTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_and_staff_see_only_their_own_choices(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(MessagePermissions::MANAGE));
        $own = $holders(MessagePermissions::RECEIVE_OWN);
        $this->assertCount(11, $own);
        $this->assertNotContains('queue_display', $own);
        foreach (array_keys(MessagePermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.messages.index'))->assertForbidden();

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.messages.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Messages')
            ->where('messages.tabs', ['my'])->where('messages.settings', null)->where('messages.sent', null)
            ->where('messages.my.0.channel', 'email')->where('messages.my.0.connected', false)->where('messages.my.0.optedIn', false));
        $this->configure($ca, 'email', ['enabled' => false, 'expected_version' => 0])->assertForbidden();

        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.messages.index'))->assertInertia(fn (Assert $page) => $page->where('messages.tabs', ['my', 'settings', 'sent']));
    }

    public function test_every_template_holds_only_dates_months_claim_numbers_and_decisions(): void
    {
        $banned = '/\b(RM|MYR|sen|amount|salary|pay(ment)? of|IC|NRIC|passport|bank|account|patient|diagnos|medic|clinical|sick|reason|type)\b/i';
        foreach (MessageTemplates::TEMPLATES as $code => $template) {
            $this->assertDoesNotMatchRegularExpression($banned, $template['subject'].' '.$template['body'], $code);
            $this->assertSame([], array_diff(MessageTemplates::placeholders($code), array_keys(MessageTemplates::PLACEHOLDERS)), $code);
        }
        $this->assertDoesNotMatchRegularExpression($banned, MessageTemplates::FOOTER);
        $this->assertSame(['leave_decided', 'roster_ready', 'payslip_ready', 'claim_decided'], array_keys(MessageTemplates::TEMPLATES));

        // A value that is not a date, a month, a claim number or a decision word is refused before anything is sent.
        foreach ([
            ['claim_decided', ['claim' => 'SC-000001', 'decision' => 'approved for RM 50.00']],
            ['leave_decided', ['from' => 'Typhoid', 'to' => '9 Mar 2026', 'decision' => 'approved']],
            ['payslip_ready', ['month' => 'RM 3,500.00']],
            ['roster_ready', []],
            ['roster_ready', ['week' => '9 Mar 2026', 'extra' => 'x']],
        ] as [$code, $params]) {
            try {
                MessageTemplates::render($code, $params);
                $this->fail("Template {$code} accepted a value it must refuse.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame("Your claim SC-000042 was recorded as paid in KPOne.\n\n".MessageTemplates::FOOTER,
            MessageTemplates::render('claim_decided', ['claim' => 'SC-000042', 'decision' => 'recorded as paid'])['body']);
    }

    public function test_a_staff_member_chooses_a_connected_channel_and_turning_it_off_always_works(): void
    {
        $ca = $this->actor('ca');
        $this->choose($ca, 'email', true, 0)->assertSessionHasErrors(['messages' => 'Not connected yet: the clinic has not chosen a provider for Email.']);
        $this->choose($ca, 'sms', true, 0)->assertSessionHasErrors('messages');
        $this->choose($ca, 'whatsapp', true, 0)->assertSessionHasErrors('messages');

        config(['hr-messages.email.connected' => true]);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();
        $this->choose($ca, 'email', true, 0)->assertSessionHasErrors('records'); // a stale page
        $this->choose($ca, 'email', true, 1)->assertSessionHasErrors(['messages' => 'Nothing was changed.']);
        // Off works even once the channel is no longer connected.
        config(['hr-messages.email.connected' => false]);
        $this->choose($ca, 'email', false, 1)->assertSessionHasNoErrors();
        $this->assertSame([true, false], DB::table('hr_message_preference_events')->where('user_id', $ca->id)->orderBy('id')->pluck('opted_in')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame(['hr.messages.opted_in', 'hr.messages.opted_out'], DB::table('audit_logs')->where('event', 'like', 'hr.messages.opted%')->orderBy('id')->pluck('event')->all());
    }

    public function test_a_channel_switches_on_only_when_connected_and_capped_and_each_change_is_a_version(): void
    {
        $hr = $this->actor('hr_manager');
        $this->configure($hr, 'email', ['enabled' => true, 'monthly_cap' => 100, 'expected_version' => 0])
            ->assertSessionHasErrors(['messages' => 'Not connected yet: the clinic has not chosen a provider for Email. Settings open when this channel is connected.']);
        // Nothing can be saved ahead for a channel that is not connected (Daniel, HR-14).
        $this->configure($hr, 'email', ['enabled' => false, 'monthly_cap' => 100, 'price' => '0.05', 'expected_version' => 0])->assertSessionHasErrors('messages');
        $this->assertSame(0, DB::table('hr_message_channel_settings')->count());
        config(['hr-messages.email.connected' => true]);
        $this->configure($hr, 'email', ['enabled' => true, 'expected_version' => 0])->assertSessionHasErrors(['monthly_cap' => 'Enter a monthly cap before switching this channel on.']);
        $this->configure($hr, 'email', ['enabled' => true, 'monthly_cap' => 0, 'expected_version' => 0])->assertSessionHasErrors('monthly_cap');
        $this->configure($hr, 'email', ['enabled' => true, 'monthly_cap' => 100, 'price' => '0.155', 'expected_version' => 0])->assertSessionHasErrors('price');
        $this->configure($hr, 'email', ['enabled' => true, 'monthly_cap' => 100, 'price' => '0.05', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->configure($hr, 'email', ['enabled' => false, 'monthly_cap' => 100, 'price' => '0.05', 'expected_version' => 0])->assertSessionHasErrors('records');
        $this->configure($hr, 'email', ['enabled' => false, 'monthly_cap' => 100, 'price' => '0.05', 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame([[1, true, 5, 100], [2, false, 5, 100]], DB::table('hr_message_channel_settings')->orderBy('version')->get()
            ->map(fn (object $r): array => [(int) $r->version, (bool) $r->enabled, (int) $r->price_sen, (int) $r->monthly_cap])->all());
        $this->configure($hr, 'sms', ['enabled' => true, 'monthly_cap' => 10, 'expected_version' => 0])->assertSessionHasErrors('messages');
        // Once set up, a channel that loses its connection can still be switched off, with its values unchanged.
        config(['hr-messages.email.connected' => false]);
        $this->configure($hr, 'email', ['enabled' => false, 'monthly_cap' => 100, 'price' => '0.05', 'expected_version' => 2])->assertSessionHasErrors('messages'); // nothing changed
        $this->configure($hr, 'email', ['enabled' => false, 'monthly_cap' => 50, 'price' => '0.05', 'expected_version' => 2])->assertSessionHasErrors('messages');
        config(['hr-messages.email.connected' => true]);

        // PostgreSQL keeps the versions and refuses a switched-on channel without a cap.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_message_channel_settings')->where('version', 1)->update(['enabled' => false]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_message_channel_settings')->insert(['organisation_id' => $hr->organisation_id, 'channel' => 'email',
            'version' => 3, 'enabled' => true, 'monthly_cap' => null, 'set_by_user_id' => $hr->id, 'created_at' => now()->utc()]), '23514');
    }

    public function test_a_claim_decision_reaches_only_people_who_chose_email_and_the_text_is_never_stored(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $finance = $this->actor('finance_officer');
        $ca = $this->actor('ca');
        $panel = $this->actor('panel_officer');
        $this->switchOnEmail($hr, 100);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();

        $category = $this->makeCategory($hr);
        $this->sendClaim($ca, $category)->assertSessionHasNoErrors();
        $this->sendClaim($panel, $category)->assertSessionHasNoErrors(); // did not choose email
        [$caClaim, $panelClaim] = DB::table('hr_staff_claims')->orderBy('id')->get()->all();
        $this->decide($director, $caClaim, 'approve')->assertSessionHasNoErrors();
        $this->decide($director, $panelClaim, 'approve')->assertSessionHasNoErrors();
        $this->selectBranch($finance);
        $this->post(route('hr.claims.pay', $caClaim->public_id), ['method' => 'cash', 'paid_on' => '2026-11-10',
            'expected_lock_version' => (int) DB::table('hr_staff_claims')->where('id', $caClaim->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        Mail::assertSent(StaffMessageMail::class, 2);
        Mail::assertSent(StaffMessageMail::class, fn (StaffMessageMail $m): bool => $m->hasTo($ca->email)
            && str_contains($m->bodyText, "Your claim {$caClaim->number} was approved in KPOne.") && ! str_contains($m->bodyText, '12.50'));
        Mail::assertSent(StaffMessageMail::class, fn (StaffMessageMail $m): bool => str_contains($m->bodyText, 'was recorded as paid'));
        Mail::assertNotSent(StaffMessageMail::class, fn (StaffMessageMail $m): bool => $m->hasTo($panel->email));

        $rows = DB::table('hr_message_deliveries')->orderBy('id')->get();
        $this->assertSame([['sent', 'claim_decided', $ca->id], ['sent', 'claim_decided', $ca->id]], $rows->map(fn (object $r): array => [$r->status, $r->template, (int) $r->user_id])->all());
        $this->assertSame(['claim_id' => (int) $caClaim->id], json_decode((string) $rows[0]->subject_ids, true));
        $this->assertStringNotContainsString('KPOne', json_encode($rows, JSON_THROW_ON_ERROR));

        // The Sent tab lists them; nothing in it is the text.
        $this->selectBranch($hr);
        $this->get(route('hr.messages.index', ['tab' => 'sent']))->assertInertia(fn (Assert $page) => $page->where('messages.sent.total', 2)
            ->where('messages.sent.rows.0.template', 'Staff claim decided or paid')->where('messages.sent.rows.0.statusLabel', 'Sent'));
        // The Sent tab filters by status.
        $this->get(route('hr.messages.index', ['tab' => 'sent', 'status' => 'failed']))->assertInertia(fn (Assert $page) => $page
            ->where('messages.sent.status', 'failed')->where('messages.sent.total', 0));
        $this->get(route('hr.messages.index', ['tab' => 'sent', 'status' => 'nonsense']))->assertInertia(fn (Assert $page) => $page
            ->where('messages.sent.status', 'all')->where('messages.sent.total', 2));
        $this->get(route('hr.messages.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('messages.settings.0.sent', 2)
            ->where('messages.settings.0.cost', 'RM 0.10')->where('messages.settings.0.people', 1));
    }

    public function test_the_monthly_cap_stops_sending_and_a_failure_never_touches_hr_data(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->switchOnEmail($hr, 1);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();
        $messenger = app(StaffMessenger::class);
        $params = ['week' => '9 Nov 2026'];

        $messenger->notify($ca->organisation_id, $ca->id, 'roster_ready', $params, ['roster_version_id' => 1]);
        $messenger->notify($ca->organisation_id, $ca->id, 'roster_ready', $params, ['roster_version_id' => 2]);
        Mail::assertSent(StaffMessageMail::class, 1);
        $this->assertSame(['sent', 'skipped_cap'], DB::table('hr_message_deliveries')->orderBy('id')->pluck('status')->all());

        // A new month starts a new count.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00', $this->branch->timezone));
        $messenger->notify($ca->organisation_id, $ca->id, 'roster_ready', $params, ['roster_version_id' => 3]);
        Mail::assertSent(StaffMessageMail::class, 2);

        // The mail service failing is recorded and nothing is thrown back.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->travelTo(CarbonImmutable::parse('2027-01-04 09:00', $this->branch->timezone));
        $messenger->notify($ca->organisation_id, $ca->id, 'payslip_ready', ['month' => 'December 2026'], ['payroll_run_id' => 9]);
        $failed = DB::table('hr_message_deliveries')->orderByDesc('id')->first();
        $this->assertNotNull($failed);
        $this->assertSame(['failed', 'send_failed'], [$failed->status, $failed->error_code]);

        // A bad template call is logged and never sent.
        $messenger->notify($ca->organisation_id, $ca->id, 'payslip_ready', ['month' => 'RM 99'], []);
        $this->assertSame(4, DB::table('hr_message_deliveries')->count());

        // PostgreSQL: a finished delivery does not change and nothing is deleted.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_message_deliveries')->where('id', $failed->id)->update(['status' => 'sent']));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_message_deliveries')->where('id', $failed->id)->delete());
    }

    public function test_a_rolled_back_hr_change_sends_nothing(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->switchOnEmail($hr, 10);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();
        try {
            DB::transaction(function () use ($ca): void {
                app(StaffMessenger::class)->notify($ca->organisation_id, $ca->id, 'roster_ready', ['week' => '9 Nov 2026'], ['roster_version_id' => 1]);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('hr_message_deliveries')->count());
    }

    public function test_a_mail_failure_neither_rolls_back_nor_errors_the_hr_action(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->switchOnEmail($hr, 10);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();
        $category = $this->makeCategory($hr);
        $this->sendClaim($ca, $category)->assertSessionHasNoErrors();
        $claim = DB::table('hr_staff_claims')->sole();

        // Sent directly after the commit, no queue (owner, 2026-11-10): the mail service refusing must not reach the Director.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->decide($director, $claim, 'approve')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('approved', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $row = DB::table('hr_message_deliveries')->sole();
        $this->assertSame(['failed', 'send_failed', 'claim_decided', (int) $ca->id], [$row->status, $row->error_code, $row->template, (int) $row->user_id]);
    }

    public function test_a_mail_service_that_fails_is_not_tried_again_for_the_rest_of_the_same_action(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $nurse = $this->actor('ca_supervisor');
        $this->switchOnEmail($hr, 10);
        $this->choose($ca, 'email', true, 0)->assertSessionHasNoErrors();
        $this->choose($nurse, 'email', true, 0)->assertSessionHasNoErrors();

        // One action sending to two people (as a payroll lock does): the mail service is asked once, not once per person.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        // (The hooks share one messenger per action; RosterTest proves it through a real approval.)
        DB::transaction(function () use ($ca, $nurse): void {
            $messenger = app(StaffMessenger::class);
            $messenger->notify($ca->organisation_id, $ca->id, 'roster_ready', ['week' => '9 Nov 2026'], ['roster_version_id' => 1]);
            $messenger->notify($nurse->organisation_id, $nurse->id, 'roster_ready', ['week' => '9 Nov 2026'], ['roster_version_id' => 1]);
        });

        $this->assertSame([['failed', 'send_failed'], ['failed', 'send_skipped']], DB::table('hr_message_deliveries')->orderBy('id')->get()
            ->map(fn (object $r): array => [$r->status, $r->error_code])->all());
        $this->selectBranch($hr);
        $this->get(route('hr.messages.index', ['tab' => 'sent']))->assertInertia(fn (Assert $page) => $page
            ->where('messages.sent.rows.0.error', 'Not tried: the mail service failed earlier in the same action'));
    }

    private function switchOnEmail(User $hr, int $cap): void
    {
        config(['hr-messages.email.connected' => true]);
        $version = (int) DB::table('hr_message_channel_settings')->where('channel', 'email')->max('version');
        $this->configure($hr, 'email', ['enabled' => true, 'monthly_cap' => $cap, 'price' => '0.05', 'expected_version' => $version])->assertSessionHasNoErrors();
    }

    private function choose(User $actor, string $channel, bool $on, int $lock): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.messages.choose', $channel), ['opted_in' => $on, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function configure(User $actor, string $channel, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.messages.configure', $channel), [...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function makeCategory(User $hr): string
    {
        $this->selectBranch($hr);
        $this->post(route('hr.claims.categories.save'), ['name' => 'Synthetic parking', 'needs_receipt' => false, 'is_active' => true,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return (string) DB::table('hr_claim_categories')->value('public_id');
    }

    private function sendClaim(User $actor, string $category): TestResponse
    {
        $this->assertTrue($actor->can(ClaimPermissions::SUBMIT_OWN));
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.send'), ['category_id' => $category, 'spent_on' => '2026-11-09', 'amount' => '12.50',
            'purpose' => 'Synthetic parking at the Cheras training day', 'idempotency_key' => (string) Str::uuid()]);
    }

    private function decide(User $actor, \stdClass $claim, string $decision): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.decide', [$claim->public_id, $decision]), [
            'expected_lock_version' => (int) DB::table('hr_staff_claims')->where('id', $claim->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function assertDatabaseRefuses(callable $write, string $code = 'P0001'): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL accepted a write it must refuse.');
        } catch (QueryException $e) {
            $this->assertStringContainsString($code, $e->getMessage());
        }
    }
}
