<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ConsultationTariffAdministrationService
{
    public function __construct(
        private readonly PricingReferenceAdministrationService $references,
        private readonly PricePublicationService $publication,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function publish(User $actor, array $attributes): void
    {
        Gate::forUser($actor)->authorize(PricingReferenceAdministrationService::PERMISSION);
        Gate::forUser($actor)->authorize('prices.publish.organisation');

        $prices = is_array($attributes['prices'] ?? null) ? $attributes['prices'] : [];
        $requested = [];
        foreach ([
            ['key' => 'self_pay_sen', 'tier' => 'self_pay', 'panel_id' => null, 'scope' => 'self_pay', 'name' => 'Self-pay'],
            ['key' => 'panel_default_sen', 'tier' => 'panel', 'panel_id' => null, 'scope' => 'panel_default', 'name' => 'Panel'],
        ] as $default) {
            if (isset($prices[$default['key']]) && $prices[$default['key']] !== '') {
                $requested[] = [...$default, 'amount_sen' => $prices[$default['key']]];
            }
        }

        foreach ($prices['panel_overrides'] ?? [] as $override) {
            if (! is_array($override) || ! isset($override['panel_id'], $override['amount_sen'])) {
                continue;
            }
            $requested[] = [
                'tier' => 'panel',
                'panel_id' => (int) $override['panel_id'],
                'scope' => 'panel_overrides.'.(int) $override['panel_id'],
                'name' => 'Panel '.$override['panel_id'],
                'amount_sen' => $override['amount_sen'],
            ];
        }

        if ($requested === []) {
            throw ValidationException::withMessages([
                'prices' => 'Enter at least one consultation tariff before saving.',
            ]);
        }

        DB::transaction(function () use ($actor, $attributes, $requested): void {
            Organisation::query()
                ->whereKey($actor->organisation_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOr(function (): never {
                    throw new ModelNotFoundException;
                });

            $charge = ChargeDefinition::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('source_key', 'consultation')
                ->lockForUpdate()
                ->first();
            if ($charge === null) {
                $charge = $this->references->createConsultationCharge($actor, [
                    'code' => $attributes['code'],
                    'display_name' => $attributes['display_name'],
                ]);
            } elseif (! $charge->is_active) {
                $charge = $this->references->activateCharge($actor, $charge);
            }

            foreach ($requested as $entry) {
                $scopeKey = match ($entry['scope']) {
                    'self_pay' => 'organisation',
                    'panel_default' => 'panel:default',
                    default => 'panel:'.$entry['panel_id'],
                };
                $book = PriceBook::query()
                    ->where('organisation_id', $actor->organisation_id)
                    ->where('scope_key', $scopeKey)
                    ->lockForUpdate()
                    ->first();
                if ($book === null) {
                    $book = $this->references->createPriceBook($actor, null, [
                        'name' => $entry['name'].' price book',
                        'currency' => 'MYR',
                        'price_tier' => $entry['tier'],
                        'panel_id' => $entry['panel_id'],
                    ]);
                } elseif (! $book->is_active) {
                    $book = $this->references->activatePriceBook($actor, $book);
                }

                $latest = PriceEntry::query()
                    ->where('price_book_id', $book->id)
                    ->where('charge_definition_id', $charge->id)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();
                $currentVersion = $latest->version ?? 0;
                $expectedVersion = data_get($attributes, 'expected_versions.'.$entry['scope']);
                if ((int) $expectedVersion !== $currentVersion) {
                    throw ValidationException::withMessages([
                        'prices' => 'A consultation tariff changed since this page was opened. Reload and review the current prices.',
                    ]);
                }

                if ($latest && $latest->unit_price_sen === (int) $entry['amount_sen']) {
                    continue;
                }

                $this->publication->publish(
                    $actor,
                    $book,
                    $charge,
                    $entry['amount_sen'],
                    $currentVersion,
                    (int) $attributes['expected_branch_id'],
                );
            }
        }, 3);
    }
}
