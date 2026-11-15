# DD-84 plan (no code): a refused request on a V2 form must not show Inertia's raw error page

Status: plan only, waiting for the owner's decision. Author: Kavitha (Staff Experience).

## The problem
On a V2 form or dialog, when the server refuses a request with 403 (the person lost authority after the page loaded; seen on Staff detail and Staff edit), Inertia shows the raw HTML error page in a modal over the screen. Nothing in `resources/js` handles Inertia's `invalid` event today, so every non-Inertia error response gets that modal. Classic does the same today.

## Where the shared handler sits
One small module, `resources/js/lib/v2-refused-request.ts`, imported next to the other V2 boot code in `app.ts` (the same place the Back guard module is imported). It listens once to Inertia's global `invalid` event, which fires when a response is not an Inertia response. It acts only when `<html data-ui="v2">` is set, and does nothing on Classic. Pages and dialogs do not change.

## Which status codes it catches
- **403**: yes. This is the refused action.
- **419** (session or CSRF expired): yes, with its own sentence, because "reload" is the right fix and the person's work is not saved.
- **409**: no. Inertia uses it for an asset-version change and reloads by itself, and KPOne reports a stale record as a normal validation error (422), which pages already show.
- **500 and other 5xx, 404**: no. The default page stays, because a server fault or a missing page is not something the person can fix from the form.
For the codes it catches it calls `preventDefault()`, so the raw page never opens.

## The exact wording (one plain line, no code or jargon)
- 403: "You no longer have permission to do this. Reload the page to see what you can do."
- 419: "Your session has expired. Reload the page to continue. Anything you typed here was not saved."
Staff-specific wording ("change this person") is not used, because the handler cannot know the page; a page may pass its own sentence later if Daniel wants it.

## How it looks
- **In a form (a page with a form):** a plain error line with `role="alert"` at the top of the main column, above the first field, text colour destructive, with a "Reload page" outline button beside it. It stays until the person reloads; typed values are not cleared.
- **In a dialog (V2Dialog open):** the same line inside the dialog body above the footer, as an error line with `role="alert"`; the dialog stays open, the filled action stays as it was, and "Reload page" is the footer's secondary action only when the dialog is a single-action sheet. Otherwise the line alone shows, and Cancel closes as usual.
- Focus does not move; the alert is announced. 44 px / 36 px sizes as everywhere in V2.

## What Classic keeps
Nothing changes in Classic: the handler returns on its first line unless `data-ui="v2"`, so Classic still shows Inertia's modal exactly as today. Verified the same way as the Back guard: a later listener on Classic still gets the event. No existing assertion should change. A new source-pin test, plus real-app shots of a refused request on Staff edit (V2, form) and the role dialog (V2, dialog), at 390 and 1440.

## Open decisions for the owner
1. 403 only, or 403 and 419 (my recommendation: both).
2. Whether the line may include a "Reload page" button (my recommendation: yes, because a refused person cannot fix it otherwise).
3. Whether Classic should get the same handler later (out of this plan).
