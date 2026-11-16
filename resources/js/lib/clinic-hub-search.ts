/**
 * The Clinic management "Find a page" rule (Classic hub): narrow the links the server already listed. Every typed word
 * must be in the page's title, its description or its card's title (case does not matter), so "stock count", "refund"
 * or "panel" finds what it should. It only filters: it never adds a link, so a page the person's permissions hide
 * cannot appear.
 */
export type HubSearchLink = { title: string; description: string };

export const searchWords = (query: string): string[] =>
    query.trim().toLowerCase().split(/\s+/).filter(Boolean);

export const hubLinkMatches = (
    words: string[],
    groupTitle: string,
    link: HubSearchLink,
): boolean => {
    const text =
        `${link.title} ${link.description} ${groupTitle}`.toLowerCase();

    return words.every((word) => text.includes(word));
};
