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

**shell-\*:** a page without PageFrame (today's Classic Quick create inside the V2 shell). The fallback "Front desk"
is drawn by the shell at PageFrame's padding (16 px, then 32 px from 1024 px), because this Classic page has its own
max-w-5xl container. It lines up exactly once the page uses PageFrame or places `<V2PageCrumbs in-frame />`.
