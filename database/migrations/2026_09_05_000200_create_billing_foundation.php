<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_definitions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('code', 64);
            $t->string('type', 20);
            $t->string('display_name', 500);
            $t->string('unit', 100);
            $t->unsignedBigInteger('medicine_catalogue_item_id')->nullable();
            $t->unsignedBigInteger('clinical_service_catalogue_item_id')->nullable();
            $t->string('source_key', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['medicine_catalogue_item_id', 'organisation_id'], 'charge_medicine_fk')->references(['id', 'organisation_id'])->on('medicine_catalogue_items')->restrictOnDelete();
            $t->foreign(['clinical_service_catalogue_item_id', 'organisation_id'], 'charge_service_fk')->references(['id', 'organisation_id'])->on('clinical_service_catalogue_items')->restrictOnDelete();
            $t->unique(['organisation_id', 'source_key']);
            $t->unique(['organisation_id', 'code']);
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('price_books', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('scope_key', 64);
            $t->string('name', 150);
            $t->string('currency', 3)->default('MYR');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['organisation_id', 'scope_key']);
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('price_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('price_book_id');
            $t->unsignedBigInteger('charge_definition_id');
            $t->bigInteger('unit_price_sen');
            $t->unsignedBigInteger('version');
            $t->timestamp('effective_at');
            $t->unsignedBigInteger('published_by_user_id');
            $t->timestamps();
            $t->foreign(['price_book_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('price_books')->restrictOnDelete();
            $t->foreign(['charge_definition_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('charge_definitions')->restrictOnDelete();
            $t->foreign(['published_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['price_book_id', 'charge_definition_id', 'version'], 'price_entry_revision_unique');
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('billing_document_counters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->string('document_type', 20);
            $t->unsignedBigInteger('next_value');
            $t->unique(['organisation_id', 'document_type']);
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedBigInteger('patient_id');
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('consultation_checkout_id');
            $t->unsignedBigInteger('current_visit_guard')->nullable()->unique();
            $t->uuid('replaces_public_id')->nullable();
            $t->string('invoice_number', 30)->nullable();
            $t->string('currency', 3)->default('MYR');
            $t->string('status', 20)->default('draft');
            $t->bigInteger('subtotal_sen')->default(0);
            $t->bigInteger('total_sen')->default(0);
            $t->json('source_manifest');
            $t->string('source_hash', 64);
            $t->boolean('source_stale')->default(false);
            $t->boolean('correction_hold')->default(false);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedBigInteger('finalized_by_user_id')->nullable();
            $t->timestamp('finalized_at')->nullable();
            $t->unsignedBigInteger('voided_by_user_id')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 500)->nullable();
            $t->timestamps();
            $t->foreign(['visit_id', 'organisation_id', 'branch_id', 'patient_id'], 'invoice_visit_owner_fk')->references(['id', 'organisation_id', 'branch_id', 'patient_id'])->on('visits')->restrictOnDelete();
            $t->foreign(['consultation_checkout_id', 'organisation_id', 'branch_id'], 'invoice_checkout_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('consultation_checkouts')->restrictOnDelete();
            foreach (['created_by_user_id', 'finalized_by_user_id', 'voided_by_user_id'] as $c) {
                $t->foreign([$c, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['organisation_id', 'invoice_number']);
        });
        Schema::create('invoice_lines', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id');
            $t->string('line_type', 20);
            $t->string('source_key', 100);
            $t->unsignedBigInteger('dispensary_item_id')->nullable();
            $t->unsignedBigInteger('service_delivery_id')->nullable();
            $t->unsignedBigInteger('consultation_checkout_id')->nullable();
            $t->unsignedBigInteger('charge_definition_id');
            $t->unsignedBigInteger('price_entry_id');
            $t->unsignedBigInteger('price_version');
            $t->string('display_name', 500);
            $t->string('code_snapshot', 100);
            $t->string('unit_snapshot', 100);
            $t->decimal('quantity', 12, 3);
            $t->bigInteger('unit_price_sen');
            $t->bigInteger('line_total_sen');
            $t->string('source_fingerprint', 64);
            $t->timestamps();
            $this->invoice($t, 'line');
            foreach (['dispensary_item_id' => 'dispensary_items', 'service_delivery_id' => 'service_deliveries', 'consultation_checkout_id' => 'consultation_checkouts'] as $c => $table) {
                $t->foreign([$c, 'organisation_id', 'branch_id'], 'line_'.$c.'_fk')->references(['id', 'organisation_id', 'branch_id'])->on($table)->restrictOnDelete();
            }
            foreach (['charge_definition_id' => 'charge_definitions', 'price_entry_id' => 'price_entries'] as $c => $table) {
                $t->foreign([$c, 'organisation_id'], 'line_'.$c.'_fk')->references(['id', 'organisation_id'])->on($table)->restrictOnDelete();
            }
            $t->unique(['invoice_id', 'source_key']);
        });
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->boolean('requires_reference')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organisation_id', 'code']);
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('payments', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedBigInteger('patient_id');
            $t->unsignedBigInteger('payment_method_id');
            $t->string('receipt_number', 30);
            $t->bigInteger('amount_sen');
            $t->string('currency', 3)->default('MYR');
            $t->string('method_snapshot', 100);
            $t->string('status', 20)->default('posted');
            $t->string('reference', 150)->nullable();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestamp('received_at');
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->foreign(['payment_method_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('payment_methods')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['patient_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $t->unique(['organisation_id', 'receipt_number']);
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        Schema::create('payment_allocations', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('payment_id')->unique();
            $t->bigInteger('amount_sen');
            $t->unsignedBigInteger('patient_receivable_id')->nullable();
            $t->bigInteger('deferment_applied_sen')->default(0);
            $t->timestamps();
            $this->invoice($t, 'payment_allocation');
            $t->foreign(['payment_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('payments')->restrictOnDelete();
        });
        Schema::create('payment_reversals', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedBigInteger('payment_id')->unique();
            $t->unsignedBigInteger('invoice_id');
            $t->bigInteger('amount_sen');
            $t->string('reason', 500);
            $t->unsignedBigInteger('approved_by_user_id');
            $t->timestamp('reversed_at');
            $t->timestamps();
            $this->invoice($t, 'reversal');
            $t->foreign(['payment_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('payments')->restrictOnDelete();
            $t->foreign(['approved_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });
        foreach (['coverage_allocations', 'patient_receivables'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $this->tenant($t);
                $t->unsignedBigInteger('invoice_id');
                $t->unsignedBigInteger('current_invoice_guard')->nullable()->unique();
                $t->bigInteger('amount_sen');
                $t->string('status', 20)->default('proposed');
                $t->unsignedBigInteger('lock_version')->default(1);
                $t->unsignedBigInteger('expected_invoice_version');
                $t->unsignedBigInteger('requested_by_user_id');
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('reason', 500);
                $t->timestamps();
                if ($table === 'coverage_allocations') {
                    $t->unsignedBigInteger('panel_id');
                    $t->string('panel_name_snapshot', 200);
                    $t->string('member_reference', 100)->nullable();
                    $t->foreign(['panel_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
                } else {
                    $t->date('due_date');
                    $t->bigInteger('remaining_sen');
                    $t->timestamp('settled_at')->nullable();
                    $t->unsignedBigInteger('last_payment_id')->nullable();
                    $t->foreign(['last_payment_id', 'organisation_id', 'branch_id'], 'receivable_settlement_fk')->references(['id', 'organisation_id', 'branch_id'])->on('payments')->restrictOnDelete();
                }
                $this->invoice($t, $table);
                foreach (['requested_by_user_id', 'approved_by_user_id'] as $c) {
                    $t->foreign([$c, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
                }
            });
        }
        Schema::create('billing_approval_limits', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('capability', 32);
            $t->bigInteger('limit_sen');
            $t->foreign(['user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['user_id', 'branch_id', 'capability']);
        });
        Schema::table('payment_allocations', function (Blueprint $t): void {
            $t->foreign(['patient_receivable_id', 'organisation_id', 'branch_id'], 'allocation_deferment_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('patient_receivables')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->checks();
        }
    }

    private function tenant(Blueprint $t): void
    {
        $t->id();
        $t->uuid('public_id')->unique();
        $t->unsignedBigInteger('organisation_id');
        $t->unsignedBigInteger('branch_id');
        $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        $t->unique(['id', 'organisation_id', 'branch_id']);
    }

    private function invoice(Blueprint $t, string $prefix): void
    {
        $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], $prefix.'_invoice_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE charge_definitions ADD CONSTRAINT charge_source_shape CHECK ((type='consultation' AND medicine_catalogue_item_id IS NULL AND clinical_service_catalogue_item_id IS NULL AND source_key='consultation') OR (type='medicine' AND medicine_catalogue_item_id IS NOT NULL AND clinical_service_catalogue_item_id IS NULL AND source_key='medicine:'||medicine_catalogue_item_id::text) OR (type='service' AND clinical_service_catalogue_item_id IS NOT NULL AND medicine_catalogue_item_id IS NULL AND source_key='service:'||clinical_service_catalogue_item_id::text))");
        DB::statement("ALTER TABLE price_books ADD CONSTRAINT price_book_shape CHECK (currency='MYR' AND ((branch_id IS NULL AND scope_key='organisation') OR (branch_id IS NOT NULL AND scope_key='branch:'||branch_id::text)))");
        DB::statement('ALTER TABLE price_entries ADD CONSTRAINT price_entry_amount CHECK (unit_price_sen BETWEEN 0 AND 999999999999 AND version>0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoice_shape CHECK (currency='MYR' AND lock_version>0 AND subtotal_sen BETWEEN 0 AND 999999999999 AND total_sen=subtotal_sen AND ((status='draft' AND invoice_number IS NULL AND finalized_at IS NULL AND finalized_by_user_id IS NULL AND current_visit_guard=visit_id AND current_visit_guard IS NOT NULL) OR (status='finalized' AND invoice_number IS NOT NULL AND finalized_at IS NOT NULL AND finalized_by_user_id IS NOT NULL AND current_visit_guard=visit_id AND current_visit_guard IS NOT NULL) OR (status='voided' AND current_visit_guard IS NULL AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND void_reason IS NOT NULL)))");
        DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT invoice_line_shape CHECK (quantity>0 AND unit_price_sen BETWEEN 0 AND 999999999999 AND line_total_sen BETWEEN 0 AND 999999999999 AND line_total_sen=round(quantity*unit_price_sen) AND price_version>0 AND ((line_type='medicine' AND dispensary_item_id IS NOT NULL AND service_delivery_id IS NULL AND consultation_checkout_id IS NULL) OR (line_type='service' AND dispensary_item_id IS NULL AND service_delivery_id IS NOT NULL AND consultation_checkout_id IS NULL) OR (line_type='consultation' AND dispensary_item_id IS NULL AND service_delivery_id IS NULL AND consultation_checkout_id IS NOT NULL)))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payment_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND currency='MYR' AND status IN ('posted','reversed') AND lock_version>0)");
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_positive CHECK (amount_sen BETWEEN 1 AND 999999999999)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_deferment_shape CHECK (deferment_applied_sen>=0 AND deferment_applied_sen<=amount_sen AND ((deferment_applied_sen=0 AND patient_receivable_id IS NULL) OR (deferment_applied_sen>0 AND patient_receivable_id IS NOT NULL)))');
        DB::statement('ALTER TABLE payment_reversals ADD CONSTRAINT reversal_positive CHECK (amount_sen BETWEEN 1 AND 999999999999)');
        foreach (['coverage_allocations', 'patient_receivables'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND lock_version>0 AND expected_invoice_version>0 AND status IN ('proposed','approved','superseded') AND ((status='superseded' AND current_invoice_guard IS NULL) OR (status IN ('proposed','approved') AND current_invoice_guard=invoice_id AND current_invoice_guard IS NOT NULL)) AND (status<>'approved' OR (approved_by_user_id IS NOT NULL AND approved_by_user_id<>requested_by_user_id AND approved_at IS NOT NULL)))");
        }
        DB::statement('ALTER TABLE patient_receivables ADD CONSTRAINT receivable_remaining CHECK (remaining_sen>=0 AND remaining_sen<=amount_sen)');
        DB::statement('ALTER TABLE billing_approval_limits ADD CONSTRAINT approval_limit_nonnegative CHECK (limit_sen BETWEEN 0 AND 999999999999)');
    }

    public function down(): void
    {
        if (DB::table('invoices')->exists()) {
            throw new RuntimeException('Financial records are retained; use fresh recreation only on disposable test databases.');
        }
        Schema::table('payment_allocations', fn (Blueprint $t) => $t->dropForeign('allocation_deferment_owner_fk'));
        foreach (['billing_approval_limits', 'patient_receivables', 'coverage_allocations', 'payment_reversals', 'payment_allocations', 'payments', 'payment_methods', 'invoice_lines', 'invoices', 'billing_document_counters', 'price_entries', 'price_books', 'charge_definitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
