<?php

namespace App\Domain\Patient\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PublicPatientIntakeService
{
    public function __construct(
        private PublicIntakePayloadValidator $payloads,
        private AuditRecorder $audit,
    ) {}

    /** @return array{session: PublicIntakeSession, nonce: string, statusReceipt: string, expiresAt: string} */
    public function openSession(PublicCheckInLink $link): array
    {
        $this->ensureEnabled();
        $nonce = $this->opaqueToken();
        $receipt = $this->opaqueToken();
        $idempotencyKey = (string) Str::uuid();
        $expiresAt = now()->utc()->addMinutes((int) config('public-intake.submission_session_ttl_minutes', 15));

        $session = PublicIntakeSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $link->organisation_id,
            'branch_id' => $link->branch_id,
            'public_checkin_link_id' => $link->id,
            'nonce_digest' => hash('sha256', $nonce),
            'status_receipt_digest' => hash('sha256', $receipt),
            'submission_idempotency_key' => $idempotencyKey,
            'expires_at' => $expiresAt,
        ]);

        return [
            'session' => $session,
            'nonce' => $nonce,
            'statusReceipt' => $receipt,
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    public function resumableSession(
        #[\SensitiveParameter] ?string $nonce,
        #[\SensitiveParameter] ?string $receipt,
    ): ?PublicIntakeSession {
        $this->ensureEnabled();
        if (! $this->isOpaqueToken($nonce) || ! $this->isOpaqueToken($receipt)) {
            return null;
        }

        return PublicIntakeSession::query()
            ->where('nonce_digest', hash('sha256', $nonce))
            ->where('status_receipt_digest', hash('sha256', $receipt))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now()->utc())
            ->whereHas('link', fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', now()->utc()))
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->with(['branch:id,organisation_id,name,is_active'])
            ->first();
    }

    public function statusSession(#[\SensitiveParameter] ?string $receipt): PublicIntakeSession
    {
        $this->ensureEnabled();
        abort_unless($this->isOpaqueToken($receipt), 404);

        return PublicIntakeSession::query()
            ->where('status_receipt_digest', hash('sha256', $receipt))
            ->where('expires_at', '>', now()->utc())
            ->with(['branch:id,organisation_id,name'])
            ->firstOrFail();
    }

    /** @return array{session: PublicIntakeSession, allowsCreation: bool} */
    public function submissionSession(
        #[\SensitiveParameter] ?string $nonce,
        #[\SensitiveParameter] ?string $receipt,
    ): array {
        $this->ensureEnabled();

        if ($this->isOpaqueToken($nonce) && $this->isOpaqueToken($receipt)) {
            $session = PublicIntakeSession::query()
                ->where('nonce_digest', hash('sha256', $nonce))
                ->where('status_receipt_digest', hash('sha256', $receipt))
                ->where('expires_at', '>', now()->utc())
                ->first();
            if ($session !== null) {
                return ['session' => $session, 'allowsCreation' => true];
            }
        }

        if ($this->isOpaqueToken($receipt)) {
            $session = PublicIntakeSession::query()
                ->where('status_receipt_digest', hash('sha256', $receipt))
                ->where('expires_at', '>', now()->utc())
                ->whereHas('intake')
                ->first();
            if ($session !== null) {
                return ['session' => $session, 'allowsCreation' => false];
            }
        }

        abort(404);
    }

    /** @param array<string, mixed> $attributes */
    public function submit(PublicIntakeSession $boundSession, array $attributes, bool $allowsCreation): PublicPatientIntake
    {
        $this->ensureEnabled();
        $payload = $this->payloads->validate($attributes);
        $fingerprint = $this->fingerprint($payload);

        return DB::transaction(function () use ($boundSession, $allowsCreation, $payload, $fingerprint): PublicPatientIntake {
            $session = PublicIntakeSession::query()
                ->whereKey($boundSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PublicPatientIntake::query()
                ->where('public_intake_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'submission' => 'Maklumat penghantaran telah berubah. Mulakan semula pendaftaran.',
                    ]);
                }

                return $existing;
            }
            if (! $allowsCreation) {
                abort(404);
            }
            if ($session->consumed_at !== null || ! $session->expires_at->isFuture()) {
                throw ValidationException::withMessages(['nonce' => 'Sesi borang telah tamat. Mulakan semula.']);
            }

            $lockedLink = PublicCheckInLink::query()
                ->whereKey($session->public_checkin_link_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $lockedLink->is_active || $lockedLink->revoked_at !== null
                || $lockedLink->expires_at === null || ! $lockedLink->expires_at->isFuture()
                || ! $lockedLink->branch()->where('is_active', true)->exists()) {
                abort(404);
            }

            $now = now()->utc();
            $retentionAt = $now->copy()->addDays((int) config('public-intake.retention_days', 30));
            $intake = PublicPatientIntake::query()->create([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $lockedLink->organisation_id,
                'branch_id' => $lockedLink->branch_id,
                'public_checkin_link_id' => $lockedLink->id,
                'public_intake_session_id' => $session->id,
                'status' => PublicPatientIntake::STATUS_PENDING,
                'submission_type' => $payload['submission_type'],
                'encrypted_payload' => $payload,
                'payload_fingerprint' => $fingerprint,
                'privacy_notice_version' => $payload['consent']['privacy_notice_version'],
                'consented_at' => $now,
                'submitted_at' => $now,
                'expires_at' => $retentionAt,
                'payload_purge_at' => $retentionAt,
                'lock_version' => 1,
            ]);
            $session->forceFill([
                'payload_fingerprint' => $fingerprint,
                'consumed_at' => $now,
                'expires_at' => $retentionAt,
            ])->save();

            $this->audit->record('public_intake.submitted', $intake, [
                'public_intake_public_id' => $intake->public_id,
                'from_state' => null,
                'to_state' => PublicPatientIntake::STATUS_PENDING,
                'submission_type' => $intake->submission_type,
                'privacy_notice_version' => $intake->privacy_notice_version,
            ], branch: $lockedLink->branch, organisationId: $lockedLink->organisation_id);

            return $intake;
        }, 3);
    }

    /** @return array{state: string, message: string, branch: string, queueNumber: string|null} */
    public function status(PublicIntakeSession $boundSession): array
    {
        $this->ensureEnabled();
        $session = PublicIntakeSession::query()
            ->whereKey($boundSession->id)
            ->where('expires_at', '>', now()->utc())
            ->with(['branch:id,organisation_id,name'])
            ->firstOrFail();
        $intake = PublicPatientIntake::query()
            ->where('public_intake_session_id', $session->id)
            ->with('queueEntry:id,queue_number')
            ->firstOrFail();

        $state = $intake->status;
        if (in_array($state, [PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_UNDER_REVIEW, PublicPatientIntake::STATUS_CORRECTION_REQUIRED], true)
            && ! $intake->expires_at->isFuture()) {
            $state = PublicPatientIntake::STATUS_EXPIRED;
        }

        return [
            'state' => $state,
            'message' => match ($state) {
                PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_UNDER_REVIEW => 'Maklumat diterima. Sila tunggu sementara staff kami membuat semakan.',
                PublicPatientIntake::STATUS_CORRECTION_REQUIRED => 'Maklumat memerlukan semakan lanjut. Sila hadir ke kaunter klinik.',
                PublicPatientIntake::STATUS_ACCEPTED => 'Pendaftaran disahkan. Sila tunggu nombor anda dipanggil.',
                PublicPatientIntake::STATUS_REJECTED => 'Pendaftaran ini tidak dapat diteruskan. Sila hadir ke kaunter klinik.',
                default => 'Pautan status ini telah tamat. Sila hadir ke kaunter klinik.',
            },
            'branch' => $session->branch->name,
            'queueNumber' => $state === PublicPatientIntake::STATUS_ACCEPTED && $intake->queueEntry
                ? sprintf('%03d', $intake->queueEntry->queue_number) : null,
        ];
    }

    /** @param array<string, mixed> $value */
    public function fingerprint(array $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application encryption key is required for intake payload fingerprints.');
        }

        return hash_hmac('sha256', json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR), $key);
    }

    public function ensureEnabled(): void
    {
        abort_unless((bool) config('public-intake.enabled'), 404);
    }

    private function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function isOpaqueToken(?string $token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? $this->sortRecursive($item) : $item, $value);
        }
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? $this->sortRecursive($item) : $item, $value);
    }
}
