# F24 switch and branch presentation

Base67a64dd5f / draft1195. Isolated tree KPOne-dispensary-settings-switch.

Actual Settings still uses pink bg-brand and repeats branch in its description despite shared PageHeader branch subline. Locked DispensarySettings.dc.html shows compact track; CLEAN-LOOK-SPEC section4 requires44x26 track,22knob,success on fill and On/Off word. Preserve a44px button hit area around compact track, all aria/disabled/click bindings and exact script. Header description becomes scope-only; shared branch marker remains authoritative.

Exact proposed files: resources/js/pages/Dispensary/Settings.vue; new tests/Frontend/dsp-settings-switch-runtime.test.mjs; docs/teams/dispensary-otc/TEAM.md. No backend, payload, metadata or link authority edits; no existing assertion edits expected.

Risks: track versus hit-area confusion, keyboard focus visibility, duplicate/lost branch context, knob contrast dark mode. New actual template rendering checks On/Off/busy/aria; scoped existing tests, lint/format/types/build. Actual synthetic390/768/1440 light/dark on/off screenshots, measured44hit/44x26track/22knob, keyboard toggle+restore and stale refusal. Review source script parity and actual branch marker once. No production or clinical case mutation; no full page parity claim.
