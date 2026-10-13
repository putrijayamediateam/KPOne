<?php

namespace App\Domain\Visit\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Services\PanelClaimDueRule;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checkout settings for Panels: contact, verification and claim due rule.
 * The Panel code is the stable identity and never changes after creation.
 */
final class PanelSettingsService
{
    public const PERMISSION = 'panels.manage.organisation';

    private const PER_PAGE = 10;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(User $actor, array $filters): array
    {
        $actor = $this->authorise($actor);
        $tab = in_array($filters['tab'] ?? 'all', ['all', 'active', 'archived'], true) ? (string) ($filters['tab'] ?? 'all') : 'all';
        $search = trim((string) ($filters['q'] ?? ''));

        $base = Panel::query()->where('organisation_id', $actor->organisation_id);
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_active', true)->count(),
            'archived' => (clone $base)->where('is_active', false)->count(),
        ];

        $query = clone $base;
        if ($tab !== 'all') {
            $query->where('is_active', $tab === 'active');
        }
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]));
        }

        $page = $query->orderByDesc('is_active')->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();
        $ownTiers = $ids === [] ? collect() : DB::table('price_books')
            ->where('organisation_id', $actor->organisation_id)->whereIn('panel_id', $ids)->where('is_active', true)
            ->orderBy('name')->get(['panel_id', 'name'])->groupBy('panel_id');

        return [
            'tab' => $tab,
            'q' => $search,
            'counts' => $counts,
            'panels' => $page->getCollection()->map(fn (Panel $panel): array => [
                'id' => $panel->id,
                'code' => $panel->code,
                'name' => $panel->name,
                'verificationMode' => $panel->verification_mode,
                'verificationUrl' => $panel->verification_url,
                'verificationRemark' => $panel->verification_remark,
                'priceTiers' => array_values(array_merge(['PANEL'], ($ownTiers[$panel->id] ?? collect())->pluck('name')->map(fn ($n): string => (string) $n)->all())),
                'isActive' => $panel->is_active,
                'lockVersion' => $panel->lock_version,
                'editUrl' => route('panel-settings.edit', $panel->id),
            ])->all(),
            'pagination' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
            'selfPayMethods' => DB::table('payment_methods')->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name')->map(fn ($n): string => (string) $n)->all(),
            'can' => ['managePaymentMethods' => $actor->can('payment_methods.manage.organisation')],
        ];
    }

    /** @return array<string, mixed> */
    public function form(User $actor, ?Panel $panel): array
    {
        $actor = $this->authorise($actor);
        if ($panel !== null) {
            $panel = $this->own($actor, $panel->id);
        }

        return ['panel' => $panel === null ? null : $this->detail($panel)];
    }

    /** @param  array<string, mixed>  $input */
    public function create(User $actor, array $input): Panel
    {
        $actor = $this->authorise($actor);
        $data = $this->validated($input);
        $code = mb_strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '' || mb_strlen($code) > 80 || preg_match('/[\p{C}\s]/u', $code) === 1) {
            throw ValidationException::withMessages(['code' => 'Enter a Panel code of 80 characters or fewer, without spaces.']);
        }

        return DB::transaction(function () use ($actor, $data, $code): Panel {
            Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (Panel::query()->where('organisation_id', $actor->organisation_id)->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->exists()) {
                throw ValidationException::withMessages(['code' => 'A Panel with this code already exists.']);
            }
            $panel = new Panel;
            $panel->forceFill($data + ['organisation_id' => $actor->organisation_id, 'code' => $code, 'is_active' => true, 'lock_version' => 1])->save();
            $this->audit->record('panel.created', $panel, ['panel_id' => $panel->id, 'code' => $panel->code, 'name' => $panel->name], $actor, organisationId: $actor->organisation_id);

            return $panel;
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    public function update(User $actor, Panel $routePanel, array $input): Panel
    {
        $actor = $this->authorise($actor);
        $data = $this->validated($input);
        $expected = filter_var($input['lock_version'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 1) {
            throw ValidationException::withMessages(['lock_version' => 'Reload the page and try again.']);
        }

        return DB::transaction(function () use ($actor, $routePanel, $data, $expected): Panel {
            $panel = Panel::query()->whereKey($routePanel->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($panel->lock_version !== $expected) {
                throw ValidationException::withMessages(['panel' => 'This Panel was changed by someone else. Reload and review it again.']);
            }
            $changed = array_keys(array_filter($data, fn ($value, string $key): bool => $panel->getAttribute($key) !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $panel->forceFill($data + ['lock_version' => $panel->lock_version + 1])->save();
                // Field names only: contact details and links stay out of the audit trail.
                $this->audit->record('panel.updated', $panel, ['panel_id' => $panel->id, 'changed_fields' => $changed], $actor, organisationId: $actor->organisation_id);
            }

            return $panel;
        }, 3);
    }

    public function setActive(User $actor, Panel $routePanel, bool $active, mixed $expectedVersion): Panel
    {
        $actor = $this->authorise($actor);
        $expected = filter_var($expectedVersion, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 1) {
            throw ValidationException::withMessages(['lock_version' => 'Reload the page and try again.']);
        }

        return DB::transaction(function () use ($actor, $routePanel, $active, $expected): Panel {
            $panel = Panel::query()->whereKey($routePanel->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($panel->lock_version !== $expected) {
                throw ValidationException::withMessages(['panel' => 'This Panel was changed by someone else. Reload and review it again.']);
            }
            if ($panel->is_active !== $active) {
                $panel->forceFill(['is_active' => $active, 'lock_version' => $panel->lock_version + 1])->save();
                $this->audit->record($active ? 'panel.restored' : 'panel.archived', $panel, ['panel_id' => $panel->id, 'code' => $panel->code], $actor, organisationId: $actor->organisation_id);
            }

            return $panel;
        }, 3);
    }

    private function authorise(User $actor): User
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        abort_unless($actor->is_active && $actor->organisation_id, 403);

        return $actor;
    }

    private function own(User $actor, int $id): Panel
    {
        return Panel::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function detail(Panel $panel): array
    {
        return [
            'id' => $panel->id, 'code' => $panel->code, 'name' => $panel->name, 'isActive' => $panel->is_active, 'lockVersion' => $panel->lock_version,
            'panelType' => $panel->panel_type, 'companyRegistrationNumber' => $panel->company_registration_number, 'companyName' => $panel->company_name,
            'personInCharge' => $panel->person_in_charge, 'phone' => $panel->phone, 'email' => $panel->email,
            'addressLine1' => $panel->address_line_1, 'addressLine2' => $panel->address_line_2, 'postcode' => $panel->postcode,
            'city' => $panel->city, 'state' => $panel->state, 'country' => $panel->country,
            'verificationMode' => $panel->verification_mode, 'verificationUrl' => $panel->verification_url, 'verificationRemark' => $panel->verification_remark,
            'claimDueDays' => $panel->claim_due_days, 'claimDueRule' => $panel->claim_due_rule, 'claimDueTime' => $panel->claim_due_time, 'claimDueOffset' => $panel->claim_due_offset, 'incomeTaxNumber' => $panel->income_tax_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $v = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'panel_type' => ['nullable', 'in:tpa,direct_company'],
            'company_registration_number' => ['nullable', 'regex:/^[0-9]{12}$/'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'person_in_charge' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]+$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'verification_mode' => ['required', 'in:url,remark'],
            'verification_url' => ['nullable', 'string', 'max:500', 'url:https'],
            'verification_remark' => ['nullable', 'string', 'max:500'],
            'claim_due_rule' => ['required', 'in:'.implode(',', PanelClaimDueRule::RULES)],
            'claim_due_days' => ['required_if:claim_due_rule,days_after_visit', 'nullable', 'integer', 'between:0,365'],
            'claim_due_time' => ['required_unless:claim_due_rule,days_after_visit', 'nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'claim_due_offset' => ['required_unless:claim_due_rule,days_after_visit', 'nullable', 'integer'],
            'income_tax_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
        ], [
            'company_registration_number.regex' => 'The company registration number must be exactly 12 digits.',
            'verification_url.url' => 'Enter a secure link that starts with https://.',
            'phone.regex' => 'Enter a valid phone number.',
            'claim_due_time.regex' => 'Enter the time as HH:MM.',
        ]);
        $clean = $v->validate();
        $text = static function (mixed $value): ?string {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

            return $value === '' ? null : $value;
        };
        $name = $text($clean['name']);
        if ($name === null || preg_match('/[\p{C}]/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Enter a readable Panel name.']);
        }
        $rule = (string) $clean['claim_due_rule'];
        $offset = $rule === 'days_after_visit' ? null : (int) $clean['claim_due_offset'];
        $range = ['daily' => [0, 3], 'weekly' => [1, 7], 'monthly' => [0, 28]][$rule] ?? null;
        if ($range !== null && ($offset < $range[0] || $offset > $range[1])) {
            throw ValidationException::withMessages(['claim_due_offset' => 'Choose a valid day for this rule.']);
        }
        $url = $clean['verification_mode'] === 'url' ? $text($clean['verification_url'] ?? null) : null;
        $remark = $clean['verification_mode'] === 'remark' ? $text($clean['verification_remark'] ?? null) : null;

        return [
            'name' => $name,
            'panel_type' => $clean['panel_type'] ?? null,
            'company_registration_number' => $clean['company_registration_number'] ?? null,
            'company_name' => $text($clean['company_name'] ?? null),
            'person_in_charge' => $text($clean['person_in_charge'] ?? null),
            'phone' => $text($clean['phone'] ?? null),
            'email' => $text($clean['email'] ?? null),
            'address_line_1' => $text($clean['address_line_1'] ?? null),
            'address_line_2' => $text($clean['address_line_2'] ?? null),
            'postcode' => $text($clean['postcode'] ?? null),
            'city' => $text($clean['city'] ?? null),
            'state' => $text($clean['state'] ?? null),
            'country' => $text($clean['country'] ?? null),
            'verification_mode' => $clean['verification_mode'],
            'verification_url' => $url,
            'verification_remark' => $remark,
            'claim_due_rule' => $rule,
            'claim_due_days' => (int) ($clean['claim_due_days'] ?? 30),
            'claim_due_time' => $rule === 'days_after_visit' ? null : (string) $clean['claim_due_time'],
            'claim_due_offset' => $offset,
            'income_tax_number' => $text($clean['income_tax_number'] ?? null),
        ];
    }
}
