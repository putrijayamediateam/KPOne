import { router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
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

/** The clock shortcut reads the same state as My attendance, and opens the Attendance page. It presses nothing. */
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

    /**
     * The shortcut never presses. Clock in and Clock out need HR's photo step, so for now it opens the Attendance page,
     * where the press and its checks live; it will open the photo clock dialog when that exists.
     */
    const press = () => {
        router.visit(ATTENDANCE);
    };

    const label = computed(() =>
        clock.state === 'in' ? 'Clock out' : 'Clock in',
    );
    const visible = computed(() => clock.loaded && clock.state !== 'done');

    return { clock, load, press, label, visible };
}
