import { router } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { useCallReadinessAlerts } from '@/composables/useCallReadinessAlerts';
import { useTreatmentDoneAlerts } from '@/composables/useTreatmentDoneAlerts';
import { completedShownLine, servingShownLine } from '@/lib/completed-tab';
import { formatDate } from '@/lib/presentation';
import {
    applyQueueFilters,
    clearQueueFilters,
    createQueuePollingLifecycle,
    emptyQueueFilters,
    mergeQueuePollSnapshot,
    queuePollPayload,
    queueVisibleError,
} from '@/lib/queue-polling';
import {
    queuePresentationLabel,
    servingDurationLabel,
    waitingDurationLabel,
} from '@/lib/r1c2-presentation';
import { treatmentMinutes } from '@/lib/treatment-progress';
import type {
    PatientBoardRow,
    QueuePollSnapshot,
    QueueRow,
    QueueSnapshot,
} from '@/types';

/**
 * Version 2 Consultation queue logic. A copy of the script of the Classic `pages/Queue/Index.vue` (which stays
 * byte-identical): the same polling, backoff, filters, tabs, call, recall, treatment call, open consultation and
 * cancel handlers, with the Classic tab-strip scroll and fade left out (V2Tabs handles its own strip). A parity test
 * (`ui-v2-queue-parity.test.mjs`) fails if the two drift apart.
 */
export type QueueTab = 'all' | 'waiting' | 'serving' | 'removed';
export type QueueBoardProps = {
    snapshot: QueueSnapshot;
    treatmentRooms?: Array<{ id: number; name: string }>;
    roomChoice?: {
        rooms: Array<{ id: number; name: string }>;
        currentRoomId: number | null;
        operationalDate: string;
    } | null;
};

