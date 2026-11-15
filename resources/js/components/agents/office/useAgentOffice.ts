import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type {
    FeedAgent,
    Light,
    OfficeFeed,
} from '@/components/agents/office/office-layout';
import {
    LIGHT_LABELS,
    recentMessages,
} from '@/components/agents/office/office-layout';
import { requestJson } from '@/lib/json-client';

/**
 * AG-23: the office page's live behaviour, shared by the Classic page (`pages/Agents/Office.vue`) and the Version 2 page
 * (`pages/Agents/V2/Office.vue`): the 10-second feed poll, the selected agent and its detail, Pause motion, and the counts.
 * Moved from the page unchanged; what each page draws stays in the page.
 */
export type AgentDetail = {
    code: string;
    displayName: string;
    jobTitle: string;
    department: string | null;
    isEnabled: boolean;
    steps: { kind: string; summary: string; at: string | null }[];
    pendingTotal: number;
    pendingBranch: string | null;
    pending: {
        id: number;
        typeLabel: string;
        createdAt: string | null;
    }[];
};

export function useAgentOffice(props: {
    feed: OfficeFeed;
    canOpenInbox: boolean;
}) {
    const POLL_MS = 10_000;

    const feed = ref<OfficeFeed>(props.feed);
    const paused = ref(false);

    // "Pause motion" stops the scene's movement (never the live data). A person who has asked their device for reduced motion
    // already gets a still scene from the CSS, so the button is not offered to them; the choice is kept in this browser only.
    const MOTION_KEY = 'kpone.agents.office.motion';
    const osReducedMotion =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const readMotionOff = (): boolean => {
        try {
            return window.localStorage.getItem(MOTION_KEY) === 'off';
        } catch {
            return false;
        }
    };
    const motionOff = ref(readMotionOff());
    const toggleMotion = () => {
        motionOff.value = !motionOff.value;

        try {
            window.localStorage.setItem(
                MOTION_KEY,
                motionOff.value ? 'off' : 'on',
            );
        } catch {
            // The choice then lasts only for this visit.
        }
    };
    const clock = ref(new Date());
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let disposed = false;

    const schedule = () => {
        clearTimeout(timer);

        if (!disposed && !document.hidden) {
            timer = setTimeout(() => void refresh(), POLL_MS);
        }
    };
    const refresh = async () => {
        if (disposed) {
            return;
        }

        controller?.abort();
        controller = new AbortController();

        try {
            feed.value = await requestJson<OfficeFeed>('/agents/office-feed', {
                signal: controller.signal,
            });
            paused.value = false;
            clock.value = new Date();
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                paused.value = true;
            }
        } finally {
            schedule();
        }
    };
    const visibilityChanged = () => {
        if (document.hidden) {
            clearTimeout(timer);
            controller?.abort();
        } else {
            void refresh();
        }
    };

    onMounted(() => {
        document.addEventListener('visibilitychange', visibilityChanged);
        schedule();
    });
    onBeforeUnmount(() => {
        disposed = true;
        clearTimeout(timer);
        controller?.abort();
        detailController?.abort();
        document.removeEventListener('visibilitychange', visibilityChanged);
    });

    const selected = ref<string | null>(null);
    const detail = ref<AgentDetail | null>(null);
    const detailError = ref('');
    const selectedAgent = computed<FeedAgent | null>(
        () =>
            feed.value.agents.find((agent) => agent.code === selected.value) ??
            null,
    );
    // Only the newest detail request may update the panel; older ones are aborted.
    let detailController: AbortController | undefined;
    const loadDetail = async (code: string) => {
        detailController?.abort();
        const current = new AbortController();
        detailController = current;
        detailError.value = '';

        try {
            const result = await requestJson<AgentDetail>(
                `/agents/office/agents/${encodeURIComponent(code)}`,
                { signal: current.signal },
            );

            if (detailController === current && selected.value === code) {
                detail.value = result;
            }
        } catch (error) {
            if (
                (error as Error).name !== 'AbortError' &&
                detailController === current &&
                selected.value === code
            ) {
                detailError.value = 'Details are unavailable right now.';
            }
        }
    };
    const select = (code: string) => {
        selected.value = code;
        detail.value = null;
        void loadDetail(code);
    };
    const closePanel = () => {
        detailController?.abort();
        selected.value = null;
        detail.value = null;
    };
    // Keep the open panel current as the feed refreshes.
    watch(
        () => feed.value.serverTime,
        () => {
            if (selected.value) {
                void loadDetail(selected.value);
            }
        },
    );

    const openInbox = () => {
        if (props.canOpenInbox) {
            router.visit('/agents/inbox');
        }
    };

    const lightFor = (agent: FeedAgent): Light =>
        agent.isEnabled ? agent.light : 'grey';
    const statusFor = (agent: FeedAgent) =>
        agent.isEnabled ? LIGHT_LABELS[agent.light] : 'Off';
    const latestMessage = computed(() => recentMessages(feed.value));
    const counts = computed(() => {
        const on = feed.value.agents.filter((agent) => agent.isEnabled);

        return {
            working: on.filter((agent) => agent.light === 'green').length,
            approval: on.filter((agent) => agent.light === 'yellow').length,
            failed: on.filter((agent) => agent.light === 'red').length,
            off: feed.value.agents.length - on.length,
            waiting: feed.value.pendingProposals,
        };
    });
    const agentName = (code: string | null): string =>
        feed.value.agents.find((agent) => agent.code === code)?.displayName ??
        'An agent';
    const waitingAgents = computed(() =>
        feed.value.agents.filter((agent) => agent.pendingProposals > 0),
    );
    const activity = computed(() => feed.value.messages.slice(0, 6));

    return {
        feed,
        paused,
        osReducedMotion,
        motionOff,
        toggleMotion,
        clock,
        selected,
        detail,
        detailError,
        selectedAgent,
        select,
        closePanel,
        openInbox,
        lightFor,
        statusFor,
        latestMessage,
        counts,
        agentName,
        waitingAgents,
        activity,
    };
}
