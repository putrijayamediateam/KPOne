import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import { requestJson } from '@/lib/json-client';

type Summary = {
    showNumber: boolean;
    total: number;
    areas: Array<{ area: string; kind: string; count: number }>;
};

// APPR-1a: one shared state for the account-button pill and the menu row. Raw count only; the server caches it 60 seconds
// and clears it when this person decides something, so a poll is cheap. Polls every 60 s while the tab is visible.
const state = reactive<{ loaded: boolean } & Summary>({
    loaded: false,
    showNumber: false,
    total: 0,
    areas: [],
});
let timer: number | null = null;
let users = 0;

const load = async () => {
    try {
        const data = await requestJson<Summary>('/approvals/count');
        state.showNumber = data.showNumber;
        state.total = data.total;
        state.areas = data.areas;
        state.loaded = true;
    } catch {
        // A failed read keeps the last number; the number is never shown as zero by mistake.
    }
};

export function useApprovalCount() {
    const page = usePage();
    const allowed = computed(() =>
        Boolean(
            page.props.workspace?.accountMenu?.links.some(
                (link) => link.href === '/approvals',
            ),
        ),
    );
    const onTick = () => {
        if (document.visibilityState === 'visible' && allowed.value) {
            void load();
        }
    };
    onMounted(() => {
        users += 1;

        if (allowed.value && !state.loaded) {
            void load();
        }

        if (timer === null) {
            timer = window.setInterval(onTick, 60000);
        }

        document.addEventListener('visibilitychange', onTick);
    });
    onBeforeUnmount(() => {
        users -= 1;
        document.removeEventListener('visibilitychange', onTick);

        if (users <= 0 && timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    });

    return {
        // Only people who can decide something see a number; nothing at zero.
        count: computed(() =>
            allowed.value && state.showNumber ? state.total : 0,
        ),
        label: computed(() =>
            state.total === 1
                ? '1 item waiting for you'
                : `${state.total} items waiting for you`,
        ),
        reload: load,
    };
}
