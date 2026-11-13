# AG-19: Quick answers date box (V2DateField)

Code: branch `feature/ag-19-quick-answers-date`, head e88b5c12. Shots from the real V2 shell on a task-owned `*_testing` database, Director, 1440/1024/390/320, light and dark.

Files per width and theme: `set` (a date chosen), `typing` ("11/11/2026" with the echo "= 11 Nov 2026"), `future`, `old`, `bad` (31 Nov), `monthfirst` (12/13/2026), `after-ask`.

Measured at every combination: no sideways overflow, no console errors, no control under the touch-target size. Behaviour: typing shows the words echo; a future or pre-2020 date reads "Choose a date from 1 Jan 2020 up to today." (the server's own words); 31 Nov reads "There is no 31 Nov."; month-first reads "Type the date day first, such as 13 Dec 2026."; each of these disables Answer; a good date enables it and the request sends date=YYYY-MM-DD.

Limit: the "subject" is set ("The date") but the page passes its own min/max message, so the subject never shows.
