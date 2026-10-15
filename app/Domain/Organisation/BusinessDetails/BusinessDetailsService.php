<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

final class BusinessDetailsService
{
    public function __construct(private BusinessDetailsAccess $access, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function save(User $actor, array $attributes, ?UploadedFile $file = null): void
    {
        abort_unless($actor->can(BusinessDetailsAccess::VIEW) && $actor->can(BusinessDetailsAccess::MANAGE), 403);
        foreach ($attributes as $field => $value) {
            if (is_string($value)) {
                $attributes[$field] = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            }
        }
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'],
            'organisation_lock_version' => ['required', 'integer', 'min:0'], 'branch_lock_version' => ['required', 'integer', 'min:0'],
            'legal_name' => ['required', 'string', 'max:200'], 'clinic_name' => ['required', 'string', 'max:200'],
            'registration_type' => ['nullable', 'in:BRN', 'required_with:registration_number'],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'address_line_1' => ['required', 'string', 'max:255'], 'address_line_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:20'], 'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'], 'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email:rfc', 'max:254'],
            'remove_logo' => ['sometimes', 'boolean'],
            'organisation_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'logo_asset_id' => ['prohibited'], 'path' => ['prohibited'],
        ])->validate();
        $data['phone'] = $this->phone($data['phone'] ?? null);
        $remove = filter_var($data['remove_logo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($file && $remove) {
            throw ValidationException::withMessages(['logo' => 'Choose a replacement logo or remove the current logo, not both.']);
        }
        $image = $file ? $this->image($file) : null;
        $organisationFields = ['legal_name' => $data['legal_name'],
            'registration_type' => empty($data['registration_number']) ? null : $data['registration_type'],
            'registration_number' => ($data['registration_number'] ?? '') === '' ? null : $data['registration_number']];
        $branchFields = [];
        foreach (['clinic_name', 'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country', 'phone', 'email'] as $field) {
            $branchFields[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        }
        $hash = hash('sha256', json_encode([(int) $data['expected_branch_id'], (int) $data['organisation_lock_version'],
            (int) $data['branch_lock_version'], $organisationFields, $branchFields, $remove, $image['sha256'] ?? null], JSON_THROW_ON_ERROR));
        $stagedPath = null;
        $usedStaged = false;
        $committed = false;
        try {
            DB::transaction(function () use ($actor, $data, $organisationFields, $branchFields, $remove, $file, $image, $hash, &$stagedPath, &$usedStaged): void {
                $usedStaged = false;
                [$actor, $organisation, $branch] = $this->access->lock($actor, $data['expected_branch_id'], true);
                $command = DB::table('business_detail_commands')->where('organisation_id', $organisation->id)
                    ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($command) {
                    if ((int) $command->actor_user_id !== $actor->id || (int) $command->branch_id !== $branch->id || $command->payload_hash !== $hash) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details. Reload before continuing.']);
                    }

                    return;
                }
                $org = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->lockForUpdate()->first();
                $details = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->lockForUpdate()->first();
                if (($org->lock_version ?? 0) !== (int) $data['organisation_lock_version'] || ($details->lock_version ?? 0) !== (int) $data['branch_lock_version']) {
                    throw ValidationException::withMessages(['version' => 'Business details have changed. Reload before saving again.']);
                }
                $org ??= new OrganisationBusinessProfile;
                $details ??= new BranchBusinessProfile;
                $changed = [];
                foreach ($organisationFields as $field => $value) {
                    if ($org->getAttribute($field) !== $value) {
                        $changed[] = 'organisation.'.$field;
                    }
                }
                foreach ($branchFields as $field => $value) {
                    if ($details->getAttribute($field) !== $value) {
                        $changed[] = 'branch.'.$field;
                    }
                }
                $oldLogo = $org->logo_asset_id;
                if ($file && $image) {
                    $id = $stagedPath ? pathinfo($stagedPath, PATHINFO_FILENAME) : (string) Str::uuid();
                    if ($stagedPath === null) {
                        $stagedPath = "business-details/{$organisation->id}/{$id}.{$image['extension']}";
                        if (! Storage::disk('local')->put($stagedPath, $file->getContent())) {
                            throw ValidationException::withMessages(['logo' => 'The logo could not be stored. Try again.']);
                        }
                    }
                    $asset = new BusinessDetailAsset;
                    $asset->forceFill(['public_id' => $id, 'organisation_id' => $organisation->id, 'path' => $stagedPath,
                        'mime' => $image['mime'], 'sha256' => $image['sha256'], 'bytes' => $image['bytes'], 'width' => $image['width'], 'height' => $image['height']])->save();
                    $organisationFields['logo_asset_id'] = $asset->id;
                    $usedStaged = true;
                    $changed[] = 'logo';
                } elseif ($remove && $oldLogo !== null) {
                    $organisationFields['logo_asset_id'] = null;
                    $changed[] = 'logo';
                }
                if (in_array('logo', $changed, true) && $oldLogo !== null) {
                    BusinessDetailAsset::query()->whereKey($oldLogo)->where('organisation_id', $organisation->id)
                        ->lockForUpdate()->firstOrFail()->forceFill(['retired_at' => now()->utc()])->save();
                }
                $org->forceFill(['organisation_id' => $organisation->id, ...$organisationFields]);
                if (! $org->exists || $org->isDirty()) {
                    $org->forceFill(['lock_version' => ($org->lock_version ?? 0) + 1])->save();
                }
                $details->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, ...$branchFields]);
                if (! $details->exists || $details->isDirty()) {
                    $details->forceFill(['lock_version' => ($details->lock_version ?? 0) + 1])->save();
                }
                DB::table('business_detail_commands')->insert(['organisation_id' => $organisation->id, 'branch_id' => $branch->id,
                    'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                    'organisation_version' => $org->lock_version, 'branch_version' => $details->lock_version, 'created_at' => now()->utc()]);
                $this->audit->record('business_details.saved', $org, ['changed_fields' => $changed,
                    'organisation_version' => $org->lock_version, 'branch_version' => $details->lock_version], $actor, $branch);
            }, 3);
            $committed = true;
            // A retried transaction can replay another committed request after staging a candidate file.
            if ($stagedPath !== null && ! $usedStaged) {
                Storage::disk('local')->delete($stagedPath);
            }
        } catch (Throwable $e) {
            if (! $committed && $stagedPath !== null) {
                Storage::disk('local')->delete($stagedPath);
            }

            throw $e;
        }
    }

    private function phone(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($input, 'MY');
            if ($util->isValidNumber($number)) {
                return $util->format($number, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // Only the safe field-level message leaves this boundary.
        }
        throw ValidationException::withMessages(['phone' => 'Enter a valid clinic phone number, including the country code.']);
    }

    /** @return array{mime: string, extension: string, sha256: string, bytes: int, width: int, height: int} */
    private function image(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['logo' => 'Choose a JPG or PNG logo no larger than 2 MB.']);
        }
        $mime = $file->getMimeType();
        $size = @getimagesize($file->getPathname());
        if (! in_array($mime, ['image/jpeg', 'image/png'], true) || ! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 || $size['mime'] !== $mime) {
            throw ValidationException::withMessages(['logo' => 'Choose a valid JPG or PNG image, at most 4096 × 4096 pixels.']);
        }
        $decoded = @imagecreatefromstring($file->getContent());
        if ($decoded === false) {
            throw ValidationException::withMessages(['logo' => 'The logo image could not be decoded. Choose a valid JPG or PNG.']);
        }
        imagedestroy($decoded);

        $digest = hash_file('sha256', $file->getPathname());
        if ($digest === false) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be read. Choose the file again.']);
        }

        return ['mime' => $mime, 'extension' => $mime === 'image/png' ? 'png' : 'jpg', 'sha256' => $digest,
            'bytes' => (int) $file->getSize(), 'width' => $size[0], 'height' => $size[1]];
    }
}
