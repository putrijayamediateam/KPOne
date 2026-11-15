import { ref } from 'vue';
import { refusalFor } from '@/lib/v2-refused-rule';
import type { RefusedRequest } from '@/lib/v2-refused-rule';

/**
 * DD-84 (Version 2 only): a refused request must not show Inertia's raw error page over the screen.
 *
 * Inertia fires a cancelable `httpException` event for an error response. On a V2 page, for 403 (the server refused the
 * action) and 419 (the page was open too long) this module cancels it, so the raw page never opens, and puts the plain
 * sentence in `refused` for `V2RefusedNotice` to show (above the page, or inside an open `V2Dialog`). Every other status
 * (409: Inertia's own reload; 5xx; 404) is left to Inertia. On a Classic page it does nothing: the first line returns
 * unless `<html data-ui="v2">`. The notice is cleared when the next request starts.
 *
 * The rule (which page, which codes) and the wording are in `lib/v2-refused-rule.ts`.
 */
export const refused = ref<RefusedRequest | null>(null);
/** How many V2Dialogs are open: while one is, it shows the notice itself and the page-level one stays hidden. */
export const openDialogs = ref(0);

export const handleHttpException = (event: Event): void => {
    const status = (event as CustomEvent<{ response?: { status?: number } }>)
        .detail?.response?.status;
    // The first thing it checks is the page: on Classic, and for any other status, it does nothing at all.
    const refusal = refusalFor(document.documentElement.dataset.ui, status);

    if (refusal === null) {
        return;
    }

    event.preventDefault();
    refused.value = refusal;
};

if (typeof document !== 'undefined') {
    document.addEventListener('inertia:httpException', handleHttpException);
    // The next request (or a page change) clears it.
    document.addEventListener('inertia:start', () => {
        refused.value = null;
    });
}
