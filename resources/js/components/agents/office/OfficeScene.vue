<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useUiVersion } from '@/composables/useUiVersion';
import AgentFigure from './AgentFigure.vue';
import type { FeedAgent, OfficeFeed, Rect, TagRequest } from './office-layout';
import {
    avatarFor,
    box,
    DIRECTOR_DESK,
    floorQuad,
    GRID_H,
    GRID_W,
    iso,
    MEETING,
    MEETING_SEATS,
    points,
    recentMessages,
    seatAgents,
    BUBBLE_GAP_PX,
    shorten,
    spreadTags,
    tagBottom,
    tagBox,
    standingAtInbox,
    VIEW_H,
    VIEW_W,
    WALL_H,
    ZONES,
} from './office-layout';

const props = defineProps<{
    feed: OfficeFeed;
    selected: string | null;
    clock: Date;
    canOpenInbox: boolean;
    motionOff?: boolean;
}>();

const emit = defineEmits<{ select: [code: string]; openInbox: [] }>();

const WALK_MS = 2400;

/** Wall planes: a point on the back-right wall (gy = 0) or the back-left wall (gx = 0). */
const rightWall = (gx1: number, gx2: number, z1: number, z2: number) =>
    points([
        iso(gx1, 0, z1),
        iso(gx2, 0, z1),
        iso(gx2, 0, z2),
        iso(gx1, 0, z2),
    ]);
const leftWall = (gy1: number, gy2: number, z1: number, z2: number) =>
    points([
        iso(0, gy1, z1),
        iso(0, gy2, z1),
        iso(0, gy2, z2),
        iso(0, gy1, z2),
    ]);
/** Draw flat on the back-right wall (x along the wall, y down). */
const rightWallMatrix = (gx: number, z: number) => {
    const p = iso(gx, 0, z);

    return `matrix(0.894 0.447 0 1 ${p.x.toFixed(1)} ${p.y.toFixed(1)})`;
};
const leftWallMatrix = (gy: number, z: number) => {
    const p = iso(0, gy, z);

    return `matrix(0.894 -0.447 0 1 ${p.x.toFixed(1)} ${p.y.toFixed(1)})`;
};

const floorTiles = computed(() => {
    const tiles: { key: string; points: string; dark: boolean }[] = [];

    for (let gx = 0; gx < GRID_W; gx++) {
        for (let gy = 0; gy < GRID_H; gy++) {
            tiles.push({
                key: `${gx}-${gy}`,
                points: floorQuad(gx, gy, 1, 1),
                dark: (gx + gy) % 2 === 1,
            });
        }
    }

    return tiles;
});

const zones = ZONES.map((zone) => ({
    ...zone,
    rug: floorQuad(zone.gx, zone.gy, zone.w, zone.d),
}));

const placements = computed(() => seatAgents(props.feed.agents));
const standing = computed(() => standingAtInbox(props.feed.agents));
const bubbles = computed(() => recentMessages(props.feed));

