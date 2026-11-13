import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import { holdTarget } from '@/lib/v2-leave-rule';

/**
 * The shared unsaved-work mechanism (one, used by V2LeaveGuard for a page and by V2Dialog for an open sheet).
 *
 * While `dirty()` is true: an in-app GET visit to another path is held (cancelled) and `onHeld(target)` is called so the
 * caller can ask "Discard your changes?"; reload and closing the tab get the browser's own warning (`beforeunload`).
 * `leave(target)` goes on to the held page after "Discard changes".
 *
 * (1) A same-path visit and a partial reload or a polling visit (such as the Registration and Queue live refresh) is never held;
 * (2) a successful save never asks;
 * (3) the listener is removed when the page or sheet unmounts, so nothing keeps asking on the next page;
 * (4) browser Back stays a known limit, with `beforeunload` for reload and tab close.
 */
export function useLeaveGuard(
    dirty: () => boolean,
    onHeld: (target: string) => void,
) {
    let bypass = false;

    const stop = router.on('before', (event) => {
        const target = holdTarget(
            event.detail.visit,
            {
                pathname: window.location.pathname,
                origin: window.location.origin,
            },
            { dirty: dirty(), bypass },
        );

        if (target === null) {
            return;
        }

        onHeld(target);

        return false;
    });

    const warnOnUnload = (event: BeforeUnloadEvent) => {
        if (dirty() && !bypass) {
            event.preventDefault();
            event.returnValue = '';
        }
    };

    window.addEventListener('beforeunload', warnOnUnload);

    onBeforeUnmount(() => {
        stop();
        window.removeEventListener('beforeunload', warnOnUnload);
    });

    const leave = (target: string) => {
        bypass = true;
        router.visit(target, { onFinish: () => (bypass = false) });
    };

    return { leave };
}
