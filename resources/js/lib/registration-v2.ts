import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import { queuePresentationLabel } from '@/lib/r1c2-presentation';
import type { PatientBoardRow, VisitRow } from '@/types';

/**
 * Version 2 Registration board: pure helpers that turn the server's visit row into what the V2 blocks show.
 * Nothing here decides a permission. Every menu item comes from the server's `can.*`, `callHold`, `historyUrl`,
 * `dispensaryUrl` and `billingUrl`, exactly as the Classic menu does (`components/patient-board/PatientBoard.vue`).
 * Call again, Call to treatment room and Treatment progress are Queue-board items: the server never sets them for a
 * Registration row, so they are not here.
 */
export type ChipTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export type RowStatus = { label: string; tone: ChipTone };

export const rowStatus = (visit: VisitRow): RowStatus => {
    if (visit.isHeld) {
        return { label: 'On hold', tone: 'warning' };
    }

    if (visit.status === 'completed') {
        return { label: 'Completed', tone: 'success' };
    }

    if (visit.awaitingBilling) {
        return { label: 'Awaiting billing', tone: 'neutral' };
    }

    if (visit.dispensaryStatus) {
        return visit.dispensaryStatus === 'pending'
            ? { label: 'Dispensary: pending', tone: 'neutral' }
            : { label: 'Dispensary: dispensing', tone: 'info' };
    }

    if (visit.status === 'cancelled') {
        return { label: 'Cancelled', tone: 'danger' };
    }

    if (visit.queueStatus === 'serving') {
        return { label: 'Serving now', tone: 'info' };
    }

    if (visit.queueStatus === 'waiting') {
        return { label: 'Waiting', tone: 'neutral' };
    }

    if (visit.queueStatus === 'removed') {
        return {
            label: queuePresentationLabel({
                status: 'removed',
                removalReason: visit.queueRemovalReason,
                visitStatus: visit.status,
            }),
            tone: 'neutral',
        };
    }

    return { label: 'Registered', tone: 'neutral' };
};

/** Minutes to show in the waiting ring: only for a waiting patient who is not on hold (as the Classic chip). */
export const waitingMinutes = (visit: VisitRow): number | null =>
    !visit.isHeld && visit.queueStatus === 'waiting'
        ? visit.durationMinutes
        : null;

export type MenuTarget =
    { kind: 'link'; href: string } | { kind: 'action'; action: RowAction };

export type RowAction =
    | 'sendToWaiting'
    | 'call'
    | 'openConsultation'
    | 'callDispensary'
    | 'dispense'
    | 'cancel';

const encode = (value: string) => encodeURIComponent(value);

export const visitHref = (visit: VisitRow) =>
    `/visits/${encode(visit.visitNumber)}`;
export const patientHref = (visit: VisitRow) =>
    `/patients/${encode(visit.patientNumber)}`;
export const editHref = (visit: VisitRow) =>
    `/visits/${encode(visit.visitNumber)}/edit`;

/**
 * The grouped menu for one row, plus the target of each item id. A group with no allowed or reasoned item is left
 * out, so a role that may only view sees only the Open group.
 */
export const rowMenu = (
    visit: VisitRow,
): { groups: RowMenuGroup[]; targets: Record<string, MenuTarget> } => {
    const targets: Record<string, MenuTarget> = {};
    const link = (id: string, href: string) => {
        targets[id] = { kind: 'link', href };
    };
    const action = (id: string, run: RowAction) => {
        targets[id] = { kind: 'action', action: run };
    };
    const can = visit.can;
    const hold = visit.callHold;
    const open: RowMenuGroup['items'] = [];
    const move: RowMenuGroup['items'] = [];
    const call: RowMenuGroup['items'] = [];
    const money: RowMenuGroup['items'] = [];
    const danger: RowMenuGroup['items'] = [];

    open.push({ id: 'view-visit', label: 'View Visit', allowed: true });
    link('view-visit', visitHref(visit));

    if (can.viewPatient) {
        open.push({ id: 'view-patient', label: 'View Patient', allowed: true });
        link('view-patient', patientHref(visit));
    }

    if (can.update) {
        open.push({ id: 'edit-visit', label: 'Edit Visit', allowed: true });
        link('edit-visit', editHref(visit));
    }

    if (can.openDispensary && visit.dispensaryUrl) {
        open.push({
            id: 'open-dispensary',
            label: 'Open Dispensary',
            allowed: true,
        });
        link('open-dispensary', visit.dispensaryUrl);
    }

    if (can.sendToWaiting) {
        move.push({
            id: 'send-to-waiting',
            label: 'Send to Waiting',
            allowed: true,
        });
        action('send-to-waiting', 'sendToWaiting');
    }

    if (can.call) {
        const blocked = !!hold?.active;

        call.push({
            id: 'call-in',
            label: 'Call In',
            allowed: !blocked,
            reason: blocked ? `Not ready to call: ${hold?.reason}` : undefined,
        });
        action('call-in', 'call');

        if (blocked && hold?.canOverride) {
            call.push({
                id: 'override-call',
                label: 'Override and call',
                allowed: true,
                reason: undefined,
            });
            action('override-call', 'call');
        }
    }

    if (can.openConsultation) {
        call.push({
            id: 'open-consultation',
            label: 'Open Consultation',
            allowed: true,
        });
        action('open-consultation', 'openConsultation');
    }

    if (can.callDispensary) {
        call.push({
            id: 'call-dispensary',
            label: 'Call to Dispensary',
            allowed: true,
        });
        action('call-dispensary', 'callDispensary');
    }

    if (can.dispense) {
        call.push({ id: 'dispense', label: 'Dispense', allowed: true });
        action('dispense', 'dispense');
    }

    if (visit.historyUrl) {
        money.push({ id: 'invoices', label: 'Invoices', allowed: true });
        link('invoices', visit.historyUrl);
    }

    if (can.openBilling && visit.billingUrl) {
        money.push({
            id: 'open-billing',
            label: 'Open Billing',
            allowed: true,
        });
        link('open-billing', visit.billingUrl);
    }

    if (can.cancel) {
        danger.push({
            id: 'cancel-visit',
            label: 'Cancel Visit…',
            allowed: true,
            tone: 'danger',
        });
        action('cancel-visit', 'cancel');
    }

    const groups: RowMenuGroup[] = [
        { label: 'Open', items: open },
        { label: 'Move', items: move },
        { label: 'Call', items: call },
        { label: 'Money', items: money },
        { label: 'Danger', items: danger },
    ].filter((group) => group.items.length > 0);

    // Link items are real links in the menu, so middle-click and "open in new tab" work.
    for (const group of groups) {
        for (const item of group.items) {
            const target = targets[item.id];

            if (target?.kind === 'link' && item.allowed) {
                item.href = target.href;
            }
        }
    }

    return { groups, targets };
};

