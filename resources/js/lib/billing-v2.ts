import type { BillingState } from '@/types/billing';
import { myr } from '@/types/billing';

/**
 * BV2-1: the Version 2 Patient Billing page's one main action and its "Due now" line, from the server's existing
 * permission flags and invoice figures only (no money rule of its own). Kept pure so each state can be tested.
 */
export type BillingPrimary =
    'build' | 'finalize' | 'pay' | 'settle' | 'complete' | null;

type PrimaryInput = {
    invoice: null | { status: string; state: BillingState };
    can: { build: boolean; finalize: boolean; pay: boolean; complete: boolean };
};

export const billingPrimary = (
    b: PrimaryInput,
    completed: boolean,
): BillingPrimary => {
    if (!b.invoice) {
        return b.can.build ? 'build' : null;
    }

    if (b.invoice.status === 'draft') {
        // A stale draft cannot be finalized until it is rebuilt.
        return b.can.finalize ? 'finalize' : b.can.build ? 'build' : null;
    }

    const { due_now: due, deferred } = b.invoice.state;

    if (b.can.pay) {
        // After completion the balance includes approved pay later still owed (the return-visit settlement).
        if (completed && due + deferred > 0) {
            return 'settle';
        }

        if (!completed && due > 0) {
            return 'pay';
        }
    }

    return !completed && b.can.complete ? 'complete' : null;
};

export const billingPrimaryLabel: Record<
    Exclude<BillingPrimary, null>,
    string
> = {
    build: 'Build / refresh draft',
    finalize: 'Finalize Invoice',
    pay: 'Record new payment',
    settle: 'Settle Outstanding Balance',
    complete: 'Complete Visitation',
};

/** "Due now RM x"; with nothing due now, approved pay later still owed in words; otherwise "Nothing due". */
export const billingDueLine = (state: BillingState | null): string | null => {
    if (!state) {
        return null;
    }

    if (state.due_now > 0) {
        return `Due now ${myr(state.due_now)}`;
    }

    return state.deferred > 0
        ? `Pay later owed ${myr(state.deferred)}`
        : 'Nothing due';
};

/** The same figure split for the summary: a small label line above a large amount (Daniel: nothing wraps in the rail). */
export const billingDueParts = (
    state: BillingState | null,
): { label: string | null; figure: string } | null => {
    if (!state) {
        return null;
    }

    if (state.due_now > 0) {
        return { label: 'Due now', figure: myr(state.due_now) };
    }

    return state.deferred > 0
        ? { label: 'Pay later owed', figure: myr(state.deferred) }
        : { label: null, figure: 'Nothing due' };
};
