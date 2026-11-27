Aligns the existing Dispensary settings switch with the Clean Look specification: a green44×26 track and22px knob within a44px keyboard/touch target, with the existing On/Off text and accessibility preserved. Removes repeated branch text from the description while retaining the shared branch subline.

Stacked on #1195. The page script, settings mutation, metadata and navigation authority are unchanged. One existing description assertion is faithfully updated and two runtime tests cover the compact switch.

Validation:13 focused frontend tests, full types/build and scoped lint/format pass. Twelve synthetic on/off captures across390/768/1440 and both themes verify exact dimensions, branch once and no overflow. Real keyboard toggle restores the initial state; stale-tab refusal remains. Independent source/visual review clear. No merge or production action.
