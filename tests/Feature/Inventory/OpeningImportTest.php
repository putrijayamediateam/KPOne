<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\OpeningImportService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-35 (approved by B.E.R.N.I.S.S for the owner): opening stock from one CSV, every row or none. Synthetic data only. */
final class OpeningImportTest extends VisitTestCase
{
    /** @var array<string, mixed> */
    private array $f;

    private User $supervisor;

    private string $expiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        // One SKU and location with an existing batch and opening (BATCH = the fixture code).
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->supervisor = $this->staff('ca_supervisor');
        $this->expiry = now($this->branch->timezone)->addYear()->toDateString();
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** @param  list<string>  $rows */
    private function csv(array $rows, ?string $header = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('opening.csv', "\xEF\xBB\xBF".($header ?? implode(',', OpeningImportService::COLUMNS))."\r\n".implode("\r\n", $rows)."\r\n");
    }

    private function row(string $batch, string $quantity = '5', string $cost = '', ?string $expiry = null, ?string $location = null, ?string $sku = null): string
    {
        return implode(',', [$location ?? $this->f['location']->code, $sku ?? $this->f['sku']->sku_code, $batch, $expiry ?? $this->expiry, $quantity, $cost]);
    }

    /** @return array{hash: string, rows: list<array<string, mixed>>, errors: int} */
    private function check(UploadedFile $file, ?User $actor = null): array
    {
        return app(OpeningImportService::class)->check($actor ?? $this->supervisor, $this->branch->id, $file);
    }

    private function record(UploadedFile $file, string $hash, ?string $key = null): array
    {
        return app(OpeningImportService::class)->record($this->supervisor, ['expected_branch_id' => $this->branch->id, 'idempotency_key' => $key ?? (string) Str::uuid(), 'file_hash' => $hash], $file);
    }

    private function counts(): array
    {
        return [DB::table('inventory_batches')->count(), DB::table('stock_movements')->where('movement_type', 'opening_balance')->count(), DB::table('inventory_opening_imports')->count()];
    }

