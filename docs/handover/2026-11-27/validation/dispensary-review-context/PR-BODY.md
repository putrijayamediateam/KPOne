Makes the existing read-only Dispensary review easier to scan: patient identity, visit and explicitly labeled case branch have a wrapping context card; Case and Allergies use the common card layout. Back to visit remains the only control. Existing clinical facts, unknown/null states and all review policy/audit behavior are unchanged.

Validation:7 frontend tests (5 existing unchanged),12 backend tests/130 assertions, full types/build and scoped lint/format. Synthetic browser checks cover long context, OTC and completed cases at390/1440 in light/dark,44/36 Back targets and no page overflow. Existing direct crossbranch404 is preserved; positive different-branch cases use the authorized branch switcher, then restore the initial selection. No clinical writes or new authority.

Independent source/visual review clear. This bounded header/context slice does not claim full Review page parity. No merge or production action.
