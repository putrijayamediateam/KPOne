import { router } from '@inertiajs/vue3';
import type { ComputedRef, InjectionKey } from 'vue';
import { computed, reactive, watch } from 'vue';
import { clockPhotoState, useClockPhoto } from '@/composables/useClockPhoto';
import { requestJson } from '@/lib/json-client';

type ClockState = {
    state: 'in' | 'out' | 'done';
    label: string | null;
    canPress: boolean;
    refusal: string | null;
    needsAttendancePage: boolean;
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
    needsAttendancePage: false,
});

/**
 * The clock shortcut reads the same state as My attendance. HR-PHOTO: a press opens the shared camera dialog (the
 * dialog posts the press with its photo); a branch that checks location still goes to the Attendance page first.
 */
export function useClockShortcut() {
    const load = async () => {
        try {
            const data = await requestJson<ClockState>(
                `${ATTENDANCE}/clock-state`,
            );

            clock.state = data.state;
            clock.canPress = data.canPress;
            clock.needsAttendancePage = data.needsAttendancePage;
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
        if (clock.needsAttendancePage) {
            router.visit(ATTENDANCE);

            return;
        }

        const opened = open(clock.state === 'in' ? 'out' : 'in', {
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
