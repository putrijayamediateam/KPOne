# UX-20 breadcrumb shots (never merge)

Version 2 breadcrumb. Device scale 2, top 260 px of each page.

**frame-\*:** a PageFrame page whose trail is set with `usePageCrumbs` ("Registration > KPV-00000008"). This is a
temporary local harness on the Clinic management page, which is not in the PR.

| Width | crumb x | h1 x | crumb-to-h1 gap |
|---|---|---|---|
| 390 | 16 | 16 | 8 px |
| 1024 | 272 | 272 | 8 px |
| 1440 | 424 | 424 | 8 px |

The figures are the same in light and dark. After a client-side visit to /staff, the trail falls back to the shell
label, "Management".

**shell-\*:** a page without PageFrame: today's Classic Quick create, shown inside the V2 shell. The shell draws the
fallback "Front desk" at the side padding most pages use today (16 px, then 24 px from 768 px).

| Width | crumb x | h1 x |
|---|---|---|
| 390 | 16 | 16 |
| 1024 | 264 | 264 |
| 1440 | 264 | 352 |

At 1440 px this page centres its own max-w-5xl container. It lines up there once the page uses PageFrame, or places
`<V2PageCrumbs in-frame />` itself.

**focus-\*:** "Back to Classic" with keyboard focus. It now has a full-strength 2 px ring: 6.43:1 in light and 6.69:1
in dark. The shared Button's 40% ring measured 1.95:1 and 2.16:1.

**Not here yet:** Registration board, Register visit, visit page and Edit visit. Those V2 pages are Nurul's; they are
not on main, and they need `usePageCrumbs` for "Registration > KPV-…". The shots follow once they use it.

**collapse-\*:** a three-item harness trail, "Registration › KPV-00000008 › Edit visit".
- Below 640 px it shows "… › Edit visit". The "…" is a 44 × 44 px link to the nearest earlier page, named
  "Back to KPV-00000008".
- At 1440 px the full trail shows.
- Two-item trails are unchanged and still wrap on phones.

**crumbfocus-\*:** keyboard focus on a crumb link. A local 2 px ring measures 5.95:1 in light and 7.47:1 in dark
against the page. The shared Button is untouched (DD-74).
