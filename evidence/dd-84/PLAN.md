# DD-84 plan (no code): a refused request on a V2 form must not show Inertia's raw error page

Status: plan only, waiting for the owner's decision. Author: Kavitha (Staff Experience).

## The problem
On a V2 form or dialog, when the server refuses a request with 403 (the person lost authority after the page loaded; seen on Staff detail and Staff edit), Inertia shows the raw HTML error page in a modal over the screen. Nothing in `resources/js` handles Inertia's `invalid` event today, so every non-Inertia error response gets that modal. Classic does the same today.

## Where the shared handler sits
One small module, `resources/js/lib/v2-refused-request.ts`, imported next to the other V2 boot code in `app.ts` (the same place the Back guard module is imported). It listens once to Inertia's global `invalid` event, which fires when a response is not an Inertia response. It acts only when `<html data-ui="v2">` is set, and does nothing on Classic. Pages and dialogs do not change.

## Which status codes it catches
- **403**: yes. This is the refused action.
- **419** (session or CSRF expired): yes, with its own sentence and the Reload button, because reloading is the right fix and the person's work is not saved.
- **409**: no. Inertia uses it for an asset-version change and reloads by itself, and KPOne reports a stale record as a normal validation error (422), which pages already show.
- **500 and other 5xx, 404**: no. The default page stays, because a server fault or a missing page is not something the person can fix from the form.
For the codes it catches it calls `preventDefault()`, so the raw page never opens.

## The exact wording (Daniel's wording, folded in)
- 403: "You don't have permission to do this. Nothing was changed."
- 419: "This page was open too long, so nothing was saved. Reload the page and try again."
Checked against Classic today: Classic's 403 is the standalone page that says only "403" and "Forbidden" (resources/views/errors/403.blade.php), and there is no custom 419 page (Laravel's default "Page Expired"). So the new sentences add words and do not contradict Classic. Staff-specific wording is not used, because the handler cannot know the page.

## How it looks
- **Tone:** warning tokens (not destructive red) with a dot or icon plus the words, never colour alone.
- **Reload button:** outline, shown on **419 only**. Reloading does not fix a 403, so the 403 line has no button.
- **In a form:** the line (role="alert") at the top of the main column above the first field; typed values are kept.
- **In a dialog (V2Dialog open):** the same line inside the dialog body above the footer; the dialog stays open and the filled action stays as it was. On 419 the outline "Reload page" button sits in the line.
- Focus does not move; the alert is announced. 44 px / 36 px sizes.

## What Classic keeps
Nothing changes in Classic: the handler returns on its first line unless `data-ui="v2"`, so Classic still shows Inertia's modal exactly as today. Verified the same way as the Back guard: a later listener on Classic still gets the event. No existing assertion should change. A new source-pin test, plus real-app shots of a refused request on Staff edit (V2, form) and the role dialog (V2, dialog), at 390 and 1440.

## Open decisions for the owner
1. 403 only, or 403 and 419 (Daniel and I: both).
2. The Reload button on 419 only (Daniel; reloading does not fix a 403).
3. Whether Classic should get the same handler later (out of this plan).
