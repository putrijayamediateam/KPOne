// CHAT-1: types and helpers for the "Ask KPOne" drawer. The server owns every figure and rule; this file only shapes them.
import { JsonRequestError, requestJson } from '@/lib/json-client';

export type AskFigure = {
    tool: string;
    group: string | null;
    label: string;
    text: string;
    asOf: string | null;
};

export type AskMessage = {
    id: number | string;
    role: 'user' | 'assistant';
    text: string | null;
    purged: boolean;
    figures: AskFigure[];
    report: { url: string; label: string } | null;
    createdAt?: string | null;
};

export type AskThreadSummary = { id: number; title: string; date: string };
export type AskOtherThread = { id: number; person: string; date: string };

export type AskChatState = {
    canViewOthers: boolean;
    limitReached: boolean;
    resetsOn: string;
    maxChars: number;
    examples: string[];
    threads: AskThreadSummary[];
};

export type AskThread = {
    thread: { id: number; title: string; date?: string };
    messages: AskMessage[];
};

export type AskAnswer = {
    thread: { id: number; title: string };
    message: AskMessage;
};

export type AskErrorKind =
    | 'personal_data'
    | 'invalid_input'
    | 'limit_reached'
    | 'rate_limited'
    | 'retry'
    | 'off';

export type AskError = { kind: AskErrorKind; message: string };

export const PERSONAL_DATA_MESSAGE =
    "Please don't type identity numbers, phone numbers or emails.";
export const RETRY_MESSAGE = "Couldn't answer. Nothing was changed. Try again.";
export const REMOVED_MESSAGE = 'Removed after 90 days.';
export const AI_LINE = 'AI answer. Check the figures on the report page.';

export const SHOW_MORE_LINES = 12;
// A line wraps at roughly 45 characters in the narrowest drawer, so this also catches one very long paragraph.
export const SHOW_MORE_CHARS = 540;

const csrf = (): string =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

export const postJson = <T>(url: string, body: unknown = {}): Promise<T> =>
    requestJson<T>(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });

export const getChat = (): Promise<AskChatState> =>
    requestJson<AskChatState>('/agents/chat');

export const getThread = (id: number): Promise<AskThread> =>
    requestJson<AskThread>(`/agents/chat/threads/${id}`);

export const getOthers = (): Promise<{ threads: AskOtherThread[] }> =>
    requestJson<{ threads: AskOtherThread[] }>('/agents/chat/others');

export const getOtherThread = (id: number): Promise<AskThread> =>
    requestJson<AskThread>(`/agents/chat/others/${id}`);

export const archiveThread = (id: number): Promise<{ ok: boolean }> =>
    postJson<{ ok: boolean }>(`/agents/chat/threads/${id}/archive`);

export const sendMessage = (
    text: string,
    threadId: number | null,
): Promise<AskAnswer> =>
    postJson<AskAnswer>('/agents/chat/messages', {
        text,
        thread_id: threadId,
    });

type ErrorPayload = { code?: string; message?: string; resetsOn?: string };

export const classifyError = (caught: unknown): AskError => {
    if (!(caught instanceof JsonRequestError)) {
        return { kind: 'retry', message: RETRY_MESSAGE };
    }

    const payload = (caught.payload ?? {}) as ErrorPayload;
    const code = payload.code ?? '';

    if (code === 'personal_data') {
        return { kind: 'personal_data', message: PERSONAL_DATA_MESSAGE };
    }

    if (code === 'limit_reached') {
        return {
            kind: 'limit_reached',
            message: `The monthly AI limit is reached. It resets on ${payload.resetsOn ?? 'the 1st'}.`,
        };
    }

    if (code === 'off' || caught.status === 404) {
        return { kind: 'off', message: 'Ask KPOne is switched off.' };
    }

    if (code === 'invalid_input' || caught.status === 422) {
        return {
            kind: 'invalid_input',
            message:
                payload.message ?? 'That question could not be sent. Check it.',
        };
    }

    if (code.startsWith('rate_limited') || caught.status === 429) {
        return {
            kind: 'rate_limited',
            message:
                payload.message ??
                'Too many questions just now. Wait a moment and try again.',
        };
    }

    return { kind: 'retry', message: RETRY_MESSAGE };
};

// Figures grouped by branch, keeping the server's order; figures with no group come first under no heading.
export const groupFigures = (
    figures: AskFigure[],
): { group: string | null; items: AskFigure[] }[] => {
    const groups: { group: string | null; items: AskFigure[] }[] = [];

    for (const figure of figures) {
        const found = groups.find((g) => g.group === figure.group);

        if (found) {
            found.items.push(figure);
        } else {
            groups.push({ group: figure.group, items: [figure] });
        }
    }

    return groups.sort(
        (a, b) => Number(a.group !== null) - Number(b.group !== null),
    );
};

export const isLongAnswer = (text: string): boolean =>
    text.split('\n').length > SHOW_MORE_LINES || text.length > SHOW_MORE_CHARS;
