import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * HR-22f "Free times" tab on /hr/open-shifts: a linked locum offers a free time and may withdraw it while it waits; a
 * roster planner uses it (a draft roster shift inside the offered time) or declines it. Every request carries the lock
 * version and an idempotency key; the server decides everything.
 */
export type Option = { value: string; label: string };
export type FreeTime = {
    id: string;
    branchId: number;
    branch: string;
    locum: string | null;
    when: string;
    startTime: string;
    endTime: string;
    note: string | null;
    status: 'waiting' | 'expired' | 'used' | 'declined' | 'withdrawn';
    statusLabel: string;
    lockVersion: number;
    takesStation?: boolean;
};
export type FreeTimesProps = {
    canPropose: boolean;
    canDecide: boolean;
    linked: boolean;
    mine: FreeTime[];
    waiting: FreeTime[];
    answered: FreeTime[];
    branchOptions: Option[];
    waitingCount: number;
    maxWaiting: number;
    maxDaysAhead: number;
    declineReasons: Option[];
    stations: Option[];
    rooms: Record<string, Option[]>;
};

export function useFreeTimes(props: {
    freeTimes: FreeTimesProps;
    today: string;
}) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const busy = reactive<{ id: string | null }>({ id: null });
    const tone = (status: FreeTime['status']) =>
        status === 'used'
            ? 'success'
            : status === 'waiting'
              ? 'warning'
              : 'neutral';
    const post = (
        url: string,
        data: Record<string, unknown>,
        done?: () => void,
    ) =>
        router.post(url, data as never, {
            preserveScroll: true,
            onSuccess: () => done?.(),
            onFinish: () => (busy.id = null),
        });

    // ---- A locum offers a free time ----
    const offer = reactive({
        open: false,
        branch: '',
        date: '',
        start_time: '08:00',
        end_time: '14:00',
        note: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const atCap = computed(
        () => props.freeTimes.waitingCount >= props.freeTimes.maxWaiting,
    );
    const startOffer = () =>
        Object.assign(offer, {
            open: true,
            branch: props.freeTimes.branchOptions[0]?.value ?? '',
            date: props.today,
            start_time: '08:00',
            end_time: '14:00',
            note: '',
            key: crypto.randomUUID(),
        });
    const offerMissing = computed(() =>
        !offer.branch
            ? 'Choose the branch.'
            : !offer.date
              ? 'Choose the date.'
              : null,
    );
    const sendOffer = () => {
        offer.processing = true;
        router.post(
            '/hr/free-times',
            {
                branch_id: offer.branch,
                date: offer.date,
                start_time: offer.start_time,
                end_time: offer.end_time,
                note: offer.note === '' ? null : offer.note,
                idempotency_key: offer.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (offer.open = false),
                onFinish: () => (offer.processing = false),
            },
        );
    };

    const withdrawing = reactive<{ item: FreeTime | null }>({ item: null });
    const withdraw = () => {
        const item = withdrawing.item;

        if (!item) {
            return;
        }

        busy.id = item.id;
        post(
            `/hr/free-times/${item.id}/withdraw`,
            {
                expected_lock_version: item.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            () => (withdrawing.item = null),
        );
    };

    // ---- HR uses or declines ----
    const using = reactive({
        item: null as FreeTime | null,
        start_time: '',
        end_time: '',
        break_minutes: '0',
        session_type: 'morning',
        room: '',
        station: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const startUse = (item: FreeTime) =>
        Object.assign(using, {
            item,
            start_time: item.startTime,
            end_time: item.endTime,
            break_minutes: '0',
            session_type: 'morning',
            room: '',
            station: '',
            key: crypto.randomUUID(),
        });
    const useRooms = computed(() =>
        using.item
            ? (props.freeTimes.rooms[String(using.item.branchId)] ?? [])
            : [],
    );
    const sendUse = () => {
        const item = using.item;

        if (!item) {
            return;
        }

        using.processing = true;
        router.post(
            `/hr/free-times/${item.id}/use`,
            {
                start_time: using.start_time,
                end_time: using.end_time,
                break_minutes: using.break_minutes,
                session_type: using.session_type,
                branch_room_id: using.room === '' ? null : using.room,
                station: using.station === '' ? null : using.station,
                expected_lock_version: item.lockVersion,
                idempotency_key: using.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (using.item = null),
                onFinish: () => (using.processing = false),
            },
        );
    };

    const declining = reactive<{ item: FreeTime | null; reason: string }>({
        item: null,
        reason: '',
    });
    const decline = () => {
        const item = declining.item;

        if (!item) {
            return;
        }

        busy.id = item.id;
        post(
            `/hr/free-times/${item.id}/decline`,
            {
                reason: declining.reason,
                expected_lock_version: item.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            () => (declining.item = null),
        );
    };

    return {
        errors,
        busy,
        tone,
        offer,
        atCap,
        startOffer,
        offerMissing,
        sendOffer,
        withdrawing,
        withdraw,
        using,
        startUse,
        useRooms,
        sendUse,
        declining,
        decline,
    };
}
