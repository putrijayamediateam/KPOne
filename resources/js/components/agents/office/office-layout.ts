/**
 * AG-3 agent office: isometric geometry and where everyone sits. Pure functions, no DOM.
 * Grid units: gx runs to the lower right, gy to the lower left; z is height in screen pixels.
 */

export type Light = 'green' | 'yellow' | 'red' | 'grey';

export type FeedAgent = {
    code: string;
    displayName: string;
    jobTitle: string;
    department: string | null;
    kind: 'build' | 'runtime';
    avatarKey: string | null;
    isEnabled: boolean;
    light: Light;
    currentTask: string | null;
    lastStep: string | null;
    lastStepAt: string | null;
    pendingProposals: number;
};

export type FeedMessage = {
    from: string | null;
    to: string | null;
    summary: string;
    at: string;
};

export type OfficeFeed = {
    serverTime: string;
    pendingProposals: number;
    agents: FeedAgent[];
    messages: FeedMessage[];
};

export const TILE_W = 56;
export const TILE_H = 28;
export const GRID_W = 14;
export const GRID_H = 11;
export const WALL_H = 130;
export const ORIGIN_X = GRID_H * (TILE_W / 2) + 24;
export const ORIGIN_Y = WALL_H + 34;
export const VIEW_W = (GRID_W + GRID_H) * (TILE_W / 2) + 48;
export const VIEW_H = ORIGIN_Y + (GRID_W + GRID_H) * (TILE_H / 2) + 24;

export type Point = { x: number; y: number };

export const iso = (gx: number, gy: number, z = 0): Point => ({
    x: ORIGIN_X + (gx - gy) * (TILE_W / 2),
    y: ORIGIN_Y + (gx + gy) * (TILE_H / 2) - z,
});

export const points = (list: Point[]): string =>
    list.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');

/** The three visible faces of a box standing on the floor (or at height z). */
export const box = (
    gx: number,
    gy: number,
    w: number,
    d: number,
    h: number,
    z = 0,
): { top: string; left: string; right: string } => ({
    top: points([
        iso(gx, gy, z + h),
        iso(gx + w, gy, z + h),
        iso(gx + w, gy + d, z + h),
        iso(gx, gy + d, z + h),
    ]),
    left: points([
        iso(gx, gy + d, z + h),
        iso(gx + w, gy + d, z + h),
        iso(gx + w, gy + d, z),
        iso(gx, gy + d, z),
    ]),
    right: points([
        iso(gx + w, gy, z + h),
        iso(gx + w, gy + d, z + h),
        iso(gx + w, gy + d, z),
        iso(gx + w, gy, z),
    ]),
});

/** A flat quad on the floor. */
export const floorQuad = (
    gx: number,
    gy: number,
    w: number,
    d: number,
): string =>
    points([
        iso(gx, gy),
        iso(gx + w, gy),
        iso(gx + w, gy + d),
        iso(gx, gy + d),
    ]);

export type Zone = {
    key: string;
    label: string;
    gx: number;
    gy: number;
    w: number;
    d: number;
    seats: { gx: number; gy: number }[];
};

/** Desks per department; the meeting table takes anyone else (an unknown department or a full zone). */
export const ZONES: Zone[] = [
    {
        key: 'Leadership',
        label: 'Leadership',
        gx: 0.6,
        gy: 0.6,
        w: 6,
        d: 4.8,
        seats: [
            { gx: 1.4, gy: 1.5 },
            { gx: 4.2, gy: 1.5 },
        ],
    },
    {
        key: 'Clinic Operations',
        label: 'Clinic Operations',
        gx: 7.4,
        gy: 0.6,
        w: 6,
        d: 4.8,
        seats: [
            { gx: 8.2, gy: 1.9 },
            { gx: 11, gy: 1.9 },
            { gx: 9.6, gy: 3.9 },
        ],
    },
    {
        key: 'Technology',
        label: 'Technology',
        gx: 0.6,
        gy: 6.6,
        w: 6,
        d: 4,
        seats: [
            { gx: 1.7, gy: 7.7 },
            { gx: 4.4, gy: 7.7 },
            { gx: 3, gy: 9.5 },
        ],
    },
];

export const MEETING = { gx: 8.6, gy: 6.9, w: 3.4, d: 1.6 };

