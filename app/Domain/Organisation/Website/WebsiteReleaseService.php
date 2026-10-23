<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteReleaseService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit, private WebsiteReleaseSelection $selection) {}

    /** @param array<string,mixed> $input */
    public function mutate(User $actor, string $operation, ?int $id, array $input): int
    {
        abort_unless(in_array($operation, ['prepare', 'approve', 'refuse', 'withdraw'], true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'manifest_hash' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'], 'confirmation' => ['nullable', 'string', 'max:30'], 'reason' => ['nullable', 'string', 'max:300']])->validate();
        $work = function () use ($actor, $operation, $id, $data): int {
            // Must be set before any query: every captured row comes from this snapshot.
            if ($operation === 'prepare') {
                if (DB::transactionLevel() !== 1) {
                    throw new \LogicException('Release preparation requires its own transaction.');
                }
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            [$actor,$branch] = $this->access->lock($actor, $data['expected_branch_id'], $operation === 'prepare' ? WebsitePermissions::PREPARE : WebsitePermissions::APPROVE);
            // Approvers also require the view/prepare authority, including after revocation.
            abort_unless($actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::PREPARE), 403);
            $reason = in_array($operation, ['refuse', 'withdraw'], true) ? WebsiteServiceFields::text($data['reason'] ?? '', 'reason', 300) : null;
            if ($reason === '') {
                self::refuse('reason', 'Give a plain-text reason.');
            }
            $fingerprint = hash_hmac('sha256', WebsiteReleaseJson::encode([$operation, $id, $data]), (string) config('app.key'));
            $retry = DB::table('website_release_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== $operation || ! hash_equals($retry->payload_hash, $fingerprint)) {
                    self::refuse('idempotency_key', 'Retry key conflicts with an earlier request.');
                }

                return (int) $retry->release_id;
            }
            $now = now()->utc()->toIso8601String();
            if ($operation === 'prepare') {
                if ((int) $data['lock_version'] !== 0 || $id !== null) {
                    self::refuse('version', 'Reload the prepare form.');
                }
                $capture = $this->selection->capture($actor->organisation_id);
                // Organisation has no timezone column. Require one common active-branch timezone.
                $zones = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->distinct()->pluck('timezone');
                if ($zones->count() !== 1) {
                    self::refuse('timezone', 'Active branches must agree on the organisation-local numbering timezone.');
                }
                $year = (int) now()->setTimezone($zones->first())->format('Y');
                $counter = DB::table('website_release_counters')->where('organisation_id', $actor->organisation_id)->where('year', $year)->lockForUpdate()->first();
                $next = ($counter->last_number ?? 0) + 1;
                if ($next > 999999) {
                    self::refuse('number', 'Annual release number range exhausted.');
                }
                if ($counter) {
                    DB::table('website_release_counters')->where('id', $counter->id)->update(['last_number' => $next]);
                } else {
                    DB::table('website_release_counters')->insert(['organisation_id' => $actor->organisation_id, 'year' => $year, 'last_number' => $next]);
                }
                $number = sprintf('WEB/%d/%06d', $year, $next);
                $hash = hash('sha256', $capture['manifest']);
                $id = DB::table('website_releases')->insertGetId(['organisation_id' => $actor->organisation_id, 'number' => $number, 'manifest_hash' => $hash, 'manifest_schema_version' => 1, 'manifest' => $capture['manifest'], 'omissions' => WebsiteReleaseJson::encode($capture['omissions']), 'created_by_user_id' => $actor->id, 'created_at' => $now]);
                DB::table('website_release_controls')->insert(['release_id' => $id, 'state' => 'prepared', 'lock_version' => 1]);
                foreach ($capture['items'] as $row) {
                    DB::table('website_release_items')->insert(['release_id' => $id, 'source_item_id' => $row['source_item_id'], 'source_revision_id' => $row['source_revision_id'], 'item_key' => $row['key'], 'type' => $row['type'], 'language' => $row['language'], 'revision_hash' => $row['revision_hash'], 'content_hash' => $row['content_hash'], 'projection' => WebsiteReleaseJson::encode($row['projection'])]);
                }
                foreach ($capture['media'] as $row) {
                    $a = $row['asset'];
                    DB::table('website_release_media')->insert(['release_id' => $id, 'source_asset_id' => $a->id, 'sha256' => $a->sha256, 'filename' => $row['filename'], 'mime' => $a->mime, 'bytes' => $a->bytes, 'width' => $a->width, 'height' => $a->height, 'alt_en' => $a->alt_en, 'alt_bm' => $a->alt_bm, 'encrypted_bytes' => Crypt::encryptString(base64_encode($row['bytes']))]);
                }
                $action = 'prepared';
            } else {
                $release = DB::table('website_releases')->where('id', $id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                abort_unless($release !== null, 404);
                $control = DB::table('website_release_controls')->where('release_id', $id)->lockForUpdate()->first();
                $this->verify($release);
                $hash = $release->manifest_hash;
                $number = $release->number;
                if ($control->lock_version !== (int) $data['lock_version'] || ! hash_equals($hash, $data['manifest_hash'] ?? '')) {
                    self::refuse('version', 'Release version or manifest hash changed. Reload.');
                }
                $state = $operation === 'withdraw' ? 'approved' : 'prepared';
                if ($control->state !== $state) {
                    self::refuse('state', 'This action is not valid for the current release state.');
                }
                if ($operation === 'approve' && ($data['confirmation'] ?? '') !== $number) {
                    self::refuse('confirmation', 'Type the exact release number to approve these frozen contents.');
                }
                $action = match ($operation) {
                    'approve' => 'approved','refuse' => 'refused','withdraw' => 'withdrawn'
                };
                DB::table('website_release_controls')->where('release_id', $id)->update(['state' => $action, 'lock_version' => $control->lock_version + 1]);
            }
            DB::table('website_release_actions')->insert(['release_id' => $id, 'actor_user_id' => $actor->id, 'action' => $action, 'manifest_hash' => $hash, 'reason' => $reason, 'created_at' => $now]);
            DB::table('website_release_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $fingerprint, 'release_id' => $id, 'operation' => $operation, 'created_at' => $now]);
            $counts = ['items' => DB::table('website_release_items')->where('release_id', $id)->count(), 'media' => DB::table('website_release_media')->where('release_id', $id)->count()];
            if ($this->audit->record('website.release.'.$action, null, ['release_id' => $id, 'number' => $number, 'action' => $action, 'manifest_hash_prefix' => substr($hash, 0, 12), 'counts' => $counts], $actor, $branch) === null) {
                throw new \RuntimeException('Release audit could not be recorded.');
            }

            return $id;
        };
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction($work, 3);
            } catch (UniqueConstraintViolationException $e) {
                // Two first preparations may establish their snapshots before the org lock.
                // Retry only this known counter race in a new, consistent snapshot.
                if ($operation !== 'prepare' || $attempt >= 2 || ! str_contains($e->getMessage(), 'website_release_counters_organisation_id_year_unique')) {
                    throw $e;
                }
            }
        }
    }

    private function verify(\stdClass $release): void
    {
        if (! hash_equals($release->manifest_hash, hash('sha256', $release->manifest)) || WebsiteReleaseJson::encode(json_decode($release->manifest, true, 512, JSON_THROW_ON_ERROR)) !== $release->manifest) {
            throw new \RuntimeException('Release integrity check failed.');
        }
    }

    /** @return array<string,mixed> */
    public function page(User $actor, ?int $id = null): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            [$actor,$branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::PREPARE);
            $rows = DB::table('website_releases')->where('organisation_id', $actor->organisation_id)->orderByDesc('id')->get();
            $summaries = $rows->map(function ($r) use ($branch): array {
                $c = DB::table('website_release_controls')->where('release_id', $r->id)->first();
                $a = DB::table('website_release_actions')->where('release_id', $r->id)->where('action', 'approved')->orderByDesc('id')->first();

                return ['id' => $r->id, 'number' => $r->number, 'state' => $c->state, 'creator' => $r->created_by_user_id, 'approver' => $a?->actor_user_id, 'approvedAt' => $a ? CarbonImmutable::parse($a->created_at)->setTimezone($branch->timezone)->format('d M Y H:i:s').' '.$branch->timezone : null, 'createdAt' => CarbonImmutable::parse($r->created_at)->setTimezone($branch->timezone)->format('d M Y H:i:s').' '.$branch->timezone];
            })->all();
            $detail = null;
            if ($id !== null) {
                $r = $rows->firstWhere('id', $id);
                abort_unless($r !== null, 404);
                $this->verify($r);
                $control = DB::table('website_release_controls')->where('release_id', $id)->first();
                $approved = DB::table('website_releases as r')->join('website_release_controls as c', 'c.release_id', '=', 'r.id')->where('r.organisation_id', $actor->organisation_id)->where('c.state', 'approved')->where('r.id', '<', $id)->orderByDesc('r.id')->first(['r.manifest', 'r.number']);
                $manifest = json_decode($r->manifest, true, 512, JSON_THROW_ON_ERROR);
                $approval = DB::table('website_release_actions')->where('release_id', $id)->where('action', 'approved')->first();
                $detail = ['id' => $id, 'number' => $r->number, 'state' => $control->state, 'lockVersion' => $control->lock_version, 'hash' => $r->manifest_hash, 'items' => $manifest['items'], 'media' => $manifest['media'], 'omissions' => json_decode($r->omissions, true, 512, JSON_THROW_ON_ERROR), 'changes' => $this->changes($manifest, $approved ? json_decode($approved->manifest, true, 512, JSON_THROW_ON_ERROR) : null), 'comparedTo' => $approved->number ?? 'First release', 'samePreparerApprover' => $r->created_by_user_id === ($approval->actor_user_id ?? $actor->id), 'actions' => DB::table('website_release_actions')->where('release_id', $id)->orderBy('id')->get()->map(fn ($a) => ['action' => $a->action, 'actor' => $a->actor_user_id, 'at' => CarbonImmutable::parse($a->created_at)->setTimezone($branch->timezone)->format('d M Y H:i:s').' '.$branch->timezone, 'reason' => $a->reason])->all()];
            }

            // Presentation-only names: evidence and audits retain actor ids only.
            $actorIds = array_column($summaries, 'creator');
            $actorIds = [...$actorIds, ...array_column($summaries, 'approver'), ...array_column($detail['actions'] ?? [], 'actor')];
            $actorIds = array_values(array_unique(array_filter($actorIds)));
            $names = $actorIds === [] ? collect() : DB::table('users')->where('organisation_id', $actor->organisation_id)->whereIn('id', $actorIds)->pluck('name', 'id');
            $displayName = fn (int $userId): string => $names[$userId] ?? 'Staff #'.$userId;
            foreach ($summaries as &$summary) {
                $summary['creator'] = $displayName($summary['creator']);
                $summary['approver'] = $summary['approver'] !== null ? $displayName($summary['approver']) : null;
            }
            unset($summary);
            if ($detail !== null) {
                foreach ($detail['actions'] as &$action) {
                    $action['actor'] = $displayName($action['actor']);
                }
                unset($action);
            }

            return ['branchId' => $branch->id, 'releases' => $summaries, 'detail' => $detail, 'canApprove' => $actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::APPROVE)];
        });
    }

    /** @param array<string,mixed> $current
     * @param  array<string,mixed>|null  $previous
     * @return array<string,mixed>
     */
    private function changes(array $current, ?array $previous): array
    {
        $index = function (?array $manifest): array {
            $indexed = [];
            foreach ($manifest['items'] ?? [] as $item) {
                $indexed[$item['key'].':'.$item['language']] = $item;
            }

            return $indexed;
        };
        $new = $index($current);
        $old = $index($previous);
        $changes = ['added' => [], 'removed' => [], 'changed' => [], 'branches' => []];
        foreach ($new as $key => $item) {
            if (! isset($old[$key])) {
                $changes['added'][] = $key;
            } elseif ($item['content_hash'] !== $old[$key]['content_hash']) {
                $changes['changed'][] = $key;
                if (($item['projection']['branches'] ?? []) !== ($old[$key]['projection']['branches'] ?? [])) {
                    $changes['branches'][] = $key;
                }
            }
        }
        foreach ($old as $key => $item) {
            if (! isset($new[$key])) {
                $changes['removed'][] = $key;
            }
        }
        $hashes = fn ($m) => array_column($m['media'] ?? [], 'sha256');
        $changes['mediaAdded'] = array_values(array_diff($hashes($current), $hashes($previous)));
        $changes['mediaRemoved'] = array_values(array_diff($hashes($previous), $hashes($current)));

        return $changes;
    }

    /** @return array{content:string,mime:string} */
    public function media(User $actor, int $id, string $filename): array
    {
        return DB::transaction(function () use ($actor, $id, $filename): array {
            $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::PREPARE);
            $release = DB::table('website_releases')->where('id', $id)->where('organisation_id', $actor->organisation_id)->first();
            abort_unless($release !== null, 404);
            $this->verify($release);
            $row = DB::table('website_release_media')->where('release_id', $id)->where('filename', $filename)->first();
            abort_unless($row !== null, 404);
            $bytes = base64_decode(Crypt::decryptString($row->encrypted_bytes), true);
            abort_unless(is_string($bytes) && hash_equals($row->sha256, hash('sha256', $bytes)) && strlen($bytes) === $row->bytes, 404);

            return ['content' => $bytes, 'mime' => $row->mime];
        });
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