// A figure walks for a moment whenever it moves between its desk and the Director's desk.
const walking = ref(new Set<string>());
const timers = new Map<string, ReturnType<typeof setTimeout>>();
watch(
    () => [...standing.value.keys()].sort().join('|'),
    (now, before) => {
        if (before === undefined) {
            return;
        }

        const was = new Set(before ? before.split('|') : []);
        const is = new Set(now ? now.split('|') : []);
        const changed = [...was, ...is].filter(
            (code) => code !== '' && was.has(code) !== is.has(code),
        );

        for (const code of changed) {
            const next = new Set(walking.value);
            next.add(code);
            walking.value = next;
            clearTimeout(timers.get(code));
            timers.set(
                code,
                setTimeout(() => {
                    const rest = new Set(walking.value);
                    rest.delete(code);
                    walking.value = rest;
                }, WALK_MS),
            );
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => timers.forEach((timer) => clearTimeout(timer)));

type Item =
    | {
          kind: 'desk';
          key: string;
          depth: number;
          gx: number;
          gy: number;
          working: boolean;
      }
    | {
          kind: 'figure';
          key: string;
          depth: number;
          agent: FeedAgent;
          x: number;
          y: number;
          seated: boolean;
      }
    | {
          kind: 'box';
          key: string;
          depth: number;
          shape: ReturnType<typeof box>;
          tone: string;
      }
    | { kind: 'plant'; key: string; depth: number; gx: number; gy: number }
    | { kind: 'cooler'; key: string; depth: number; gx: number; gy: number }
    | { kind: 'rack'; key: string; depth: number; gx: number; gy: number }
    | { kind: 'shelf'; key: string; depth: number; gx: number; gy: number }
    | { kind: 'meeting'; key: string; depth: number }
    | {
          kind: 'meeting-chair';
          key: string;
          depth: number;
          gx: number;
          gy: number;
      }
    | { kind: 'director'; key: string; depth: number };

const items = computed<Item[]>(() => {
    const list: Item[] = [];
    const agents = new Map(
        props.feed.agents.map((agent) => [agent.code, agent]),
    );
    const seatedAtMeeting = new Set<string>();

    for (const placement of placements.value) {
        const agent = agents.get(placement.code);

        if (!agent) {
            continue;
        }

        const spot = standing.value.get(agent.code);
        const seat = placement.seat;

        if (placement.atMeeting) {
            seatedAtMeeting.add(`${seat.gx}-${seat.gy}`);
        } else {
            list.push({
                kind: 'desk',
                key: `desk-${agent.code}`,
                depth: seat.gx + seat.gy + 0.8,
                gx: seat.gx - 1,
                gy: seat.gy + 0.35,
                working: agent.isEnabled && agent.light === 'green',
            });
        }

        const at = spot ? iso(spot.x, spot.y) : iso(seat.gx, seat.gy);
        list.push({
            kind: 'figure',
            key: `figure-${agent.code}`,
            depth: spot
                ? spot.x + spot.y
                : seat.gx +
                  seat.gy +
                  (placement.atMeeting && seat.gy > MEETING.gy ? 3 : 0),
            agent,
            x: at.x,
            y: at.y,
            seated: !spot && !walking.value.has(agent.code),
        });
    }

    for (const seat of MEETING_SEATS) {
        if (!seatedAtMeeting.has(`${seat.gx}-${seat.gy}`)) {
            list.push({
                kind: 'meeting-chair',
                key: `mchair-${seat.gx}-${seat.gy}`,
                depth: seat.gx + seat.gy,
                gx: seat.gx,
                gy: seat.gy,
            });
        }
    }

    list.push(
        {
            kind: 'meeting',
            key: 'meeting',
            depth: MEETING.gx + MEETING.gy + 2.4,
        },
        {
            kind: 'director',
            key: 'director',
            depth: DIRECTOR_DESK.gx + DIRECTOR_DESK.gy + 1.8,
        },
        { kind: 'shelf', key: 'shelf-stock', depth: 8.4, gx: 7.7, gy: 0.05 },
        { kind: 'rack', key: 'rack', depth: 10.4, gx: 0.05, gy: 9.3 },
        { kind: 'plant', key: 'plant-1', depth: 7.3, gx: 6.95, gy: 0.4 },
        { kind: 'plant', key: 'plant-2', depth: 14.1, gx: 13.3, gy: 0.6 },
        { kind: 'plant', key: 'plant-3', depth: 13.2, gx: 6.5, gy: 6.4 },
        { kind: 'cooler', key: 'cooler', depth: 23, gx: 13.1, gy: 9.7 },
    );

    return list.sort((a, b) => a.depth - b.depth);
});

const deskShape = (gx: number, gy: number) => box(gx, gy, 2, 0.9, 18);
const laptop = (gx: number, gy: number) => ({
    base: box(gx + 0.25, gy + 0.2, 0.62, 0.42, 1.5, 18),
    lid: points([
        iso(gx + 0.25, gy + 0.2, 19.5),
        iso(gx + 0.87, gy + 0.2, 19.5),
        iso(gx + 0.87, gy + 0.2, 33),
        iso(gx + 0.25, gy + 0.2, 33),
    ]),
    logo: iso(gx + 0.56, gy + 0.2, 26.5),
});
const mug = (gx: number, gy: number) => iso(gx + 1.55, gy + 0.5, 18);
const papers = (gx: number, gy: number) =>
    box(gx + 1.05, gy + 0.25, 0.4, 0.35, 1.2, 18);

const director = {
    shape: box(
        DIRECTOR_DESK.gx,
        DIRECTOR_DESK.gy,
        DIRECTOR_DESK.w,
        DIRECTOR_DESK.d,
        22,
    ),
    plate: iso(DIRECTOR_DESK.gx + 0.55, DIRECTOR_DESK.gy + DIRECTOR_DESK.d, 13),
    tray: box(DIRECTOR_DESK.gx + 1.5, DIRECTOR_DESK.gy + 0.2, 0.7, 0.55, 4, 22),
    stack: box(
        DIRECTOR_DESK.gx + 1.58,
        DIRECTOR_DESK.gy + 0.27,
        0.54,
        0.42,
        3,
        26,
    ),
    badge: iso(DIRECTOR_DESK.gx + 1.85, DIRECTOR_DESK.gy + 0.45, 40),
    lamp: iso(DIRECTOR_DESK.gx + 0.4, DIRECTOR_DESK.gy + 0.35, 22),
};
const meeting = {
    top: box(MEETING.gx, MEETING.gy, MEETING.w, MEETING.d, 3, 15),
    legs: [
        box(MEETING.gx + 0.2, MEETING.gy + 0.2, 0.2, 0.2, 15),
        box(MEETING.gx + MEETING.w - 0.4, MEETING.gy + 0.2, 0.2, 0.2, 15),
        box(MEETING.gx + 0.2, MEETING.gy + MEETING.d - 0.4, 0.2, 0.2, 15),
        box(
            MEETING.gx + MEETING.w - 0.4,
            MEETING.gy + MEETING.d - 0.4,
            0.2,
            0.2,
            15,
        ),
    ],
    books: box(MEETING.gx + 1.3, MEETING.gy + 0.5, 0.5, 0.35, 5, 18),
    bars: [
        {
            shape: box(MEETING.gx + 2.2, MEETING.gy + 0.6, 0.12, 0.12, 16, 18),
            tone: '#ef4444',
        },
        {
            shape: box(MEETING.gx + 2.36, MEETING.gy + 0.6, 0.12, 0.12, 24, 18),
            tone: '#f59e0b',
        },
        {
            shape: box(MEETING.gx + 2.52, MEETING.gy + 0.6, 0.12, 0.12, 30, 18),
            tone: '#0ea5e9',
        },
        {
            shape: box(MEETING.gx + 2.68, MEETING.gy + 0.6, 0.12, 0.12, 20, 18),
            tone: '#14b8a6',
        },
    ],
};
const meetingChair = (gx: number, gy: number) => ({
    seat: box(gx - 0.25, gy - 0.25, 0.5, 0.5, 3, 11),
    stem: box(gx - 0.05, gy - 0.05, 0.1, 0.1, 11),
});
const plant = (gx: number, gy: number) => ({
    pot: box(gx - 0.22, gy - 0.22, 0.44, 0.44, 16),
    leaves: iso(gx, gy, 30),
});
const cooler = (gx: number, gy: number) => ({
    body: box(gx - 0.3, gy - 0.3, 0.6, 0.6, 34),
    bottle: iso(gx, gy, 46),
    tap: iso(gx - 0.05, gy + 0.3, 24),
});
const rack = (gx: number, gy: number) => ({
    shape: box(gx, gy, 0.7, 1.4, 78),
    leds: Array.from({ length: 6 }, (_, index) =>
        iso(
            gx + 0.7,
            gy + 0.3 + (index % 3) * 0.4,
            60 - Math.floor(index / 3) * 22,
        ),
    ),
});
const shelf = (gx: number, gy: number) => {
    const rows = [6, 30, 54];

    return {
        frame: box(gx, gy, 4.8, 0.55, 72),
        boxes: rows.flatMap((z, row) =>
            [0.15, 0.95, 1.75, 2.55, 3.35, 4.05].map((offset, index) => ({
                key: `${row}-${index}`,
                shape: box(gx + offset, gy + 0.1, 0.6, 0.4, 16, z),
                tone: [
                    '#0d9488',
                    '#38bdf8',
                    '#f472b6',
                    '#facc15',
                    '#a78bfa',
                    '#34d399',
                ][(row + index) % 6],
            })),
        ),
    };
};

const clockHands = computed(() => {
    const hours = props.clock.getHours() % 12;
    const minutes = props.clock.getMinutes();
    const hour = ((hours + minutes / 60) / 12) * Math.PI * 2;
    const minute = (minutes / 60) * Math.PI * 2;

    return {
        hour: { x: Math.sin(hour) * 5.5, y: -Math.cos(hour) * 5.5 },
        minute: { x: Math.sin(minute) * 8, y: -Math.cos(minute) * 8 },
    };
});

// One speech bubble at a time, taking turns, so the office never gets crowded.
const BUBBLE_MS = 5000;
const bubbleTurn = ref(0);
const bubbleTimer = setInterval(() => {
    bubbleTurn.value += 1;
}, BUBBLE_MS);
onBeforeUnmount(() => clearInterval(bubbleTimer));
const speaking = computed(() => {
    const codes = [...bubbles.value.keys()];

    return codes.length > 0 ? codes[bubbleTurn.value % codes.length] : null;
});
const bubbleFor = (code: string) => {
    const message =
        code === speaking.value ? bubbles.value.get(code) : undefined;

    return message ? shorten(message.summary, 38) : null;
};
const inboxCount = computed(() => props.feed.pendingProposals);

// AG-13b: the scene is drawn in scene units and scaled to fit; tags are drawn in real pixels, so they need the scale.
const svgEl = ref<SVGSVGElement | null>(null);
const sceneWidth = ref(VIEW_W);
let resizeObserver: ResizeObserver | undefined;
onMounted(() => {
    if (svgEl.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver((entries) => {
            sceneWidth.value = entries[0]?.contentRect.width || VIEW_W;
        });
        resizeObserver.observe(svgEl.value);
    }
});
onBeforeUnmount(() => {
    resizeObserver?.disconnect();
});
const tagFactor = computed(() => VIEW_W / Math.max(sceneWidth.value, 1));
// Classic (the default look) draws the office as it always did; the control room look is Version 2 only.
const { isV2 } = useUiVersion();
const tagLayout = computed(() => {
    if (!isV2.value) {
        return {
            shifts: new Map<string, { dx: number; dy: number }>(),
            topPad: 0,
        };
    }

    const requests: TagRequest[] = items.value.flatMap((item) => {
        if (item.kind !== 'figure') {
            return [];
        }

        const box = tagBox(item.agent.displayName, false);

        return [
            {
                code: item.agent.code,
                x: item.x,
                bottomY: item.y + tagBottom(item.seated, tagFactor.value),
                w: box.w,
                h: box.h,
            },
        ];
    });
    const label = director.badge;
    const obstacles: Rect[] = [
        { x: label.x - 44, y: label.y - 26, w: 88, h: 35 },
    ];
    const shifts = spreadTags(requests, obstacles, tagFactor.value, VIEW_W);
    // The tag and, above it, a speech bubble may sit above the walls: make room in the view box.
    let top = 0;

    for (const request of requests) {
        const dy = shifts.get(request.code)?.dy ?? 0;
        top = Math.min(
            top,
            request.bottomY +
                dy -
                (request.h + BUBBLE_GAP_PX + 31) * tagFactor.value,
        );
    }

    return { shifts, topPad: Math.ceil(-top) + 6 };
});
</script>

<template>
    <svg
        ref="svgEl"
        class="office-scene h-auto w-full select-none"
        :class="{ 'control-room max-md:min-w-[560px]': isV2 }"
        :data-motion="motionOff ? 'off' : 'on'"
        :viewBox="`0 ${-tagLayout.topPad} ${VIEW_W} ${VIEW_H + tagLayout.topPad}`"
        role="group"
        :aria-label="
            isV2
                ? 'Agent office scene. The agent list has the same information.'
                : 'Agent office'
        "
        data-test="office-scene"
    >
        <defs>
            <linearGradient id="office-sky" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="var(--office-sky-a)" />
                <stop offset="1" stop-color="var(--office-sky-b)" />
            </linearGradient>
        </defs>

        <!-- Walls -->
        <polygon :points="rightWall(0, GRID_W, 0, WALL_H)" class="wall-right" />
        <polygon :points="leftWall(0, GRID_H, 0, WALL_H)" class="wall-left" />
        <polygon :points="rightWall(0, GRID_W, 0, 8)" class="skirting" />
        <polygon :points="leftWall(0, GRID_H, 0, 8)" class="skirting" />
        <polygon
            :points="rightWall(0, GRID_W, WALL_H - 6, WALL_H)"
            class="trim"
        />
        <polygon
            :points="leftWall(0, GRID_H, WALL_H - 6, WALL_H)"
            class="trim"
        />

        <!-- Windows on the back-right wall -->
        <g
            v-for="span in [
                [0.9, 3.1],
                [4.6, 6.8],
            ]"
            :key="span[0]"
        >
            <polygon
                :points="rightWall(span[0], span[1], 42, 108)"
                class="window-frame"
            />
            <polygon
                :points="rightWall(span[0] + 0.12, span[1] - 0.12, 46, 104)"
                fill="url(#office-sky)"
            />
            <polygon
                :points="
                    rightWall(
                        (span[0] + span[1]) / 2 - 0.04,
                        (span[0] + span[1]) / 2 + 0.04,
                        46,
                        104,
                    )
                "
                class="window-frame"
            />
            <polygon
                :points="rightWall(span[0] + 0.12, span[1] - 0.12, 74, 77)"
                class="window-frame"
            />
            <g :transform="rightWallMatrix(span[0] + 0.3, 92)">
                <g class="cloud">
                    <ellipse cx="14" cy="0" rx="9" ry="3.5" />
                    <ellipse cx="22" cy="-2" rx="7" ry="4" />
                </g>
            </g>
        </g>

        <!-- Clock -->
        <g :transform="rightWallMatrix(3.85, 92)">
            <circle cx="0" cy="0" r="11" class="clock-face" />
            <line
                x1="0"
                y1="0"
                :x2="clockHands.hour.x"
                :y2="clockHands.hour.y"
                class="clock-hand"
                stroke-width="2"
            />
            <line
                x1="0"
                y1="0"
                :x2="clockHands.minute.x"
                :y2="clockHands.minute.y"
                class="clock-hand"
                stroke-width="1.3"
            />
            <circle cx="0" cy="0" r="1.4" class="clock-pin" />
        </g>

        <!-- Department sign on the back-right wall above the stock shelf -->
        <g :transform="rightWallMatrix(7.9, 112)">
            <rect
                x="0"
                y="-11"
                width="150"
                height="17"
                rx="4"
                class="wall-sign"
            />
            <text x="75" y="1" text-anchor="middle" class="wall-sign-text">
                CLINIC OPERATIONS
            </text>
        </g>

        <!-- Whiteboard and window on the back-left wall -->
        <polygon :points="leftWall(1.2, 3.8, 40, 100)" class="board-frame" />
        <polygon :points="leftWall(1.3, 3.7, 44, 96)" class="board" />
        <g :transform="leftWallMatrix(1.5, 88)" class="board-marks">
            <path
                d="M4 0 Q16 -6 26 2 T52 0"
                fill="none"
                stroke="#0ea5e9"
                stroke-width="1.6"
            />
            <path d="M4 14 L40 14" stroke="#f43f5e" stroke-width="1.6" />
            <path d="M4 24 L30 24" stroke="#22c55e" stroke-width="1.6" />
            <circle cx="62" cy="22" r="2" fill="#f59e0b" />
            <circle cx="68" cy="22" r="2" fill="#0ea5e9" />
            <circle cx="74" cy="22" r="2" fill="#ef4444" />
        </g>
        <g :transform="leftWallMatrix(1.4, 112)">
            <rect
                x="0"
                y="-11"
                width="96"
                height="17"
                rx="4"
                class="wall-sign"
            />
            <text x="48" y="1" text-anchor="middle" class="wall-sign-text">
                LEADERSHIP
            </text>
        </g>
        <polygon :points="leftWall(5.2, 7.6, 42, 108)" class="window-frame" />
        <polygon
            :points="leftWall(5.32, 7.48, 46, 104)"
            fill="url(#office-sky)"
        />
        <polygon :points="leftWall(6.36, 6.44, 46, 104)" class="window-frame" />
        <g :transform="leftWallMatrix(7.9, 112)">
            <rect
                x="0"
                y="-11"
                width="96"
                height="17"
                rx="4"
                class="wall-sign"
            />
            <text x="48" y="1" text-anchor="middle" class="wall-sign-text">
                TECHNOLOGY
            </text>
        </g>

        <!-- Floor -->
        <polygon
            v-for="tile in floorTiles"
            :key="tile.key"
            :points="tile.points"
            :class="tile.dark ? 'floor-b' : 'floor-a'"
        />
        <polygon
            v-for="zone in zones"
            :key="`rug-${zone.key}`"
            :points="zone.rug"
            class="rug"
            :class="`rug-${zone.key.replace(/\s+/g, '-').toLowerCase()}`"
        />
        <polygon
            :points="
                floorQuad(
                    MEETING.gx - 0.6,
                    MEETING.gy - 1.1,
                    MEETING.w + 1.2,
                    MEETING.d + 2.6,
                )
            "
            class="rug rug-meeting"
        />

        <!-- Furniture and agents, drawn back to front -->
        <template v-for="item in items" :key="item.key">
            <g v-if="item.kind === 'desk'">
                <polygon
                    :points="deskShape(item.gx, item.gy).left"
                    class="desk-left"
                />
                <polygon
                    :points="deskShape(item.gx, item.gy).right"
                    class="desk-right"
                />
                <polygon
                    :points="deskShape(item.gx, item.gy).top"
                    class="desk-top"
                />
                <polygon
                    :points="laptop(item.gx, item.gy).base.top"
                    class="laptop-base"
                />
                <polygon
                    :points="laptop(item.gx, item.gy).lid"
                    class="laptop-lid"
                    :class="{ glow: item.working }"
                />
                <circle
                    :cx="laptop(item.gx, item.gy).logo.x"
                    :cy="laptop(item.gx, item.gy).logo.y"
                    r="1.6"
                    class="laptop-logo"
                />
                <template v-if="isV2">
                    <circle
                        v-for="led in [0, 1, 2]"
                        :key="led"
                        :cx="
                            iso(
                                item.gx + 1.15 + led * 0.14,
                                item.gy + 0.18,
                                18.6,
                            ).x
                        "
                        :cy="
                            iso(
                                item.gx + 1.15 + led * 0.14,
                                item.gy + 0.18,
                                18.6,
                            ).y
                        "
                        r="1.2"
                        class="desk-led"
                        :class="{ on: item.working || led === 0 }"
                    />
                </template>
                <polygon :points="papers(item.gx, item.gy).top" class="paper" />
                <polygon
                    :points="papers(item.gx, item.gy).left"
                    class="paper-side"
                />
                <g
                    :transform="`translate(${mug(item.gx, item.gy).x} ${mug(item.gx, item.gy).y})`"
                >
                    <rect
                        x="-3.5"
                        y="-8"
                        width="7"
                        height="8"
                        rx="1.5"
                        class="mug"
                    />
                    <ellipse cx="0" cy="-8" rx="3.5" ry="1.4" class="mug-top" />
                    <path
                        d="M3.5 -6 q3 1 0 4"
                        fill="none"
                        class="mug-handle"
                        stroke-width="1.2"
                    />
                    <path
                        v-if="item.working"
                        class="steam"
                        d="M-1 -11 q-2 -3 0 -6 q2 -3 0 -6"
                        fill="none"
                        stroke-width="1"
                    />
                </g>
            </g>

            <AgentFigure
                v-else-if="item.kind === 'figure'"
                :agent="item.agent"
                :avatar="avatarFor(item.agent.code)"
                :x="item.x"
                :y="item.y"
                :seated="item.seated"
                :walking="walking.has(item.agent.code)"
                :selected="selected === item.agent.code"
                :bubble="bubbleFor(item.agent.code)"
                :tag-scale="tagFactor"
                :tag-dx="tagLayout.shifts.get(item.agent.code)?.dx ?? 0"
                :tag-dy="tagLayout.shifts.get(item.agent.code)?.dy ?? 0"
                :show-role="false"
                :v2="isV2"
                @select="emit('select', $event)"
            />

            <g v-else-if="item.kind === 'director'">
                <polygon :points="director.shape.left" class="director-left" />
                <polygon
                    :points="director.shape.right"
                    class="director-right"
                />
                <polygon :points="director.shape.top" class="director-top" />
                <g
                    :transform="`matrix(0.894 0.447 0 1 ${director.plate.x.toFixed(1)} ${director.plate.y.toFixed(1)})`"
                >
                    <rect
                        x="0"
                        y="-8"
                        width="58"
                        height="11"
                        rx="2"
                        class="plate"
                    />
                    <text
                        x="29"
                        y="0.5"
                        text-anchor="middle"
                        class="plate-text"
                    >
                        DIRECTOR
                    </text>
                </g>
                <g
                    :transform="`translate(${director.lamp.x} ${director.lamp.y})`"
                >
                    <ellipse cx="0" cy="0" rx="5" ry="2" class="lamp-base" />
                    <path
                        d="M0 0 L0 -16 L6 -22"
                        fill="none"
                        class="lamp-arm"
                        stroke-width="1.6"
                    />
                    <path d="M3 -25 L11 -19 L7 -16 Z" class="lamp-head" />
                </g>
                <g
                    class="inbox-tray"
                    :class="{ clickable: canOpenInbox }"
                    :role="canOpenInbox ? 'button' : 'img'"
                    :tabindex="canOpenInbox ? 0 : undefined"
                    :aria-label="`Approval inbox: ${inboxCount} waiting for a decision`"
                    data-test="office-inbox"
                    @click="canOpenInbox && emit('openInbox')"
                    @keydown.enter.prevent="canOpenInbox && emit('openInbox')"
                    @keydown.space.prevent="canOpenInbox && emit('openInbox')"
                >
                    <polygon :points="director.tray.left" class="tray-side" />
                    <polygon :points="director.tray.right" class="tray-side" />
                    <polygon :points="director.tray.top" class="tray-top" />
                    <template v-if="inboxCount > 0">
                        <polygon
                            :points="director.stack.left"
                            class="paper-side"
                        />
                        <polygon
                            :points="director.stack.right"
                            class="paper-side"
                        />
                        <polygon :points="director.stack.top" class="paper" />
                    </template>
                    <g
                        :transform="`translate(${director.badge.x} ${director.badge.y})`"
                    >
                        <rect
                            x="-44"
                            y="-26"
                            width="88"
                            height="17"
                            rx="8.5"
                            class="inbox-label"
                        />
                        <text
                            x="0"
                            y="-14.5"
                            text-anchor="middle"
                            class="inbox-label-text"
                        >
                            Approval inbox
                        </text>
                        <circle
                            cx="0"
                            cy="0"
                            r="9"
                            :class="inboxCount > 0 ? 'badge-on' : 'badge-off'"
                        />
                        <text
                            x="0"
                            y="3.6"
                            text-anchor="middle"
                            class="badge-text"
                        >
                            {{ inboxCount > 99 ? '99+' : inboxCount }}
                        </text>
                    </g>
                </g>
            </g>

            <g v-else-if="item.kind === 'meeting'">
                <polygon
                    v-for="(leg, index) in meeting.legs"
                    :key="index"
                    :points="leg.left"
                    class="leg"
                />
                <polygon :points="meeting.top.left" class="table-side" />
                <polygon :points="meeting.top.right" class="table-side" />
                <polygon :points="meeting.top.top" class="table-top" />
                <polygon :points="meeting.books.left" fill="#b45309" />
                <polygon :points="meeting.books.right" fill="#92400e" />
                <polygon :points="meeting.books.top" fill="#f87171" />
                <g v-for="bar in meeting.bars" :key="bar.tone">
                    <polygon :points="bar.shape.left" :fill="bar.tone" />
                    <polygon
                        :points="bar.shape.right"
                        :fill="bar.tone"
                        opacity="0.75"
                    />
                    <polygon
                        :points="bar.shape.top"
                        :fill="bar.tone"
                        opacity="0.9"
                    />
                </g>
            </g>

            <g v-else-if="item.kind === 'meeting-chair'">
                <polygon
                    :points="meetingChair(item.gx, item.gy).stem.left"
                    class="leg"
                />
                <polygon
                    :points="meetingChair(item.gx, item.gy).seat.left"
                    class="chair"
                />
                <polygon
                    :points="meetingChair(item.gx, item.gy).seat.right"
                    class="chair-dark"
                />
                <polygon
                    :points="meetingChair(item.gx, item.gy).seat.top"
                    class="chair-top"
                />
            </g>

            <g v-else-if="item.kind === 'plant'">
                <polygon
                    :points="plant(item.gx, item.gy).pot.left"
                    class="pot-left"
                />
                <polygon
                    :points="plant(item.gx, item.gy).pot.right"
                    class="pot-right"
                />
                <polygon
                    :points="plant(item.gx, item.gy).pot.top"
                    class="soil"
                />
                <g
                    :transform="`translate(${plant(item.gx, item.gy).leaves.x} ${plant(item.gx, item.gy).leaves.y})`"
                >
                    <g class="leaves">
                        <ellipse
                            cx="-7"
                            cy="2"
                            rx="8"
                            ry="5"
                            transform="rotate(-30 -7 2)"
                        />
                        <ellipse
                            cx="7"
                            cy="2"
                            rx="8"
                            ry="5"
                            transform="rotate(30 7 2)"
                        />
                        <ellipse cx="0" cy="-6" rx="6" ry="9" />
                        <ellipse
                            cx="-4"
                            cy="-3"
                            rx="4"
                            ry="7"
                            transform="rotate(-20 -4 -3)"
                            class="leaf-light"
                        />
                    </g>
                </g>
            </g>

            <g v-else-if="item.kind === 'cooler'">
                <polygon
                    :points="cooler(item.gx, item.gy).body.left"
                    class="cooler-left"
                />
                <polygon
                    :points="cooler(item.gx, item.gy).body.right"
                    class="cooler-right"
                />
                <polygon
                    :points="cooler(item.gx, item.gy).body.top"
                    class="cooler-top"
                />
                <g
                    :transform="`translate(${cooler(item.gx, item.gy).bottle.x} ${cooler(item.gx, item.gy).bottle.y})`"
                >
                    <rect
                        x="-9"
                        y="-18"
                        width="18"
                        height="22"
                        rx="7"
                        class="bottle"
                    />
                    <ellipse cx="0" cy="-8" rx="9" ry="2" class="bottle-ring" />
                </g>
                <g
                    :transform="`translate(${cooler(item.gx, item.gy).tap.x} ${cooler(item.gx, item.gy).tap.y})`"
                >
                    <rect
                        x="-5"
                        y="-3"
                        width="4"
                        height="4"
                        rx="1"
                        fill="#3b82f6"
                    />
                    <rect
                        x="1"
                        y="-3"
                        width="4"
                        height="4"
                        rx="1"
                        fill="#ef4444"
                    />
                </g>
            </g>

            <g v-else-if="item.kind === 'rack'">
                <polygon
                    :points="rack(item.gx, item.gy).shape.left"
                    class="rack-left"
                />
                <polygon
                    :points="rack(item.gx, item.gy).shape.right"
                    class="rack-right"
                />
                <polygon
                    :points="rack(item.gx, item.gy).shape.top"
                    class="rack-top"
                />
                <circle
                    v-for="(led, index) in rack(item.gx, item.gy).leds"
                    :key="index"
                    :cx="led.x"
                    :cy="led.y"
                    r="1.6"
                    class="led"
                    :style="{ animationDelay: `${index * 0.37}s` }"
                />
            </g>

            <g v-else-if="item.kind === 'shelf'">
                <polygon
                    :points="shelf(item.gx, item.gy).frame.left"
                    class="shelf-left"
                />
                <polygon
                    :points="shelf(item.gx, item.gy).frame.right"
                    class="shelf-right"
                />
                <polygon
                    :points="shelf(item.gx, item.gy).frame.top"
                    class="shelf-top"
                />
                <g
                    v-for="part in shelf(item.gx, item.gy).boxes"
                    :key="part.key"
                >
                    <polygon :points="part.shape.left" :fill="part.tone" />
                    <polygon
                        :points="part.shape.right"
                        :fill="part.tone"
                        opacity="0.7"
                    />
                    <polygon
                        :points="part.shape.top"
                        :fill="part.tone"
                        opacity="0.85"
                    />
                </g>
            </g>
        </template>
    </svg>
</template>

<style scoped>
.office-scene {
    --office-sky-a: #bae6fd;
    --office-sky-b: #e0f2fe;
    --office-wall-left: #dbeafe;
    --office-wall-right: #eff6ff;
    --office-trim: #93c5fd;
    --office-skirting: #cbd5e1;
    --office-floor-a: #e2e8f0;
    --office-floor-b: #d7dee8;
    --office-desk-top: #f8fafc;
    --office-desk-left: #cbd5e1;
    --office-desk-right: #94a3b8;
    --office-chair: #475569;
    --office-tag: #ffffff;
    --office-tag-border: #e2e8f0;
    --office-text: #0f172a;
    --office-muted: #be185d;
    --office-bubble: #ffffff;
    --office-chip: #fce7f3;
    --office-chip-border: #f9a8d4;
    --office-chip-text: #9d174d;
    --office-focus: #db2777;
    font-family: inherit;
}

.wall-left {
    fill: var(--office-wall-left);
}
.wall-right {
    fill: var(--office-wall-right);
}
.trim {
    fill: var(--office-trim);
}
.skirting {
    fill: var(--office-skirting);
}
.floor-a {
    fill: var(--office-floor-a);
}
.floor-b {
    fill: var(--office-floor-b);
}
.window-frame {
    fill: #f8fafc;
    stroke: #cbd5e1;
    stroke-width: 0.6;
}
.cloud {
    fill: rgb(255 255 255 / 0.85);
    animation: drift 18s ease-in-out infinite alternate;
}
.clock-face {
    fill: #ffffff;
    stroke: #db2777;
    stroke-width: 2;
}
.clock-hand {
    stroke: #0f172a;
    stroke-linecap: round;
}
.clock-pin {
    fill: #db2777;
}
.board-frame {
    fill: #94a3b8;
}
.board {
    fill: #ffffff;
}
.wall-sign {
    fill: #db2777;
}
.wall-sign-text {
    fill: #ffffff;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
}
.rug {
    opacity: 0.55;
}
.rug-leadership {
    fill: #fbcfe8;
}
.rug-clinic-operations {
    fill: #99f6e4;
}
.rug-technology {
    fill: #c7d2fe;
}
.rug-meeting {
    fill: #fde68a;
    opacity: 0.4;
}
.desk-top,
.table-top {
    fill: var(--office-desk-top);
}
.desk-left,
.table-side {
    fill: var(--office-desk-left);
}
.desk-right {
    fill: var(--office-desk-right);
}
.leg {
    fill: #64748b;
}
.chair {
    fill: var(--office-chair);
}
.chair-dark {
    fill: #334155;
}
.chair-top {
    fill: #64748b;
}
.laptop-base {
    fill: #94a3b8;
}
.laptop-lid {
    fill: #475569;
}
.laptop-lid.glow {
    fill: #64748b;
    filter: drop-shadow(0 0 3px rgb(56 189 248 / 0.9));
}
.laptop-logo {
    fill: #e2e8f0;
}
.paper {
    fill: #ffffff;
}
.paper-side {
    fill: #e2e8f0;
}
.mug {
    fill: #db2777;
}
.mug-top {
    fill: #7c2d12;
}
.mug-handle {
    stroke: #db2777;
}
.steam {
    stroke: #94a3b8;
    opacity: 0.7;
    animation: steam 2.2s ease-in-out infinite;
}
.director-top {
    fill: #a16207;
}
.director-left {
    fill: #854d0e;
}
.director-right {
    fill: #713f12;
}
.plate {
    fill: #fde68a;
}
.plate-text {
    fill: #713f12;
    font-size: 7px;
    font-weight: 700;
    letter-spacing: 0.1em;
}
.lamp-base,
.lamp-head {
    fill: #0f172a;
}
.lamp-arm {
    stroke: #0f172a;
}
.inbox-tray {
    outline: none;
}
.inbox-tray.clickable {
    cursor: pointer;
}
.inbox-tray:focus-visible .inbox-label {
    stroke: var(--office-focus);
    stroke-width: 2;
}
.tray-top {
    fill: #334155;
}
.tray-side {
    fill: #1e293b;
}
.inbox-label {
    fill: var(--office-tag);
    stroke: var(--office-tag-border);
}
.inbox-label-text {
    fill: var(--office-text);
    font-size: 9px;
    font-weight: 700;
}
.badge-on {
    fill: #f59e0b;
    animation: badge 1.8s ease-in-out infinite;
    transform-box: fill-box;
    transform-origin: 50% 50%;
}
.badge-off {
    fill: #94a3b8;
}
.badge-text {
    fill: #ffffff;
    font-size: 10px;
    font-weight: 800;
}
.pot-left {
    fill: #c2410c;
}
.pot-right {
    fill: #9a3412;
}
.soil {
    fill: #422006;
}
.leaves {
    fill: #16a34a;
    animation: sway 6s ease-in-out infinite;
    transform-box: fill-box;
    transform-origin: 50% 100%;
}
.leaf-light {
    fill: #4ade80;
}
.cooler-left {
    fill: #e2e8f0;
}
.cooler-right {
    fill: #cbd5e1;
}
.cooler-top {
    fill: #f8fafc;
}
.bottle {
    fill: rgb(56 189 248 / 0.55);
    stroke: rgb(14 165 233 / 0.7);
}
.bottle-ring {
    fill: rgb(14 165 233 / 0.5);
}
.rack-left {
    fill: #1e293b;
}
.rack-right {
    fill: #0f172a;
}
.rack-top {
    fill: #334155;
}
.led {
    fill: #22c55e;
    animation: led 1.4s steps(2) infinite;
}
.shelf-left {
    fill: #e2e8f0;
}
.shelf-right {
    fill: #cbd5e1;
}
.shelf-top {
    fill: #f8fafc;
}

@keyframes drift {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(18px);
    }
}
@keyframes steam {
    0%,
    100% {
        opacity: 0;
        transform: translateY(0);
    }
    50% {
        opacity: 0.7;
        transform: translateY(-3px);
    }
}
@keyframes badge {
    0%,
    100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.15);
    }
}
@keyframes sway {
    0%,
    100% {
        transform: rotate(-2deg);
    }
    50% {
        transform: rotate(2deg);
    }
}
@keyframes led {
    0% {
        opacity: 1;
    }
    50% {
        opacity: 0.25;
    }
}

