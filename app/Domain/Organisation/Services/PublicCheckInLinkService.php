<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicCheckInLinkService
{
    public function __construct(private AuditRecorder $audit) {}

    /** @return array{link: PublicCheckInLink, rawToken: string} */
    public function issue(User $actor, Branch $branch, string $label): array
    {
        $this->authorize($actor, $branch);

        return DB::transaction(function () use ($actor, $branch, $label): array {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if (PublicCheckInLink::query()->where('branch_id', $branch->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'This branch already has an active public check-in link. Rotate it instead.']);
            }

            [$link, $rawToken] = $this->createLink($actor, $branch, $label);
            $this->audit->record('public_checkin_link.created', $link, [
                'public_checkin_link_public_id' => $link->public_id,
                'branch_id' => $branch->id,
            ], $actor, $branch);

            return compact('link', 'rawToken');
        }, 3);
    }

    /** @return array{link: PublicCheckInLink, rawToken: string} */
    public function rotate(User $actor, PublicCheckInLink $current): array
    {
        $this->authorize($actor, $current->branch);

        return DB::transaction(function () use ($actor, $current): array {
            $locked = PublicCheckInLink::query()
                ->whereKey($current->id)->where('organisation_id', $actor->organisation_id)
                ->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->revoked_at !== null) {
                throw ValidationException::withMessages(['link' => 'Only an active public check-in link can be rotated.']);
            }

            $this->markRevoked($locked, $actor);
            [$link, $rawToken] = $this->createLink($actor, $locked->branch, $locked->label, $locked->id);
            $this->audit->record('public_checkin_link.rotated', $link, [
                'public_checkin_link_public_id' => $link->public_id,
                'rotated_from_public_id' => $locked->public_id,
                'branch_id' => $locked->branch_id,
            ], $actor, $locked->branch);

            return compact('link', 'rawToken');
        }, 3);
    }

    public function revoke(User $actor, PublicCheckInLink $current): void
    {
        $this->authorize($actor, $current->branch);

        DB::transaction(function () use ($actor, $current): void {
            $locked = PublicCheckInLink::query()
                ->whereKey($current->id)->where('organisation_id', $actor->organisation_id)
                ->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->revoked_at !== null) {
                return;
            }

            $this->markRevoked($locked, $actor);
            $this->audit->record('public_checkin_link.revoked', $locked, [
                'public_checkin_link_public_id' => $locked->public_id,
                'branch_id' => $locked->branch_id,
            ], $actor, $locked->branch);
        }, 3);
    }

    public function resolve(#[\SensitiveParameter] string $rawToken): PublicCheckInLink
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{43}\z/', $rawToken) === 1, 404);

        return PublicCheckInLink::query()
            ->with('branch:id,organisation_id,name,is_active')
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now()->utc())
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();
    }

    public function recover(User $actor, PublicCheckInLink $link): ?string
    {
        $this->authorize($actor, $link->branch);

        if (! $link->is_active || $link->revoked_at !== null || ! $link->expires_at?->isFuture()) {
            return null;
        }

        $rawToken = rescue(
            fn (): string => (string) $link->encrypted_token,
            report: false,
        );

        if (! is_string($rawToken)) {
            return null;
        }

        if ($rawToken === '' || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $rawToken) !== 1
            || ! hash_equals($link->token_hash, hash('sha256', $rawToken))) {
            return null;
        }

        return $rawToken;
    }

    private function authorize(User $actor, Branch $branch): void
    {
        $canManageOrganisation = $actor->can('public_checkin_links.manage.organisation');
        $canManageBranch = $actor->can('public_checkin_links.manage.branch');
        if (! $canManageOrganisation && ! $canManageBranch) {
            throw new AuthorizationException;
        }
        if ($actor->organisation_id !== $branch->organisation_id || ! $branch->is_active
            || (! $canManageOrganisation
                && ! app(BranchAccessService::class)->hasEffectiveAssignment($actor, $branch))) {
            throw new AuthorizationException;
        }
    }

    /** @return array{PublicCheckInLink, string} */
    private function createLink(User $actor, Branch $branch, string $label, ?int $rotatedFromId = null): array
    {
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = PublicCheckInLink::query()->create([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $branch->organisation_id,
            'branch_id' => $branch->id,
            'token_hash' => hash('sha256', $rawToken),
            'encrypted_token' => $rawToken,
            'label' => Str::limit(trim($label), 120, ''),
            'is_active' => true,
            'active_branch_guard' => 'branch:'.$branch->id,
            'expires_at' => now()->utc()->addDays((int) config('public-intake.link_ttl_days', 90)),
            'created_by_user_id' => $actor->id,
            'rotated_from_id' => $rotatedFromId,
        ]);

        return [$link, $rawToken];
    }

    private function markRevoked(PublicCheckInLink $link, User $actor): void
    {
        $link->forceFill([
            'is_active' => false,
            'active_branch_guard' => null,
            'revoked_by_user_id' => $actor->id,
            'revoked_at' => now()->utc(),
        ])->save();
    }
}
