import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import { registerPopStateGuard } from '@/lib/v2-leave-popstate';
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
 * (4) browser Back and Forward are held too (see `lib/v2-leave-popstate.ts`: the question is asked, the page stays as it is);
 *     reload and tab close get the browser's own warning (`beforeunload`).
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
    const stopPop = registerPopStateGuard({
        dirty: () => dirty() && !bypass,
        held: onHeld,
    });

    onBeforeUnmount(() => {
        stop();
        window.removeEventListener('beforeunload', warnOnUnload);
        stopPop();
    });

    const leave = (target: string) => {
        bypass = true;
        router.visit(target, { onFinish: () => (bypass = false) });
    };

    return { leave };
}
