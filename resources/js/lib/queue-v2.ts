import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import type { PatientBoardRow } from '@/types';

/**
 * Version 2 Consultation queue board: pure helpers that turn the server's board row into what the V2 blocks show.
 * Nothing here decides a permission. Every menu item comes from the server's `can.*`, `callHold`, `treatmentProgress`,
 * `historyUrl` and `dispensaryUrl`, exactly as the Classic menu does (`components/patient-board/PatientBoard.vue`).
 * The Classic board never wires Send to waiting, Call to dispensary, Dispense or Open billing on the queue page, so
 * they are not here either.
 */
export type ChipTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export type RowStatus = { label: string; tone: ChipTone };

/** The Classic status words in sentence case, with the V2 chip tone for the Classic `statusTone`. */
export const rowStatus = (row: PatientBoardRow): RowStatus => {
    const label = row.statusLabel
        .replace('Serving Now', 'Serving now')
        .replace('On Hold', 'On hold')
        .replace(' · carry-over', '');

    switch (row.statusTone) {
        case 'serving':
            return { label, tone: 'info' };
        case 'held':
            return { label, tone: 'warning' };
        case 'cancelled':
            return { label, tone: 'danger' };
        default:
            return { label, tone: 'neutral' };
    }
};

export type MenuTarget =
    { kind: 'link'; href: string } | { kind: 'action'; action: RowAction };

export type RowAction =
    | 'call'
    | 'recall'
    | 'callTreatment'
    | 'openConsultation'
    | 'markNotReady'
    | 'markReady'
    | 'treatmentProgress'
    | 'cancel';

const encode = (value: string) => encodeURIComponent(value);

export const visitHref = (row: PatientBoardRow) =>
    `/visits/${encode(row.visitNumber)}`;
export const patientHref = (row: PatientBoardRow) =>
    `/patients/${encode(row.patientNumber)}`;
export const editHref = (row: PatientBoardRow) =>
    `/visits/${encode(row.visitNumber)}/edit`;

/**
 * The grouped menu for one board row, plus the target of each item id. A group with no allowed item is left out, so a
 * role that may only view sees only the Open group.
 */
