<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-7 step 1 (owner decisions 2026-11-05, TEAM.md decision 20): a CA or CA Supervisor records medicine a patient
 * brought back on a completed case. Recording moves no stock and is append-only. Synthetic data only.
 */
class DispensaryReturnRecordTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array{0: array<string, mixed>, 1: DispensaryCase, 2: string} */
    private function completed(string $quantity = '3.000'): array
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, $quantity));
        $this->confirmAllergy($f, $case->refresh());
        $case = $this->complete($f, $case);
        $this->actingAs($f['ca']);

        return [$f, $case->refresh(), $case->handoffs()->sole()->items()->sole()->public_id];
    }

    /** @return array<string, mixed> */
    private function payload(array $f, array $override = []): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 0, 'quantity' => '1', 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date', ...$override];
    }

    /** @return array<string, int> */
    private function stockSnapshot(): array
    {
        return ['movements' => DB::table('stock_movements')->count(), 'balances' => (int) DB::table('inventory_stock_balances')->sum(DB::raw('quantity * 1000'))];
    }

    public function test_a_ca_records_a_return_which_moves_no_stock_and_shows_on_the_completed_page(): void
    {
        [$f, $case, $item] = $this->completed();
        $before = $this->stockSnapshot();
        $url = route('dispensary.returns.record', [$case, $item]);

        $this->post($url, $this->payload($f, ['quantity' => '1.5', 'reason' => 'other', 'note' => 'Synthetic extra strip', 'condition' => 'opened_or_damaged']))
            ->assertRedirect(route('dispensary.show', $case))->assertSessionHasNoErrors();

        $return = DispensaryReturn::query()->sole();
        $this->assertSame(['1.500', 'other', 'Synthetic extra strip', 'opened_or_damaged', $f['ca']->id, $case->id], [$return->quantity, $return->reason, $return->note_encrypted, $return->condition, $return->recorded_by_user_id, $return->dispensary_case_id]);
        $this->assertNotSame('Synthetic extra strip', DB::table('dispensary_returns')->value('note_encrypted'));
        $this->assertSame($before, $this->stockSnapshot());
        $audit = AuditLog::query()->where('event', 'dispensary.return_recorded')->sole();
        $this->assertSame(['return_id', 'dispensary_case_id', 'dispensary_item_id', 'reason', 'condition', 'quantity'], array_keys($audit->metadata));
        $this->assertStringNotContainsString('Synthetic', json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $this->get(route('dispensary.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Completed')
            ->where('returns.canRecord', true)
            ->where('returns.lines.0.publicId', $item)
            ->where('returns.lines.0.dispensed', '3')
            ->where('returns.lines.0.returned', '1.5')
            ->where('returns.lines.0.returnable', '1.5')
            ->where('returns.lines.0.returnCount', 1)
            ->where('returns.lines.0.returns.0.status', 'waiting_check')
            ->where('returns.lines.0.returns.0.recordedByMe', true)
            ->where('returns.lines.0.returns.0.note', 'Synthetic extra strip'));
    }

    public function test_bad_or_stale_returns_are_refused_and_save_nothing(): void
    {
        [$f, $case, $item] = $this->completed();
        $url = route('dispensary.returns.record', [$case, $item]);
        $this->from(route('dispensary.show', $case));

        $this->post($url, $this->payload($f, ['quantity' => '3.001']))->assertSessionHasErrors('quantity');
        $this->post($url, $this->payload($f, ['quantity' => '0']))->assertSessionHasErrors('quantity');
        $this->post($url, $this->payload($f, ['quantity' => '1.0005']))->assertSessionHasErrors('quantity');
        $this->post($url, $this->payload($f, ['reason' => 'other', 'note' => '  ']))->assertSessionHasErrors('note');
        $this->post($url, $this->payload($f, ['reason' => 'not_used', 'note' => 'Not allowed here']))->assertSessionHasErrors('note');
        $this->post($url, $this->payload($f, ['note' => null, 'reason' => 'other', 'quantity' => '1', 'condition' => 'sealed_in_date']))->assertSessionHasErrors('note');
        $this->post($url, $this->payload($f, ['reason' => 'unknown']))->assertSessionHasErrors('reason');
        $this->post($url, $this->payload($f, ['condition' => 'unknown']))->assertSessionHasErrors('condition');
        $this->post($url, $this->payload($f, ['expected_return_count' => 1]))->assertSessionHasErrors('expected_return_count');
        $this->assertSame(0, DispensaryReturn::query()->count());

        $this->post($url, $this->payload($f, ['quantity' => '3']))->assertSessionHasNoErrors();
        $this->post($url, $this->payload($f, ['expected_return_count' => 1, 'quantity' => '0.001']))->assertSessionHasErrors('quantity');
        $this->assertSame(1, DispensaryReturn::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.return_recorded')->count());
    }

    public function test_a_case_still_being_dispensed_cannot_take_a_return(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $item = $case->handoffs()->sole()->items()->sole()->public_id;

        $this->expectException(ValidationException::class);
        try {
            app(DispensaryReturnService::class)->record($f['ca'], $case->refresh(), $item, $this->payload($f));
        } finally {
            $this->assertSame(0, DispensaryReturn::query()->count());
        }
    }

    public function test_a_retry_replays_once_and_a_reused_key_with_other_details_is_refused(): void
    {
        [$f, $case, $item] = $this->completed();
        $payload = $this->payload($f);
        $service = app(DispensaryReturnService::class);

        $first = $service->record($f['ca'], $case, $item, $payload);
        $again = $service->record($f['ca'], $case, $item, $payload);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, DispensaryReturn::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.return_recorded')->count());

        try {
            $service->record($f['ca'], $case, $item, [...$payload, 'quantity' => '2']);
            $this->fail('A reused retry key with other details must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retry_key', $e->errors());
        }
        $this->assertSame(1, DispensaryReturn::query()->count());
    }

    public function test_only_ca_and_ca_supervisor_at_the_case_branch_may_record(): void
    {
        [$f, $case, $item] = $this->completed();
        $url = route('dispensary.returns.record', [$case, $item]);
        foreach (['finance_officer', 'director', 'resident_doctor', 'technical_admin'] as $role) {
            $this->selectBranch($actor = $this->actor($role), $f['visit']->branch);
            $this->actingAs($actor)->post($url, $this->payload($f))->assertForbidden();
        }
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        $this->actingAs($supervisor)->post($url, $this->payload($f))->assertSessionHasNoErrors();
        $this->assertSame([$supervisor->id], DispensaryReturn::query()->pluck('recorded_by_user_id')->all());

        $other = Branch::query()->where('organisation_id', $case->organisation_id)->whereKeyNot($case->branch_id)->firstOrFail();
        $elsewhere = $this->actor('ca', $other);
        $this->selectBranch($elsewhere, $other);
        $this->actingAs($elsewhere)->post($url, $this->payload($f, ['expected_branch_id' => $other->id, 'expected_return_count' => 1]))->assertNotFound();
        $this->assertSame(1, DispensaryReturn::query()->count());
    }

    public function test_returns_are_append_only_in_the_model_and_the_database(): void
    {
        [$f, $case, $item] = $this->completed();
        $return = app(DispensaryReturnService::class)->record($f['ca'], $case, $item, $this->payload($f));

        foreach ([fn () => $return->forceFill(['quantity' => '2.000'])->save(), fn () => $return->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('A dispensary return must not change.');
            } catch (LogicException) {
            }
        }
        foreach (['UPDATE dispensary_returns SET quantity = 2', 'DELETE FROM dispensary_returns', 'TRUNCATE dispensary_returns CASCADE'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL must refuse: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        // The trigger repeats the quantity rule: 1 already returned of 3, so 2.001 more is refused.
        $row = (array) DB::table('dispensary_returns')->first();
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('dispensary_returns')->insert([...$row, 'public_id' => (string) Str::uuid(), 'retry_key' => (string) Str::uuid(), 'quantity' => '2.001']));
            $this->fail('PostgreSQL must refuse a return above the returnable quantity.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('exceeds the quantity still returnable', $e->getMessage());
        }
        $this->assertSame(1, DispensaryReturn::query()->count());
    }
}
