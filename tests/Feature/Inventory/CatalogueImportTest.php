<?php

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Services\CatalogueImportRefused;
use App\Domain\Organisation\Inventory\Services\CatalogueImportService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/** IMP-1 (owner, 25 Nov 2026): the one-off catalogue and Self-pay price import, every row or none. Synthetic data only. */
final class CatalogueImportTest extends VisitTestCase
{
    private User $admin;

    private Branch $other;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->where('is_active', true)->orderBy('id')->firstOrFail();
        $this->admin = $this->staff('administrator');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function staff(string $role, bool $bothBranches = true): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        if ($bothBranches) {
            $profile = StaffProfile::query()->where('user_id', $actor->id)->firstOrFail();
            StaffBranchAssignmentBootstrapper::create($profile, $this->other, ['is_primary' => false]);
        }

        return $actor;
    }

    /** @param  list<string>  $rows */
    private function file(array $rows, string $header): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp1-');
        file_put_contents($path, "\xEF\xBB\xBF".$header."\r\n".implode("\r\n", $rows)."\r\n");
        $this->files[] = $path;

        return $path;
    }

    private function catalogue(array $rows): string
    {
        return $this->file($rows, implode(',', CatalogueImportService::CATALOGUE_COLUMNS));
    }

    private function prices(array $rows): string
    {
        return $this->file($rows, implode(',', CatalogueImportService::BRANCH_PRICE_COLUMNS));
    }

    private function row(string $code, string $selfPay = '12.50', string $controlled = '', string $panel = '', ?string $barcode = null, string $stock = 'tablet', string $dispensing = 'tablet'): string
    {
        return implode(',', [$code, 'Synthetic '.$code, 'synthetamol', '500 mg', 'Tablet', 'Analgesic', '', $barcode ?? '', 'box', '100', $stock, $dispensing, $controlled, $selfPay, $panel]);
    }

    private function import(string $catalogue, ?string $prices = null, bool $dryRun = false, ?User $actor = null): array
    {
        return app(CatalogueImportService::class)->run($actor ?? $this->admin, $this->branch, $catalogue, $prices, $dryRun);
    }

    /** @return list<string> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (CatalogueImportRefused $refused) {
            return $refused->problems;
        }
        $this->fail('The import was not refused.');
    }

    private function counts(): array
    {
        return [
            DB::table('medicine_catalogue_items')->count(), DB::table('inventory_skus')->count(), DB::table('price_entries')->count(),
            DB::table('price_books')->count(), DB::table('charge_definitions')->count(), DB::table('audit_logs')->where('event', 'inventory.catalogue_import.recorded')->count(),
        ];
    }

    private function price(string $code, string $scope): ?int
    {
        $value = DB::table('price_entries as e')->join('price_books as b', 'b.id', '=', 'e.price_book_id')->join('charge_definitions as c', 'c.id', '=', 'e.charge_definition_id')
            ->join('medicine_catalogue_items as m', 'm.id', '=', 'c.medicine_catalogue_item_id')->where('m.code', $code)->where('b.scope_key', $scope)->orderByDesc('e.version')->value('e.unit_price_sen');

        return $value === null ? null : (int) $value;
    }

    public function test_a_clean_import_creates_medicines_skus_hq_and_branch_self_pay_prices_and_one_audit_row(): void
    {
        $report = $this->import(
            $this->catalogue([$this->row('IMP-A', '12.50', 'yes', '', '9555000000017'), $this->row('IMP-B', '3.05'), $this->row('IMP-C', '')]),
            $this->prices(['IMP-A,'.$this->other->code.',13.00', 'IMP-B,'.$this->other->code.',3.10', 'IMP-A,'.$this->branch->code.',12.00']),
        );

        $this->assertSame(['medicines' => 3, 'hqPrices' => 2, 'branchPrices' => 3, 'controlled' => 1, 'dryRun' => false], $report);
        $this->assertSame(1250, $this->price('IMP-A', 'organisation'));
        $this->assertSame(305, $this->price('IMP-B', 'organisation'));
        $this->assertNull($this->price('IMP-C', 'organisation'));
        $this->assertSame(1300, $this->price('IMP-A', 'branch:'.$this->other->id));
        $this->assertSame(310, $this->price('IMP-B', 'branch:'.$this->other->id));
        $this->assertSame(1200, $this->price('IMP-A', 'branch:'.$this->branch->id));
        // No Panel price is written.
        $this->assertSame(0, DB::table('price_entries as e')->join('price_books as b', 'b.id', '=', 'e.price_book_id')->where('b.price_tier', '!=', 'self_pay')->count());

        $sku = DB::table('inventory_skus')->where('sku_code', 'IMP-A')->sole();
        $this->assertSame(['tablet', 'tablet', 'box', '9555000000017'], [$sku->stock_unit, $sku->dispensing_unit, $sku->purchase_unit, $sku->barcode]);
        $this->assertTrue((bool) DB::table('medicine_catalogue_items')->where('code', 'IMP-A')->value('is_controlled'));
        $this->assertFalse((bool) DB::table('medicine_catalogue_items')->where('code', 'IMP-B')->value('is_controlled'));
        // No stock moves: opening stock stays with the STK-35 import and its sign-off.
        $this->assertSame(0, DB::table('stock_movements')->count());

        $audit = DB::table('audit_logs')->where('event', 'inventory.catalogue_import.recorded')->sole();
        $this->assertSame(['medicines' => 3, 'hq_prices' => 2, 'branch_prices' => 3, 'controlled' => 1, 'dry_run' => false], json_decode((string) $audit->metadata, true));
        $this->assertSame($this->admin->id, (int) $audit->actor_user_id);
    }

    public function test_a_dry_run_reports_the_same_counts_and_writes_nothing(): void
    {
        $before = $this->counts();
        $catalogue = $this->catalogue([$this->row('IMP-D', '5.00', 'yes'), $this->row('IMP-E')]);
        $prices = $this->prices(['IMP-D,'.$this->other->code.',6.00']);

        $report = $this->import($catalogue, $prices, true);

        $this->assertSame(['medicines' => 2, 'hqPrices' => 2, 'branchPrices' => 1, 'controlled' => 1, 'dryRun' => true], $report);
        $this->assertSame($before, $this->counts());
        // The same files then import for real.
        $this->assertSame(2, $this->import($catalogue, $prices)['medicines']);
    }

    public function test_one_bad_row_refuses_the_whole_file_with_every_problem_and_nothing_is_written(): void
    {
        $before = $this->counts();
        $problems = $this->refusal(fn () => $this->import(
            $this->catalogue([
                $this->row('IMP-OK'),
                $this->row('IMP-BAD1', '12.505'),
                $this->row('IMP-BAD2', '1.00', 'maybe'),
                $this->row('IMP-BAD3', '1.00', '', '', null, 'box', 'tablet'),
                $this->row('IMP-OK'),
                $this->row('IMP-PANEL', '1.00', '', '9.00'),
            ]),
            $this->prices(['IMP-OK,NOPE,1.00', 'IMP-GHOST,'.$this->other->code.',1.00', 'IMP-OK,'.$this->other->code.',']),
        ));

        $joined = implode("\n", $problems);
        foreach ([
            'catalogue line 3: hq_self_pay_rm must be ringgit',
            'catalogue line 4: controlled must be yes, no or blank.',
            'catalogue line 5: stock_unit and dispensing_unit must be the same unit',
            'catalogue line 6: code IMP-OK is also on line 2.',
            'catalogue line 7: hq_panel_rm must be blank.',
            'branch prices line 2: branch_code NOPE is not an active branch',
            'branch prices line 3: code IMP-GHOST is not in the catalogue file.',
            'branch prices line 4: self_pay_rm is required.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $joined);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_a_code_sku_or_barcode_already_in_kpone_is_refused_so_a_second_run_changes_nothing(): void
    {
        $catalogue = $this->catalogue([$this->row('IMP-F', '2.00', '', '', '9555000000024')]);
        $this->import($catalogue);
        $before = $this->counts();

        $problems = $this->refusal(fn () => $this->import($catalogue));

        $this->assertContains('catalogue line 2: medicine code IMP-F already exists in KPOne.', $problems);
        $this->assertContains('catalogue line 2: SKU code IMP-F already exists in KPOne.', $problems);
        $this->assertContains('catalogue line 2: barcode 9555000000024 already belongs to another SKU.', $problems);
        $this->assertSame($before, $this->counts());

        // A branch price for a medicine already in KPOne is refused: live prices change in Pricing, not here.
        $problems = $this->refusal(fn () => $this->import($this->catalogue([$this->row('IMP-G')]), $this->prices(['IMP-F,'.$this->other->code.',2.20'])));
        $this->assertStringContainsString('branch prices line 2: code IMP-F is not in the catalogue file.', $problems[0]);

        // An inventory item that already has the code would be silently reused, so it is refused too.
        $item = DB::table('inventory_items')->where('code', 'IMP-F')->sole();
        DB::table('inventory_items')->where('id', $item->id)->update(['code' => 'IMP-K']);
        DB::table('inventory_skus')->where('inventory_item_id', $item->id)->update(['sku_code' => 'IMP-K-OLD']);
        $problems = $this->refusal(fn () => $this->import($this->catalogue([$this->row('IMP-K')])));
        $this->assertSame(['catalogue line 2: an inventory item with code IMP-K already exists in KPOne.'], $problems);
        $this->assertSame($before, $this->counts());
    }

    public function test_branch_problems_are_listed_before_any_write(): void
    {
        $before = $this->counts();
        $catalogue = $this->catalogue([$this->row('IMP-L'), $this->row('IMP-M')]);

        // The actor must be assigned to every branch in the branch file, and to the home branch.
        $homeOnly = $this->staff('administrator', false);
        $problems = $this->refusal(fn () => $this->import($catalogue, $this->prices(['IMP-L,'.$this->other->code.',1.00', 'IMP-M,'.$this->other->code.',1.00']), false, $homeOnly));
        $this->assertSame(["branch prices line 2: the actor is not assigned to branch {$this->other->code}, so cannot publish its prices."], $problems);

        $outsider = (new GovernedStockFixture($this->other))->actor('administrator');
        $problems = $this->refusal(fn () => $this->import($catalogue, null, false, $outsider));
        $this->assertSame(["The actor is not assigned to the home branch {$this->branch->code}."], $problems);

        // An inactive branch book is never switched back on by the import.
        DB::table('price_books')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->other->id, 'scope_key' => 'branch:'.$this->other->id,
            'name' => 'Synthetic inactive book', 'currency' => 'MYR', 'price_tier' => 'self_pay', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->counts();
        $problems = $this->refusal(fn () => $this->import($catalogue, $this->prices(['IMP-L,'.$this->other->code.',1.00', 'IMP-L,'.$this->other->code.',2.00'])));
        $this->assertSame([
            "branch prices line 2: the branch price book for {$this->other->code} is inactive; reactivate it in Pricing first.",
            'branch prices line 3: this code and branch are also on line 2.',
        ], $problems);
        $this->assertSame($before, $this->counts());
    }

    public function test_the_file_itself_is_checked_before_any_row(): void
    {
        $header = implode(',', CatalogueImportService::CATALOGUE_COLUMNS);
        $cases = [
            'The catalogue file must be UTF-8.' => "{$header}\r\n".$this->row('IMP-N')."\xFF\r\n",
            'catalogue line 2: expected 15 columns, found 2.' => "{$header}\r\nIMP-N,Synthetic\r\n",
            'catalogue line 2: use plain text only (no control characters or < >).' => "{$header}\r\n".str_replace('Synthetic IMP-N', '<b>Synthetic</b>', $this->row('IMP-N'))."\r\n",
            'catalogue line 3: use plain text only (no control characters or < >).' => "{$header}\r\n".$this->row('IMP-N')."\r\n".str_replace('Synthetic IMP-O', "\"Synthetic\tIMP-O\"", $this->row('IMP-O'))."\r\n",
            'The catalogue file has no rows.' => "{$header}\r\n\r\n",
            'The catalogue file has more than 5000 rows.' => "{$header}\r\n".implode("\r\n", array_map(fn (int $i): string => $this->row('IMP-R'.$i), range(1, 5001)))."\r\n",
            'The catalogue file is larger than 5 MB.' => "{$header}\r\n".str_repeat(' ', CatalogueImportService::MAX_BYTES),
        ];
        $before = $this->counts();
        foreach ($cases as $expected => $content) {
            $path = tempnam(sys_get_temp_dir(), 'imp1-');
            file_put_contents($path, $content);
            $this->files[] = $path;
            $problems = $this->refusal(fn () => $this->import($path));
            $this->assertContains($expected, $problems, $expected);
        }
        $this->assertSame($before, $this->counts());

        // A BOM and a plain (no BOM) file both read the same.
        $plain = tempnam(sys_get_temp_dir(), 'imp1-');
        file_put_contents($plain, "{$header}\n".$this->row('IMP-P')."\n");
        $this->files[] = $plain;
        $this->assertSame(1, $this->import($plain)['medicines']);
    }

    public function test_the_actor_must_hold_all_four_permissions_and_a_wrong_header_is_refused(): void
    {
        $catalogue = $this->catalogue([$this->row('IMP-H')]);
        $before = $this->counts();

        // The Director has no prices.publish, so cannot run the import.
        $problems = $this->refusal(fn () => $this->import($catalogue, null, false, $this->staff('director')));
        $this->assertStringContainsString('prices.publish.organisation', $problems[0]);
        $problems = $this->refusal(fn () => $this->import($catalogue, null, false, $this->staff('ca')));
        $this->assertStringContainsString('The actor lacks', $problems[0]);

        $wrong = $this->file(['IMP-H,Synthetic'], 'code,name');
        $problems = $this->refusal(fn () => $this->import($wrong));
        $this->assertStringStartsWith('The first row of the catalogue file must be exactly: code,display_name', $problems[0]);

        $this->assertSame($before, $this->counts());
        // A CA Supervisor holds all four and may run it.
        $this->assertSame(1, $this->import($catalogue, null, false, $this->staff('ca_supervisor'))['medicines']);
    }

    public function test_the_console_command_prints_counts_or_problems(): void
    {
        $catalogue = $this->catalogue([$this->row('IMP-J')]);

        $this->artisan('inventory:import-catalogue', ['catalogue' => $catalogue, '--actor' => $this->admin->email, '--branch' => $this->branch->code, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('medicine_catalogue_items')->where('code', 'IMP-J')->count());

        $this->artisan('inventory:import-catalogue', ['catalogue' => $catalogue, '--actor' => $this->admin->email, '--branch' => $this->branch->code])
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('medicine_catalogue_items')->where('code', 'IMP-J')->count());

        $this->artisan('inventory:import-catalogue', ['catalogue' => $catalogue, '--actor' => $this->admin->email, '--branch' => $this->branch->code])
            ->expectsOutputToContain('already exists in KPOne')
            ->assertExitCode(1);
        $this->assertSame(1, DB::table('medicine_catalogue_items')->where('code', 'IMP-J')->count());
    }

    public function test_the_command_reports_a_domain_refusal_without_a_stack_trace(): void
    {
        $catalogue = $this->catalogue([$this->row('IMP-Q')]);
        $args = ['catalogue' => $catalogue, '--actor' => $this->admin->email, '--branch' => $this->branch->code];

        $this->app->instance(CatalogueImportService::class, new class extends CatalogueImportService
        {
            public function __construct() {}

            public function run(User $actor, Branch $homeBranch, string $cataloguePath, ?string $branchPricesPath, bool $dryRun): array
            {
                throw ValidationException::withMessages(['code' => 'A synthetic domain rule refused this.']);
            }
        });
        $this->artisan('inventory:import-catalogue', $args)
            ->expectsOutputToContain('A service refused the import. Nothing was saved.')
            ->expectsOutputToContain('A synthetic domain rule refused this.')
            ->assertExitCode(1);

        $this->app->instance(CatalogueImportService::class, new class extends CatalogueImportService
        {
            public function __construct() {}

            public function run(User $actor, Branch $homeBranch, string $cataloguePath, ?string $branchPricesPath, bool $dryRun): array
            {
                abort(403, 'Synthetic refusal.');
            }
        });
        $this->artisan('inventory:import-catalogue', $args)
            ->expectsOutputToContain('The import was refused (403): Synthetic refusal. Nothing was saved.')
            ->assertExitCode(1);
        $this->assertSame(0, DB::table('medicine_catalogue_items')->where('code', 'IMP-Q')->count());
    }
}