export const rowMenu = (
    row: PatientBoardRow,
    branchId: number | undefined,
): { groups: RowMenuGroup[]; targets: Record<string, MenuTarget> } => {
    const targets: Record<string, MenuTarget> = {};
    const link = (id: string, href: string) => {
        targets[id] = { kind: 'link', href };
    };
    const action = (id: string, run: RowAction) => {
        targets[id] = { kind: 'action', action: run };
    };
    const can = row.can;
    const hold = row.callHold;
    const open: RowMenuGroup['items'] = [];
    const call: RowMenuGroup['items'] = [];
    const treatment: RowMenuGroup['items'] = [];
    const money: RowMenuGroup['items'] = [];
    const danger: RowMenuGroup['items'] = [];

    open.push({ id: 'view-visit', label: 'View visit', allowed: true });
    link('view-visit', visitHref(row));

    if (can.viewPatient) {
        open.push({ id: 'view-patient', label: 'View patient', allowed: true });
        link('view-patient', patientHref(row));
    }

    if (can.update) {
        open.push({ id: 'edit-visit', label: 'Edit visit', allowed: true });
        link('edit-visit', editHref(row));
    }

    if (can.openConsultation) {
        open.push({
            id: 'open-consultation',
            label: 'Open consultation',
            allowed: true,
        });
        action('open-consultation', 'openConsultation');
    }

    if (can.openDispensary && row.dispensaryUrl) {
        open.push({
            id: 'open-dispensary',
            label: 'Open dispensary',
            allowed: true,
        });
        link('open-dispensary', row.dispensaryUrl);
    }

    if (can.call) {
        const blocked = !!hold?.active;

        // The row has its own "Call in" button at every width, so the menu keeps only the override path.
        if (blocked && hold?.canOverride) {
            call.push({
                id: 'override-call',
                label: 'Override and call',
                allowed: true,
            });
            action('override-call', 'call');
        }
    }

    if (can.recall) {
        call.push({ id: 'call-again', label: 'Call again', allowed: true });
        action('call-again', 'recall');
    }

    if (can.callTreatment) {
        call.push({
            id: 'call-treatment',
            label: 'Call to treatment room',
            allowed: true,
        });
        action('call-treatment', 'callTreatment');
    }

    // Same rule as Classic's `CallReadinessActions`: only on a waiting row, and set or clear as the server allows.
    if (row.statusTone === 'waiting') {
        if (hold?.active ? hold.canClear : hold?.canSet) {
            const id = hold?.active ? 'mark-ready' : 'mark-not-ready';

            call.push({
                id,
                label: hold?.active ? 'Ready to be called' : 'Mark not ready',
                allowed: true,
            });
            action(id, hold?.active ? 'markReady' : 'markNotReady');
        }
    }

    if (branchId && row.treatmentProgress?.canManage) {
        treatment.push({
            id: 'treatment-progress',
            label: 'Treatment progress…',
            allowed: true,
        });
        action('treatment-progress', 'treatmentProgress');
    }

    if (row.historyUrl) {
        money.push({ id: 'invoices', label: 'Invoices', allowed: true });
        link('invoices', row.historyUrl);
    }

    if (can.cancel) {
        danger.push({
            id: 'cancel-visit',
            label: 'Cancel visit…',
            allowed: true,
            tone: 'danger',
        });
        action('cancel-visit', 'cancel');
    }

    const groups: RowMenuGroup[] = [
        { label: 'Open', items: open },
        { label: 'Call', items: call },
        { label: 'Treatment', items: treatment },
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

/** Minutes to show in the waiting ring: only a waiting patient who is not on hold (as the Classic chip). */
export const waitRingMinutes = (row: PatientBoardRow): number | null =>
    row.waitingMinutes ?? null;

export type AttentionChip = {
    tone: ChipTone;
    label: string;
    /** The reason, shown as plain text beside the chip, so a long reason never wraps inside a pill. */
    detail?: string;
};

export type AttentionItem = {
    id: string;
    visitNumber: string;
    /** Queue number, or the visit number when there is none. */
    label: string;
    patientName: string;
    /** "97 min · very long wait" when the wait is 60 minutes or more, otherwise ''. */
    waitText: string;
    /** Flags in words: not ready, treatment done, held over 30 minutes. */
    chips: AttentionChip[];
};

/**
 * "Needs attention" from the entries on screen only (the panel says so), one item per patient: a very long wait (60
 * minutes, as the waiting ring), a "not ready to call" flag, a treatment step done and ready to resume, and a held
 * consultation over 30 minutes.
 */
export const needsAttention = (rows: PatientBoardRow[]): AttentionItem[] => {
    const items: AttentionItem[] = [];

    for (const row of rows) {
        const chips: AttentionChip[] = [];
        const minutes = waitRingMinutes(row);
        const veryLong = minutes !== null && minutes >= 60;

        if (row.callHold?.active) {
            chips.push({
                tone: 'warning',
                label: 'Not ready',
                detail: row.callHold.reason ?? 'No reason recorded',
            });
        }

        if (row.treatmentProgress?.state === 'done') {
            chips.push({
                tone: 'info',
                label: `${row.treatmentProgress.label ?? 'Treatment'} done`,
                detail: 'Ready to resume',
            });
        }

        if (row.statusTone === 'held' && /30\+/.test(row.statusLabel)) {
            chips.push({ tone: 'warning', label: 'On hold over 30 minutes' });
        }

        if (!veryLong && chips.length === 0) {
            continue;
        }

        items.push({
            id: row.key,
            visitNumber: row.visitNumber,
            label: row.queueNumber ?? row.visitNumber,
            patientName: row.patientName,
            waitText: veryLong ? `${minutes} min · very long wait` : '',
            chips,
        });
    }

    return items;
};
