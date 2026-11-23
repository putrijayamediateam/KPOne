<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPackDocument;
use App\Domain\Visit\Billing\Models\PanelClaimPackPrint;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PNL-25: a Panel claim pack is frozen at first print (as BIL-5 does for invoices and receipts). Each distinct printed
 * content of one pack (the same claims) is one encrypted, immutable version; the first print of version 1 is ORIGINAL,
 * the first print of a later version is REVISED, every other print is COPY. Printing never changes a claim, an amount
 * or any financial record. The content is what the paper shows (letterhead and logo bytes included, photos as
 * "Photo 1, 2, ..."), so the print time is not part of it: pressing Print twice on unchanged claims is a COPY.
 */
final class PanelClaimPackIssueService
{
    public const PERMISSION = PanelClaimPackService::PERMISSION;

    public function __construct(
        private PanelClaimPackService $packs,
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /**
     * Records one print of the pack of these claims (up to 50 of one Panel), freezing a new version when the printed
     * content differs from the latest one.
     *
     * @param  array<string, mixed>  $input  claims (public ids, in print order) and idempotency_key
     */
    public function issue(User $actor, array $input): PanelClaimPackPrint
    {
        $key = $this->key($input['idempotency_key'] ?? null);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $input, $key): PanelClaimPackPrint {
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $built = $this->packs->pack($actor, ['claims' => $input['claims'] ?? null], true);
            $claimIds = array_map(static fn (array $row): int => (int) $row['claimNo'], $built['pack']['claims']);
            $hash = hash('sha256', json_encode(['issue', $actor->id, $claimIds], JSON_THROW_ON_ERROR));
            if ($existing = $this->replay($actor, $key, $hash)) {
                return $existing;
            }

            // The print time changes on every press, so it is not part of what is frozen or compared.
            $content = $built;
            unset($content['pack']['printedAt']);
            $contentHash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
            $sorted = $claimIds;
            sort($sorted);
            $packKey = hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR));
            // One lock per pack, so two people printing the same pack at once are ordered and numbered one after the other.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$actor->organisation_id.':'.$branch->id.':'.$packKey]);
            $latest = PanelClaimPackDocument::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('pack_key', $packKey)->orderByDesc('version')->first();
            if ($latest !== null && hash_equals($latest->content_sha256, $contentHash)) {
                return $this->print($actor, $latest, 'copy', $key, $hash, $branch);
            }

