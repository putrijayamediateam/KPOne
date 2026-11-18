import { router } from '@inertiajs/vue3';
import type { ComputedRef, InjectionKey } from 'vue';
import { computed, reactive, watch } from 'vue';
import type { ClockLocation } from '@/composables/useClockPhoto';
import { clockPhotoState, useClockPhoto } from '@/composables/useClockPhoto';
import { requestJson } from '@/lib/json-client';

type ClockState = {
    state: 'in' | 'out' | 'done';
    label: string | null;
    canPress: boolean;
    refusal: string | null;
    location: ClockLocation | null;
};

const ATTENDANCE = '/hr/attendance';

// UX-24 (Daniel, 2026-11-13): pages that already carry their own Clock in as the page's button, so the V2 top bar
// leaves its shortcut out there and a page never shows it twice.
const OWN_CLOCK = ['/spaces/me', '/hr/roster', ATTENDANCE];
/**
 * UX-24: the V2 shell tells the account menu whether its top-bar clock button is drawn right now (from 1024 px, not on
 * pages with their own Clock in, not in focus mode). The menu row hides only then, so exactly one of them shows.
 */
export const TOP_BAR_CLOCK: InjectionKey<ComputedRef<boolean>> =
    Symbol('v2-top-bar-clock');

export const pageHasOwnClock = (url: string): boolean => {
    const path = url.split('?')[0];

    return OWN_CLOCK.some((own) => path === own || path.startsWith(`${own}/`));
};

// One shared state for the top-bar button and the phone menu row (DS-12).
const clock = reactive({
    loaded: false,
    state: 'out' as ClockState['state'],
    canPress: false,
    location: null as ClockLocation | null,
});

/**
 * The clock shortcut reads the same state as My attendance. HR-PHOTO: a press opens the shared camera dialog (the
 * dialog posts the press with its photo). One Clock in (owner, 18 Nov 2026): a branch that checks location is handled
 * in that dialog too (consent once, then one location read), so a press never sends anyone to the Attendance page.
 */
export function useClockShortcut() {
    const load = async () => {
        try {
            const data = await requestJson<ClockState>(
                `${ATTENDANCE}/clock-state`,
            );

            clock.state = data.state;
            clock.canPress = data.canPress;
            clock.location = data.location ?? null;
            clock.loaded = true;
        } catch {
            clock.loaded = false;
        }
    };

    const { open } = useClockPhoto();
    /**
     * `returnFocusTo` (UX-24, Daniel): where focus goes once the photo dialog has closed (Not now, Done or the close
     * button). The account menu row passes its menu button, because the row itself is gone by then.
     */
    const press = (returnFocusTo?: HTMLElement | null) => {
        void pressFresh(returnFocusTo);
    };
    const pressFresh = async (returnFocusTo?: HTMLElement | null) => {
        // Read the state fresh at the press: it must know in or out and whether the branch checks location, and a
        // press or consent made elsewhere since the last read (the Attendance page, another tab) must count.
        // If that read fails, the last state read still serves, but only where the branch checks no location: an old
        // "already agreed" could skip a consent the Director has since asked for again (Suresh, m1).
        const known = {
            state: clock.state,
            canPress: clock.canPress,
            location: clock.location,
        };
        const hadState = clock.loaded;
        await load();

        if (!clock.loaded) {
            if (!hadState || known.location !== null) {
                return;
            }

            Object.assign(clock, known, { loaded: true });
        }

        // The fresh state says no press is possible now (done for today, or a refusal): the page shows why instead of
        // opening a camera the server would refuse (Suresh, m3).
        if (!clock.canPress || clock.state === 'done') {
            router.reload();

            return;
        }

        const opened = open(clock.state === 'in' ? 'out' : 'in', {
            location: clock.location,
            onDone: () => {
                void load();
                router.reload();
            },
        });

        if (opened && returnFocusTo) {
            const stop = watch(
                () => clockPhotoState.step,
                (step) => {
                    if (step !== 'idle') {
                        return;
                    }

                    stop();
                    // The rest of the page is inert while the dialog is still on screen, so wait until it has gone
                    // (at most about a second), then put focus back.
                    let frames = 0;
                    const refocus = () => {
                        if (
                            document.querySelector(
                                '[data-test="clock-photo-dialog"]',
                            ) &&
                            frames++ < 60
                        ) {
                            requestAnimationFrame(refocus);

                            return;
                        }

                        // A new flow has opened meanwhile: leave focus to that dialog. A control the page has
                        // removed (a page change while the dialog was open) is never focused; focus stays where the
                        // browser put it.
                        if (
                            clockPhotoState.step === 'idle' &&
                            returnFocusTo.isConnected
                        ) {
                            returnFocusTo.focus();
                        }
                    };

                    requestAnimationFrame(refocus);
                },
            );
        }
    };

    const label = computed(() =>
        clock.state === 'in' ? 'Clock out' : 'Clock in',
    );
    const visible = computed(() => clock.loaded && clock.state !== 'done');

    return { clock, load, press, label, visible };
}
