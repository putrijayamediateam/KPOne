/**
 * Q-5: the Consultation Completed tab lists only the latest entries, while its count is every completed entry
 * at the branch. Returns the line to show under the list, or null when nothing is hidden. No line while the
 * tab has no rows yet (its refresh is still in flight or failed), so it never says "the latest 0 of N".
 */
export function completedShownLine(
    activeTab: string,
    shown: number,
    count: number | undefined,
): string | null {
    if (activeTab !== 'removed' || shown <= 0 || count === undefined) {
        return null;
    }

    return count > shown ? `Showing the latest ${shown} of ${count}` : null;
}

/**
 * Q-5b: the Serving Now tab also lists only the latest 25 entries while its count is every serving entry at the
 * branch (a patient left in Serving from an earlier day still counts). Same rules as the Completed tab.
 */
export function servingShownLine(
    activeTab: string,
    shown: number,
    count: number | undefined,
): string | null {
    if (activeTab !== 'serving' || shown <= 0 || count === undefined) {
        return null;
    }

    return count > shown ? `Showing the latest ${shown} of ${count}` : null;
}
