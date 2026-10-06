export type DisplayCall = {
    id: number;
    number: string;
    room: string | null;
    service?: CallService;
    calledAt: string;
    isRecall: boolean;
};

export type CallService = 'consultation' | 'dispensary' | 'treatment';

export type DisplaySettings = {
    tickerText: string | null;
    youtubeVideoId: string | null;
    posterSeconds: number;
    posters: Array<{ id: string; url: string }>;
    lockVersion: number;
};

export type DisplayFeed = {
    branch: { id: number; name: string; timezone: string };
    serverTime: string;
    calls: DisplayCall[];
    settings: DisplaySettings;
};

export const POLL_MILLISECONDS = 5_000;
export const HIGHLIGHT_MILLISECONDS = 12_000;

/** Shown when a call has no room: the doctor did not choose one for today. */
export const ROOM_FALLBACK = 'Sila ke kaunter';

/** What the TV shows and says when a call has no room, per service. */
const SERVICE_FALLBACK: Record<
    CallService,
    { display: string; ms: string; en: string }
> = {
    consultation: { display: ROOM_FALLBACK, ms: 'Kaunter', en: 'Counter' },
    dispensary: { display: 'Farmasi', ms: 'Farmasi', en: 'Pharmacy' },
    treatment: {
        display: 'Bilik rawatan',
        ms: 'Bilik rawatan',
        en: 'Treatment room',
    },
};

export const roomLabel = (
    room: string | null,
    service: CallService = 'consultation',
): string =>
    room && room.trim() !== '' ? room : SERVICE_FALLBACK[service].display;

/**
 * Calls the screen has not announced yet, oldest first. The first feed after the page opens only
 * marks what is already there as seen, so a reload never replays old calls.
 */
export const unannouncedCalls = (
    calls: DisplayCall[],
    seen: ReadonlySet<number>,
): DisplayCall[] =>
    calls.filter((call) => !seen.has(call.id)).sort((a, b) => a.id - b.id);

const YOUTUBE_ID = /^[A-Za-z0-9_-]{11}$/;

export const youtubeEmbedUrl = (videoId: string | null): string | null => {
    if (!videoId || !YOUTUBE_ID.test(videoId)) {
        return null;
    }

    const params = new URLSearchParams({
        autoplay: '1',
        mute: '1',
        loop: '1',
        playlist: videoId,
        controls: '0',
        rel: '0',
        playsinline: '1',
    });

    return `https://www.youtube-nocookie.com/embed/${videoId}?${params.toString()}`;
};

export const nextPosterIndex = (current: number, count: number): number =>
    count <= 0 ? 0 : (current + 1) % count;

export const formatDisplayDate = (now: Date, timeZone: string): string =>
    new Intl.DateTimeFormat('ms-MY', {
        timeZone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(now);

export const formatDisplayTime = (now: Date, timeZone: string): string =>
    new Intl.DateTimeFormat('ms-MY', {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(now);

export const formatCallTime = (iso: string, timeZone: string): string =>
    new Intl.DateTimeFormat('ms-MY', {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date(iso));

export type AnnouncementLanguage = 'ms' | 'en';

type VoiceLike = { lang: string; name: string };

/**
 * A Malay voice when the TV has one, otherwise English, otherwise the browser default (English text).
 */
export const pickAnnouncementVoice = <T extends VoiceLike>(
    voices: readonly T[],
): { voice: T | null; language: AnnouncementLanguage } => {
    const byLanguage = (prefix: string) =>
        voices.find((voice) => voice.lang.toLowerCase().startsWith(prefix)) ??
        null;
    const malay = byLanguage('ms');

    if (malay) {
        return { voice: malay, language: 'ms' };
    }

    return {
        voice: byLanguage('en-gb') ?? byLanguage('en') ?? null,
        language: 'en',
    };
};

const DIGIT_WORDS: Record<AnnouncementLanguage, readonly string[]> = {
    en: [
        'zero',
        'one',
        'two',
        'three',
        'four',
        'five',
        'six',
        'seven',
        'eight',
        'nine',
    ],
    ms: [
        'kosong',
        'satu',
        'dua',
        'tiga',
        'empat',
        'lima',
        'enam',
        'tujuh',
        'lapan',
        'sembilan',
    ],
};

/** "P082" is read one character at a time: "P, zero, eight, two". */
export const spokenQueueNumber = (
    number: string,
    language: AnnouncementLanguage,
): string =>
    [...number.replace(/[^A-Za-z0-9]/g, '')]
        .map((character) =>
            /\d/.test(character)
                ? DIGIT_WORDS[language][Number(character)]
                : character.toUpperCase(),
        )
        .join(', ');

/** The number one character at a time, then the room exactly as named in settings. */
export const announcementText = (
    call: Pick<DisplayCall, 'number' | 'room' | 'service'>,
    language: AnnouncementLanguage,
): string => {
    const room =
        call.room && call.room.trim() !== ''
            ? call.room.trim()
            : SERVICE_FALLBACK[call.service ?? 'consultation'][language];

    return `${spokenQueueNumber(call.number, language)}. ${room}.`;
};
