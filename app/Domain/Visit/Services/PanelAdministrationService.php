<?php

namespace App\Domain\Visit\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PanelAdministrationService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array{code:mixed,name:mixed} $attributes */
    public function create(User $actor, array $attributes): Panel
    {
        Gate::forUser($actor)->authorize('pricing.references.manage.organisation');

        if (! $actor->is_active || ! $actor->organisation_id) {
            abort(403);
        }

        $code = Str::upper(trim((string) ($attributes['code'] ?? '')));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($attributes['name'] ?? '')) ?? '');
        if ($code === '' || mb_strlen($code) > 80 || preg_match('/[\p{C}]/u', $code) === 1) {
            throw ValidationException::withMessages(['code' => 'Enter a readable Panel code of 80 characters or fewer.']);
        }
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\p{C}]/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Enter a readable Panel name of 255 characters or fewer.']);
        }

        return DB::transaction(function () use ($actor, $code, $name): Panel {
            Organisation::query()->whereKey($actor->organisation_id)
                ->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (Panel::query()->where('organisation_id', $actor->organisation_id)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->exists()) {
                throw ValidationException::withMessages(['code' => 'A Panel with this code already exists.']);
            }

            $panel = new Panel;
            $panel->forceFill([
                'organisation_id' => $actor->organisation_id,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
            ])->save();
            $this->audit->record('panel.created', $panel, [
                'panel_id' => $panel->id,
                'code' => $panel->code,
                'name' => $panel->name,
            ], $actor, organisationId: $actor->organisation_id);

            return $panel;
        }, 3);
    }
}
