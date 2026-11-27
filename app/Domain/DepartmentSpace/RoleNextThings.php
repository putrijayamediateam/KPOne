<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Visit\Billing\Services\SalesTargetService;
use App\Models\User;

/**
 * Clean Look role homes (CA, CA Supervisor, Resident Doctor): one list of "Your next things", a live "Right now" strip and the doctor's
 * own sales target, composed only from the services each board already uses. Every part keeps that board's own permission and branch
 * checks, so a person sees only what they could open on its page, and a patient shows as a queue number and a short name, never the
 * full name. Read only: nothing here writes, and nothing is cached (the home polls it).
 */
final class RoleNextThings
{
    public const LIMIT = 8;

    public function __construct(
        private FrontDeskSpace $frontDesk,
        private PharmacySpace $pharmacy,
        private ConsultationSpace $consultation,
        private SalesTargetService $targets,
    ) {}

    /**
     * @return array{live: array<string, int>|null, things: list<array<string, mixed>>, thingsTotal: int, sales: array<string, mixed>|null}
     */
    public function for(User $actor): array
    {
        $things = [];
        $live = [];

        $waiting = $this->frontDesk->waiting($actor);
        if ($waiting !== null) {
            $live['waiting'] = $waiting['counts']['waiting'] ?? 0;
            $live['serving'] = $waiting['counts']['serving'] ?? 0;
            foreach ($waiting['all'] as $row) {
                $things[] = $this->thing('call', 'Waiting '.($row['queueNumber'] ?? $row['visitNumber']).' '.$row['patient'], SpaceDisplay::waitText((int) $row['minutes']), (int) $row['minutes'], $row['href'], $row['notReady'] ?? null);
            }
        }

        $dispense = $this->pharmacy->toDispense($actor);
        if ($dispense !== null) {
            $live['toDispense'] = $dispense['total'];
            foreach ($dispense['rows'] as $row) {
                $things[] = $this->thing('dispense', 'Dispense '.($row['queueNumber'] ?? $row['visitNumber']).' '.$row['patient'], SpaceDisplay::waitText((int) $row['minutes']), (int) $row['minutes'], $row['href'], null);
            }
        }

        $billing = $this->frontDesk->awaitingBilling($actor);
        if ($billing !== null) {
            $live['awaitingPayment'] = $billing['total'];
            foreach ($billing['rows'] as $row) {
                $things[] = $this->thing('payment', 'Collect payment '.($row['queueNumber'] ?? '').' '.$row['patient'], 'Waiting for the bill', 0, route('registration.index', absolute: false), null);
            }
        }

        $own = $this->consultation->snapshot($actor);
        if ($own !== null) {
            $live['myWaiting'] = $own['waitingTotal'];
            foreach ($own['waiting'] as $row) {
                $things[] = $this->thing('consult', ($row['urgent'] ? 'Urgent ' : 'Waiting ').$row['queueNumber'].' '.$row['patient'], (string) ($row['waitText'] ?? ''), (int) $row['minutes'], $row['href'], $row['notReady'], (bool) $row['urgent']);
            }
            foreach ($own['held'] as $row) {
                $things[] = $this->thing('held', 'On hold '.$row['queueNumber'].' '.$row['patient'], (string) $row['heldText'], $row['tooLong'] ? 30 : 0, $row['href'], null);
            }
        }

        // A doctor sees the branch Waiting list and their own queue: show each visit once, as the richer own-queue row.
        $ownHrefs = array_column(array_filter($things, fn (array $t): bool => in_array($t['kind'], ['consult', 'held'], true)), 'href');
        $things = array_values(array_filter($things, fn (array $t): bool => $t['kind'] !== 'call' || ! in_array($t['href'], $ownHrefs, true)));

        usort($things, fn (array $a, array $b): int => [$b['rank'], $b['minutes']] <=> [$a['rank'], $a['minutes']]);

        return [
            'live' => $live === [] ? null : $live,
            'things' => array_map(static function (array $t): array {
                unset($t['rank']);

                return $t;
            }, array_slice($things, 0, self::LIMIT)),
            'thingsTotal' => count($things),
            'sales' => $this->targets->own($actor),
        ];
    }

    /**
     * Amber from 30 minutes, red from 60 (the Queue board's own colours); an urgent patient always ranks first.
     *
     * @return array<string, mixed>
     */
    private function thing(string $kind, string $title, string $detail, int $minutes, string $href, ?string $notReady, bool $urgent = false): array
    {
        $tone = $minutes >= 60 ? 'red' : ($minutes >= 30 ? 'amber' : 'plain');

        return [
            'kind' => $kind, 'title' => trim(preg_replace('/\s+/', ' ', $title) ?? $title), 'detail' => $detail, 'minutes' => $minutes,
            'tone' => $urgent ? 'red' : $tone, 'notReady' => $notReady, 'href' => $href,
            'rank' => $urgent ? 3 : ($tone === 'red' ? 2 : ($tone === 'amber' ? 1 : 0)),
        ];
    }
}