@media (prefers-reduced-motion: reduce) {
    .office-scene * {
        animation: none !important;
    }
}
</style>

<style>
/* "Pause motion": the same stillness the reduced-motion preference gives, switched by a button. */
.office-scene[data-motion='off'],
.office-scene[data-motion='off'] * {
    animation: none !important;
    transition: none !important;
}

/* Dark mode, limited to the office drawing by its class so no rule reaches the rest of the page. */
.dark .office-scene.office-scene {
    --office-sky-a: #1e3a8a;
    --office-sky-b: #312e81;
    --office-wall-left: #1e293b;
    --office-wall-right: #273449;
    --office-trim: #be185d;
    --office-skirting: #0f172a;
    --office-floor-a: #334155;
    --office-floor-b: #2b3748;
    --office-desk-top: #64748b;
    --office-desk-left: #475569;
    --office-desk-right: #334155;
    --office-chair: #0f172a;
    --office-tag: #0f172a;
    --office-tag-border: #475569;
    --office-text: #f8fafc;
    --office-muted: #f9a8d4;
    --office-bubble: #1e293b;
    --office-chip: rgb(167 139 250 / 0.16);
    --office-chip-border: rgb(167 139 250 / 0.55);
    --office-chip-text: #c4b5fd;
    --office-focus: #f472b6;
}
.dark .office-scene .window-frame {
    fill: #475569;
    stroke: #334155;
}
.dark .office-scene .cloud {
    fill: rgb(255 255 255 / 0.15);
}
.dark .office-scene .board {
    fill: #e2e8f0;
}
.dark .office-scene .rug {
    opacity: 0.22;
}
.dark .office-scene .director-top {
    fill: #854d0e;
}
.dark .office-scene .shelf-left,
.dark .office-scene .cooler-left {
    fill: #64748b;
}
.dark .office-scene .shelf-right,
.dark .office-scene .cooler-right {
    fill: #475569;
}
.dark .office-scene .shelf-top,
.dark .office-scene .cooler-top {
    fill: #94a3b8;
}
</style>
<style>
/* AG-13b: the Control room look of the KPOne Team Board: dark floor, cyan grid, neon walls, glass name tags. It
   belongs to this drawing only (its own class), never to the page around it. */
