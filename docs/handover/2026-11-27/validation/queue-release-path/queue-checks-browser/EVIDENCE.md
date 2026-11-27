# Populated Medicine-check evidence

27 November 2026. Product source is unchanged, clean reviewed context #1223 at cc73b15203f774c25a48f9a6a5af2eed8b12b015. Preview3175 uses only kpone_queue_checks_preview_test, a separate synthetic clone. Catalogue/order instructions are explicitly NOT FOR CLINICAL USE.

Independent Dispensary review preceded one-shot preparation. Actual inventory batch/opening balance, branch setting, Queue call, encounter, allergy, treatment plan, handoff, Dispensary prepare and send-for-doctor-check services produced one pending case. No Verify, handover, new grant, direct clinical status or balance write occurred. Preparation intentionally changes synthetic state; the read-only baseline was captured afterwards.

Domain membership: own doctor1 with existing canOpenEncounter, other doctor0, CA0, Director0. The Queue response excludes medicine lines/items/sender. Actual browser Needs me/Medicine checks shows one populated row and the existing Open Consultation menu entry. The action is an existing versioned POST encounter entry; it was not invoked. Its action-menu trigger remains focusable/hit-testable below the persistent emergency host at390x640 dark and1440x1000 light. No horizontal overflow or page errors.

Seven clinical/stock row sets (visit, queue, encounter, case, item, allocation and stock balance) compare byte-identically after reads. Saved state-before/state-after files are local synthetic evidence. This proves a populated list and existing access path, not canonical Items/Sent by columns or a complete medicine verification workflow.

Two initial harness failures are retained: wrong PHP router path prevented login; the first action locator incorrectly expected a direct button instead of the existing dropdown menu. Corrected harness then passed; neither required product changes. Additional captures show the deliberately scrolled action position below the fixed alert host; they do not claim the whole long row fits one short viewport.