export function useQueueBoardV2(props: QueueBoardProps) {
    const roomSaving = ref(false);
    const roomError = ref('');
    const roomOptions = computed(() => [
        { value: '', label: 'Not chosen' },
        ...(props.roomChoice?.rooms ?? []).map((room) => ({
            value: room.id,
            label: room.name,
        })),
    ]);
    const chooseRoom = (value: string | number) => {
        if (value === (props.roomChoice?.currentRoomId ?? '')) {
            return;
        }

        roomSaving.value = true;
        roomError.value = '';
        router.put(
            '/queue/room',
            {
                branch_room_id: value === '' ? null : Number(value),
                expected_branch_id: live.value.branch.id,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    roomError.value =
                        errors.branch_room_id ??
                        errors.expected_branch_id ??
                        'The room could not be saved. Reload and try again.';
                },
                onFinish: () => (roomSaving.value = false),
            },
        );
    };
    type QueueTab = 'all' | 'waiting' | 'serving' | 'removed';
    const tabs = computed<
        Array<{ value: QueueTab; label: string; count?: number }>
    >(() => [
        { value: 'all', label: 'All active', count: live.value.tabCounts?.all },
        {
            value: 'waiting',
            label: 'Waiting',
            count: live.value.tabCounts?.waiting,
        },
        {
            value: 'serving',
            label: 'Serving Now',
            count: live.value.tabCounts?.serving,
        },
        {
            value: 'removed',
            label: 'Consultation Completed',
            count: live.value.tabCounts?.removed,
        },
    ]);
    const live = ref(props.snapshot);
    const readyAlerts = useCallReadinessAlerts(true);
    // Q-2: the attending doctor (own queue) is told when a treatment step is done.
    const treatmentAlerts = useTreatmentDoneAlerts(true);
    const activeTab = ref<QueueTab>('all');
    // Q-5: the Completed tab lists the latest 25 entries; its count is every completed entry at the branch.
    const completedLine = computed(() =>
        completedShownLine(
            activeTab.value,
            live.value.removed.length,
            live.value.tabCounts?.removed,
        ),
    );
    // Q-5b: the Serving Now tab lists the latest 25 serving entries; its count is every serving entry.
    const servingLine = computed(() =>
        servingShownLine(
            activeTab.value,
            live.value.serving.length,
            live.value.tabCounts?.serving,
        ),
    );
    const isRefreshing = ref(false);
    const isApplyingFilters = ref(false);
    const draftValidationError = ref('');
    const operationalError = ref('');
    const error = computed(() =>
        queueVisibleError(draftValidationError.value, operationalError.value),
    );
    const busyKey = ref<string | null>(null);
    const cancellationRow = ref<PatientBoardRow | null>(null);
    const cancelOpen = ref(false);
    const callRow = ref<PatientBoardRow | null>(null);
    const callOpen = ref(false);
    const draftFilters = reactive(emptyQueueFilters());
    const appliedFilters = reactive(emptyQueueFilters());
    const csrf = () =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';
    let pollTimer: ReturnType<typeof setTimeout> | undefined;
    let clockTimer: ReturnType<typeof setInterval> | undefined;
    let controller: AbortController | undefined;
    let activeRefresh: Promise<void> | undefined;
    let failures = 0;
    const pollingLifecycle = createQueuePollingLifecycle();
    const receivedAt = ref(Date.now());
    const clockNow = ref(Date.now());

    watch(
        () => props.snapshot,
        (snapshot) => {
            if (live.value.branch.id !== snapshot.branch.id) {
                readyAlerts.reset();
                treatmentAlerts.reset();
            }

            live.value = snapshot;
            receivedAt.value = Date.now();
        },
    );
    const effectiveNowMs = () => {
        void clockNow.value;

        return (
            Date.parse(live.value.serverNow) + (Date.now() - receivedAt.value)
        );
    };
    const elapsedMinutes = (row: QueueRow) => {
        const effectiveNow = effectiveNowMs();
        const start =
            row.status === 'serving' && row.calledAt
                ? row.calledAt
                : row.queuedAt;
        const minutes = Math.max(
            0,
            Math.floor((effectiveNow - Date.parse(start)) / 60_000),
        );

        return minutes;
    };
    const doctorOptions = computed(() => [
        { value: '', label: 'All doctors' },
        ...live.value.doctors.map((doctor) => ({
            value: doctor.id,
            label: doctor.name,
        })),
    ]);
    const urgencyOptions = [
        { value: '', label: 'All urgency' },
        { value: 'urgent', label: 'Urgent' },
        { value: 'normal', label: 'Normal' },
    ];
    const sourceRows = computed<QueueRow[]>(() => {
        if (activeTab.value === 'removed') {
            return live.value.removed;
        }

        if (activeTab.value === 'serving') {
            return live.value.serving;
        }

        if (activeTab.value === 'waiting') {
            return [...live.value.carryOver.data, ...live.value.waiting.data];
        }

        return [
            ...live.value.carryOver.data,
            ...live.value.waiting.data,
            ...live.value.serving,
        ];
    });
    const compactTime = (value: string) => {
        const [hour, minute] = value.split(':').map(Number);

        if (hour === undefined || minute === undefined) {
            return value;
        }

        return new Intl.DateTimeFormat('en-MY', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZone: 'UTC',
        })
            .format(new Date(Date.UTC(2000, 0, 1, hour, minute)))
            .replace(/\b(am|pm)\b/gi, (period) => period.toUpperCase());
    };
    const boardRows = computed<PatientBoardRow[]>(() =>
        sourceRows.value.map((row) => ({
            key: `${row.operationalDate}-${row.queueNumber}`,
            canWhatsapp: row.canWhatsapp,
            callHold: row.callHold,
            treatmentProgress: row.treatmentProgress ?? null,
            treatmentMinutes: treatmentMinutes(
                row.treatmentProgress,
                effectiveNowMs(),
            ),
            patientName: row.patientName,
            patientNumber: row.patientNumber,
            visitNumber: row.visitNumber,
            queueNumber: row.queueNumber,
            arrivedDate: formatDate(row.operationalDate),
            arrivedTime: compactTime(row.queuedTime),
            visitNotes: row.visitReasonExcerpt,
            doctorName: row.doctorName,
            coverageLabel: row.coverageLabel,
            panelNote: row.panelNote,
            durationLabel: row.isHeld
                ? `Held ${row.heldMinutes} min`
                : row.status === 'serving'
                  ? servingDurationLabel(elapsedMinutes(row))
                  : row.status === 'waiting'
                    ? waitingDurationLabel(elapsedMinutes(row))
                    : '—',
            waitingMinutes:
                !row.isHeld && row.status === 'waiting'
                    ? elapsedMinutes(row)
                    : null,
            priority: row.priority,
            returnedFromDispensary: row.returnedFromDispensary,
            statusLabel: row.isHeld
                ? row.isHeldTooLong
                    ? 'On Hold · 30+ min'
                    : 'On Hold'
                : row.status === 'waiting' &&
                    row.operationalDate !== live.value.operationalDate
                  ? 'Waiting · carry-over'
                  : queuePresentationLabel(row),
            statusTone: row.isHeld
                ? 'held'
                : row.status === 'serving'
                  ? 'serving'
                  : row.status === 'waiting'
                    ? 'waiting'
                    : 'removed',
            can: {
                viewPatient: row.can.viewPatient,
                update: row.can.update,
                cancel: row.can.cancel,
                sendToWaiting: false,
                call: row.canCall && row.status === 'waiting',
                recall: row.canCall && row.status === 'serving' && !row.isHeld,
                callTreatment:
                    row.canCall &&
                    row.status === 'serving' &&
                    !row.isHeld &&
                    (props.treatmentRooms?.length ?? 0) > 0,
                openConsultation: row.canOpenEncounter,
            },
            source: row,
        })),
    );

    const schedule = () => {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = undefined;
        }

        if (!pollingLifecycle.canRun() || document.hidden) {
            return;
        }

        pollTimer = setTimeout(
            () => {
                pollTimer = undefined;

                if (!pollingLifecycle.canRun()) {
                    return;
                }

                refresh(
                    live.value.waiting.currentPage,
                    live.value.carryOver.currentPage,
                );
            },
            failures
                ? Math.min(30_000, 3_000 * 2 ** Math.min(failures, 3))
                : 3_000,
        );
    };
    const refresh = (page = 1, carryPage = 1, reschedule = true) => {
        if (!pollingLifecycle.canRun()) {
            return Promise.resolve();
        }

        if (activeRefresh || document.hidden) {
            if (reschedule) {
                schedule();
            }

            return activeRefresh ?? Promise.resolve();
        }

        const request = new AbortController();
        const requestGeneration = pollingLifecycle.currentGeneration();
        const operation = (async () => {
            isRefreshing.value = true;
            operationalError.value = '';
            controller = request;

            try {
                const response = await fetch('/queue/search', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                    },
                    signal: request.signal,
                    body: JSON.stringify(
                        queuePollPayload(appliedFilters, page, carryPage),
                    ),
                });
                const payload = await response.json();

                if (!pollingLifecycle.accepts(requestGeneration)) {
                    return;
                }

                if (!response.ok) {
                    const validationErrors = payload.errors as
                        Record<string, string[]> | undefined;
                    operationalError.value = validationErrors
                        ? Object.values(validationErrors)[0]?.[0]
                        : payload.message;

                    throw new Error('Queue snapshot rejected.');
                }

                live.value = mergeQueuePollSnapshot(
                    live.value,
                    payload as QueuePollSnapshot,
                );
                receivedAt.value = Date.now();
                failures = 0;
                await readyAlerts
                    .poll(live.value.branch.id, request.signal)
                    .catch(() => undefined);

                if (live.value.scope === 'own') {
                    await treatmentAlerts
                        .poll(live.value.branch.id, request.signal)
                        .catch(() => undefined);
                }
            } catch (caught) {
                if (
                    pollingLifecycle.accepts(requestGeneration) &&
                    !(
                        caught instanceof DOMException &&
                        caught.name === 'AbortError'
                    )
                ) {
                    failures += 1;
                    operationalError.value ||=
                        'Live Queue could not be refreshed. Retrying…';
                }
            } finally {
                if (controller === request) {
                    controller = undefined;

                    if (pollingLifecycle.canRun()) {
                        isRefreshing.value = false;
                    }
                }

                if (reschedule && pollingLifecycle.accepts(requestGeneration)) {
                    schedule();
                }
            }
        })();
        activeRefresh = operation;
        void operation.finally(() => {
            if (activeRefresh === operation) {
                activeRefresh = undefined;
            }
        });

        return operation;
    };
    const replaceAppliedRefresh = async (prepare: () => void) => {
        const generation = pollingLifecycle.invalidate();
        const obsoleteRefresh = activeRefresh;
        controller?.abort();
        prepare();
        isApplyingFilters.value = true;

        try {
            if (obsoleteRefresh) {
                await obsoleteRefresh;
            }

            if (!pollingLifecycle.accepts(generation)) {
                return;
            }

            await refresh(1, 1, false);
        } finally {
            if (pollingLifecycle.accepts(generation)) {
                isApplyingFilters.value = false;
                schedule();
            }
        }
    };
    const applyFilters = () => {
        const candidate = { ...draftFilters };
        const validationError = applyQueueFilters(candidate, {
            ...appliedFilters,
        });

        if (validationError) {
            draftValidationError.value = validationError;

            return;
        }

        draftValidationError.value = '';

        return replaceAppliedRefresh(() => {
            applyQueueFilters(draftFilters, appliedFilters);
        });
    };
    const selectTab = (tab: QueueTab) => {
        activeTab.value = tab;
        const status = tab === 'all' ? '' : tab;
        void replaceAppliedRefresh(() => {
            draftFilters.status = status;
            appliedFilters.status = status;
        });
    };
    const clearFilters = () => {
        void replaceAppliedRefresh(() => {
            draftValidationError.value = '';
            clearQueueFilters(
                draftFilters,
                appliedFilters,
                activeTab.value === 'all' ? '' : activeTab.value,
            );
        });
    };
    const source = (row: PatientBoardRow) => row.source as QueueRow;
    const requestCall = (row: PatientBoardRow) => {
        if (busyKey.value !== null) {
            return;
        }

        operationalError.value = '';
        callRow.value = row;
        callOpen.value = true;
    };
    const recall = (row: PatientBoardRow) => {
        if (busyKey.value !== null) {
            return;
        }

        busyKey.value = row.key;
        operationalError.value = '';
        router.patch(
            `/visits/${encodeURIComponent(row.visitNumber)}/queue/recall`,
            {
                expected_branch_id: live.value.branch.id,
                queue_lock_version: source(row).queueLockVersion,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    operationalError.value =
                        Object.values(errors)[0] ??
                        'The patient could not be called again.';
                },
                onFinish: () => {
                    busyKey.value = null;
                },
            },
        );
    };
    // Calls a serving patient to a treatment room on the TV; the consultation stays with the doctor.
    const treatmentRow = ref<PatientBoardRow | null>(null);
    const treatmentRoomId = ref<string | number>('');
    const treatmentError = ref('');
    const openTreatmentCall = (row: PatientBoardRow) => {
        treatmentRow.value = row;
        treatmentError.value = '';
        treatmentRoomId.value =
            props.treatmentRooms?.length === 1
                ? props.treatmentRooms[0].id
                : '';
    };
    const submitTreatmentCall = () => {
        const row = treatmentRow.value;

        if (row === null || treatmentRoomId.value === '') {
            treatmentError.value = 'Choose a treatment room.';

            return;
        }

        busyKey.value = row.key;
        router.patch(
            `/visits/${encodeURIComponent(row.visitNumber)}/queue/treatment-call`,
            {
                expected_branch_id: live.value.branch.id,
                queue_lock_version: source(row).queueLockVersion,
                branch_room_id: treatmentRoomId.value,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (treatmentRow.value = null),
                onError: (errors) => {
                    treatmentError.value =
                        Object.values(errors)[0] ??
                        'The patient could not be called to the treatment room.';
                },
                onFinish: () => {
                    busyKey.value = null;
                },
            },
        );
    };
    // Q-4: re-poll with the applied filters (keeps the current pages) so Call next sees the latest next patient.
    const refreshBoard = () =>
        refresh(
            live.value.waiting.currentPage,
            live.value.carryOver.currentPage,
            false,
        );
    const finishCall = () => {
        void refresh(1, 1, false).finally(schedule);
    };
    const openConsultation = (row: PatientBoardRow) => {
        const entry = source(row);

        if (entry.status === 'removed') {
            // Existing authorized read-only entry; never restart a removed Queue.
            router.get(
                `/visits/${encodeURIComponent(row.visitNumber)}/encounter`,
            );

            return;
        }

        busyKey.value = row.key;
        router.post(
            `/visits/${encodeURIComponent(row.visitNumber)}/encounter`,
            {
                expected_branch_id: live.value.branch.id,
                visit_lock_version: entry.visitLockVersion,
                queue_lock_version: entry.queueLockVersion,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    operationalError.value =
                        Object.values(errors)[0] ??
                        'The consultation could not be opened.';
                },
                onFinish: () => {
                    busyKey.value = null;
                },
            },
        );
    };
    const requestCancellation = (row: PatientBoardRow) => {
        cancellationRow.value = row;
        cancelOpen.value = true;
    };
    const handleVisibility = () => {
        if (!pollingLifecycle.canRun()) {
            return;
        }

        if (document.hidden) {
            pollingLifecycle.invalidate();

            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = undefined;
            }

            controller?.abort();
        } else {
            failures = 0;
            void replaceAppliedRefresh(() => undefined);
        }
    };
    onMounted(() => {
        pollingLifecycle.mount();
        document.addEventListener('visibilitychange', handleVisibility);
        clockTimer = setInterval(() => (clockNow.value = Date.now()), 60_000);
        schedule();
    });
    onBeforeUnmount(() => {
        pollingLifecycle.dispose();
        document.removeEventListener('visibilitychange', handleVisibility);

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = undefined;
        }

        if (clockTimer) {
            clearInterval(clockTimer);
        }

        controller?.abort();
    });

    return {
        roomSaving,
        roomError,
        roomOptions,
        chooseRoom,
        tabs,
        live,
        readyAlerts,
        treatmentAlerts,
        activeTab,
        completedLine,
        servingLine,
        isRefreshing,
        isApplyingFilters,
        draftValidationError,
        operationalError,
        error,
        busyKey,
        cancellationRow,
        cancelOpen,
        callRow,
        callOpen,
        draftFilters,
        appliedFilters,
        csrf,
        pollingLifecycle,
        receivedAt,
        clockNow,
        effectiveNowMs,
        elapsedMinutes,
        doctorOptions,
        urgencyOptions,
        sourceRows,
        compactTime,
        boardRows,
        schedule,
        refresh,
        replaceAppliedRefresh,
        applyFilters,
        selectTab,
        clearFilters,
        source,
        requestCall,
        recall,
        treatmentRow,
        treatmentRoomId,
        treatmentError,
        openTreatmentCall,
        submitTreatmentCall,
        refreshBoard,
        finishCall,
        openConsultation,
        requestCancellation,
        handleVisibility,
    };
}
