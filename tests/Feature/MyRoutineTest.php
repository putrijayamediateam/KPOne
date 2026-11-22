<?php

namespace Tests\Feature;

use App\Domain\MyDay\MyRoutineService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;

/** DAY-1b: the personal routine. Strictly the person's own; resets by branch-local date; nothing about it reaches audit titles or reports. */
final class MyRoutineTest extends VisitTestCase
{
    private function service(): MyRoutineService
    {
        return app(MyRoutineService::class);
    }

    private function me(string $role = 'finance_officer'): User
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);

        return $actor;
    }

    private function add(User $actor, string $title, ?int $version = null, ?string $key = null): void
    {
        $this->service()->add($actor, ['title' => $title, 'lock_version' => $version ?? $this->service()->page($actor)['lockVersion'], 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function version(User $actor): int
    {
        return $this->service()->page($actor)['lockVersion'];
    }

    public function test_a_person_adds_renames_reorders_ticks_hides_and_restores_their_own_items(): void
    {
        $me = $this->me();
        $this->add($me, 'Check the fridge log');
        $this->add($me, '  Count   the till float ');
        $page = $this->service()->page($me);
        $this->assertSame(['Check the fridge log', 'Count the till float'], array_column($page['items'], 'title'));
        [$a, $b] = array_column($page['items'], 'id');

        $this->service()->rename($me, $a, ['title' => 'Check the fridge', 'lock_version' => $this->version($me)]);
        $this->service()->reorder($me, ['order' => [$b, $a], 'lock_version' => $this->version($me)]);
        $this->assertSame(['Count the till float', 'Check the fridge'], array_column($this->service()->page($me)['items'], 'title'));

        $this->service()->tick($me, $a, true);
        $this->service()->tick($me, $a, true); // A double tap is one tick.
        $this->assertSame(1, DB::table('my_routine_ticks')->count());
        $this->assertSame([1, 2], [$this->service()->page($me)['done'], $this->service()->page($me)['total']]);
        $this->service()->tick($me, $a, false);
        $this->assertSame(0, $this->service()->page($me)['done']);

        $this->service()->hide($me, $a, ['lock_version' => $this->version($me)]);
        $page = $this->service()->page($me);
        $this->assertSame([1, 1], [$page['total'], count($page['removed'])]);
        $this->service()->restore($me, $a, ['lock_version' => $this->version($me), 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(2, $this->service()->page($me)['total']);
    }

    public function test_one_person_can_never_read_or_change_another_persons_items(): void
    {
        $alice = $this->me('finance_officer');
        $bob = $this->me('ca');
        $this->add($alice, 'Alice private routine');
        $aliceItem = $this->service()->page($alice)['items'][0]['id'];

        $this->assertSame([], $this->service()->page($bob)['items']);
        foreach ([
            fn () => $this->service()->tick($bob, $aliceItem, true),
            fn () => $this->service()->rename($bob, $aliceItem, ['title' => 'Hijacked', 'lock_version' => 1]),
            fn () => $this->service()->hide($bob, $aliceItem, ['lock_version' => 1]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another person\'s item must not be reachable.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertSame('Alice private routine', $this->service()->page($alice)['items'][0]['title']);
        // Not in the HTTP responses of another person either.
        $this->actingAs($bob)->get(route('my-day'))->assertOk()->assertDontSee('Alice private routine');
    }

    public function test_ticks_reset_on_a_new_branch_local_date_across_kuala_lumpur_midnight(): void
    {
        $me = $this->me();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 23:50', $this->branch->timezone));
        $this->add($me, 'Lock the pharmacy');
        $id = $this->service()->page($me)['items'][0]['id'];
        $this->service()->tick($me, $id, true);
        $this->assertSame(1, $this->service()->page($me)['done']);
        $this->travelTo(CarbonImmutable::parse('2027-03-09 00:10', $this->branch->timezone));
        $this->assertSame(0, $this->service()->page($me)['done'], 'a new local date shows every item unticked');
        $this->service()->tick($me, $id, true);
        $this->assertSame(2, DB::table('my_routine_ticks')->count());
    }

    public function test_the_list_holds_twenty_items_and_the_database_refuses_a_twenty_first(): void
    {
        $me = $this->me();
        for ($i = 1; $i <= 20; $i++) {
            $this->add($me, "Item {$i}");
        }
        try {
            $this->add($me, 'Item 21');
            $this->fail('A 21st item must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }
        $this->assertSame(20, DB::table('my_routine_items')->where('user_id', $me->id)->count());
        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(QueryException::class);
            DB::table('my_routine_items')->insert(['public_id' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'x', 'position' => 21, 'created_at' => now()]);
        }
    }

    public function test_titles_are_encrypted_limited_and_refuse_personal_details(): void
    {
        $me = $this->me();
        $this->add($me, 'Review yesterday\'s Panel claims');
        $this->assertNotSame('Review yesterday\'s Panel claims', DB::table('my_routine_items')->value('title'));
        foreach (['', str_repeat('a', 121), 'Call 012-3456789', 'IC 900101-14-5678', 'mail me@example.test', '1234567890123'] as $bad) {
            try {
                $this->add($me, $bad);
                $this->fail("Refused: {$bad}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('title', $e->errors());
            }
        }
        $this->assertSame(1, DB::table('my_routine_items')->count());
    }

    public function test_a_retry_with_the_same_key_adds_one_item_and_a_stale_list_version_is_refused(): void
    {
        $me = $this->me();
        $key = (string) Str::uuid();
        $version = $this->version($me);
        $this->add($me, 'Once only', $version, $key);
        $this->add($me, 'Once only', $version, $key); // The retry arrives with the old version and still changes nothing.
        $this->assertSame(1, DB::table('my_routine_items')->count());
        try {
            $this->add($me, 'Late', $version);
            $this->fail('A stale version must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $this->add($me, 'Same key, other text', null, $key);
    }

    public function test_audit_holds_the_action_and_count_only_and_ticks_are_not_audited(): void
    {
        $me = $this->me();
        $this->add($me, 'Secret routine title');
        $id = $this->service()->page($me)['items'][0]['id'];
        $this->service()->tick($me, $id, true);
        $row = DB::table('audit_logs')->where('event', 'my_routine.changed')->sole();
        $this->assertSame(['action' => 'added', 'item_count' => 1], json_decode((string) $row->metadata, true));
        $this->assertStringNotContainsString('Secret routine title', (string) json_encode(DB::table('audit_logs')->get()));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'my_routine.changed')->count());
    }

    public function test_the_tv_account_is_refused_and_no_route_takes_a_user_id(): void
    {
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->actingAs($tv)->post(route('my-day.routine.store'), ['title' => 'x', 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'my-day.routine')) {
                $this->assertStringNotContainsString('user', $route->uri());
            }
        }
    }

    public function test_the_routes_work_end_to_end_and_the_page_carries_the_routine(): void
    {
        $me = $this->me();
        $this->actingAs($me)->post(route('my-day.routine.store'), ['title' => 'Check the fridge log', 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertRedirect(route('my-day'));
        $item = $this->service()->page($me)['items'][0]['id'];
        $this->put(route('my-day.routine.tick', $item), ['done' => true])->assertRedirect(route('my-day'));
        $this->get(route('my-day'))->assertInertia(fn ($page) => $page->where('routine.done', 1)->where('routine.total', 1)->where('routine.items.0.title', 'Check the fridge log'));
        $this->post(route('my-day.routine.hide', $item), ['lock_version' => $this->version($me)])->assertRedirect(route('my-day'));
        $this->post(route('my-day.routine.restore', 'not-a-uuid'), ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_purge_removes_old_ticks_long_hidden_items_and_deactivated_lists_only(): void
    {
        $me = $this->me();
        $this->add($me, 'Keep');
        $this->add($me, 'Old hidden');
        [$keep, $old] = array_column($this->service()->page($me)['items'], 'id');
        $this->service()->tick($me, $keep, true);
        $this->service()->hide($me, $old, ['lock_version' => $this->version($me)]);
        DB::table('my_routine_items')->where('public_id', $old)->update(['hidden_at' => now()->subDays(8)]);
        DB::table('my_routine_ticks')->insert(['item_id' => DB::table('my_routine_items')->where('public_id', $keep)->value('id'), 'user_id' => $me->id, 'done_on' => now()->subDays(40)->toDateString(), 'created_at' => now()]);

        $this->assertSame(2, $this->service()->purge());
        $this->assertSame(1, DB::table('my_routine_items')->count());
        $this->assertSame(1, DB::table('my_routine_ticks')->count());
    }
}
