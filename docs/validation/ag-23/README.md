# AG-23: Version 2 agent office

Code: branch `feature/ag-23-v2-office`, head ccd3c0b8. Real V2 shell, task-owned `*_testing` database, Director.

Files: `v2-{1440,1024,390,320}-{light,dark}-pause-{on,off}.jpg` (the page, Pause motion on and off); `dialog-{1440,390}-{light,dark}.jpg` (the agent details dialog, Hana); `classic-before-1440-light.png` and `classic-after-1440-light.png` (Classic, before and after).

## Daniel's six points
1. Layout C moved to `pages/Agents/V2/Office.vue` and registered. Classic `Office.vue` is Classic only. **Pixel compare of Classic, before (main) and after, 1440/1024/390/320 in light and dark, Pause motion on so nothing animates: all 8 screenshots are identical except 17 to 94 pixels in a box about 18 px square, the wall clock's hands (the clock shows real time and a minute passed between the two runs).**
2. V2WorkLayout, crumb "Agents > Office", V2Chip chips (working, waiting for approval, last run failed, off, waiting for a decision, live updates paused). The AI-agent notice keeps its wording.
3. Pause motion unchanged: aria-pressed, 36 px on desktop and 44 px on phones, remembered in this browser only, hidden with reduced motion, in the scene header. Measured: 36 running animations with Pause off and 0 with it on, at every width, light and dark; no overflow at any width; no console errors.
4. The click detail is a V2Dialog (a bottom sheet on phones: 390 px wide, touching the bottom edge; a 512 px dialog at 1440) with the same rows: title, status, current task, last steps, pending suggestions (inbox viewers at their active branch only), the read-only note. Opens with focus on Close; Esc closes it and focus returns to the agent's card. **Updates in place on the 10-second poll:** with the dialog open and focus on its Close button, a poll refreshed the details (a changed step text appeared), the dialog stayed open (1), and the same Close node still had focus.
5. The plain list of agents already existed and is kept: name, "AI agent", job title and status in words, current task and the latest message, in a fixed order. It is on the left from 1280 px and below the scene on narrower screens.
6. Tab order with Pause on: the scene's agents are reached in the same order with Pause on and off (Dato' Arif, Lina, Approval inbox, Hana, Faris, Nadia, Rizal). **Pausing does not stop the walking focus-order change.** When the feed gives an agent a pending suggestion, it walks to the Director's desk, which changes its paint depth, so the scene reorders its figures in the page: the order after one poll moved Rizal ahead of Faris and Nadia, with Pause on and off alike. Pause only stops animation and transitions, not that state change. Documented limit: use the plain agent list, whose order never changes, for keyboard and screen-reader navigation.

Limits: no real device or screen reader check. The scene itself is as chosen on 12 Nov and unchanged.
