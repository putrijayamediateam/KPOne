# Stock Batch & expiry review packet

Candidate `/workspace/KPOne-stock-batch`, branch `feature/stock-batch-demo`, stacked on Stock `456ab8feb459e194255ffe193d0f28fe2d6b749e`. Frozen8-file patch `STOCK-BATCH.patch`, SHA256 `75c123bab67ea772971ade3fa79620b252f3386302beb3c9b3b0617c8432a14f`.

Replaces only Batch & Expiry's horizontal table with readable batch/location disclosure rows. Exact existing fields, separate expiry and held statuses, zero/fractional units, missing received date, reason fallback, bounded server filters/paging and authority remain. Index script excluding imports is byte-identical (`SCRIPT-PARITY.txt`). The new component has no mutation/report action. Local Location picker sizing corrects inherited36px mobile controls to44px, retaining36px desktop. No shared component, backend, permission, schema or query changes.

Four existing source-location test adaptations are itemized in `ASSERTION-DECISION.md`; no assertion behavior or denial is removed. Quantity and formatter ownership checks now cover both extracted components. Coordinator authorized this follow-on and delegated technical repairs; these are not invented additional owner tickboxes.

Final gates:

- Full frontend2215/2215 pass, including7 new rendered/focus tests. Final local target-sizing class was subsequently checked by24 focused tests, scoped ESLint/format checks, final full vue-tsc and final production build, all exit0. Full repository lint/resource format passed earlier in this slice; final edited paths were checked again.
- Native browser DOM fixture compiles the actual StockBatchList with real Vue; only icon/status visual children are stubbed. Surviving trigger, removed-row fallback, loading fallback, external focus before update and external focus during pending restoration all pass. `browser/native-focus.json` explicitly identifies this fixture separately from the real application.
- Real synthetic server page at3124:390/1440 light/dark,13 batch/location records, expired+held same row, valid rows and null received detail, Enter disclosure, server Batch filter returning1 row, expansion reset, Clear and return to On-hand. No new row actions, no browser errors or horizontal overflow. Opened Location picker and all measured owned controls are44px mobile/36px desktop. Screens and `browser/results.json` are final build evidence.
- No new backend implementation or backend assertion changes; no fresh full backend rerun claimed locally. Full exact-head CI is required after publication. Existing13-record fixture exercises one page only; server pagination code is unchanged, with row replacement covered in runtime tests.

Independent designer reviewed source, four faithful source-pin repairs and rendered focus tests, plus initial mobile/desktop images; final exact-hash/target measurement closure is recorded separately before publication. No merge or production authorization. This slice does not complete broader Expiring and held workflows, other Stock pages, or a SKU aggregation model.
