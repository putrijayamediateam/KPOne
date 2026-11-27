# SUG-1b independent responsive preflight

Read-only source review of original6f68902e80ff3e9424e7cfa2b4f15e7f459e3544 and effective current-main shared Button sizing. No runtime or final release clearance.

Concrete minimal repairs for refresh:
- Phrases native role=switch uses h-7 on the interactive element, not merely its decorative track; no enlarged wrapper exists. Use a44px below-lg/36px desktop button hitbox with the28px visual track inside, visible keyboard focus and semantic thumb token instead of bg-white. Preserve toggle command, state and aria labels.
- Native search h-11 md:h-9 becomes36px at768; change md to lg to preserve44px below1024.
- Page-local size=sm buttons inherit32px desktop and need min-h-9 for the agreed36px. Shared Button already supplies max-lg:h-11, so max-sm:min-h-11 on those components is redundant and is not by itself evidence of a tablet defect. Measure actual merged CSS before broadening repairs.
- No duplicate-main allegation: AdminLayout's landmark role is dynamic. Verify rendered landmark only after refresh.

Keep original clinical permissions, migration and approved assertions intact. Proposed description refresh should name actual frequency/instruction/precaution/duration kinds. Full demo composition remains separate; this preflight does not authorize a new page framework or clinical behavior.
