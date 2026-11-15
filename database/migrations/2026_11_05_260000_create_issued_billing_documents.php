<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-5 (owner decisions 2026-11-05): an invoice or receipt is frozen at first print. Each distinct printed content is
 * one immutable, encrypted version; every print of a version is one row labelled ORIGINAL (first print of version 1),
 * REVISED (first print of a later version) or COPY. No financial record changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_billing_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $t->string('document_type', 10);
            $t->unsignedInteger('version');
            $t->char('content_sha256', 64);
            $t->longText('encrypted_snapshot');
            $t->char('ciphertext_sha256', 64);
            $t->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('issued_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['invoice_id', 'payment_id']);
        });
        DB::statement("ALTER TABLE issued_billing_documents ADD CONSTRAINT issued_billing_documents_shape CHECK (
            document_type IN ('invoice', 'receipt') AND (document_type = 'receipt') = (payment_id IS NOT NULL) AND version >= 1
            AND content_sha256 ~ '^[0-9a-f]{64}$' AND ciphertext_sha256 ~ '^[0-9a-f]{64}$' AND length(encrypted_snapshot) > 50)");
        DB::statement('CREATE UNIQUE INDEX issued_billing_documents_version_unique ON issued_billing_documents (invoice_id, COALESCE(payment_id, 0), version)');

        Schema::create('issued_billing_document_prints', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $t->foreignId('issued_billing_document_id')->constrained('issued_billing_documents')->restrictOnDelete();
            $t->string('label', 10);
            $t->foreignId('printed_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('printed_at');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE issued_billing_document_prints ADD CONSTRAINT issued_billing_document_prints_shape CHECK (label IN ('original', 'revised', 'copy') AND payload_hash ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_issued_billing_documents() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Issued billing documents are immutable' USING ERRCODE = '0A000'; END; $$;
CREATE TRIGGER issued_billing_documents_immutable BEFORE UPDATE OR DELETE ON issued_billing_documents FOR EACH ROW EXECUTE FUNCTION protect_issued_billing_documents();
CREATE TRIGGER issued_billing_documents_no_truncate BEFORE TRUNCATE ON issued_billing_documents FOR EACH STATEMENT EXECUTE FUNCTION protect_issued_billing_documents();
CREATE TRIGGER issued_billing_document_prints_immutable BEFORE UPDATE OR DELETE ON issued_billing_document_prints FOR EACH ROW EXECUTE FUNCTION protect_issued_billing_documents();
CREATE TRIGGER issued_billing_document_prints_no_truncate BEFORE TRUNCATE ON issued_billing_document_prints FOR EACH STATEMENT EXECUTE FUNCTION protect_issued_billing_documents();

-- A version belongs to its invoice's organisation and branch, a receipt to that invoice, and versions are gapless.
CREATE OR REPLACE FUNCTION guard_issued_billing_document() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE latest integer;
BEGIN
  PERFORM 1 FROM invoices WHERE id = NEW.invoice_id FOR UPDATE;
  IF NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND organisation_id = NEW.organisation_id AND branch_id = NEW.branch_id AND status <> 'draft') THEN
    RAISE EXCEPTION 'Issued document must belong to a finalised invoice of the same branch' USING ERRCODE = '23514';
  END IF;
  IF NEW.payment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
      WHERE a.payment_id = NEW.payment_id AND a.invoice_id = NEW.invoice_id AND p.organisation_id = NEW.organisation_id AND p.branch_id = NEW.branch_id) THEN
    RAISE EXCEPTION 'Issued receipt must belong to the invoice' USING ERRCODE = '23514';
  END IF;
  SELECT max(version) INTO latest FROM issued_billing_documents WHERE invoice_id = NEW.invoice_id AND COALESCE(payment_id, 0) = COALESCE(NEW.payment_id, 0);
  IF NEW.version <> COALESCE(latest, 0) + 1 THEN
    RAISE EXCEPTION 'Issued document versions must follow on' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER issued_billing_documents_guard BEFORE INSERT ON issued_billing_documents FOR EACH ROW EXECUTE FUNCTION guard_issued_billing_document();

-- ORIGINAL only for the first print of version 1, REVISED only for the first print of a later version.
CREATE OR REPLACE FUNCTION guard_issued_billing_document_print() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE doc issued_billing_documents%ROWTYPE; printed boolean;
BEGIN
  SELECT * INTO doc FROM issued_billing_documents WHERE id = NEW.issued_billing_document_id;
  IF doc.organisation_id <> NEW.organisation_id OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.printed_by_user_id AND organisation_id = NEW.organisation_id) THEN
    RAISE EXCEPTION 'Print must stay in its organisation' USING ERRCODE = '23514';
  END IF;
  PERFORM 1 FROM invoices WHERE id = doc.invoice_id FOR UPDATE;
  printed := EXISTS (SELECT 1 FROM issued_billing_document_prints WHERE issued_billing_document_id = doc.id);
  IF (NEW.label = 'copy') <> printed OR (NEW.label = 'original' AND doc.version <> 1) OR (NEW.label = 'revised' AND doc.version = 1) THEN
    RAISE EXCEPTION 'Print label does not match the version' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER issued_billing_document_prints_guard BEFORE INSERT ON issued_billing_document_prints FOR EACH ROW EXECUTE FUNCTION guard_issued_billing_document_print();
SQL);
    }

    public function down(): void
    {
        // Evidence is retained: rolling back is refused once it exists.
        if (DB::table('issued_billing_documents')->exists()) {
            throw new RuntimeException('Issued billing documents exist; they are retained evidence.');
        }
        Schema::dropIfExists('issued_billing_document_prints');
        Schema::dropIfExists('issued_billing_documents');
        foreach (['protect_issued_billing_documents', 'guard_issued_billing_document', 'guard_issued_billing_document_print'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
