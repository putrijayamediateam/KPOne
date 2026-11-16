// V2 doctor's Dispensary attention panel: the words for the review count and the Show all toggle (owner's middle way,
// with Mira's conditions). Kept in a plain module so tests can check the real output.
export const visibleLimit = 5;

/** The heading chip: "1 needs review", "6 need review". */
export const needReviewLabel = (count: number): string =>
    count === 1 ? '1 needs review' : `${count} need review`;

/** The collapsed toggle: "Show all 6", or "Show all 7 · 2 more need review" when hidden rows still need review. */
export const showAllLabel = (total: number, hiddenReview: number): string => {
    if (hiddenReview === 1) {
        return `Show all ${total} · 1 more needs review`;
    }

    if (hiddenReview > 1) {
        return `Show all ${total} · ${hiddenReview} more need review`;
    }

    return `Show all ${total}`;
};
