import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
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

// One shared state for the Clock in on Me home.
const clock = reactive({
    loaded: false,
    state: 'out' as ClockState['state'],
    canPress: false,
    location: null as ClockLocation | null,
});

/**
 * One Clock in (owner, 18 Nov 2026): the one Clock in button is on Me home (MeHome.vue); the top bar, the account menu and
 * the space pages have none. This reads the same state as My attendance. HR-PHOTO: a press opens the shared camera dialog (the
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
     * button), for the control that opened it.
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

    return { clock, load, press };
}
