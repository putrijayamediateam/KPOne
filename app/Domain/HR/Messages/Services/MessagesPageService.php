<?php

namespace App\Domain\HR\Messages\Services;

use App\Domain\HR\Messages\MessagePermissions;
use App\Domain\HR\Messages\MessageTemplates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-14 page props for /hr/messages: "My messages" (every human staff member: which channels KPOne may use for their
 * own leave, roster, payslip and claims), "Settings" (Director, HR Manager: each channel's switch, price per message,
 * monthly cap and this month's count and cost) and "Sent" (Director, HR Manager: one row per message with the
 * template, channel, recipient, status and time; never the text). Read only.
 */
final class MessagesPageService
{
    public const PER_PAGE = 25;

    public const TEMPLATE_LABELS = [
        'leave_decided' => 'Leave approved or rejected',
        'roster_ready' => 'Roster ready',
        'payslip_ready' => 'Payslip ready',
        'claim_decided' => 'Staff claim decided or paid',
    ];

    public const STATUS_LABELS = ['sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped_cap' => 'Not sent: monthly cap reached'];

    public function __construct(private MessageDelivery $delivery) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canReceive = $actor->can(MessagePermissions::RECEIVE_OWN);
        $canManage = $actor->can(MessagePermissions::MANAGE);
        abort_unless($canReceive || $canManage, 403);
        $tabs = array_keys(array_filter(['my' => $canReceive, 'settings' => $canManage, 'sent' => $canManage]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');
        $zone = $this->zone($actor->organisation_id);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'templates' => collect(MessageTemplates::TEMPLATES)->map(fn (array $t, string $code): array => [
                'code' => $code, 'label' => self::TEMPLATE_LABELS[$code], 'example' => self::example($code),
            ])->values()->all(),
            'my' => $canReceive && $tab === 'my' ? $this->my($actor) : null,
            'settings' => $canManage && $tab === 'settings' ? $this->settings($actor) : null,
            'sent' => $canManage && $tab === 'sent' ? $this->sent($actor, $query, $zone) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function my(User $actor): array
    {
        $prefs = DB::table('hr_message_preferences')->where('user_id', $actor->id)->get()->keyBy('channel');

        return array_values(collect(MessageDelivery::CHANNELS)->map(function (string $channel) use ($actor, $prefs): array {
            $settings = MessageDelivery::settings($actor->organisation_id, $channel);

            return [
                'channel' => $channel,
                'label' => MessageSettingsService::LABELS[$channel],
                'connected' => $this->delivery->channel($channel)->connected(),
                'switchedOn' => $settings !== null && (bool) $settings->enabled,
                'optedIn' => (bool) ($prefs[$channel]->opted_in ?? false),
                'lockVersion' => (int) ($prefs[$channel]->lock_version ?? 0),
                'address' => $channel === 'email' ? $actor->email : null,
            ];
        })->values()->all());
    }

    /** @return list<array<string, mixed>> */
    private function settings(User $actor): array
    {
        $month = MessageDelivery::periodMonth($actor->organisation_id);
        $counts = DB::table('hr_message_deliveries')->where('organisation_id', $actor->organisation_id)->where('period_month', $month)
            ->selectRaw("channel, count(*) filter (where status in ('sending', 'sent')) as sent, count(*) filter (where status = 'failed') as failed, count(*) filter (where status = 'skipped_cap') as skipped")
            ->groupBy('channel')->get()->keyBy('channel');
        $chosen = DB::table('hr_message_preferences')->where('organisation_id', $actor->organisation_id)->where('opted_in', true)
            ->selectRaw('channel, count(*) as people')->groupBy('channel')->pluck('people', 'channel');

        return array_values(collect(MessageDelivery::CHANNELS)->map(function (string $channel) use ($actor, $counts, $chosen, $month): array {
            $s = MessageDelivery::settings($actor->organisation_id, $channel);
            $sent = (int) ($counts[$channel]->sent ?? 0);
            $price = $s?->price_sen === null ? null : (int) $s->price_sen;

            return [
                'channel' => $channel,
                'label' => MessageSettingsService::LABELS[$channel],
                'connected' => $this->delivery->channel($channel)->connected(),
                'version' => $s === null ? 0 : (int) $s->version,
                'enabled' => $s !== null && (bool) $s->enabled,
                'monthlyCap' => $s?->monthly_cap === null ? null : (int) $s->monthly_cap,
                'price' => $price === null ? null : self::ringgit($price),
                'month' => CarbonImmutable::parse($month)->format('F Y'),
                'sent' => $sent,
                'failed' => (int) ($counts[$channel]->failed ?? 0),
                'skipped' => (int) ($counts[$channel]->skipped ?? 0),
                'cost' => $price === null ? null : self::ringgit($price * $sent),
                'people' => (int) ($chosen[$channel] ?? 0),
            ];
        })->values()->all());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function sent(User $actor, array $query, string $zone): array
    {
        $page = max(1, (int) filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));
        $base = DB::table('hr_message_deliveries as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.organisation_id', $actor->organisation_id);
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('d.created_at')->orderByDesc('d.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['d.public_id', 'd.channel', 'd.template', 'd.status', 'd.error_code', 'd.created_at', 'u.name']);

        return [
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'rows' => $rows->map(fn (object $r): array => [
                'id' => (string) $r->public_id,
                'to' => (string) $r->name,
                'channel' => MessageSettingsService::LABELS[$r->channel] ?? (string) $r->channel,
                'template' => self::TEMPLATE_LABELS[$r->template] ?? (string) $r->template,
                'status' => (string) $r->status,
                'statusLabel' => self::STATUS_LABELS[$r->status] ?? (string) $r->status,
                'error' => match ($r->error_code) {
                    'no_address' => 'No email address on the account',
                    'not_connected' => 'Channel not connected',
                    'send_skipped' => 'Not tried: the mail service failed earlier in the same action',
                    null => null,
                    default => 'The mail service refused it',
                },
                'at' => CarbonImmutable::parse((string) $r->created_at)->setTimezone($zone)->format('j M Y, g:i A'),
            ])->values()->all(),
        ];
    }

    /** A sample with made-up values, so staff and HR see exactly what is sent. */
    public static function example(string $code): string
    {
        $params = ['from' => '8 Mar 2026', 'to' => '10 Mar 2026', 'week' => '9 Mar 2026', 'month' => 'October 2026', 'claim' => 'SC-000042', 'decision' => 'approved'];
        $needed = MessageTemplates::placeholders($code);

        return MessageTemplates::render($code, array_intersect_key($params, array_flip($needed)))['body'];
    }

    private static function ringgit(int $sen): string
    {
        return 'RM '.number_format(intdiv($sen, 100)).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}
