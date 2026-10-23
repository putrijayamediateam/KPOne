<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteOutputPlanService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $input */
    public function mutate(User $actor, string $operation, ?int $id, array $input): int
    {
        abort_unless(in_array($operation, ['prepare', 'approve', 'refuse', 'withdraw'], true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'release_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'plan_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'], 'confirmation' => ['nullable', 'string', 'max:30'], 'reason' => ['nullable', 'string', 'max:300'], 'content' => ['sometimes', 'array']])->validate();

        return DB::transaction(function () use ($actor, $operation, $id, $data): int {
            [$actor,$branch] = $this->access->lock($actor, $data['expected_branch_id'], $operation === 'prepare' ? WebsitePermissions::PREPARE : WebsitePermissions::APPROVE);
            abort_unless($actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::PREPARE), 403);
            $fingerprint = hash_hmac('sha256', WebsiteReleaseJson::encode([$operation, $id, $data]), (string) config('app.key'));
            $retry = DB::table('website_output_plan_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== $operation || ! hash_equals($retry->payload_hash, $fingerprint)) {
                    self::refuse('idempotency_key', 'Retry key conflicts with an earlier request.');
                }

                return (int) $retry->plan_id;
            }
            // One lock order: authority/organisation, plan counter, release, release control, plan/control.
            $counter = null;
            $year = null;
            if ($operation === 'prepare') {
                $zones = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->distinct()->pluck('timezone');
                if ($zones->count() !== 1) {
                    self::refuse('timezone', 'Active branches must agree on the organisation-local numbering timezone.');
                }
                $year = (int) now()->setTimezone($zones->first())->format('Y');
                DB::table('website_output_plan_counters')->insertOrIgnore(['organisation_id' => $actor->organisation_id, 'year' => $year, 'last_number' => 0]);
                $counter = DB::table('website_output_plan_counters')->where('organisation_id', $actor->organisation_id)->where('year', $year)->lockForUpdate()->first();
            }
            $release = $this->release($actor->organisation_id, (int) $data['release_id']);
            $sourceControl = DB::table('website_release_controls')->where('release_id', $release->id)->lockForUpdate()->first();
            if (in_array($operation, ['prepare', 'approve'], true) && $sourceControl->state !== 'approved') {
                self::refuse('release_id', 'Choose a currently approved release.');
            }
            $now = now()->utc()->toIso8601String();
            if ($operation === 'prepare') {
                if ($id !== null || (int) $data['lock_version'] !== 0) {
                    self::refuse('version', 'Reload the prepare form.');
                }
                $fields = WebsiteOutputPlanFields::validate($data['content'] ?? [], json_decode($release->manifest, true, 512, JSON_THROW_ON_ERROR));
                $logo = null;
                $capture = null;
                if ($fields['logo_public_id'] !== null) {
                    try {
                        $capture = WebsiteMediaCapture::capture($actor->organisation_id, $fields['logo_public_id']);
                    } catch (\Throwable) {
                        self::refuse('content.logo_public_id', 'Logo is unavailable, retired, foreign, corrupt or lacks rights, consent or both alt texts.');
                    }
                    $a = $capture['asset'];
                    $logo = ['sha256' => $a->sha256, 'mime' => $a->mime, 'bytes' => $a->bytes, 'width' => $a->width, 'height' => $a->height, 'alt_en' => $a->alt_en, 'alt_bm' => $a->alt_bm];
                }
                $navigation = array_intersect_key(WebsiteOutputProfile::LABELS, array_flip($fields['pages']));
                $content = ['schema_version' => 1, 'release' => ['number' => $release->number, 'manifest_hash' => $release->manifest_hash], 'public_origin' => $fields['public_origin'], 'aliases' => $fields['aliases'], 'pages' => $fields['pages'], 'footer' => $fields['footer'], 'language_policy' => WebsiteOutputProfile::LANGUAGE, 'design_profile' => WebsiteOutputProfile::frozen(), 'navigation' => $navigation, 'logo' => $logo];
                $json = WebsiteReleaseJson::encode($content);
                $hash = hash('sha256', $json);
                $next = $counter->last_number + 1;
                if ($next > 999999) {
                    self::refuse('number', 'Annual output plan number range exhausted.');
                }
                DB::table('website_output_plan_counters')->where('id', $counter->id)->update(['last_number' => $next]);
                $number = sprintf('WEBPLAN/%d/%06d', $year, $next);
                $id = DB::table('website_output_plans')->insertGetId(['organisation_id' => $actor->organisation_id, 'release_id' => $release->id, 'release_number' => $release->number, 'release_manifest_hash' => $release->manifest_hash, 'number' => $number, 'schema_version' => 1, 'content' => $json, 'plan_hash' => $hash, 'created_by_user_id' => $actor->id, 'created_at' => $now]);
                DB::table('website_output_plan_controls')->insert(['plan_id' => $id, 'state' => 'prepared', 'lock_version' => 1]);
                if ($capture !== null) {
                    DB::table('website_output_plan_assets')->insert(['plan_id' => $id, 'source_asset_id' => $capture['asset']->id, ...$logo, 'encrypted_bytes' => Crypt::encryptString(base64_encode($capture['bytes']))]);
                }
                $action = 'prepared';
            } else {
                $plan = DB::table('website_output_plans')->where('id', $id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                abort_unless($plan !== null, 404);
                $this->verify($plan);
                if ($plan->release_id !== $release->id) {
                    self::refuse('release_id', 'Plan belongs to another release.');
                }
                $control = DB::table('website_output_plan_controls')->where('plan_id', $id)->lockForUpdate()->first();
                if ($control->lock_version !== (int) $data['lock_version'] || ! hash_equals($plan->plan_hash, $data['plan_hash'] ?? '')) {
                    self::refuse('version', 'Plan version or hash changed. Reload.');
                }
                if ($control->state !== ($operation === 'withdraw' ? 'approved' : 'prepared')) {
                    self::refuse('state', 'This action is not valid for the current plan state.');
                }
                if ($operation === 'approve' && ($data['confirmation'] ?? '') !== $plan->number) {
                    self::refuse('confirmation', 'Type the exact plan number to approve these frozen inputs.');
                }
                $number = $plan->number;
                $hash = $plan->plan_hash;
                $action = match ($operation) {
                    'approve' => 'approved','refuse' => 'refused','withdraw' => 'withdrawn'
                };
                DB::table('website_output_plan_controls')->where('plan_id', $id)->update(['state' => $action, 'lock_version' => $control->lock_version + 1]);
            }
            $reason = in_array($operation, ['refuse', 'withdraw'], true) ? WebsiteServiceFields::text($data['reason'] ?? '', 'reason', 300) : null;
            if ($reason === '') {
                self::refuse('reason', 'Give a plain-text reason.');
            }
            DB::table('website_output_plan_actions')->insert(['plan_id' => $id, 'actor_user_id' => $actor->id, 'action' => $action, 'plan_hash' => $hash, 'reason' => $reason, 'created_at' => $now]);
            DB::table('website_output_plan_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $fingerprint, 'plan_id' => $id, 'operation' => $operation, 'created_at' => $now]);
            $counts = ['aliases' => count(json_decode(DB::table('website_output_plans')->where('id', $id)->value('content'), true)['aliases']), 'assets' => DB::table('website_output_plan_assets')->where('plan_id', $id)->count()];
            if ($this->audit->record('website.output_plan.'.$action, null, ['plan_id' => $id, 'release_id' => $release->id, 'number' => $number, 'action' => $action, 'hash_prefix' => substr($hash, 0, 12), 'counts' => $counts], $actor, $branch) === null) {
                throw new \RuntimeException('Output plan audit could not be recorded.');
            }

            return $id;
        }, 3);
    }

    private function release(int $organisation, int $id): \stdClass
    {
        $r = DB::table('website_releases')->where('organisation_id', $organisation)->where('id', $id)->lockForUpdate()->first();
        abort_unless($r !== null, 404);
        if (! hash_equals($r->manifest_hash, hash('sha256', $r->manifest)) || WebsiteReleaseJson::encode(json_decode($r->manifest, true, 512, JSON_THROW_ON_ERROR)) !== $r->manifest) {
            throw new \RuntimeException('Release integrity check failed.');
        }

        return $r;
    }

    private function verify(\stdClass $plan): void
    {
        if (! hash_equals($plan->plan_hash, hash('sha256', $plan->content)) || WebsiteReleaseJson::encode(json_decode($plan->content, true, 512, JSON_THROW_ON_ERROR)) !== $plan->content) {
            throw new \RuntimeException('Output plan integrity check failed.');
        }
        $logo = json_decode($plan->content, true)['logo'];
        if ($logo !== null) {
            $asset = DB::table('website_output_plan_assets')->where('plan_id', $plan->id)->first();
            $bytes = $asset ? base64_decode(Crypt::decryptString($asset->encrypted_bytes), true) : false;
            if (! is_string($bytes) || strlen($bytes) !== $logo['bytes'] || ! hash_equals($logo['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('Output plan logo integrity check failed.');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function listing(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            [$actor] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::PREPARE);

            return array_values(DB::table('website_output_plans as p')->join('website_output_plan_controls as c', 'c.plan_id', '=', 'p.id')->where('p.organisation_id', $actor->organisation_id)->orderByDesc('p.id')->get(['p.id', 'p.number', 'p.release_number', 'c.state'])->map(fn ($r) => (array) $r)->values()->all());
        });
    }

    /** @return array<string,mixed> */
    public function page(User $actor, ?int $releaseId = null, ?int $id = null): array
    {
        return DB::transaction(function () use ($actor, $releaseId, $id): array {
            [$actor,$branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::PREPARE);
            $plans = DB::table('website_output_plans as p')->join('website_output_plan_controls as c', 'c.plan_id', '=', 'p.id')->where('p.organisation_id', $actor->organisation_id)->orderByDesc('p.id')->get(['p.*', 'c.state', 'c.lock_version']);
            $actions = DB::table('website_output_plan_actions')->whereIn('plan_id', $plans->pluck('id'))->orderBy('id')->get();
            $ids = $plans->pluck('created_by_user_id')->merge($actions->filter(fn ($a) => $a->action === 'approved' || $a->plan_id === $id)->pluck('actor_user_id'))->unique()->values()->all();
            $names = $ids === [] ? collect() : DB::table('users')->where('organisation_id', $actor->organisation_id)->whereIn('id', $ids)->pluck('name', 'id');
            $name = fn (int $userId): string => $names[$userId] ?? 'Staff #'.$userId;
            $detail = null;
            if ($id !== null) {
                $p = $plans->firstWhere('id', $id);
                abort_unless($p !== null, 404);
                $this->verify($p);
                $releaseId = $p->release_id;
                $detail = ['id' => $p->id, 'number' => $p->number, 'state' => $p->state, 'lockVersion' => $p->lock_version, 'hash' => $p->plan_hash, 'content' => json_decode($p->content, true, 512, JSON_THROW_ON_ERROR), 'actions' => $actions->where('plan_id', $id)->map(fn ($a) => ['action' => $a->action, 'actor' => $name($a->actor_user_id), 'at' => CarbonImmutable::parse($a->created_at)->setTimezone($branch->timezone)->format('d M Y H:i:s').' '.$branch->timezone, 'reason' => $a->reason])->values()->all()];
            }
            $release = null;
            $targets = [];
            $available = [];
            $usable = false;
            if ($releaseId !== null) {
                $r = $this->release($actor->organisation_id, $releaseId);
                $state = DB::table('website_release_controls')->where('release_id', $r->id)->value('state');
                if ($id === null) {
                    abort_unless($state === 'approved', 409, 'Choose a currently approved release.');
                }
                $release = ['id' => $r->id, 'number' => $r->number, 'hash' => $r->manifest_hash, 'state' => $state];
                $manifest = json_decode($r->manifest, true, 512, JSON_THROW_ON_ERROR);
                $targets = WebsiteOutputPlanFields::targets($manifest);
                $available = WebsiteOutputPlanFields::available($manifest);
                $usable = $state === 'approved' && ($detail['state'] ?? null) === 'approved';
            }
            $assets = $id === null && $release !== null ? DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->orderBy('id')->get(['public_id', 'alt_en', 'alt_bm'])->all() : [];

            return ['branchId' => $branch->id, 'release' => $release, 'targets' => $targets, 'availablePages' => $available, 'labels' => WebsiteOutputProfile::LABELS, 'designProfile' => WebsiteOutputProfile::frozen(), 'assets' => $assets, 'plans' => $plans->map(function ($p) use ($name, $actions): array {
                $approval = $actions->where('plan_id', $p->id)->firstWhere('action', 'approved');

                return ['id' => $p->id, 'number' => $p->number, 'releaseNumber' => $p->release_number, 'state' => $p->state, 'creator' => $name($p->created_by_user_id), 'approver' => $approval ? $name($approval->actor_user_id) : null];
            })->all(), 'detail' => $detail, 'usable' => $usable, 'canApprove' => $actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::APPROVE)];
        });
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
