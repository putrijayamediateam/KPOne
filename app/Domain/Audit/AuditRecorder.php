<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditRecorder
{
    public const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_SEGMENTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'authorization',
        'cookie',
        'session',
        'credential',
        'credentials',
    ];

    private const SENSITIVE_COMPOUND_KEYS = [
        'api_key',
        'headers',
        'http_headers',
        'raw_headers',
        'request_headers',
        'body',
        'request_body',
        'raw_body',
    ];

    /** @param array<string, mixed> $metadata */
    public function record(
        string $event,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
        ?Branch $branch = null,
        ?int $organisationId = null,
    ): ?AuditLog {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $request = app()->bound('request') ? request() : null;
        $actorId = $actor instanceof User ? $actor->id : Auth::id();
        $actorOrganisationId = $actor instanceof User
            ? $actor->organisation_id
            : User::query()->whereKey($actorId)->value('organisation_id');
        $organisationId ??= $actorOrganisationId
            ?? ($subject instanceof User ? $subject->organisation_id : null)
            ?? $branch?->organisation_id;

        return AuditLog::query()->create([
            'request_id' => $this->requestId($request?->header('X-Request-ID')),
            'organisation_id' => $organisationId,
            'branch_id' => $branch?->id,
            'actor_user_id' => $actorId,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 1000, '') : null,
            'metadata' => $this->sanitize($metadata),
            'occurred_at' => now(),
        ]);
    }

    private function requestId(?string $value): string
    {
        return $value && Str::isUuid($value) ? $value : (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        foreach (array_keys($values) as $key) {
            if ($this->isSensitiveKey((string) $key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($values[$key])) {
                $values[$key] = $this->sanitize($values[$key]);
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $wordBoundaries = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key) ?? $key;
        $wordBoundaries = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $wordBoundaries) ?? $wordBoundaries;
        $normalized = Str::of($wordBoundaries)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if ($normalized === '') {
            return false;
        }

        $segments = explode('_', $normalized);

        if (array_intersect($segments, self::SENSITIVE_KEY_SEGMENTS) !== []) {
            return true;
        }

        foreach (self::SENSITIVE_COMPOUND_KEYS as $compoundKey) {
            if (preg_match('/(?:^|_)'.preg_quote($compoundKey, '/').'(?:_|$)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