.dark .office-scene.office-scene.control-room,
.office-scene.control-room {
    --office-sky-a: #0b1730;
    --office-sky-b: #1b1646;
    --office-wall-left: #0d1830;
    --office-wall-right: #101d38;
    --office-trim: #5eead4;
    --office-skirting: #070b14;
    --office-floor-a: #0b1322;
    --office-floor-b: #0e182a;
    --office-desk-top: #1d2c49;
    --office-desk-left: #111c33;
    --office-desk-right: #0c1627;
    --office-chair: #131c30;
    --office-tag: rgb(10 16 28 / 0.9);
    --office-tag-border: rgb(148 163 184 / 0.32);
    --office-text: #e2e8f0;
    --office-muted: #8d9bb3;
    --office-bubble: rgb(14 21 38 / 0.95);
    --office-chip: rgb(167 139 250 / 0.16);
    --office-chip-border: rgb(167 139 250 / 0.55);
    --office-chip-text: #c4b5fd;
    --office-focus: #5eead4;
}
.office-scene.control-room .floor-a,
.office-scene.control-room .floor-b {
    stroke: rgb(94 234 212 / 0.14);
    stroke-width: 0.6;
}
.office-scene.control-room .trim {
    filter: drop-shadow(0 0 3px rgb(94 234 212 / 0.9));
}
.office-scene.control-room .wall-left,
.office-scene.control-room .wall-right {
    stroke: rgb(167 139 250 / 0.2);
    stroke-width: 0.8;
}
.office-scene.control-room .window-frame {
    fill: #0a1220;
    stroke: rgb(167 139 250 / 0.7);
    stroke-width: 1;
}
.office-scene.control-room .cloud {
    fill: rgb(94 234 212 / 0.1);
}
.office-scene.control-room .clock-face {
    fill: #0a1220;
    stroke: #5eead4;
}
.office-scene.control-room .clock-hand {
    stroke: #e2e8f0;
}
.office-scene.control-room .clock-pin {
    fill: #5eead4;
}
.office-scene.control-room .board-frame {
    fill: #1e293b;
    stroke: rgb(167 139 250 / 0.6);
}
.office-scene.control-room .board {
    fill: #0a1220;
}
.office-scene.control-room .wall-sign {
    fill: rgb(10 16 28 / 0.9);
    stroke: #5eead4;
    stroke-width: 0.8;
    filter: drop-shadow(0 0 2px rgb(94 234 212 / 0.8));
}
.office-scene.control-room .wall-sign-text {
    fill: #5eead4;
}
.office-scene.control-room .rug {
    opacity: 0.2;
    stroke: rgb(94 234 212 / 0.45);
    stroke-width: 0.8;
}
.office-scene.control-room .rug-meeting {
    opacity: 0.16;
}
.office-scene.control-room .desk-top,
.office-scene.control-room .table-top {
    stroke: rgb(94 234 212 / 0.4);
    stroke-width: 0.8;
}
.office-scene.control-room .leg {
    fill: #1e293b;
}
.office-scene.control-room .chair-dark {
    fill: #131c30;
}
.office-scene.control-room .chair-top {
    fill: #1d2c49;
}
.office-scene.control-room .laptop-base {
    fill: #1e293b;
}
.office-scene.control-room .laptop-lid {
    fill: #08111f;
    stroke: rgb(94 234 212 / 0.55);
    stroke-width: 0.8;
}
.office-scene.control-room .laptop-lid.glow {
    fill: #0a2a33;
    stroke: #5eead4;
    filter: drop-shadow(0 0 4px rgb(94 234 212 / 0.95));
}
.office-scene.control-room .laptop-logo {
    fill: #5eead4;
}
.office-scene.control-room .desk-led {
    fill: #334155;
}
.office-scene.control-room .desk-led.on {
    fill: #5eead4;
    filter: drop-shadow(0 0 2px #5eead4);
}
.office-scene.control-room .paper {
    fill: #1e293b;
}
.office-scene.control-room .paper-side {
    fill: #0f172a;
}
.office-scene.control-room .mug {
    fill: #a78bfa;
}
.office-scene.control-room .mug-handle {
    stroke: #a78bfa;
}
.office-scene.control-room .director-top {
    fill: #2a2147;
    stroke: rgb(167 139 250 / 0.6);
}
.office-scene.control-room .director-left {
    fill: #1b1533;
}
.office-scene.control-room .director-right {
    fill: #130f27;
}
.office-scene.control-room .plate {
    fill: rgb(167 139 250 / 0.2);
    stroke: #a78bfa;
    stroke-width: 0.6;
}
.office-scene.control-room .plate-text {
    fill: #c4b5fd;
}
.office-scene.control-room .lamp-base,
.office-scene.control-room .lamp-head {
    fill: #5eead4;
}
.office-scene.control-room .lamp-arm {
    stroke: #5eead4;
}
.office-scene.control-room .tray-top {
    fill: #1d2c49;
}
.office-scene.control-room .inbox-label {
    stroke: rgb(94 234 212 / 0.55);
}
.office-scene.control-room .leaves {
    fill: #0f9f6e;
}
.office-scene.control-room .leaf-light {
    fill: #34d399;
}
.office-scene.control-room .pot-left {
    fill: #312e81;
}
.office-scene.control-room .pot-right {
    fill: #1e1b4b;
}
.office-scene.control-room .cooler-left,
.office-scene.control-room .shelf-left {
    fill: #1d2c49;
}
.office-scene.control-room .cooler-right,
.office-scene.control-room .shelf-right {
    fill: #111c33;
}
.office-scene.control-room .cooler-top,
.office-scene.control-room .shelf-top {
    fill: #263a5e;
}
.office-scene.control-room .rack-top {
    fill: #1d2c49;
}
.office-scene.control-room .led {
    fill: #5eead4;
    filter: drop-shadow(0 0 2px #5eead4);
}
</style>
