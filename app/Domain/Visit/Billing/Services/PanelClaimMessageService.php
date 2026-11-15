<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\PanelClaimMessageTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The plain-text message a Panel Officer copies and sends to a Panel by hand (there is no Panel
 * integration). One template per organisation for single claims; smart fields are filled from the claim.
 */
final class PanelClaimMessageService
{
    public const PERMISSION = 'panels.manage.organisation';

    public const KIND = 'single';

    public const MAX_LENGTH = 2000;

    /** @var array<string, string> */
    public const FIELDS = [
        'panel_name' => 'Panel name',
        'patient_name' => 'Patient name',
        'patient_nric' => 'Patient NRIC',
        'staff_id' => 'Staff ID',
        'claim_number' => 'Claim number',
        'claimed_amount' => 'Claimed amount',
        'submission_date' => 'Submission date',
        'claim_link' => 'Claim link',
        'clinic_name' => 'Clinic name',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{body: string, lockVersion: int, fields: list<array{key: string, label: string}>, maxLength: int} */
    public function forSettings(User $actor): array
    {
        $row = PanelClaimMessageTemplate::query()->where('organisation_id', $actor->organisation_id)->where('kind', self::KIND)->first();
        $fields = [];
        foreach (self::FIELDS as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label];
        }

        return ['body' => $row === null ? '' : $row->body, 'lockVersion' => $row === null ? 0 : $row->lock_version, 'fields' => $fields, 'maxLength' => self::MAX_LENGTH];
    }

    public function save(User $actor, mixed $body, mixed $expectedVersion): PanelClaimMessageTemplate
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        abort_unless($actor->is_active && $actor->organisation_id, 403);
        $version = filter_var($expectedVersion, FILTER_VALIDATE_INT);
        if ($version === false || $version < 0) {
            throw ValidationException::withMessages(['lock_version' => 'Reload the page and try again.']);
        }
        $text = $this->clean((string) $body);
        $used = $this->tokens($text);

        return DB::transaction(function () use ($actor, $text, $version, $used): PanelClaimMessageTemplate {
            $row = PanelClaimMessageTemplate::query()->where('organisation_id', $actor->organisation_id)
                ->where('kind', self::KIND)->lockForUpdate()->first();
            if (($row === null ? 0 : $row->lock_version) !== $version) {
                throw ValidationException::withMessages(['body' => 'This template was changed by someone else. Reload and review it again.']);
            }
            $row ??= new PanelClaimMessageTemplate;
            $row->forceFill([
                'organisation_id' => $actor->organisation_id, 'kind' => self::KIND, 'body' => $text,
                'lock_version' => $version + 1, 'updated_by_user_id' => $actor->id,
            ])->save();
            // Length and field names only: the message text itself stays out of the audit trail.
            $this->audit->record('panel_claim_message_template.updated', $row, ['length' => mb_strlen($text), 'fields' => $used], $actor, organisationId: $actor->organisation_id);

            return $row;
        }, 3);
    }

    /** @param  array<string, string>  $values */
    public static function render(string $body, array $values): ?string
    {
        if (trim($body) === '') {
            return null;
        }

        return preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => $values[$m[1]] ?? $m[0], $body);
    }

    private function clean(string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text) ?? '';
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'The message can be at most '.self::MAX_LENGTH.' characters.']);
        }

        return $text;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/\{([^{}\n]{1,40})\}/', $text, $matches);
        $unknown = array_values(array_filter($matches[1], static fn (string $name): bool => ! array_key_exists($name, self::FIELDS)));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => 'Unknown smart field: {'.$unknown[0].'}. Use the field buttons to insert fields.']);
        }

        return array_values(array_unique($matches[1]));
    }
}
