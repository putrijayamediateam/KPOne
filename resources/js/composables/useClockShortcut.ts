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

// One shared state for the top-bar button, the phone menu row and the Clock out confirm (DS-12).
const clock = reactive({
    loaded: false,
    state: 'out' as ClockState['state'],
    canPress: false,
    needsAttendancePage: false,
    processing: false,
    confirmOut: false,
    key: '',
});

/** The clock shortcut reads the same state as My attendance and presses the same clock-in and clock-out routes. */
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

    const send = (kind: 'in' | 'out') => {
        clock.processing = true;
        clock.key = clock.key || crypto.randomUUID();
        router.post(
            `${ATTENDANCE}/clock-${kind}`,
            {
                idempotency_key: clock.key,
                // Back to the page the person was on: the server checks it is a real internal page.
                from: `${window.location.pathname}${window.location.search}`,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    clock.processing = false;
                    clock.confirmOut = false;
                    clock.key = '';
                    void load();
                },
            },
        );
    };

    /** Clock in presses at once; Clock out asks first (as on the Attendance page). */
    const press = () => {
        if (clock.processing) {
            return;
        }

        // A branch that checks location needs the consent and position steps, which only the Attendance page has.
        if (!clock.canPress || clock.needsAttendancePage) {
            router.visit(ATTENDANCE);

            return;
        }

        if (clock.state === 'in') {
            clock.confirmOut = true;

            return;
        }

        send('in');
    };

    const confirmOut = () => send('out');
    const label = computed(() =>
        clock.state === 'in' ? 'Clock out' : 'Clock in',
    );
    const visible = computed(() => clock.loaded && clock.state !== 'done');

    return { clock, load, press, confirmOut, label, visible };
}
