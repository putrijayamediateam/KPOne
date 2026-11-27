# F24 authorized waiting-case navigation

Next bounded actual demo gap after1191: Settings demo provides See cases waiting for the doctor's check. Settings.manage alone is not list authority. Current /dispensary GET middleware accepts view.branch or view_readonly.branch; its DispensaryListService::page additionally delegates DispensaryDirectoryService::canView and uses the actual TAB_CHECK='check'.

Proposed separate nullable waitingCasesUrl from the freshly authorized SettingsReader actor, only when DispensaryDirectoryService::canView returns true. Generate actual dispensary.index route with TAB_CHECK. Existing branch validation already scopes the reader; destination continues its own unchanged server policy and branch filtering. No new count/query/grant, no staff role assumption. Director settings access must not imply list access; positive existing CA Supervisor and negative Director tests, plus actual navigation to the check tab.

UI renders a44/36 Inertia link only for server-supplied URL after existing setting/context cards. Add optional prop/import only; switch script/payload/errors/lastChanged untouched. Do not add counts, all-branch/Ready behavior, return checking or a fake action. No existing assertion change expected: existing no-links test default remains waitingCasesUrl:null; add positive link and null/omission tests. Reader metadata2keys/settings2keys remain exact, new navigation prop separate.

Scope: reader, Settings.vue, focused backend/frontend tests, TEAM.md. Stacked on1191; independent source/design review, relevant backend/static/type/build and actual positive/negative responsive navigation proof. No production/merge. Existing pink switch and duplicate branch text are separate presentation debt, excluded from this navigation contract.
