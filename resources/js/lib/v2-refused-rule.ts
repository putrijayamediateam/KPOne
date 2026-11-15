/**
 * The pure rule behind `lib/v2-refused-request.ts` (DD-84): which responses on which pages get the plain notice.
 * Nothing here touches the page, so it can be tested alone.
 *
 * Only a Version 2 page (`<html data-ui="v2">`) and only 403 and 419. Classic, 409 (Inertia's own reload), 5xx and 404
 * return null, so Inertia behaves exactly as before for them.
 *
 * Wording (Daniel): 403 "You don't have permission to do this. Nothing was changed."; 419 "This page was open too long,
 * so nothing was saved. Reload the page and try again." Only 419 offers Reload: reloading does not fix a 403.
 */
export type RefusedRequest = { status: 403 | 419; message: string };

export const REFUSED_MESSAGES: Record<403 | 419, string> = {
    403: "You don't have permission to do this. Nothing was changed.",
    419: 'This page was open too long, so nothing was saved. Reload the page and try again.',
};

export const refusalFor = (
    ui: string | undefined,
    status: number | undefined,
): RefusedRequest | null => {
    if (ui !== 'v2') {
        return null;
    }

    if (status !== 403 && status !== 419) {
        return null;
    }

    return { status, message: REFUSED_MESSAGES[status] };
};
