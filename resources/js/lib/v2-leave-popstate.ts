/**
 * Browser Back and Forward while a V2 page or sheet holds unsaved work.
 *
 * Inertia handles `popstate` itself (it swaps the page, and the form is lost with no question), and it registers its own
 * listener first. So the one listener lives HERE, in a module `app.ts` imports before it starts Inertia, and runs before
 * Inertia's. While a guard says it is dirty it takes the event (Inertia never sees it), puts the page we are on back as
 * the newest history entry and asks; "Keep editing" leaves everything as it was, "Discard changes" visits the page Back
 * was heading to.
 *
 * When a sheet and its page are both dirty, the newest guard (the sheet) takes the event, so Back asks once, never twice.
 *
 * Guards register through `registerPopStateGuard` (useLeaveGuard does it; nothing else should).
 */
type Guard = { dirty: () => boolean; held: (target: string) => void };

const guards = new Set<Guard>();
let shown = { state: null as unknown, url: '' };

const remember = () => {
    shown = { state: window.history.state, url: window.location.href };
};

if (typeof window !== 'undefined') {
    remember();
    // Inertia writes the new page into history just after it navigates, so read it a moment later.
    document.addEventListener('inertia:navigate', () =>
        setTimeout(remember, 0),
    );
    window.addEventListener('popstate', (event) => {
        const guard = [...guards].reverse().find((g) => g.dirty());

        if (!guard || window.location.href === shown.url) {
            return;
        }

        event.stopImmediatePropagation();

        const target = window.location.href;

        window.history.pushState(shown.state, '', shown.url);
        guard.held(target);
    });
}

export const registerPopStateGuard = (guard: Guard) => {
    guards.add(guard);

    return () => guards.delete(guard);
};