export type AttentionItem = {
    id: string;
    tone: 'warning' | 'danger';
    title: string;
    why: string;
};

/**
 * "Needs attention" from the rows now on screen only (the panel says so): a patient marked not ready to call, and a
 * waiting patient at the very-long-wait time (60 minutes, as the waiting ring).
 */
export const needsAttention = (rows: VisitRow[]): AttentionItem[] => {
    const items: AttentionItem[] = [];

    for (const visit of rows) {
        const label = visit.queueNumber ?? visit.visitNumber;

        if (visit.callHold?.active) {
            items.push({
                id: `hold-${visit.visitNumber}`,
                tone: 'warning',
                title: `${label} · ${visit.patientName} is marked not ready to call`,
                why: visit.callHold.reason ?? 'No reason recorded.',
            });
        }

        const minutes = waitingMinutes(visit);

        if (minutes !== null && minutes >= 60) {
            items.push({
                id: `wait-${visit.visitNumber}`,
                tone: 'danger',
                title: `${label} · ${visit.patientName} has waited ${minutes} minutes`,
                why: 'A very long wait. Call this patient or tell them why they are waiting.',
            });
        }
    }

    return items;
};

/** "Waiting now" and "Longest wait" over the rows now on screen. */
export const waitStats = (rows: VisitRow[]) => {
    const waiting = rows
        .map(waitingMinutes)
        .filter((minutes): minutes is number => minutes !== null);

    return {
        waitingNow: waiting.length,
        longestWait: waiting.length ? Math.max(...waiting) : null,
    };
};

/**
 * The Classic board row shape, only so the shared dialogs (`CallReadinessActions`, `VisitCancellationDialog`) can be
 * reused unchanged. `statusTone` follows the Classic precedence: completed and awaiting billing are neutral, a
 * pending Dispensary case or a waiting patient is "waiting".
 */
export const legacyBoardRow = (visit: VisitRow): PatientBoardRow => ({
    key: visit.visitNumber,
    canWhatsapp: visit.canWhatsapp,
    callHold: visit.callHold,
    patientName: visit.patientName,
    patientNumber: visit.patientNumber,
    visitNumber: visit.visitNumber,
    queueNumber: visit.queueNumber,
    arrivedDate: visit.registeredAtDate,
    arrivedTime: visit.registeredAt,
    visitNotes: visit.visitReasonExcerpt,
    doctorName: visit.doctorName,
    coverageLabel: visit.coverageLabel,
    panelNote: visit.panelNote,
    durationLabel: '',
    waitingMinutes: waitingMinutes(visit),
    priority: visit.priority,
    returnedFromDispensary: visit.returnedFromDispensary,
    dispensaryUrl: visit.dispensaryUrl,
    billingUrl: visit.billingUrl,
    historyUrl: visit.historyUrl,
    completedAt: visit.completedAt,
    statusLabel: rowStatus(visit).label,
    statusTone: visit.isHeld
        ? 'held'
        : visit.status === 'completed' || visit.awaitingBilling
          ? 'neutral'
          : visit.dispensaryStatus
            ? visit.dispensaryStatus === 'pending'
                ? 'waiting'
                : 'serving'
            : visit.status === 'cancelled'
              ? 'cancelled'
              : visit.queueStatus === 'serving'
                ? 'serving'
                : visit.queueStatus === 'waiting'
                  ? 'waiting'
                  : visit.queueStatus === 'removed'
                    ? 'removed'
                    : 'neutral',
    can: visit.can,
    source: visit,
});