            $encrypted = Crypt::encryptString(json_encode($content, JSON_THROW_ON_ERROR));
            $document = new PanelClaimPackDocument;
            $document->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'panel_id' => (int) DB::table('panel_claims')->where('id', $claimIds[0])->value('panel_id'), 'pack_key' => $packKey, 'claim_ids' => $sorted,
                'version' => ($latest->version ?? 0) + 1, 'content_sha256' => $contentHash, 'shows_identity' => $this->showsIdentity($content), 'encrypted_snapshot' => $encrypted,
                'ciphertext_sha256' => hash('sha256', $encrypted), 'issued_by_user_id' => $actor->id, 'issued_at' => now()->utc()])->save();
            $this->audit->record('panel_claim.pack_issued', $document, ['version' => $document->version, 'claim_count' => count($claimIds),
                'claim_ids' => $claimIds, 'panel_id' => $document->panel_id], $actor, $branch);

            return $this->print($actor, $document, $document->version === 1 ? 'original' : 'revised', $key, $hash, $branch);
        }, 3);
    }

    /**
     * Reprints a frozen version (any version of this branch's packs), always as COPY.
     *
     * @param  array<string, mixed>  $input
     */
    public function reprint(User $actor, string $documentPublicId, array $input): PanelClaimPackPrint
    {
        $key = $this->key($input['idempotency_key'] ?? null);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $documentPublicId, $key): PanelClaimPackPrint {
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $document = PanelClaimPackDocument::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('public_id', $documentPublicId)->first();
            abort_if($document === null, 404);
            $this->assertCanSee($actor, $this->decrypt($document));
            $hash = hash('sha256', json_encode(['reprint', $actor->id, $document->id], JSON_THROW_ON_ERROR));
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$actor->organisation_id.':'.$branch->id.':'.$document->pack_key]);

            return $this->replay($actor, $key, $hash) ?? $this->print($actor, $document, 'copy', $key, $hash, $branch);
        }, 3);
    }

    /**
     * The frozen content of one recorded print, with its label and what has happened since.
     *
     * @return array<string, mixed>
     */
    public function show(User $actor, string $printPublicId): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $printPublicId): array {
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $print = PanelClaimPackPrint::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $printPublicId)->first();
            abort_if($print === null, 404);
            $document = PanelClaimPackDocument::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereKey($print->panel_claim_pack_document_id)->first();
            abort_if($document === null, 404);
            $content = $this->decrypt($document);
            $this->assertCanSee($actor, $content);
            $latest = (int) PanelClaimPackDocument::query()->where('organisation_id', $document->organisation_id)->where('branch_id', $document->branch_id)
                ->where('pack_key', $document->pack_key)->max('version');
            $notes = $latest > $document->version
                ? ["A later version of this claim pack exists (version {$latest}). This paper is not the current one."] : [];
            // Only the person who made the latest print of this version sees ORIGINAL or REVISED again; every other
            // reopening reads COPY, so no second ORIGINAL sheet can be printed.
            $lastPrint = PanelClaimPackPrint::query()->where('panel_claim_pack_document_id', $document->id)->max('id');
            $mine = $print->printed_by_user_id === $actor->id;
            $label = $mine && (int) $lastPrint === $print->id ? $print->label : 'copy';

            return $content + ['issued' => [
                'label' => $label,
                'recordedLabel' => $print->label,
                'version' => $document->version,
                'issuedAt' => $document->issued_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'printedAt' => $print->printed_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'printedByYou' => $mine,
                'notes' => $notes,
            ]];
        }, 3);
    }

    /**
     * Frozen packs that include this claim, newest first, for the "Issued packs" list on the claim page. Rows only for
     * a holder of the claims permission at the active branch; each row says how many claims the pack holds, who issued
     * it and when, how many times it was printed, and carries the address of its latest print.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function forClaim(User $actor, PanelClaim $claim): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || $branch->organisation_id !== $actor->organisation_id || $claim->branch_id !== $branch->id || ! $actor->can(self::PERMISSION)) {
            return null;
        }
        $documents = PanelClaimPackDocument::query()->withCount('prints')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->whereRaw('claim_ids @> ?::jsonb', [json_encode([(int) $claim->id], JSON_THROW_ON_ERROR)])->orderByDesc('issued_at')->orderByDesc('id')->limit(25)->get();
        $people = app(StaffAccessService::class)->visibleUsers($actor)->whereIn('id', $documents->pluck('issued_by_user_id')->unique())->pluck('name', 'id');
        $latestPrints = PanelClaimPackPrint::query()->whereIn('panel_claim_pack_document_id', $documents->pluck('id'))->orderBy('id')->get()
            ->groupBy('panel_claim_pack_document_id')->map(static fn ($rows) => $rows->last());
        $firstLabels = PanelClaimPackPrint::query()->whereIn('panel_claim_pack_document_id', $documents->pluck('id'))->where('label', '!=', 'copy')->pluck('label', 'panel_claim_pack_document_id');

        $canSeeIdentity = $actor->can(PanelClaimPackService::IDENTITY_PERMISSION);

        return $documents->map(static fn (PanelClaimPackDocument $d): array => [
            'publicId' => $d->public_id,
            'version' => $d->version,
            'firstLabel' => (string) ($firstLabels[$d->id] ?? 'original'),
            'claimCount' => count($d->claim_ids),
            'issuedBy' => (string) ($people[$d->issued_by_user_id] ?? 'Staff member'),
            'issuedAt' => $d->issued_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
            'prints' => $d->prints_count,
            'viewUrl' => route('panel-claims.pack.copy', ['print' => $latestPrints[$d->id]->public_id, 'from' => $claim->public_id], absolute: false),
            'reprintUrl' => route('panel-claims.pack.reprint', ['document' => $d->public_id], absolute: false),
            'canOpen' => ! $d->shows_identity || $canSeeIdentity,
        ])->values()->all();
    }

    /** @param array<string, mixed> $content */
    private function assertCanSee(User $actor, array $content): void
    {
        if ($this->showsIdentity($content) && ! $actor->can(PanelClaimPackService::IDENTITY_PERMISSION)) {
            throw ValidationException::withMessages(['pack' => 'This copy shows patient identity numbers, which your role cannot see.']);
        }
    }

    /** @param array<string, mixed> $content */
    private function showsIdentity(array $content): bool
    {
        $rows = is_array($content['pack']['claims'] ?? null) ? $content['pack']['claims'] : [];
        foreach ($rows as $row) {
            if (is_array($row) && ! ($row['identityHidden'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function decrypt(PanelClaimPackDocument $document): array
    {
        $failed = ValidationException::withMessages(['pack' => 'This frozen copy failed its integrity check. Do not print it; report it.']);
        if (! hash_equals($document->ciphertext_sha256, hash('sha256', $document->encrypted_snapshot))) {
            throw $failed;
        }
        try {
            /** @var array<string, mixed> $content */
            $content = json_decode(Crypt::decryptString($document->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $failed;
        }
        if (! hash_equals($document->content_sha256, hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)))) {
            throw $failed;
        }

        return $content;
    }

    private function print(User $actor, PanelClaimPackDocument $document, string $label, string $key, string $hash, Branch $branch): PanelClaimPackPrint
    {
        $print = new PanelClaimPackPrint;
        $print->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'panel_claim_pack_document_id' => $document->id,
            'label' => $label, 'printed_by_user_id' => $actor->id, 'printed_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
        $this->audit->record('panel_claim.pack_printed_recorded', $document, ['version' => $document->version, 'label' => $label,
            'claim_count' => count($document->claim_ids), 'panel_id' => $document->panel_id], $actor, $branch);

        return $print;
    }

    private function replay(User $actor, string $key, string $hash): ?PanelClaimPackPrint
    {
        $existing = PanelClaimPackPrint::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages(['pack' => 'This request was already used for a different print. Reload and try again.']);
        }

        return $existing;
    }

    private function key(mixed $key): string
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Reload the page and try again.']);
        }

        return $key;
    }
}
