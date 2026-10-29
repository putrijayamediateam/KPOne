<?php

namespace Tests\Unit;

use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LegacyStockWriterBoundaryTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function body(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    public function test_all_retired_entrypoints_only_delegate_to_the_authority_first_refusal(): void
    {
        foreach (['createStocktake', 'startCounting', 'recordCounts', 'postStocktake', 'cancelStocktake', 'adjust'] as $method) {
            $body = $this->body(InventoryControlService::class, $method);
            $this->assertStringContainsString('LegacyStockPath::refuse(', $body);
            $this->assertDoesNotMatchRegularExpression('/->(?:save|create|update|insert|movement|recordAdjustment|recordStocktakeVariance)\s*\(/', $body);
        }
        foreach (['recordStocktakeVariance', 'recordAdjustment'] as $method) {
            $body = $this->body(InventoryMovementService::class, $method);
            $this->assertStringContainsString('LegacyStockPath::refuseForLocation(', $body);
            $this->assertDoesNotMatchRegularExpression('/->(?:save|create|update|insert|movement|creditOperation|debitOperation)\s*\(/', $body);
        }
    }

    public function test_kept_posting_helpers_and_reorder_never_call_the_closed_helpers(): void
    {
        foreach (['openingBalance', 'transfer', 'debitForDispense', 'recordPurchaseReceipt', 'recordTransferDispatch', 'recordTransferReceipt', 'recordReceiptCorrection', 'recordGovernedChange', 'creditOperation', 'debitOperation', 'movement'] as $method) {
            $this->assertDoesNotMatchRegularExpression('/LegacyStockPath|->(?:recordAdjustment|recordStocktakeVariance)\s*\(/', $this->body(InventoryMovementService::class, $method), $method);
        }
        $this->assertDoesNotMatchRegularExpression('/LegacyStockPath|->(?:adjust|postStocktake)\s*\(/', $this->body(InventoryControlService::class, 'setReorderLevel'));
    }

    public function test_application_movement_writer_and_governed_correction_callers_are_explicitly_allowlisted(): void
    {
        $root = $this->root();
        $ledger = 'app/Domain/Organisation/Inventory/Services/InventoryMovementService.php';
        $expected = [
            'debitForDispense' => ['app/Domain/Clinical/Dispensary/Services/DispensaryService.php'],
            'recordPurchaseReceipt' => ['app/Domain/Organisation/Inventory/Services/ProcurementService.php'],
            'recordTransferDispatch' => ['app/Domain/Organisation/Inventory/Services/StockRequestService.php'],
            'recordTransferReceipt' => ['app/Domain/Organisation/Inventory/Services/StockRequestService.php'],
            'recordReceiptCorrection' => ['app/Domain/Organisation/Inventory/Services/ReceiptCorrectionService.php'],
            'recordGovernedChange' => ['app/Domain/Organisation/Inventory/Services/GovernedStockService.php'],
            'recordAdjustment' => [],
            'recordStocktakeVariance' => [],
        ];
        $actual = array_fill_keys(array_keys($expected), []);
        foreach (['app', 'routes'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = substr($file->getPathname(), strlen($root) + 1);
                $source = file_get_contents($file->getPathname());
                // Ignore comments, not executable strings: SQL and table names remain inspected.
                $source = implode('', array_map(fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($source)));
                foreach ($actual as $method => &$callers) {
                    if (preg_match('/->'.preg_quote($method, '/').'\s*\(/', $source)) {
                        $callers[] = $path;
                    }
                }
                unset($callers);
                if ($path === $ledger) {
                    // The only model constructor must remain inside the one private movement writer.
                    $this->assertSame(1, preg_match_all('/new\s+StockMovement\b/', $source));
                    $this->assertStringContainsString('new StockMovement', $this->body(InventoryMovementService::class, 'movement'));

                    // Only this constructor is exempt; raw SQL/model insert shortcuts remain forbidden here too.
                    $source = str_replace('$movement = new StockMovement;', '', $source);
                }
                $source = str_replace('(new StockMovement)->getMorphClass()', '', $source);
                $this->assertDoesNotMatchRegularExpression('/new\s+(?:[\\\\\w]+\\\\)?StockMovement\b|StockMovement\s*::\s*(?:create|insert|upsert|forceCreate)\s*\(/', $source, $path);
                $this->assertDoesNotMatchRegularExpression('/(?:StockMovement\s*::|(?:table|from)\s*\(\s*[\'"]stock_movements[\'"])[^;]*?->\s*(?:insert|insertGetId|upsert|update|delete|truncate|create)\s*\(/s', $source, $path);
                $this->assertDoesNotMatchRegularExpression('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE)\s+["\x60]?stock_movements\b/i', $source, $path);
            }
        }
        foreach ($actual as &$callers) {
            sort($callers);
        }
        unset($callers);
        $this->assertSame($expected, $actual);
        $this->assertStringContainsString("'inventory_receipt_correction'", $this->body(InventoryMovementService::class, 'recordReceiptCorrection'));
        $this->assertStringContainsString("'inventory_governed_case'", $this->body(InventoryMovementService::class, 'recordGovernedChange'));
    }

    public function test_private_ledger_writers_have_no_unreviewed_callers(): void
    {
        $expected = [
            'movement' => ['creditOperation', 'debitForDispense', 'debitOperation', 'openingBalance', 'recordPurchaseReceipt', 'transfer'],
            'creditOperation' => ['recordGovernedChange', 'recordTransferReceipt'],
            'debitOperation' => ['recordGovernedChange', 'recordReceiptCorrection', 'recordTransferDispatch'],
        ];
        foreach ($expected as $callee => $callers) {
            $actual = [];
            foreach ((new \ReflectionClass(InventoryMovementService::class))->getMethods() as $method) {
                if (preg_match('/->'.preg_quote($callee, '/').'\s*\(/', $this->body(InventoryMovementService::class, $method->name))) {
                    $actual[] = $method->name;
                }
            }
            sort($actual);
            $this->assertSame($callers, $actual, $callee);
        }
    }
}
