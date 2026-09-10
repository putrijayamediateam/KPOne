<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $db = DB::connection();
    if (! app()->environment('testing') || $db->getDriverName() !== 'pgsql' || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) $db->getDatabaseName()) !== 1) {
        exit(65);
    }
    $db->statement("select set_config('application_name', ?, false)", [(string) end($argv)]);
    fwrite(STDOUT, 'READY '.(int) $db->scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    $mode = $argv[1];
    $a = json_decode(base64_decode($argv[5], true), true, 64, JSON_THROW_ON_ERROR);
    $actor = User::query()->findOrFail((int) $argv[2]);
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $argv[3]]);
    $a['expected_branch_id'] = (int) $argv[3];
    $visit = Visit::query()->where('visit_number', $argv[4])->firstOrFail();
    $invoice = isset($a['invoice']) ? Invoice::query()->where('public_id', $a['invoice'])->firstOrFail() : null;
    $hold = (bool) ($a['hold'] ?? false);
    if ($hold) {
        DB::beginTransaction();
    }
    match ($mode) {
        'bill-build' => app(BillingBuilderService::class)->build($actor, $visit, $a),
        'bill-finalize' => app(BillingBuilderService::class)->finalize($actor, $visit, $invoice, $a),
        'bill-pay' => app(PaymentService::class)->add($actor, $visit, $invoice, $a),
        'bill-payment-method-deactivate' => app(PaymentMethodAdministrationService::class)->deactivate($actor, PaymentMethod::query()->findOrFail($a['method'])),
        'bill-complete' => app(CompleteVisitationService::class)->complete($actor, $visit, $a),
        'bill-propose' => app(ResponsibilityService::class)->propose($actor, $visit, $invoice, $a['kind'], $a),
        'bill-approve' => app(ResponsibilityService::class)->approve($actor, $visit, $invoice, $a['kind'], $a['proposal'], $a),
        'bill-reverse' => app(InvoiceCorrectionService::class)->reverse($actor, $visit, $invoice, Payment::query()->where('public_id', $a['payment'])->firstOrFail(), $a),
        'bill-void' => app(InvoiceCorrectionService::class)->void($actor, $visit, $invoice, $a),
        'bill-checkout' => app(CompleteConsultationService::class)->complete($actor, $visit, $a),
        'bill-reopen' => app(ReopenConsultationCheckoutService::class)->reopen($actor, $visit, $a),
        'bill-edit' => app(ClinicalEncounterService::class)->update($actor, $visit, $a),
        'bill-price' => app(PricePublicationService::class)->publish($actor, PriceBook::query()->findOrFail($a['book']), ChargeDefinition::query()->findOrFail($a['charge']), $a['amount_sen'], $a['price_version'], (int) $argv[3]),
        default => throw new RuntimeException('Unknown test worker mode'),
    };
    if ($hold) {
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        DB::commit();
    }
    fwrite(STDOUT, 'SUCCESS'.PHP_EOL);
} catch (ValidationException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDOUT, 'STALE'.PHP_EOL);
} catch (AuthorizationException|ModelNotFoundException|HttpException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDOUT, 'DENIED'.PHP_EOL);
} catch (Throwable $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($e).' SQLSTATE='.$e->getCode().PHP_EOL);
    exit(1);
}
