# AG-20: Pause motion on the agent office

Code: branch `feature/ag-20-office-pause-motion`, head 5c70beaa. Real shell, task-owned `*_testing` database, Director.

Shots: `pause-v2-{1440,390}-{before,paused}`, `pause-classic-1440-{before,paused}`. The paused shots show the pressed state (tinted button, tick).

Measured with the browser's own animation list (running animations inside the scene): Version 2 and Classic, 36 running before, 0 after pressing; still 0 after a reload (the choice is kept in this browser only); pressing again resumes (36). Button 44 px on phones, 36 px on desktop. No overflow at 1440 or 390, no console errors.

With the device set to reduced motion the scene already has no running animation (0) and the button is not offered.

Live data is untouched: the "Live updates paused" flag is a separate state, and a test pins that the toggle never touches it.

Limit: the pressed state is shown by tint, a tick and weight, with `aria-pressed` for screen readers; the label stays "Pause motion" on purpose.
