# HOME-CL1 — review-ready bounded visual slice

Fresh implementation in /workspace/KPOne-homes, feature/clean-look-homes-continue, base23639679e. Root Stage0 frozen source untouched. No commit/push/PR. Exact patch: HOME-CL1.patch.

## Plan and final change

Retain My day's Today → waiting decisions → routine → week/credentials → shortcuts, Front desk masked five-row projections, and Consultation waiting/on-hold blocks. No new destinations, widgets or placeholder counts. Reuse shared calm surfaces and existing primary actions.

Three files: resources/css/space-shell-layout.css, resources/js/pages/MyDay/Index.vue, resources/js/pages/Spaces/Show.vue. Existing Classic space bridge now maps accent/tab underline to primary, quiet surface to card-quiet, and personal gradient/tile labels/tints to semantic Clean Look variables. MyDay and only Front desk/Consultation card sections adopt shared shadow-card with visible border. No new theme/switch/wrapper or token system.

## Risk and boundaries

Shared Classic bridge affects MyDay and every department rendered through Spaces/Show—the only two .space-shell consumers. All inherit blue accents (whole-app pink-logo-only rule) and semantic personal-home surfaces. Other department layouts remain unchanged. Both changed Vue script blocks match base byte-for-byte. Props, masking, limits, permissions, routes, landing, actions and handlers unchanged. No package/dependency or existing assertion changes. Preview uses main shell; combined frozenStage0+HOME integration is a later integration check, not claimed here.

## Validation

-35 existing focused tests pass: ds-0-space-tokens, ds-1-space-shell, classic-only-co1, ux-24-part2-spaces, ux-24-v2-spaces, ds-13-me-home. Classic tests rerun unchanged after tab fix.
-Scoped Vue ESLint and three-file Prettier check pass; git diff --check pass.
-TypeScript pass, /tmp/kpone-home-types.log.
-Final exact-source production build pass, /tmp/kpone-home-build-final.log; existing runtime font-resolution warning only. Heavy gates serial with team queue.
-32 before/after captures (three homes plus resident-doctor Consultation,1440/390 light/dark). Final after states refreshed from exact final build; browser-final.log exit0, HTTP200/no overflow/no JavaScript errors. capture-final.mjs and capture-doctor-final.mjs repeatable.
-Independently viewed MyDay390 dark, Front desk1440 light and doctor Consultation390 dark. Final Front desk underline visually verified blue. Director Consultation correctly shows role fallback; separate resident-doctor evidence shows real Waiting/On hold blocks.

Self-review corrections: muted text on info wash measured4.43:1, so personal gradient/tile secondary label uses foreground. Found/fixed direct legacy pink tab underline outside bridge. Empty synthetic queues only; populated masked rows are preserved source, not demonstrated anew. Preview3025 own vendor/generated/build; same synthetic registration DB as baseline3021 with login session/cache writes only, no clinic record mutations.

## Proposed commit / PR

Commit: restyle(homes): reuse Clean Look tokens on existing home surfaces

PR: Existing personal/department homes retained pink accents and separate gradient colours after the shared shell restyle. Reuse Clean Look primary/quiet/status tokens through the existing Classic bridge and apply shared card treatment to My day, Front desk and Consultation. Preserve every projection, permission, destination and action. Validated35 focused tests, scoped lint/format, types/build and32 responsive theme captures; combinedStage0 integration remains separate.
