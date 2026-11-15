# Inventory V2 page 8: Controlled register (evidence)

Synthetic `.test` data on a task-owned preview database. `shots/` are the V2 pages; `classic/` is the Classic
compare (Classic page with V2 off, this branch's build vs main's build): 0 different pixels on every page body.
The only difference is 13 edge-smoothing pixels in the top bar's branch switcher on the 390 light list, which
also appears between two shots of the unchanged main build.

The first commit on this branch copied the project files by mistake (a failed step); the second replaces them.
