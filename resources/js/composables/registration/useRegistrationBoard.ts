import { onBeforeUnmount, onMounted, reactive, ref, computed } from 'vue';
import type { Ref } from 'vue';
import type { VisitRow } from '@/types';

/**
 * Version 2 Registration board state: the same search, applied-filter snapshot, tab and page rules, the same
 * 10-second live refresh (backoff, pause rules, failure notice) and the same request as the Classic board
 * (`POST /registration/search`). Classic keeps its own copy in `pages/Registration/Index.vue`; a parity test compares
 * the two so a rule change in one is caught in the other. No backend change, no new endpoint.
 */
export type BoardTab =
    | 'all'
    | 'waiting'
    | 'serving'
    | 'dispensary'
    | 'billing'
    | 'completed'
    | 'cancelled'
    | 'qr-intake';

export type BoardVisits = {
    data: VisitRow[];
    total: number;
    currentPage: number;
    lastPage: number;
    tabCounts?: Record<string, number>;
};

export const POLL_INTERVAL_MS = 10_000;
export const POLL_MAX_MS = 60_000;
export const POLL_FAILURE_LIMIT = 3;
export const SEARCH_URL = '/registration/search';
export const SEARCH_FAILED = 'Registration search could not be completed.';
export const PAUSED_NOTICE =
    'Live refresh paused. Showing last successful results.';
export const PAUSED_RETRY = ' Press Search to try again.';

const OPEN_OVERLAY =
    '[role="menu"][data-state="open"], [role="dialog"]:not([data-state="closed"]), [role="alertdialog"]:not([data-state="closed"]), [role="listbox"][data-state="open"], dialog[open]';
const TYPING_TARGET =
    'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"]';

export const SEARCH_INPUT_ID = 'registration-board-search';

export const todayIn = (timeZone: string): string =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());

