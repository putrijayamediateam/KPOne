Supplier invoices now uses a responsive match worklist over the existing scoped, paginated projection. Invoice/order/status summaries keep amounts behind the server cost flag; persistent details retain original match evidence and independent decision actions. Pending commands lock selection/status/pagination without changing backend authority or money rules.

Stacked on #1226. No backend, query, migration or grant change. Parent command script changes only by importing the presentation component.

Validation: 2,276 frontend tests, types, scoped lint, resource format, production build and 10 existing backend tests / 69 assertions pass. Seven new runtime tests independently pass, including hidden raw totals, zero/missing values, busy/focus and captured decision version. One exact whitespace-only source pin was independently reviewed.

Actual synthetic UI proves Finance recording/self-decision refusal, CA quantity-only visibility, independent Director acceptance and stale decision refusal with dialog retained. All balance quantities and 24 stock movements unchanged. Six viewport/theme states have 44px mobile/tablet and 36px desktop owned controls, no overflow or page errors. Focus restoration is runtime-tested; actual keyboard disclosure is browser-tested.

Frozen five-file patch34c60e4435a3da8d644a065bfdd9fb1e49086be9e8b1758da6ef151b17033bbe. Evidence/reviews: `/workspace/kpone-validation/stock-supplier-invoice-worklist/`. Broader navigation and due/paid metadata remain separate; no production execution or merge.
