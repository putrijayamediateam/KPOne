<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WebsiteArtifactBuildService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit, private WebsiteArtifactSource $source, private WebsiteStaticRenderer $renderer, private WebsiteArtifactBudget $budget, private WebsiteArtifactStore $store) {}

    /** @param array<string,mixed> $input */
    public function build(User $actor, int $release, array $input): int
    {
        $d = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'plan_id' => ['required', 'integer'], 'release_version' => ['required', 'integer', 'min:1'], 'plan_version' => ['required', 'integer', 'min:1'], 'release_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'plan_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $this->reconcile($actor, $d['expected_branch_id']);
        $recipe = $this->renderer->recipe();
        $fingerprint = hash_hmac('sha256', WebsiteReleaseJson::encode([$release, $d, $recipe]), (string) config('app.key'));
        $begin = DB::transaction(function () use ($actor, $release, $d, $fingerprint): array {
            [$actor,$branch] = $this->access->lock($actor, $d['expected_branch_id'], WebsitePermissions::PREPARE);
            $locked = $this->lockSources($actor->organisation_id, $release, (int) $d['plan_id']);
            $existing = DB::table('website_artifact_attempts')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $d['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->actor_user_id !== $actor->id || ! hash_equals($existing->payload_hash, $fingerprint)) {
                    $this->refuse('idempotency_key', 'Retry key conflicts with an earlier build.');
                }
                $expired = in_array($existing->state, ['requested', 'building'], true) && now()->greaterThan($existing->lease_until);
                if ($expired) {
                    DB::table('website_artifact_attempts')->where('id', $existing->id)->update(['state' => 'failed', 'failure_code' => 'interrupted', 'lock_version' => $existing->lock_version + 1]);
                    $this->action($actor, $branch, $existing->id, 'failed', $locked, null);
                }

                return ['id' => $existing->id, 'existing' => true, 'cleanup' => ($expired || $existing->state === 'failed') ? $existing->stage_token : null];
            }
            $this->assertSources($locked, $d);
            $id = DB::table('website_artifact_attempts')->insertGetId(['organisation_id' => $actor->organisation_id, 'release_id' => $release, 'plan_id' => $d['plan_id'], 'actor_user_id' => $actor->id, 'idempotency_key' => $d['idempotency_key'], 'payload_hash' => $fingerprint, 'stage_token' => (string) Str::uuid(), 'state' => 'requested', 'lock_version' => 1, 'lease_until' => now()->utc()->addMinutes(2)->toIso8601String(), 'created_at' => now()->utc()->toIso8601String()]);
            $this->action($actor, $branch, $id, 'requested', $locked, null);
            DB::table('website_artifact_attempts')->where('id', $id)->update(['state' => 'building', 'lock_version' => 2]);
            $this->action($actor, $branch, $id, 'building', $locked, null);
            try {
                $source = $this->source->read($actor->organisation_id, $release, (int) $d['plan_id']);

                return ['id' => $id, 'existing' => false, 'source' => $source];
            } catch (\Throwable $e) {
                return ['id' => $id, 'existing' => false, 'failure' => $e instanceof WebsiteArtifactFailure ? $e->failureCode : 'integrity'];
            }
        }, 3);
        if ($begin['existing']) {
            if ($begin['cleanup'] !== null) {
                try {
                    $this->store->discard($begin['cleanup']);
                } catch (\Throwable) { /* Next matching retry may reconcile inaccessible staging. */
                }
            }

            return $begin['id'];
        }
        $id = $begin['id'];
        $attempt = DB::table('website_artifact_attempts')->where('id', $id)->first();
        try {
            if (isset($begin['failure'])) {
                throw new WebsiteArtifactFailure($begin['failure']);
            }
            $source = $begin['source'];
            $started = microtime(true);
            $this->budget->check([], $started);
            $files = $this->renderer->render($source['dto']);
            $this->budget->check($files, $started);
            $again = $this->renderer->render($source['dto']);
            $this->budget->check($again, $started);
            if ($files !== $again) {
                throw new WebsiteArtifactFailure('determinism');
            }
            unset($again);
            $manifest = WebsiteArtifactStore::manifest($files);
            $generated = [];
            foreach ($files as $path => $file) {
                if (! isset($source['media'][$path])) {
                    $generated[$path] = $this->store->stage($attempt->stage_token, $path, $file['bytes']);
                }
            }
            $fileJson = WebsiteReleaseJson::encode($manifest);
            $header = ['release_number' => $source['release']->number, 'release_manifest_hash' => $source['release']->manifest_hash, 'plan_number' => $source['plan']->number, 'plan_hash' => $source['plan']->plan_hash, 'renderer_version' => $recipe['version'], 'renderer_digest' => $recipe['digest'], 'profile_id' => $recipe['profile'], 'profile_digest' => $recipe['profile_digest'], 'file_count' => count($manifest), 'total_bytes' => array_sum(array_column($manifest, 'bytes')), 'file_manifest_hash' => hash('sha256', $fileJson)];
            $identity = WebsiteReleaseJson::encode(['header' => $header, 'files' => $manifest]);
            DB::transaction(function () use ($actor, $release, $d, $id, $attempt, $recipe, $manifest, $generated, $source, $fileJson, $header, $identity): void {
                [$actor,$branch] = $this->access->lock($actor, $d['expected_branch_id'], WebsitePermissions::PREPARE);
                $locked = $this->lockSources($actor->organisation_id, $release, (int) $d['plan_id']);
                $this->assertSources($locked, $d);
                $current = DB::table('website_artifact_attempts')->where('id', $id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                if (! $current || $current->state !== 'building' || $current->lock_version !== 2 || now()->greaterThan($current->lease_until) || $recipe !== $this->renderer->recipe()) {
                    throw new WebsiteArtifactFailure('version');
                }
                // Stage writes verify bytes; the immutable index pins their hashes for every later materialisation.
                foreach ($generated as $path => $key) {
                    if ($key !== $this->store->key($attempt->stage_token, $path)) {
                        throw new WebsiteArtifactFailure('storage');
                    }
                }
                $zones = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->distinct()->pluck('timezone');
                if ($zones->count() !== 1) {
                    throw new WebsiteArtifactFailure('version');
                }
                $year = (int) now()->setTimezone($zones->first())->format('Y');
                DB::table('website_artifact_counters')->insertOrIgnore(['organisation_id' => $actor->organisation_id, 'year' => $year, 'last_number' => 0]);
                $counter = DB::table('website_artifact_counters')->where('organisation_id', $actor->organisation_id)->where('year', $year)->lockForUpdate()->first();
                if ($counter->last_number >= 999999) {
                    throw new WebsiteArtifactFailure('version');
                }
                DB::table('website_artifact_counters')->where('id', $counter->id)->update(['last_number' => $counter->last_number + 1]);
                $number = sprintf('WEBBUILD/%d/%06d', $year, $counter->last_number + 1);
                $artifact = DB::table('website_artifacts')->insertGetId(['organisation_id' => $actor->organisation_id, 'attempt_id' => $id, 'release_id' => $release, 'plan_id' => $d['plan_id'], ...$header, 'file_manifest' => $fileJson, 'identity_manifest' => $identity, 'artifact_hash' => hash('sha256', $identity), 'number' => $number, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()->toIso8601String()]);
                $releaseMedia = DB::table('website_release_media')->where('release_id', $release)->pluck('id', 'sha256');
                $planMedia = DB::table('website_output_plan_assets')->where('plan_id', $d['plan_id'])->pluck('id', 'sha256');
                foreach ($manifest as $file) {
                    $ref = $source['media'][$file['path']] ?? null;
                    DB::table('website_artifact_files')->insert(['artifact_id' => $artifact, ...$file, 'storage_kind' => $ref['kind'] ?? 'generated', 'storage_key' => $generated[$file['path']] ?? null, 'release_media_id' => ($ref['kind'] ?? '') === 'release_media' ? $releaseMedia[$ref['sha256']] : null, 'plan_asset_id' => ($ref['kind'] ?? '') === 'plan_asset' ? $planMedia[$ref['sha256']] : null, 'encrypted' => true]);
                }
                DB::table('website_artifact_attempts')->where('id', $id)->update(['state' => 'built', 'artifact_id' => $artifact, 'lock_version' => 3]);
                $this->action($actor, $branch, $id, 'built', $locked, ['id' => $artifact, 'number' => $number, 'hash' => hash('sha256', $identity), 'count' => $header['file_count'], 'bytes' => $header['total_bytes']]);
            }, 3);
        } catch (\Throwable $e) {
            try {
                $persisted = DB::table('website_artifact_attempts')->where('organisation_id', $actor->organisation_id)->where('id', $id)->value('state');
                if ($persisted === 'built') {
                    return $id;
                }
            } catch (\Throwable) {
                // Unknown commit outcome: retain files until an authorised request can reconcile it.
                return $id;
            }
            try {
                $this->store->discard($attempt->stage_token);
            } catch (\Throwable) { /* Retain inaccessible unindexed staging for reconciliation. */
            }
            $code = $e instanceof WebsiteArtifactFailure ? $e->failureCode : ($e instanceof HttpException && $e->getStatusCode() === 403 ? 'authority' : 'render');
            // Internal failure reconciliation never creates an artifact or grants access after revocation.
            try {
                DB::transaction(function () use ($actor, $release, $d, $id, $code): void {
                    $locked = $this->lockSources($actor->organisation_id, $release, (int) $d['plan_id']);
                    $a = DB::table('website_artifact_attempts')->where('organisation_id', $actor->organisation_id)->where('id', $id)->lockForUpdate()->first();
                    if ($a && in_array($a->state, ['requested', 'building'], true)) {
                        DB::table('website_artifact_attempts')->where('id', $id)->update(['state' => 'failed', 'failure_code' => $code, 'lock_version' => $a->lock_version + 1]);
                        $branch = $this->branches->activeBranch($actor);
                        $this->action($actor, $branch, $id, 'failed', $locked, null);
                    }
                }, 3);
            } catch (\Throwable) {
                // An audit outage leaves the earlier truthful building intent for next-request lease reconciliation.
            }
        }

        return $id;
    }

    private function reconcile(User $actor, mixed $expected): void
    {
        $tokens = DB::transaction(function () use ($actor, $expected): array {
            [$actor,$branch] = $this->access->lock($actor, $expected, WebsitePermissions::PREPARE);
            $tokens = [];
            $stale = DB::table('website_artifact_attempts')->where('organisation_id', $actor->organisation_id)->whereIn('state', ['requested', 'building'])->where('lease_until', '<', now()->utc()->toIso8601String())->orderBy('release_id')->orderBy('plan_id')->orderBy('id')->get(['id', 'release_id', 'plan_id']);
            foreach ($stale as $old) {
                $sources = $this->lockSources($actor->organisation_id, $old->release_id, $old->plan_id);
                $current = DB::table('website_artifact_attempts')->where('id', $old->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                if ($current && in_array($current->state, ['requested', 'building'], true) && now()->greaterThan($current->lease_until)) {
                    DB::table('website_artifact_attempts')->where('id', $current->id)->update(['state' => 'failed', 'failure_code' => 'interrupted', 'lock_version' => $current->lock_version + 1]);
                    $this->action($actor, $branch, $current->id, 'failed', $sources, null);
                    $tokens[] = $current->stage_token;
                }
            }

            return $tokens;
        }, 3);
        foreach ($tokens as $token) {
            try {
                $this->store->discard($token);
            } catch (\Throwable) { /* Unindexed files remain private and retry cleanup is safe. */
            }
        }
    }

    /** @return array{release:\stdClass,releaseControl:\stdClass,plan:\stdClass,planControl:\stdClass} */
    private function lockSources(int $organisation, int $release, int $plan): array
    {
        $r = DB::table('website_releases')->where('organisation_id', $organisation)->where('id', $release)->lockForUpdate()->first();
        abort_unless($r !== null, 404);
        $rc = DB::table('website_release_controls')->where('release_id', $release)->lockForUpdate()->first();
        $p = DB::table('website_output_plans')->where('organisation_id', $organisation)->where('release_id', $release)->where('id', $plan)->lockForUpdate()->first();
        abort_unless($p !== null, 404);
        $pc = DB::table('website_output_plan_controls')->where('plan_id', $plan)->lockForUpdate()->first();

        return ['release' => $r, 'releaseControl' => $rc, 'plan' => $p, 'planControl' => $pc];
    }

    /** @param array<string,\stdClass> $s
     * @param  array<string,mixed>  $d
     */
    private function assertSources(array $s, array $d): void
    {
        if ($s['releaseControl']->state !== 'approved' || $s['planControl']->state !== 'approved') {
            $this->refuse('approval', 'Both the release and selected plan must currently be approved.');
        }
        if ($s['releaseControl']->lock_version !== (int) $d['release_version'] || $s['planControl']->lock_version !== (int) $d['plan_version'] || ! hash_equals($s['release']->manifest_hash, $d['release_hash']) || ! hash_equals($s['plan']->plan_hash, $d['plan_hash']) || hash('sha256', $s['release']->manifest) !== $s['release']->manifest_hash || hash('sha256', $s['plan']->content) !== $s['plan']->plan_hash) {
            throw new WebsiteArtifactFailure('version');
        }
    }

    /** @param array<string,\stdClass> $s
     * @param  array<string,mixed>|null  $artifact
     */
    private function action(User $actor, mixed $branch, int $id, string $action, array $s, ?array $artifact): void
    {
        DB::table('website_artifact_actions')->insert(['attempt_id' => $id, 'actor_user_id' => $actor->id, 'action' => $action, 'release_hash' => $s['release']->manifest_hash, 'plan_hash' => $s['plan']->plan_hash, 'artifact_hash' => $artifact['hash'] ?? null, 'created_at' => now()->utc()->toIso8601String()]);
        if ($this->audit->record('website.artifact.'.$action, null, ['attempt_id' => $id, 'release_id' => $s['release']->id, 'plan_id' => $s['plan']->id, 'release_number' => $s['release']->number, 'plan_number' => $s['plan']->number, 'action' => $action, 'release_hash_prefix' => substr($s['release']->manifest_hash, 0, 12), 'plan_hash_prefix' => substr($s['plan']->plan_hash, 0, 12), 'artifact_id' => $artifact['id'] ?? null, 'number' => $artifact['number'] ?? null, 'hash_prefix' => isset($artifact['hash']) ? substr($artifact['hash'], 0, 12) : null, 'file_count' => $artifact['count'] ?? 0, 'total_bytes' => $artifact['bytes'] ?? 0], $actor, $branch) === null) {
            throw new WebsiteArtifactFailure('audit');
        }
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @return array<string,mixed>|null */
    public function page(User $actor, ?int $release): ?array
    {
        if ($release === null) {
            return null;
        }

        return DB::transaction(function () use ($actor, $release): array {
            [$actor,$branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::PREPARE);
            $r = DB::table('website_releases')->where('organisation_id', $actor->organisation_id)->where('id', $release)->first();
            abort_unless($r !== null, 404);
            $state = DB::table('website_release_controls')->where('release_id', $release)->value('state');
            $plans = DB::table('website_output_plans as p')->join('website_output_plan_controls as c', 'c.plan_id', '=', 'p.id')->where('p.organisation_id', $actor->organisation_id)->where('p.release_id', $release)->where('c.state', 'approved')->orderBy('p.id')->get(['p.id', 'p.number', 'p.plan_hash', 'c.lock_version'])->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'hash' => $p->plan_hash, 'version' => $p->lock_version])->all();
            $attempts = DB::table('website_artifact_attempts as t')->leftJoin('website_artifacts as a', 'a.attempt_id', '=', 't.id')->join('website_output_plans as p', 'p.id', '=', 't.plan_id')->where('t.organisation_id', $actor->organisation_id)->where('t.release_id', $release)->orderByDesc('t.id')->get(['t.id', 't.state', 't.failure_code', 'p.number as plan_number', 'a.id as artifact_id', 'a.number', 'a.file_count', 'a.total_bytes', 'a.artifact_hash'])->map(function ($a): array {
                return ['id' => $a->id, 'state' => $a->state, 'failureCode' => $a->failure_code, 'plan' => $a->plan_number, 'number' => $a->number, 'count' => $a->file_count, 'bytes' => $a->total_bytes, 'hash' => $a->artifact_hash, 'files' => $a->artifact_id ? DB::table('website_artifact_files')->where('artifact_id', $a->artifact_id)->orderBy('path')->get(['path', 'bytes', 'sha256'])->all() : []];
            })->all();

            return ['branchId' => $branch->id, 'releaseId' => $r->id, 'releaseNumber' => $r->number, 'releaseHash' => $r->manifest_hash, 'releaseVersion' => DB::table('website_release_controls')->where('release_id', $release)->value('lock_version'), 'available' => $state === 'approved', 'plans' => $plans, 'attempts' => $attempts];
        });
    }
}