export function useRegistrationBoard(input: {
    visits: BoardVisits;
    timezone: string;
    hasQrIntakes: boolean;
    initialTab: BoardTab;
    /** True while a dialog or a row action owned by the page must pause the live refresh. */
    pageBusy: () => boolean;
}) {
    const tabCounts: Ref<Record<string, number> | undefined> = ref(
        input.visits.tabCounts,
    );
    const rows = ref<VisitRow[]>(input.visits.data);
    const total = ref(input.visits.total);
    const currentPage = ref(input.visits.currentPage);
    const lastPage = ref(input.visits.lastPage);
    const loading = ref(false);
    const error = ref('');
    const liveRefreshNotice = ref('');
    const searchComposing = ref(false);
    const activeTab = ref<BoardTab>(input.initialTab);
    const today = todayIn(input.timezone);
    const form = reactive({
        patient_query: '',
        doctor_id: '',
        visit_type: '',
        priority: '',
        coverage_type: '',
        board_status: (input.initialTab === 'qr-intake'
            ? 'all'
            : input.initialTab) as BoardTab,
        date_from: today,
        date_to: today,
    });
    const csrf = () =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';

    let searchGeneration = 0;
    let appliedFilters = { ...form };
    let pollTimer: ReturnType<typeof setTimeout> | undefined;
    let requestController: AbortController | undefined;
    let pollingEnabled = false;
    let disposed = false;
    let pollFailures = 0;
    let pollingStopped = false;

    const isQrIntake = computed(() => activeTab.value === 'qr-intake');
    const advancedFilterCount = computed(
        () =>
            [form.priority, form.visit_type].filter(Boolean).length +
            (form.date_from !== today || form.date_to !== today ? 1 : 0),
    );

    const clearBoard = () => {
        tabCounts.value = undefined;
        rows.value = [];
        total.value = 0;
        currentPage.value = 1;
        lastPage.value = 1;
    };
    const clearPollTimer = () => {
        if (pollTimer !== undefined) {
            clearTimeout(pollTimer);
            pollTimer = undefined;
        }
    };
    const pollProtected = () =>
        input.pageBusy() ||
        searchComposing.value ||
        !!document.querySelector(OPEN_OVERLAY) ||
        (!!document.activeElement?.matches(TYPING_TARGET) &&
            (document.activeElement.id !== SEARCH_INPUT_ID ||
                form.patient_query !== appliedFilters.patient_query));
    const schedulePoll = () => {
        clearPollTimer();

        if (
            !pollingEnabled ||
            disposed ||
            document.hidden ||
            pollingStopped ||
            isQrIntake.value
        ) {
            return;
        }

        pollTimer = setTimeout(
            () => {
                pollTimer = undefined;
                void search(currentPage.value, true);
            },
            pollFailures
                ? Math.min(POLL_MAX_MS, POLL_INTERVAL_MS * 2 ** pollFailures)
                : POLL_INTERVAL_MS,
        );
    };
    const recordSearchFailure = (automatic: boolean, message: string) => {
        pollingStopped = ++pollFailures >= POLL_FAILURE_LIMIT;

        if (automatic) {
            liveRefreshNotice.value =
                PAUSED_NOTICE + (pollingStopped ? PAUSED_RETRY : '');
        } else {
            error.value = message;
            clearBoard();
        }
    };
    const search = async (page = 1, automatic = false) => {
        if (disposed) {
            return;
        }

        if (
            automatic &&
            (!pollingEnabled ||
                document.hidden ||
                pollingStopped ||
                isQrIntake.value ||
                requestController ||
                pollProtected())
        ) {
            schedulePoll();

            return;
        }

        const generation = ++searchGeneration;
        clearPollTimer();
        requestController?.abort();
        requestController = undefined;

        if (!automatic) {
            appliedFilters = { ...form };
            pollFailures = 0;
            pollingStopped = false;
            error.value = '';
            liveRefreshNotice.value = '';
        }

        const request = new AbortController();
        requestController = request;
        loading.value = !automatic;

        try {
            const response = await fetch(SEARCH_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                signal: request.signal,
                body: JSON.stringify({ ...appliedFilters, page }),
            });
            const payload = await response.json();

            if (generation !== searchGeneration) {
                return;
            }

            if (disposed || (automatic && pollProtected())) {
                return;
            }

            if (!response.ok) {
                const validationErrors = payload.errors as
                    Record<string, string[]> | undefined;
                recordSearchFailure(
                    automatic,
                    payload.message ??
                        (validationErrors
                            ? Object.values(validationErrors)[0]?.[0]
                            : undefined) ??
                        SEARCH_FAILED,
                );
                console.error(
                    `Registration search failed with status ${response.status}`,
                );

                return;
            }

            tabCounts.value = payload.tabCounts;
            rows.value = payload.data;
            total.value = payload.total;
            currentPage.value = payload.currentPage;
            lastPage.value = payload.lastPage;
            error.value = '';
            liveRefreshNotice.value = '';
            pollFailures = 0;
        } catch (thrown) {
            if (generation === searchGeneration) {
                if (!disposed && (!automatic || !pollProtected())) {
                    recordSearchFailure(automatic, SEARCH_FAILED);
                    console.error('Registration search request failed', thrown);
                }
            }
        } finally {
            if (generation === searchGeneration) {
                if (!disposed) {
                    if (requestController === request) {
                        requestController = undefined;
                    }

                    loading.value = false;
                    schedulePoll();
                }
            }
        }
    };
    const selectTab = (tab: BoardTab) => {
        activeTab.value = tab;

        if (tab === 'qr-intake') {
            ++searchGeneration;
            clearPollTimer();
            requestController?.abort();
            requestController = undefined;
            loading.value = false;
            window.history.replaceState({}, '', '/registration?tab=qr-intake');

            return;
        }

        if (window.location.search !== '') {
            window.history.replaceState({}, '', '/registration');
        }

        form.board_status = tab;
        void search(1);
    };
    const resetFilters = () => {
        Object.assign(form, {
            patient_query: '',
            doctor_id: '',
            visit_type: '',
            priority: '',
            coverage_type: '',
            board_status: activeTab.value,
            date_from: today,
            date_to: today,
        });
        void search(1);
    };
    const refreshAppliedBoard = (afterTick: (run: () => void) => void) => {
        pollFailures = 0;
        pollingStopped = false;
        // Wait for dialogs and busy controls to close; the automatic search keeps the applied filters and page and
        // checks typing, menus and stale responses.
        afterTick(() => void search(currentPage.value, true));
    };
    const handleVisibility = () => {
        if (!pollingEnabled || disposed) {
            return;
        }

        if (document.hidden) {
            ++searchGeneration;
            clearPollTimer();
            requestController?.abort();
            requestController = undefined;
            loading.value = false;
        } else if (!pollingStopped) {
            void search(currentPage.value, true);
        }
    };

    onMounted(() => {
        // Browser polling requires a document lifecycle; SSR/non-DOM rendering has none.
        if (
            typeof document === 'undefined' ||
            typeof document.addEventListener !== 'function' ||
            typeof document.removeEventListener !== 'function'
        ) {
            return;
        }

        pollingEnabled = true;
        document.addEventListener('visibilitychange', handleVisibility);
        schedulePoll();
    });
    onBeforeUnmount(() => {
        disposed = true;
        pollingEnabled = false;
        ++searchGeneration;
        clearPollTimer();
        requestController?.abort();
        requestController = undefined;

        if (typeof document !== 'undefined') {
            document.removeEventListener?.(
                'visibilitychange',
                handleVisibility,
            );
        }
    });

    return {
        tabCounts,
        rows,
        total,
        currentPage,
        lastPage,
        loading,
        error,
        liveRefreshNotice,
        searchComposing,
        activeTab,
        today,
        form,
        isQrIntake,
        advancedFilterCount,
        search,
        selectTab,
        resetFilters,
        refreshAppliedBoard,
    };
}