    public function test_a_clean_file_is_checked_then_recorded_and_each_opening_still_needs_sign_off_by_someone_else(): void
    {
        $file = $this->csv([$this->row('NEW-A', '12.5', '0.25'), $this->row('NEW-B', '3'), $this->row('NEW-C', '1', '', null, $this->f['location']->code)]);
        $before = $this->counts();
        $review = $this->check($file);
        $this->assertSame(0, $review['errors']);
        $this->assertSame([true, true, true], array_column($review['rows'], 'new_batch'));
        $this->assertSame(['12.500', '3.000', '1.000'], array_column($review['rows'], 'quantity'));
        $this->assertSame([25, null, null], array_column($review['rows'], 'unit_cost_sen'));
        // Checking writes nothing.
        $this->assertSame($before, $this->counts());

        $result = $this->record($file, $review['hash']);
        $this->assertSame([3, false], [$result['recorded'], $result['replayed']]);
        $this->assertSame([$before[0] + 3, $before[1] + 3, 1], $this->counts());
        $this->assertSame('12.500', (string) DB::table('stock_movements as m')->join('inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')
            ->where('b.batch_number', 'NEW-A')->where('m.movement_type', 'opening_balance')->value('m.quantity'));
        $this->assertSame(25, (int) DB::table('stock_movements as m')->join('inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')->where('b.batch_number', 'NEW-A')->value('m.unit_cost_sen'));
        // The file is never stored: only its hash.
        $this->assertSame($review['hash'], DB::table('inventory_opening_imports')->value('file_hash'));
        $audit = DB::table('audit_logs')->where('event', 'inventory.opening_import.recorded')->sole();
        $this->assertSame(['import_id' => $result['import_id'], 'row_count' => 3, 'new_batch_count' => 3], json_decode((string) $audit->metadata, true));

        // STK-1 sign-off is unchanged: each opening waits, and the importer cannot sign their own.
        $opening = DB::table('stock_movements as m')->join('inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')->where('b.batch_number', 'NEW-B')->value('m.public_id');
        $this->assertSame(0, DB::table('inventory_opening_verifications')->count());
        try {
            app(InventoryReadinessService::class)->verify($this->supervisor, 'opening', $opening, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid()]);
            $this->fail('The importer signed off their own opening.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_every_problem_is_shown_on_its_row_and_nothing_is_recorded(): void
    {
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        $location = fn (Branch $branch, string $type = 'dispensary', bool $active = true) => tap(new InventoryLocation, fn (InventoryLocation $l) => $l->forceFill(['public_id' => (string) Str::uuid(),
            'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'code' => 'OI-'.Str::upper(Str::random(6)), 'name' => 'Synthetic import location', 'type' => $type, 'is_active' => $active])->save());
        $elsewhere = $location($other);
        $returns = $location($this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $existingExpiry = DB::table('inventory_batches')->where('id', $this->f['batch']->id)->value('expiry_date');

        $rows = [
            $this->row('OK-1'),
            $this->row('X1', '5', '', null, 'NO-SUCH-LOCATION'),
            $this->row('X2', '5', '', null, $elsewhere->code),
            $this->row('X3', '5', '', null, $returns->code),
            $this->row('X4', '5', '', null, null, 'NO-SUCH-SKU'),
            $this->row('X5', '5', '', '31/12/2027'),
            $this->row('X6', '5', '', now($this->branch->timezone)->toDateString()),
            $this->row('X7', '0'),
            $this->row('X8', '5', '1.234'),
            $this->row($this->f['batch']->batch_number, '5', '', now($this->branch->timezone)->addYears(2)->toDateString()),
            $this->row($this->f['batch']->batch_number, '5', '', substr((string) $existingExpiry, 0, 10)),
            $this->row('OK-1'),
        ];
        $before = $this->counts();
        $review = $this->check($this->csv($rows));
        $problems = array_column($review['rows'], 'problems');
        $this->assertSame([], $problems[0]);
        $this->assertSame(['No active location with this code at this branch.'], $problems[1]);
        $this->assertSame(['No active location with this code at this branch.'], $problems[2]);
        $this->assertSame(['Opening stock never goes into a patient returns location.'], $problems[3]);
        $this->assertSame(['No active SKU with this code.'], $problems[4]);
        $this->assertSame(['Enter the expiry date as YYYY-MM-DD.'], $problems[5]);
        $this->assertSame(['The expiry date must be after today.'], $problems[6]);
        $this->assertSame(['Enter a quantity above 0, with up to 3 decimals.'], $problems[7]);
        $this->assertSame(['Enter the unit cost in RM with up to 2 decimals, or leave it empty.'], $problems[8]);
        $this->assertStringStartsWith('This batch already exists with expiry ', $problems[9][0]);
        $this->assertSame(['An opening is already recorded for this location, SKU and batch.'], $problems[10]);
        $this->assertSame(['The same location, SKU and batch are already on row 2.'], $problems[11]);
        $this->assertSame(11, $review['errors']);

        try {
            $this->record($this->csv($rows), $review['hash']);
            $this->fail('A file with problems was recorded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_the_file_itself_is_refused_when_it_is_not_a_plain_small_csv(): void
    {
        $refused = function (UploadedFile $file, string $message): void {
            try {
                $this->check($file);
                $this->fail('Accepted: '.$message);
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->errors()['file'][0]);
            }
        };
        $refused($this->csv([$this->row('A')], 'sku,location,batch'), 'The first row must be');
        $refused($this->csv([implode(',', [$this->f['location']->code, '=HYPERLINK("x")', 'A', $this->expiry, '1', ''])]), 'holds a formula');
        $refused($this->csv(array_fill(0, OpeningImportService::MAX_ROWS + 1, $this->row('A'))), 'more than 500 rows');
        $refused(UploadedFile::fake()->createWithContent('opening.csv', "location_code\0,x"), 'not a UTF-8 CSV');
        $refused($this->csv([]), 'no rows under the header');
        // Five columns (no unit_cost) is accepted.
        $short = $this->csv([implode(',', [$this->f['location']->code, $this->f['sku']->sku_code, 'FIVE', $this->expiry, '2'])], implode(',', array_slice(OpeningImportService::COLUMNS, 0, 5)));
        $this->assertSame(0, $this->check($short)['errors']);
    }

    public function test_a_repeat_replays_and_a_changed_file_or_reused_key_is_refused(): void
    {
        $file = $this->csv([$this->row('REPLAY')]);
        $review = $this->check($file);
        $key = (string) Str::uuid();
        $first = $this->record($file, $review['hash'], $key);
        $after = $this->counts();
        $again = $this->record($file, $review['hash'], $key);
        $this->assertSame([$first['import_id'], true], [$again['import_id'], $again['replayed']]);
        $this->assertSame($after, $this->counts());

        // The same key with a different file is refused, and so is a file that changed since it was checked.
        $other = $this->csv([$this->row('OTHER')]);
        $this->expectRefusal(fn () => $this->record($other, $this->check($other)['hash'], $key), 'idempotency_key');
        $this->expectRefusal(fn () => $this->record($other, $review['hash']), 'file');
        $this->assertSame($after, $this->counts());
    }

    public function test_only_a_ca_supervisor_at_the_active_branch_imports(): void
    {
        $file = $this->csv([$this->row('ROLE')]);
        $this->actingAs($this->staff('ca'))->get(route('inventory.opening-import'))->assertForbidden();
        $this->actingAs($this->staff('finance_officer'))->post(route('inventory.opening-import.check'), ['expected_branch_id' => $this->branch->id, 'file' => $file])->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('inventory.opening-import'))->assertOk();
        $this->actingAs($this->supervisor)->get(route('inventory.opening-import.template'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->expectRefusal(fn () => app(OpeningImportService::class)->check($this->supervisor, $this->branch->id + 999, $file), 'expected_branch_id');

        // Over HTTP: the check returns rows without internal ids, and recording lands on Pilot readiness.
        $json = $this->actingAs($this->supervisor)->postJson(route('inventory.opening-import.check'), ['expected_branch_id' => $this->branch->id, 'file' => $file])->assertOk()->json();
        $this->assertArrayNotHasKey('sku_id', $json['rows'][0]);
        $this->assertArrayNotHasKey('batch_id', $json['rows'][0]);
        $this->actingAs($this->supervisor)->post(route('inventory.opening-import.store'), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'file_hash' => $json['hash'], 'file' => $file])
            ->assertRedirect(route('inventory.readiness'));
        $this->assertSame(1, DB::table('inventory_opening_imports')->count());
    }

    private function expectRefusal(callable $call, string $key): void
    {
        try {
            $call();
            $this->fail('Expected a refusal on '.$key);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