export const MEETING_SEATS = [
    { gx: 9.1, gy: 6.4 },
    { gx: 10.5, gy: 6.4 },
    { gx: 11.6, gy: 6.4 },
    { gx: 9.1, gy: 8.9 },
    { gx: 10.5, gy: 8.9 },
    { gx: 11.6, gy: 8.9 },
];

/** The Director's desk: not a person, only the approval inbox tray. */
export const DIRECTOR_DESK = { gx: 2.4, gy: 3.5, w: 2.6, d: 1 };

/** Where an agent stands while its suggestions wait in the inbox (in front of the Director's desk). */
export const INBOX_SPOTS = [
    { gx: 3.1, gy: 5.05 },
    { gx: 4.3, gy: 5.05 },
    { gx: 2, gy: 5.05 },
    { gx: 5.3, gy: 5.05 },
];

export type Placement = {
    code: string;
    seat: { gx: number; gy: number };
    atMeeting: boolean;
};

/** Stable seats: agents keep their place as the feed refreshes (ordered by department, then name). */
export const seatAgents = (agents: FeedAgent[]): Placement[] => {
    const used = new Map<string, number>();
    let meeting = 0;

    return agents.map((agent) => {
        const zone = ZONES.find((item) => item.key === agent.department);
        const index = zone ? (used.get(zone.key) ?? 0) : Infinity;

        if (zone && index < zone.seats.length) {
            used.set(zone.key, index + 1);

            return {
                code: agent.code,
                seat: zone.seats[index],
                atMeeting: false,
            };
        }

        const seat = MEETING_SEATS[meeting % MEETING_SEATS.length];
        meeting += 1;

        return { code: agent.code, seat, atMeeting: true };
    });
};

/** Agents with suggestions waiting walk to the Director's desk, in feed order, up to the spots available. */
export const standingAtInbox = (agents: FeedAgent[]): Map<string, Point> => {
    const result = new Map<string, Point>();
    agents
        .filter((agent) => agent.isEnabled && agent.pendingProposals > 0)
        .slice(0, INBOX_SPOTS.length)
        .forEach((agent, index) => {
            const spot = INBOX_SPOTS[index];
            result.set(agent.code, { x: spot.gx, y: spot.gy });
        });

    return result;
};

export type Avatar = {
    skin: string;
    hair: string;
    shirt: string;
    style: 'short' | 'hijab' | 'bun' | 'long' | 'curly';
    extra?: 'tie' | 'glasses' | 'headset';
};

/** Fictional looks for the wave 1 agents; any other agent gets a stable look from its code. */
export const AVATARS: Record<string, Avatar> = {
    director_assistant: {
        skin: '#c68b59',
        hair: '#2b2118',
        shirt: '#334155',
        style: 'short',
        extra: 'tie',
    },
    inventory_analyst: {
        skin: '#e0ac7e',
        hair: '#db2777',
        shirt: '#f9a8d4',
        style: 'hijab',
    },
    stocktake_officer: {
        skin: '#a5714b',
        hair: '#1c1917',
        shirt: '#0d9488',
        style: 'curly',
    },
    report_assistant: {
        skin: '#d9a273',
        hair: '#0f766e',
        shirt: '#e2e8f0',
        style: 'hijab',
        extra: 'glasses',
    },
    technical_admin_agent: {
        skin: '#b97f55',
        hair: '#111827',
        shirt: '#4f46e5',
        style: 'short',
        extra: 'headset',
    },
    qa_reviewer: {
        skin: '#edc19a',
        hair: '#57351f',
        shirt: '#f59e0b',
        style: 'bun',
        extra: 'glasses',
    },
};

const SKINS = ['#edc19a', '#e0ac7e', '#c68b59', '#a5714b', '#8d5a3b'];
const HAIRS = ['#1c1917', '#2b2118', '#57351f', '#7c2d12', '#334155'];
const SHIRTS = [
    '#0ea5e9',
    '#22c55e',
    '#f97316',
    '#a855f7',
    '#ef4444',
    '#14b8a6',
];
const STYLES: Avatar['style'][] = ['short', 'long', 'bun', 'curly', 'hijab'];

export const avatarFor = (code: string): Avatar => {
    if (AVATARS[code]) {
        return AVATARS[code];
    }

    let hash = 0;

    for (const char of code) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return {
        skin: SKINS[hash % SKINS.length],
        hair: HAIRS[(hash >>> 3) % HAIRS.length],
        shirt: SHIRTS[(hash >>> 6) % SHIRTS.length],
        style: STYLES[(hash >>> 9) % STYLES.length],
    };
};

