<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentMethodAdministrationService
{
    public const PERMISSION = 'payment_methods.manage.organisation';

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): PaymentMethod
    {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['code', 'name', 'description', 'requires_reference', 'sort_order']);
        $values = $this->values($attributes, true);

        return DB::transaction(function () use ($actor, $values): PaymentMethod {
            $this->lockOrganisation($actor);
            if (PaymentMethod::query()->where('organisation_id', $actor->organisation_id)
                ->whereRaw('LOWER(code) = ?', [Str::lower($values['code'])])->exists()) {
                $this->invalid('code', 'A retained Payment Method with this code already exists in the organisation.');
            }

            $method = new PaymentMethod;
            $method->forceFill([
                'organisation_id' => $actor->organisation_id,
                ...$values,
                'is_active' => false,
            ])->save();
            $this->record('payment_method.created', $method, $actor, [
                'code', 'name', 'description', 'requires_reference', 'sort_order', 'is_active',
            ]);

            return $method;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, PaymentMethod $method, array $attributes): PaymentMethod
    {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['name', 'description', 'requires_reference', 'sort_order']);
        $values = $this->values($attributes, false);

        return DB::transaction(function () use ($actor, $method, $values): PaymentMethod {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $method);
            $changed = array_keys(array_filter($values, fn (mixed $value, string $field): bool => $locked->{$field} !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $locked;
            }

            $locked->forceFill($values)->save();
            $this->record('payment_method.updated', $locked, $actor, $changed);

            return $locked;
        }, 3);
    }

    public function publish(User $actor, PaymentMethod $method): PaymentMethod
    {
        return $this->setOperational($actor, $method, true, 'payment_method.published');
    }

    public function deactivate(User $actor, PaymentMethod $method): PaymentMethod
    {
        return $this->setOperational($actor, $method, false, 'payment_method.deactivated');
    }

    private function setOperational(User $actor, PaymentMethod $method, bool $active, string $event): PaymentMethod
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $method, $active, $event): PaymentMethod {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $method);
            if ($locked->is_active === $active) {
                return $locked;
            }

            $locked->forceFill(['is_active' => $active])->save();
            $this->record($event, $locked, $actor, ['is_active']);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes, bool $creating): array
    {
        $values = [];
        if ($creating) {
            $values['code'] = $this->code($attributes['code'] ?? null);
        }
        foreach (['name' => 100, 'description' => 500] as $field => $maximum) {
            if (array_key_exists($field, $attributes)) {
                $values[$field] = $field === 'description' && $attributes[$field] === null
                    ? null
                    : $this->text($attributes[$field], $field, $maximum);
            } elseif ($creating && $field === 'name') {
                $values[$field] = $this->text(null, $field, $maximum);
            }
        }
        if (array_key_exists('requires_reference', $attributes)) {
            if (! is_bool($attributes['requires_reference'])) {
                $this->invalid('requires_reference', 'Choose whether this Payment Method requires a transaction reference.');
            }
            $values['requires_reference'] = $attributes['requires_reference'];
        } elseif ($creating) {
            $values['requires_reference'] = false;
        }
        if (array_key_exists('sort_order', $attributes)) {
            if (! is_int($attributes['sort_order']) || $attributes['sort_order'] < 0 || $attributes['sort_order'] > 32767) {
                $this->invalid('sort_order', 'Enter a sort order from 0 to 32767.');
            }
            $values['sort_order'] = $attributes['sort_order'];
        } elseif ($creating) {
            $values['sort_order'] = 100;
        }

        return $values;
    }

    private function code(mixed $value): string
    {
        $code = Str::upper($this->text($value, 'code', 40));
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]*\z/', $code) !== 1) {
            $this->invalid('code', 'Use letters, numbers, hyphens, or underscores for the Payment Method code.');
        }

        return $code;
    }

    private function text(mixed $value, string $field, int $maximum): string
    {
        if (! is_string($value)) {
            $this->invalid($field, "Enter a valid {$field}.");
        }
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\p{C}]/u', $value) === 1) {
            $this->invalid($field, "Enter a readable {$field} of {$maximum} characters or fewer.");
        }

        return $value;
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage Payment Method references.');
        }
    }

    private function lockOrganisation(User $actor): Organisation
    {
        return Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    private function lockOwned(User $actor, PaymentMethod $method): PaymentMethod
    {
        return PaymentMethod::query()->whereKey($method->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    /** @param array<string, mixed> $attributes
     * @param  list<string>  $allowed
     */
    private function assertOnly(array $attributes, array $allowed): void
    {
        $unsupported = array_diff(array_keys($attributes), $allowed);
        if ($unsupported !== []) {
            $this->invalid((string) reset($unsupported), 'This Payment Method field is not administratively editable.');
        }
    }

    /** @param list<string> $changedFields */
    private function record(string $event, PaymentMethod $method, User $actor, array $changedFields): void
    {
        $this->audit->record($event, $method, [
            'reference_code' => $method->code,
            'changed_fields' => $changedFields,
        ], $actor, organisationId: $actor->organisation_id);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
