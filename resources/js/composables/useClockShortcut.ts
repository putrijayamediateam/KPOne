import { router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useClockPhoto } from '@/composables/useClockPhoto';
import { requestJson } from '@/lib/json-client';

type ClockState = {
    state: 'in' | 'out' | 'done';
    label: string | null;
    canPress: boolean;
    refusal: string | null;
    needsAttendancePage: boolean;
};

const ATTENDANCE = '/hr/attendance';

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
    const press = () => {
        if (clock.needsAttendancePage) {
            router.visit(ATTENDANCE);

            return;
        }

        open(clock.state === 'in' ? 'out' : 'in', {
            onDone: () => {
                void load();
                router.reload();
            },
        });
    };

    const label = computed(() =>
        clock.state === 'in' ? 'Clock out' : 'Clock in',
    );
    const visible = computed(() => clock.loaded && clock.state !== 'done');

    return { clock, load, press, label, visible };
}
