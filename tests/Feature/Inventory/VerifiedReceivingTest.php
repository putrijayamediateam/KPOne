<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Services\InventoryBatchEligibility;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Inventory\Services\ReceivingReviewService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\Support\VerifiedReceivingFixture;

class VerifiedReceivingTest extends VisitTestCase
{
    private function snapshotExcept(array $allowed): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (in_array($table->tablename, $allowed, true)) {
                continue;
            }
            $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $table->tablename]), 'column_name');
            $q = DB::table($table->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $q->orderBy($column);
            }
            $out[$table->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    private function fixture(): array
    {
        return (new VerifiedReceivingFixture($this->branch))->build();
    }

    private function lot(string $quantity, string $outcome = 'accepted', ?string $reason = null, string $batch = 'SYN-LOT'): array
    {
        return ['quantity' => $quantity, 'batch_number' => $batch, 'expiry_date' => '2028-12-31', 'outcome' => $outcome, 'reason' => $reason];
    }

    private function rich(array $f, array $lots, string $physical): array
    {
        return [...$f['payload'], 'lines' => [['line_public_id' => $f['order']->lines()->sole()->public_id, 'physical_quantity' => $physical, 'lots' => $lots]]];
    }

    private function approved(array $f, array $payload): array
    {
        $this->selectBranch($f['receiver']);
        $id = app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], $payload);
        $review = DB::table('inventory_receiving_reviews')->find($id);
        $this->selectBranch($f['director']);
        app(ReceivingReviewService::class)->approve($f['director'], $review->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        $this->selectBranch($f['receiver']);

        return [...$payload, 'review_public_id' => $review->public_id, 'review_lock_version' => 2];
    }

    private function invalid(callable $action, string $field = 'lines'): void
    {
        try {
            $action();
            $this->fail('Invalid receiving data accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_exact_approval_holders_every_role_receive_and_approve_and_no_implied_release(): void
    {
        $this->assertSame(['ca_supervisor', 'director', 'finance_officer'], collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(ReceivingReviewService::APPROVE, $p, true))->keys()->sort()->values()->all());
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $f = $this->fixture();
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            try {
                app(ProcurementService::class)->receive($actor, $f['order'], $f['payload']);
                $this->assertSame('ca_supervisor', $role);
            } catch (AuthorizationException) {
                $this->assertNotSame('ca_supervisor', $role);
            }
            $f = $this->fixture();
            $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'damaged')], '2.000');
            $this->selectBranch($f['receiver']);
            $id = app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], $p);
            $r = DB::table('inventory_receiving_reviews')->find($id);
            $this->selectBranch($actor);
            try {
                app(ReceivingReviewService::class)->approve($actor, $r->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
                $this->assertContains($role, ['ca_supervisor', 'finance_officer', 'director']);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, ['ca_supervisor', 'finance_officer', 'director']);
            }
            if ($role === 'ca_supervisor') {
                $this->assertFalse($actor->can(InventoryBatchHoldService::RELEASE));
            }
        }
    }

    public function test_partial_split_lots_exact_conservation_and_idempotent_retry(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $p = $this->rich($f, [$this->lot('1.001', batch: 'SYN-A'), $this->lot('2.002', batch: 'SYN-B')], '3.003');
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p);
        $this->assertSame($receipt->id, app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p)->id);
        $this->assertSame('3.003', $f['order']->lines()->sole()->received_quantity);
        $this->assertSame('partially_received', $f['order']->refresh()->status);
        $line = DB::table('inventory_receipt_line_evidence')->where('goods_receipt_id', $receipt->id)->sole();
        $this->assertSame('3.003', $line->physical_quantity);
        $this->assertSame('3.003', $line->accepted_quantity);
        $this->assertSame('0.000', $line->rejected_quantity);
        $this->assertSame('0.000', $line->quarantined_quantity);
        $this->assertSame(2, DB::table('inventory_receipt_lots')->where('line_evidence_id', $line->id)->count());
        $this->assertSame(2, DB::table('stock_movements')->where('reference_public_id', $receipt->public_id)->count());
        $this->assertSame(0, $receipt->lines()->count()); // Never fabricate one legacy batch for a multi-lot total.
        $this->invalid(fn () => app(ProcurementService::class)->receive($f['receiver'], $f['order'], [...$p, 'delivery_reference' => 'SYN-CHANGED']), 'idempotency_key');
        $mismatch = $this->rich($f, [$this->lot('1.001')], '1.002');
        $this->invalid(fn () => app(ProcurementService::class)->receive($f['receiver'], $f['order'], $mismatch));
    }

    public function test_overage_quarantine_rejection_require_independent_approval_and_only_stock_in_posts(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('8.000', batch: 'SYN-ACCEPT'), $this->lot('3.000', 'quarantined', 'damaged', 'SYN-QUAR'), $this->lot('1.000', 'rejected', 'wrong_item', 'SYN-WRONG')], '12.000');
        $this->selectBranch($f['receiver']);
        $this->invalid(fn () => app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p));
        $this->assertDatabaseCount('inventory_goods_receipts', 0);
        $p = $this->approved($f, $p);
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p);
        $line = DB::table('inventory_receipt_line_evidence')->where('goods_receipt_id', $receipt->id)->sole();
        $this->assertSame('12.000', $line->physical_quantity);
        $this->assertSame('8.000', $line->accepted_quantity);
        $this->assertSame('3.000', $line->quarantined_quantity);
        $this->assertSame('1.000', $line->rejected_quantity);
        $this->assertSame('10.000', $line->applied_order_quantity);
        $this->assertSame('1.000', $line->overage_quantity);
        $this->assertSame('10.000', $f['order']->lines()->sole()->received_quantity);
        $this->assertSame('fully_received', $f['order']->refresh()->status);
        $this->assertSame(2, DB::table('stock_movements')->where('reference_public_id', $receipt->public_id)->count());
        $batch = DB::table('inventory_batches')->where('batch_number', 'SYN-QUAR')->sole();
        $this->invalid(fn () => app(InventoryBatchEligibility::class)->assertEligible($f['receiver']->organisation_id, $this->branch, $batch->id, 'Synthetic medicine'), 'allocations');
        $this->assertFalse(DB::table('inventory_batches')->where('batch_number', 'SYN-WRONG')->exists());
        $this->selectBranch($f['finance']);
        app(InventoryBatchHoldService::class)->act($f['finance'], $batch->public_id, 'release', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'reason' => 'inspection_passed']);
        app(InventoryBatchEligibility::class)->assertEligible($f['finance']->organisation_id, $this->branch, $batch->id, 'Synthetic medicine');
        $this->assertSame('3.000', DB::table('inventory_stock_balances')->where('inventory_batch_id', $batch->id)->value('quantity'));
    }

    public function test_fresh_revocation_self_approval_ended_assignment_stale_and_retry_conflicts(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'documentation_missing')], '2.000');
        $this->selectBranch($f['receiver']);
        $service = app(ReceivingReviewService::class);
        $id = $service->request($f['receiver'], $f['order'], $p);
        $this->assertSame($id, $service->request($f['receiver'], $f['order'], $p));
        $this->invalid(fn () => $service->request($f['receiver'], $f['order'], [...$p, 'delivery_reference' => 'SYN-CONFLICT']), 'retry_key');
        $r = DB::table('inventory_receiving_reviews')->find($id);
        $command = ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()];
        try {
            $service->approve($f['receiver'], $r->public_id, $command);
            $this->fail('Self approval');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->selectBranch($f['director']);
        $this->invalid(fn () => $service->approve($f['director'], $r->public_id, [...$command, 'lock_version' => 2]), 'lock_version');
        $approval = $service->approve($f['director'], $r->public_id, $command);
        $this->assertSame($approval, $service->approve($f['director'], $r->public_id, $command));
        $f['director']->removeRole('director');
        try {
            $service->approve($f['director'], $r->public_id, $command);
            $this->fail('Revoked approval');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        StaffBranchAssignment::query()->where('staff_profile_id', $f['receiver']->staffProfile->id)->update(['valid_from' => '2020-01-01', 'valid_until' => '2020-01-02']);
        $this->selectBranch($f['receiver']);
        try {
            $service->request($f['receiver'], $f['order'], $p);
            $this->fail('Ended assignment');
        } catch (AuthorizationException|HttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_real_expiry_required_tracking_off_refused_and_midnight_today_past_stay_strict(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $service = app(ProcurementService::class);
        $f['sku']->forceFill(['expiry_tracking_required' => false])->save();
        try {
            $service->receive($f['receiver'], $f['order'], $f['payload']);
            $this->fail('Tracking disabled');
        } catch (ValidationException $e) {
            $this->assertSame('This item has no expiry tracking. A real expiry date is required before it can be received. Review the item setup.', $e->errors()['lines'][0]);
        }
        $f['sku']->forceFill(['expiry_tracking_required' => true])->save();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2028-12-30 16:01:00', 'UTC'));
        try {
            foreach (['2028-12-31', '2028-12-30', null, '2028-12'] as $expiry) {
                $p = $f['payload'];
                $p['lines'][0]['expiry_date'] = $expiry;
                try {
                    $service->receive($f['receiver'], $f['order'], $p);
                    $this->fail('Invalid expiry');
                } catch (ValidationException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertDatabaseCount('inventory_goods_receipts', 0);
        $this->assertFalse(DB::table('inventory_batches')->whereDate('expiry_date', '9999-12-31')->exists());
    }

    public function test_duplicate_delivery_reference_refused_without_mutation_and_no_rounding_or_overflow(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $service = app(ProcurementService::class);
        $service->receive($f['receiver'], $f['order'], $f['payload']);
        $p = [...$f['payload'], 'lock_version' => $f['order']->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), 'delivery_reference' => strtolower($f['payload']['delivery_reference'])];
        $this->invalid(fn () => $service->receive($f['receiver'], $f['order'], $p), 'delivery_reference');
        foreach (['0.0004', '1000000000000', '1e3', '-1'] as $quantity) {
            $p = $f['payload'];
            $p['lines'][0]['quantity'] = $quantity;
            try {
                $service->receive($f['receiver'], $f['order'], $p);
                $this->fail('Non-exact quantity');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('inventory_goods_receipts', 1);
        $this->assertSame('1.001', StockUnitQuantity::format(StockUnitQuantity::scaled('1.001')));
    }

    public function test_full_unrelated_row_comparison_review_approval_and_post_audit_failure_rolls_back(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'damaged')], '2.000');
        $snapshot = function (): array {
            $out = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, ['inventory_receiving_reviews', 'inventory_receiving_approvals', 'audit_logs'], true)) {
                    continue;
                } $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $table->tablename]), 'column_name');
                $q = DB::table($table->tablename);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $q->orderBy($column);
                } $out[$table->tablename] = $q->get()->toJson();
            }

            return $out;
        };
        $before = $snapshot();
        $p = $this->approved($f, $p);
        $this->assertSame($before, $snapshot());
        foreach (DB::table('audit_logs')->where('event', 'like', 'inventory.receiving.%')->get() as $audit) {
            foreach (array_keys(json_decode($audit->metadata, true)) as $field) {
                $this->assertContains($field, ['review_id', 'approval_id']);
            }
        }
        $auditBefore = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andReturnNull());
        try {
            app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p);
            $this->fail('Unaudited receipt');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame($auditBefore, DB::table('audit_logs')->orderBy('id')->get()->toJson());
    }

    public function test_append_only_evidence_postgresql_refuses_update_delete_and_truncate(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'damaged')], '2.000');
        $p = $this->approved($f, $p);
        app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p);
        foreach (['inventory_receipt_delivery_evidence', 'inventory_receiving_reviews', 'inventory_receiving_approvals', 'inventory_receipt_line_evidence', 'inventory_receipt_lots', 'inventory_receiving_consumptions', 'inventory_receipt_corrections', 'inventory_goods_receipts', 'inventory_goods_receipt_lines'] as $table) {
            $count = DB::table($table)->count();
            foreach (['UPDATE '.$table.' SET id=id', 'DELETE FROM '.$table, 'TRUNCATE '.$table.' CASCADE'] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Mutable receipt evidence');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }
                $this->assertSame($count, DB::table($table)->count());
            }
        }
    }

    public function test_whole_receipt_reversing_correction_requires_independent_approval_and_retains_originals(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
        $old = DB::table('inventory_goods_receipts')->orderBy('id')->get()->toJson();
        $movements = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $p = ['expected_branch_id' => $this->branch->id, 'lock_version' => $f['order']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(), 'kind' => 'correction', 'receipt_public_id' => $receipt->public_id, 'reason' => 'incorrect_quantity'];
        $id = app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], $p);
        $r = DB::table('inventory_receiving_reviews')->find($id);
        $this->selectBranch($f['director']);
        app(ReceivingReviewService::class)->approve($f['director'], $r->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        $this->selectBranch($f['receiver']);
        $command = ['expected_branch_id' => $this->branch->id, 'review_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()];
        $correction = app(ReceiptCorrectionService::class)->reverse($f['receiver'], $r->public_id, $command);
        $this->assertSame($correction, app(ReceiptCorrectionService::class)->reverse($f['receiver'], $r->public_id, $command));
        $this->assertSame($old, DB::table('inventory_goods_receipts')->orderBy('id')->get()->toJson());
        $this->assertSame($movements, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame('0.000', $f['order']->lines()->sole()->received_quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'inventory_receipt_correction')->count());
    }

    public function test_foreign_organisation_and_branch_are_isolated_and_page_is_bounded_without_patient_queries(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'damaged')], '2.000');
        $this->selectBranch($f['receiver']);
        for ($i = 0; $i < 32; $i++) {
            app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], [...$p, 'retry_key' => (string) Str::uuid()]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(ReceivingReviewService::class)->page($f['receiver'], []);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(32, $page['total']);
        $this->assertCount(25, $page['rows']);
        $this->assertLessThanOrEqual(25, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|join) "(?:patients|visits|clinical_encounters|invoices|payments|clinician_credentials)"/i', $query['query']);
        }
        $foreign = $this->organisation->replicate();
        $foreign->forceFill(['code' => 'SYN-OTHER-'.Str::random(5), 'name' => 'Synthetic foreign organisation'])->save();
        $branch = $this->branch->replicate();
        $branch->forceFill(['organisation_id' => $foreign->id, 'code' => 'SYN-OTHER', 'name' => 'Synthetic foreign branch'])->save();
        $actor = $this->actor('ca_supervisor', $branch);
        $this->selectBranch($actor, $branch);
        try {
            app(ProcurementService::class)->receive($actor, $f['order'], [...$f['payload'], 'expected_branch_id' => $branch->id]);
            $this->fail('Foreign organisation receipt');
        } catch (ModelNotFoundException|HttpException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, app(ReceivingReviewService::class)->page($actor, [])['total']);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($f['receiver']->staffProfile, $other, ['is_primary' => false]);
        app()->forgetInstance(BranchAccessService::class);
        $this->selectBranch($f['receiver'], $other);
        app(BranchAccessService::class)->select($f['receiver'], $other);
        try {
            app(ProcurementService::class)->receive($f['receiver'], $f['order'], [...$f['payload'], 'expected_branch_id' => $other->id]);
            $this->fail('Foreign branch receipt');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(0, app(ReceivingReviewService::class)->page($f['receiver'], [])['total']);
    }

    public function test_post_changes_only_declared_receiving_rows_and_expected_stock_and_audit_contains_no_reference(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $allowed = ['inventory_goods_receipts', 'inventory_goods_receipt_lines', 'inventory_receipt_delivery_evidence', 'inventory_receipt_line_evidence', 'inventory_receipt_lots', 'inventory_batches', 'inventory_stock_balances', 'stock_movements', 'inventory_purchase_orders', 'inventory_purchase_order_lines', 'audit_logs'];
        $snapshot = function () use ($allowed): array {
            $out = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, $allowed, true)) {
                    continue;
                } $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $table->tablename]), 'column_name');
                $q = DB::table($table->tablename);
                foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                    $q->orderBy($column);
                } $out[$table->tablename] = $q->get()->toJson();
            }

            return $out;
        };
        $before = $snapshot();
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $audits = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $auditMax = DB::table('audit_logs')->max('id');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $snapshot());
        $this->assertSame($moves, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->where('inventory_batch_id', $f['batch']->id)->orderBy('id')->get()->toJson());
        $this->assertSame($audits, DB::table('audit_logs')->where('id', '<=', $auditMax)->orderBy('id')->get()->toJson());
        $audit = DB::table('audit_logs')->where('event', 'inventory.goods_receipt.posted')->where('subject_id', $receipt->id)->sole();
        $this->assertSame(['purchase_order_public_id', 'line_count'], array_keys(json_decode($audit->metadata, true)));
        $this->assertStringNotContainsString($f['payload']['delivery_reference'], $audit->metadata);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|join) "(?:patients|visits|clinical_encounters|invoices|payments|clinician_credentials)"/i', $query['query']);
        }
    }

    public function test_request_and_approval_audit_failures_roll_back_without_stock_or_evidence_changes(): void
    {
        $f = $this->fixture();
        $p = $this->rich($f, [$this->lot('2.000', 'quarantined', 'damaged')], '2.000');
        $this->selectBranch($f['receiver']);
        $real = app(AuditRecorder::class);
        $this->mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnUsing(fn (...$args) => $args[0] === 'inventory.receiving.review_requested' ? null : $real->record(...$args)));
        try {
            app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], $p);
            $this->fail('Unaudited review');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_receiving_reviews', 0);
        $this->forgetMock(AuditRecorder::class);
        $id = app(ReceivingReviewService::class)->request($f['receiver'], $f['order'], $p);
        $r = DB::table('inventory_receiving_reviews')->find($id);
        $this->selectBranch($f['director']);
        $this->mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull());
        try {
            app(ReceivingReviewService::class)->approve($f['director'], $r->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
            $this->fail('Unaudited approval');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('inventory_receiving_approvals', 0);
        $this->assertDatabaseCount('inventory_goods_receipts', 0);
        $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_batch_id', $f['batch']->id)->value('quantity'));
    }

    public function test_historical_sentinel_batch_is_never_rewritten_and_known_sentinel_input_is_refused(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $legacy = $f['batch']->replicate();
        $legacy->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-HISTORICAL-SENTINEL', 'expiry_date' => '9999-12-31'])->save();
        $before = DB::table('inventory_batches')->where('id', $legacy->id)->sole();
        $p = $f['payload'];
        $p['lines'][0]['expiry_date'] = '9999-12-31';
        $this->invalid(fn () => app(ProcurementService::class)->receive($f['receiver'], $f['order'], $p));
        app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
        $this->assertEquals($before, DB::table('inventory_batches')->where('id', $legacy->id)->sole());
    }

    public function test_receiving_permission_revocation_and_replay_safe_grant_migration(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $f['receiver']->removeRole('ca_supervisor');
        try {
            app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
            $this->fail('Revoked receiving');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $migration = require database_path('migrations/2026_11_02_000200_grant_receiving_exception_approval.php');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $role = Role::findByName('director', 'web');
        $role->delete();
        Log::shouldReceive('warning')->once()->with('Receiving exception grant skipped missing role', ['role' => 'director']);
        $migration->up();
        $this->assertFalse(Role::where('name', 'director')->exists());
    }

    public function test_correction_audit_failure_rolls_back_and_correction_evidence_is_immutable(): void
    {
        $f = $this->fixture();
        $this->selectBranch($f['receiver']);
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
        $payload = ['expected_branch_id' => $this->branch->id, 'lock_version' => $f['order']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(), 'kind' => 'correction', 'receipt_public_id' => $receipt->public_id, 'reason' => 'incorrect_batch'];
        $payload = $this->approved($f, $payload);
        $command = ['expected_branch_id' => $this->branch->id, 'review_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()];
        $balance = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $movements = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $order = $f['order']->refresh()->toJson();
        $unrelated = $this->snapshotExcept(['inventory_receipt_corrections', 'inventory_receiving_consumptions', 'stock_movements', 'inventory_stock_balances', 'inventory_purchase_orders', 'inventory_purchase_order_lines', 'audit_logs']);
        $this->mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull());
        try {
            app(ReceiptCorrectionService::class)->reverse($f['receiver'], $payload['review_public_id'], $command);
            $this->fail('Unaudited correction');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($balance, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame($movements, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($order, $f['order']->refresh()->toJson());
        $this->assertDatabaseCount('inventory_receipt_corrections', 0);
        $this->forgetMock(AuditRecorder::class);
        app(ReceiptCorrectionService::class)->reverse($f['receiver'], $payload['review_public_id'], $command);
        $this->assertSame($unrelated, $this->snapshotExcept(['inventory_receipt_corrections', 'inventory_receiving_consumptions', 'stock_movements', 'inventory_stock_balances', 'inventory_purchase_orders', 'inventory_purchase_order_lines', 'audit_logs']));
        foreach (['UPDATE inventory_receipt_corrections SET id=id', 'DELETE FROM inventory_receipt_corrections', 'TRUNCATE inventory_receipt_corrections CASCADE'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Mutable correction');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }$this->assertDatabaseCount('inventory_receipt_corrections', 1);
        }
    }

    public function test_receipt_evidence_is_utc_and_local_display_is_independent_of_database_session_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:30:00+00:00'));
        foreach (['UTC', 'Asia/Kuala_Lumpur'] as $zone) {
            DB::select("select set_config('TimeZone', ?, true)", [$zone]);
            $f = $this->fixture();
            $this->selectBranch($f['receiver']);
            $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
            $evidence = DB::table('inventory_receipt_delivery_evidence')->where('goods_receipt_id', $receipt->id)->sole();
            $this->assertSame('2026-12-31 16:30:00', $evidence->received_at);
            $this->assertSame('2026-12-31 16:30:00', $receipt->received_at->utc()->format('Y-m-d H:i:s'));
            $page = app(ReceivingReviewService::class)->page($f['receiver'], []);
            $row = collect($page['receipts']['rows'])->firstWhere('public_id', $receipt->public_id);
            $this->assertSame('01 Jan 2027 00:30', $row['received_at_local']);
            $this->assertSame('Asia/Kuala_Lumpur', $page['timezone']);
        }
    }

    public function test_fixture_branch_is_independent_of_heap_order_prior_selection_and_hour(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        try {
            foreach (['2026-10-28T00:05:00+00:00', '2026-10-28T23:55:00+00:00'] as $instant) {
                $this->travelTo(CarbonImmutable::parse($instant));
                foreach ([$this->branch, $other] as $target) {
                    $prior = $this->actor('director');
                    app(BranchAccessService::class)->select($prior, $target->is($other) ? $this->branch : $other);
                    // A legitimate UPDATE changes heap order without changing branch identity.
                    DB::table('branches')->where('id', $target->id)->update(['updated_at' => now()]);
                    $f = (new VerifiedReceivingFixture($target))->build();
                    $this->assertSame($target->id, $f['visit']->branch_id);
                    $this->assertSame($target->id, $f['location']->branch_id);
                    $this->assertSame($target->id, $f['payload']['expected_branch_id']);
                    foreach (['ca', 'receiver', 'finance', 'director'] as $key) {
                        $this->assertSame($target->id, $f[$key]->staffProfile->branchAssignments()->where('is_primary', true)->sole()->branch_id);
                        $this->assertSame($target->id, app(BranchAccessService::class)->activeBranch($f[$key])->id);
                    }
                }
            }
        } finally {
            $this->travelBack();
        }
    }
}
