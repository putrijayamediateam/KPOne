/**
 * The one rule for "is this visit held by an unsaved-work guard?". Pure, so it is unit-tested without a browser.
 * V2LeaveGuard (a page) and V2Dialog (an open, changed sheet) both call it through `useLeaveGuard`.
 *
 * Held ONLY when all of these are true: the guard is dirty and not bypassed; the visit is a GET; and it goes to a
 * DIFFERENT path. So never held: a save (POST, PATCH, PUT, DELETE), a visit to the same path (a reload, a partial reload
 * with `only`, a polling refresh such as the Registration and Queue live refresh, a filter or page change on the same
 * screen), and anything while nothing has changed.
 */
export type VisitLike = { method: string; url: URL | string };

export function holdTarget(
    visit: VisitLike,
    current: { pathname: string; origin: string },
    state: { dirty: boolean; bypass: boolean },
): string | null {
    if (state.bypass || !state.dirty) {
        return null;
    }

    if (visit.method.toLowerCase() !== 'get') {
        return null;
    }

    const target = new URL(visit.url.toString(), current.origin);

    if (target.pathname === current.pathname) {
        return null;
    }

    return `${target.pathname}${target.search}`;
}