export const LIGHT_LABELS: Record<Light, string> = {
    green: 'Working',
    yellow: 'Waiting for approval',
    red: 'Last run failed',
    grey: 'Idle',
};

export const LIGHT_COLOURS: Record<Light, string> = {
    green: '#22c55e',
    yellow: '#f59e0b',
    red: '#ef4444',
    grey: '#94a3b8',
};

/** The newest message an agent sent within the last two hours, for its speech bubble. */
export const recentMessages = (
    feed: OfficeFeed,
    maxAgeMs = 2 * 60 * 60 * 1000,
): Map<string, FeedMessage> => {
    const now = Date.parse(feed.serverTime);
    const result = new Map<string, FeedMessage>();

    for (const message of feed.messages) {
        if (message.from === null || result.has(message.from)) {
            continue;
        }

        if (now - Date.parse(message.at) <= maxAgeMs) {
            result.set(message.from, message);
        }
    }

    return result;
};

export const shorten = (text: string, max = 44): string =>
    text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;

/**
 * AG-13b name tags. A tag is drawn in real screen pixels (13 px name) by scaling it with 1 / scene scale, so it stays
 * readable when the scene is shrunk; these pure functions work out its size and keep tags apart.
 */
export const TAG_NAME_PX = 13;
export const TAG_GAP_PX = 10;
export const BUBBLE_GAP_PX = 12;
const TAG_MAX_NAME_CHARS = 16;

export const tagName = (name: string): string =>
    name.length > TAG_MAX_NAME_CHARS
        ? `${name.slice(0, TAG_MAX_NAME_CHARS - 1).trimEnd()}…`
        : name;

/** Tag size in screen pixels: name row, an optional role row (not on phones) and the AI agent chip on its own row. */
export const tagBox = (
    name: string,
    showRole: boolean,
): { w: number; h: number } => ({
    w: Math.max(96, Math.min(176, tagName(name).length * 7.9 + 36)),
    h: showRole ? 74 : 56,
});

/** Where the bottom edge of a tag sits above the figure's feet (scene units), clear of the head by TAG_GAP_PX. */
export const tagBottom = (seated: boolean, f: number): number =>
    (seated ? -49 : -60) - (TAG_GAP_PX + 2) * f;

export type TagRequest = {
    code: string;
    x: number;
    bottomY: number;
    w: number;
    h: number;
};

export type Rect = { x: number; y: number; w: number; h: number };

/**
 * Keeps tags inside the scene and at least TAG_GAP_PX (screen pixels) from each other and from the obstacles, by
 * moving a tag up when it would touch another one. Returns the shift of each tag in scene units.
 */
export const spreadTags = (
    tags: TagRequest[],
    obstacles: Rect[],
    f: number,
    width: number,
): Map<string, { dx: number; dy: number }> => {
    const gap = TAG_GAP_PX * f;
    const state = tags.map((tag) => {
        const w = tag.w * f;
        const half = w / 2;
        const x = Math.min(Math.max(tag.x, half + 2), width - half - 2);

        return {
            code: tag.code,
            x,
            dx: x - tag.x,
            y: tag.bottomY,
            w,
            h: tag.h * f,
        };
    });
    const rect = (item: (typeof state)[number], at: number): Rect => ({
        x: item.x - item.w / 2,
        y: at - item.h,
        w: item.w,
        h: item.h,
    });
    const hit = (a: Rect, b: Rect): boolean =>
        a.x < b.x + b.w + gap &&
        b.x < a.x + a.w + gap &&
        a.y < b.y + b.h + gap &&
        b.y < a.y + a.h + gap;

    // Lower tags (nearer the viewer) keep their place; the ones behind move up.
    const order = state
        .map((item, index) => ({ item, index, at: tags[index].bottomY }))
        .sort((a, b) => b.at - a.at);
    const placed: Rect[] = [];

    for (const entry of order) {
        let at = entry.at;
        let guard = 0;
        let moved = true;

        while (moved && guard < 40) {
            moved = false;
            guard += 1;

            for (const other of [...placed, ...obstacles]) {
                const mine = rect(entry.item, at);

                if (hit(mine, other)) {
                    at = other.y - gap;
                    moved = true;
                }
            }
        }

        entry.item.y = at;
        placed.push(rect(entry.item, at));
    }

    return new Map(
        state.map((item, index) => [
            item.code,
            { dx: item.dx, dy: item.y - tags[index].bottomY },
        ]),
    );
};
