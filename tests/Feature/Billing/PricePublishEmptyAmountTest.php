<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * CAT-1b (owner decision 2026-11-05): publishing a price with an empty amount is refused with a clear message,
 * never read as RM 0.00. An amount typed as 0.00 (zero sen) is still allowed.
 */
class PricePublishEmptyAmountTest extends ClinicalTestCase
{
    /** @return array{0: PriceBook, 1: ChargeDefinition} */
    private function bookAndCharge(): array
    {
        $this->post(route('pricing.price-books.store'), ['name' => 'Empty amount book', 'currency' => 'MYR'])
            ->assertRedirect(route('pricing.index'));
        $this->post(route('pricing.charges.store'), ['type' => 'consultation', 'code' => 'EMPTY-AMOUNT', 'display_name' => 'Empty amount consultation'])
            ->assertRedirect(route('pricing.index'));

        return [
            PriceBook::query()->where('name', 'Empty amount book')->sole(),
            ChargeDefinition::query()->where('code', 'EMPTY-AMOUNT')->sole(),
        ];
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function emptyAmounts(): array
    {
        return [
            'missing' => [null, false],
            'null' => [null, true],
            'empty string' => ['', true],
            'spaces only' => ['   ', true],
        ];
    }

    #[DataProvider('emptyAmounts')]
    public function test_an_empty_amount_is_refused_with_a_clear_message_and_nothing_is_published(mixed $amount, bool $send): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        [$book, $charge] = $this->bookAndCharge();
        $auditCount = DB::table('audit_logs')->where('event', 'billing.price_published')->count();

        $payload = [
            'price_book_public_id' => $book->public_id,
            'expected_version' => 0,
            'expected_branch_id' => $this->branch->id,
        ];
        if ($send) {
            $payload['amount_sen'] = $amount;
        }

        $this->from(route('pricing.index'))
            ->post(route('pricing.charges.publish', $charge), $payload)
            ->assertRedirect(route('pricing.index'))
            ->assertSessionHasErrors(['amount_sen' => 'Enter an amount. Type 0.00 if this is free.']);

        $this->assertDatabaseMissing('price_entries', ['charge_definition_id' => $charge->id]);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('event', 'billing.price_published')->count());
    }

    public function test_an_explicit_zero_amount_is_still_published(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        [$book, $charge] = $this->bookAndCharge();

        $this->post(route('pricing.charges.publish', $charge), [
            'price_book_public_id' => $book->public_id,
            'amount_sen' => 0,
            'expected_version' => 0,
            'expected_branch_id' => $this->branch->id,
        ])->assertRedirect(route('pricing.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('price_entries', ['charge_definition_id' => $charge->id, 'unit_price_sen' => 0, 'version' => 1]);
    }

    #[DataProvider('emptyAmounts')]
    public function test_the_publication_service_refuses_an_empty_amount_on_every_path(mixed $amount, bool $send): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        [$book, $charge] = $this->bookAndCharge();

        try {
            app(PricePublicationService::class)->publish($supervisor, $book, $charge, $send ? $amount : null, 0, $this->branch->id);
            $this->fail('An empty amount must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Enter an amount. Type 0.00 if this is free.'], $exception->errors()['amount_sen'] ?? null);
        }

        $this->assertDatabaseMissing('price_entries', ['charge_definition_id' => $charge->id]);
    }
}
