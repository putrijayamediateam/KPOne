<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BranchDocumentFooterService
{
    public function __construct(private BusinessDetailsAccess $access, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function save(User $actor, array $attributes): void
    {
        abort_unless($actor->can(BusinessDetailsAccess::VIEW), 403);
        if (is_string($attributes['footer_text'] ?? null)) {
            $attributes['footer_text'] = trim(str_replace(["\r\n", "\r"], "\n", $attributes['footer_text']));
        }
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
            'footer_text' => ['present', 'nullable', 'string', 'max:1000', function ($attribute, $value, $fail) {
                if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
                    $fail('Use plain text without HTML or control characters.');
                }
            }],
        ])->validate();
        $text = ($data['footer_text'] ?? '') === '' ? null : $data['footer_text'];
        $hash = hash('sha256', json_encode(['branch-print-footer', (int) $data['expected_branch_id'], (int) $data['lock_version'], $text], JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($actor, $data, $text, $hash): void {
            [$actor, $organisation, $branch] = $this->access->lock($actor, $data['expected_branch_id']);
            $command = DB::table('business_detail_commands')->where('organisation_id', $organisation->id)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($command) {
                if ((int) $command->actor_user_id !== $actor->id || (int) $command->branch_id !== $branch->id || $command->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details. Reload before saving.']);
                }

                return;
            }
            $footer = BranchDocumentFooter::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            if (($footer->lock_version ?? 0) !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'The print footer changed. Reload before saving again.']);
            }
            $footer ??= new BranchDocumentFooter;
            $footer->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'text' => $text,
                'lock_version' => ($footer->lock_version ?? 0) + 1])->save();
            DB::table('business_detail_commands')->insert(['organisation_id' => $organisation->id, 'branch_id' => $branch->id,
                'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'organisation_version' => OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->value('lock_version') ?? 0,
                'branch_version' => $footer->lock_version, 'created_at' => now()->utc()]);
            $this->audit->record('business_details.print_footer_saved', $footer, ['changed_fields' => ['footer_text'], 'record_version' => $footer->lock_version], $actor, $branch);
        }, 3);
    }
}
