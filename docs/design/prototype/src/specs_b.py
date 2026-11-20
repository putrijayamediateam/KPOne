"""Stock, purchasing and catalogue screens (fictional data)."""
from specs_a import R, F

SPECS = []
STOCK = ['Stock', 'Stock.dc.html']

SPECS.append(dict(
    id='PurchaseRequests', kind='list', title='Purchase requests', area='stock', crumbs=[STOCK, 'Purchase requests'],
    search='Item or request number', header_actions=[dict(label='Reorder suggestions', href='ReorderSuggestions.dc.html'), dict(label='New request', primary=True, toast='New request started for Cheras (prototype).')],
    stats=[['5', 'waiting for approval', 'wait'], ['3', 'approved, no PO yet', 'appr'], ['2', 'turned into POs', 'po'], ['1', 'returned', 'ret']],
    table_title='Requests', table_note='A request becomes a purchase order only after approval by someone else',
    filters=[['all', 'All'], ['wait', 'Waiting'], ['appr', 'Approved'], ['po', 'PO raised'], ['ret', 'Returned']],
    cols=[['Request', '120px'], ['Items', 'minmax(200px,2fr)'], ['Supplier', 'minmax(120px,1fr)'], ['Estimate', '110px', 'r'], ['Status', '160px']], statusCol=4, min_width=760,
    rows=[
        R('r1', 'wait', ['PR-0412', {'t': 'Paracetamol 500 mg, Cetirizine 10 mg', 's': '2 lines · from reorder suggestions'}, 'MedSupply (fictional)', 'RM 418.00', ['w', 'Waiting']], [['Requested by', 'Siti (CA Supervisor)'], ['Needed by', '24 Nov 2026']], actions=[dict(label='Approve', set=['ok', 'Approved', 'appr']), dict(label='Return with a question', set=['r', 'Returned', 'ret'])]),
        R('r2', 'wait', ['PR-0411', {'t': 'Salbutamol inhaler', 's': '1 line · below reorder level'}, 'PharmaLink (fictional)', 'RM 96.00', ['w', 'Waiting']], [['Requested by', 'Siti'], ['Stock now', '3 inhalers']], actions=[dict(label='Approve', set=['ok', 'Approved', 'appr'])]),
        R('r3', 'appr', ['PR-0409', {'t': 'Gauze swab 10 x 10, Normal saline 500 ml', 's': '2 lines · treatment room'}, 'MedSupply (fictional)', 'RM 152.40', ['ok', 'Approved']], [['Approved by', 'Mei Lin (Finance)']], actions=[dict(label='Raise purchase order', href='PurchaseOrders.dc.html')]),
        R('r4', 'po', ['PR-0405', {'t': 'Amoxicillin 250 mg', 's': '1 line'}, 'PharmaLink (fictional)', 'RM 210.00', ['i', 'PO-CHR-0188']], [['PO', 'PO-CHR-0188 · sent']], actions=[dict(label='Open PO print', href='PurchaseOrderPrint.dc.html')]),
        R('r5', 'ret', ['PR-0403', {'t': 'Omeprazole 20 mg', 's': '1 line'}, 'MedSupply (fictional)', 'RM 88.00', ['r', 'Returned']], [['Question', 'Transfer from Puchong instead?']]),
        R('r6', 'wait', ['PR-0402', {'t': 'Diclofenac gel 1%', 's': '1 line'}, 'PharmaLink (fictional)', 'RM 64.00', ['w', 'Waiting']], [['Requested by', 'Hafiz (CA)']], actions=[dict(label='Approve', set=['ok', 'Approved', 'appr'])]),
    ],
    detail_label='Request',
    notes=[dict(title='Who approves', items=[['Approver', 'Finance Officer or Director, never the requester'], ['Estimates', 'Labelled as estimates until the supplier invoice arrives']])],
))

SPECS.append(dict(
    id='ReorderSuggestions', kind='list', title='Reorder suggestions', area='stock', crumbs=[STOCK, 'Reorder suggestions'],
    header_actions=[dict(label='Turn ticked into a request', primary=True, href='PurchaseRequests.dc.html')],
    stats=[['7', 'below reorder level', 'low'], ['3', 'cover from another branch', 'tr'], ['2', 'slow moving', 'slow'], ['14 days', 'lead time used']],
    table_title='Suggestions for Cheras', table_note='Reorder level minus stock now, rounded up to the pack size',
    filters=[['all', 'All'], ['low', 'Order'], ['tr', 'Transfer'], ['slow', 'Slow moving']],
    cols=[['Item', 'minmax(200px,2fr)'], ['Stock now', '100px', 'r'], ['Level', '80px', 'r'], ['Suggest', '120px', 'r'], ['Action', '150px']], statusCol=4, min_width=720,
    rows=[
        R('s1', 'low', [{'t': 'Paracetamol 500 mg', 's': 'Dispensary + store'}, '420', '1,000', '600 tablet', ['w', 'Order']], [['Pack', 'Box of 100'], ['Usage', 'About 80 a day']], actions=[dict(label='Add to request', set=['ok', 'Ticked', 'low']), dict(label='Dismiss', set=['n', 'Dismissed', 'low'])]),
        R('s2', 'low', [{'t': 'Salbutamol inhaler', 's': 'Dispensary'}, '3', '10', '7 inhaler', ['w', 'Order']], [['Supplier', 'PharmaLink (fictional)']], actions=[dict(label='Add to request', set=['ok', 'Ticked', 'low'])]),
        R('s3', 'tr', [{'t': 'Cetirizine 10 mg', 's': 'Puchong has 900 spare'}, '80', '300', '220 tablet', ['i', 'Transfer']], [['From', 'Puchong stays above its own level']], actions=[dict(label='Request transfer', href='Transfers.dc.html')]),
        R('s4', 'low', [{'t': 'Gauze swab 10 x 10', 's': 'Treatment room'}, '40', '200', '160 piece', ['w', 'Order']], [['Pack', 'Pack of 100']], actions=[dict(label='Add to request', set=['ok', 'Ticked', 'low'])]),
        R('s5', 'slow', [{'t': 'Ranitidine 150 mg', 's': 'No dispensing for 74 days'}, '260', '100', '—', ['n', 'Review']], [['Suggestion', 'Stop reordering from 120 days']]),
        R('s6', 'tr', [{'t': 'Omeprazole 20 mg', 's': 'Sungai Besi has 400 spare'}, '84', '200', '116 capsule', ['i', 'Transfer']], [['From', 'Sungai Besi']], actions=[dict(label='Request transfer', href='Transfers.dc.html')]),
    ],
    detail_label='Suggestion',
    notes=[dict(title='Hana (AI agent)', items=[['07:00 and 14:00', 'Suggestions refresh twice a day'], ['Approval', 'An approval records the decision only; nothing moves by itself']])],
))

SPECS.append(dict(
    id='Recalls', kind='list', title='Recalls', area='stock', crumbs=[STOCK, 'Recalls'], badge=['r', '1 active recall'],
    header_actions=[dict(label='New recall', primary=True, toast='New recall: choose the batch and the reason (prototype).')],
    stats=[['1', 'active recall', 'act'], ['3', 'branches on hold', 'act'], ['412', 'units held'], ['1', 'closed this year', 'done']],
    table_title='Recall holds across branches', table_note='A recall places one hold per branch on the batch',
    filters=[['all', 'All'], ['act', 'Active'], ['done', 'Closed']],
    cols=[['Batch', 'minmax(200px,2fr)'], ['Branch', '120px'], ['On hand', '90px', 'r'], ['Patients given', '120px', 'r'], ['Status', '150px']], statusCol=4, min_width=720,
    rows=[
        R('h1', 'act', [{'t': 'Amoxicillin 250 mg', 's': 'Batch AM-2207 · exp 03/2027'}, 'Cheras', '94', '6', ['r', 'On hold']], [['Reason', 'Supplier notice: wrong strength label'], ['Placed by', 'Siti · 18 Nov 09:10']], actions=[dict(label='Return to supplier', href='SupplierReturns.dc.html'), dict(label='Release hold', toast='Release needs a different person (prototype).')]),
        R('h2', 'act', [{'t': 'Amoxicillin 250 mg', 's': 'Batch AM-2207'}, 'Puchong', '210', '11', ['r', 'On hold']], [['Placed by', 'Recall from Cheras']]),
        R('h3', 'act', [{'t': 'Amoxicillin 250 mg', 's': 'Batch AM-2207'}, 'Sungai Besi', '108', '3', ['r', 'On hold']], [['Placed by', 'Recall from Cheras']]),
        R('h4', 'done', [{'t': 'Cough syrup 100 ml', 's': 'Batch CS-0021'}, 'All branches', '0', '0', ['ok', 'Closed']], [['Closed', '2 Jun 2026'], ['Outcome', 'Returned to supplier, credit note received']]),
    ],
    detail_label='Hold',
    notes=[dict(title='Patients who received the batch', items=[['20 dispensing lines', 'List for the doctor to decide on contact'], ['No messages sent', 'KPOne never contacts patients by itself']])],
))

SPECS.append(dict(
    id='BatchHolds', kind='list', title='Batch holds', area='stock', crumbs=[STOCK, 'Batch holds'],
    header_actions=[dict(label='Place a hold', primary=True, toast='Choose the batch and a reason (prototype).')],
    stats=[['4', 'batches on hold', 'on'], ['1', 'quarantined on receipt', 'q'], ['2', 'released this month', 'off']],
    table_title='Holds at Cheras', table_note='A held batch cannot be dispensed, picked or used in treatment',
    filters=[['all', 'All'], ['on', 'On hold'], ['q', 'Quarantine'], ['off', 'Released']],
    cols=[['Batch', 'minmax(200px,2fr)'], ['Reason', 'minmax(140px,1.2fr)'], ['Placed', '120px'], ['Status', '140px']], statusCol=3,
    rows=[
        R('b1', 'on', [{'t': 'Amoxicillin 250 mg', 's': 'AM-2207 · 94 capsule'}, 'Recall', 'Siti · 18 Nov', ['r', 'On hold']], [['Release by', 'Finance Officer or Director, not the placer']], actions=[dict(label='Release', set=['ok', 'Released', 'off'])]),
        R('b2', 'q', [{'t': 'Normal saline 500 ml', 's': 'NS-7710 · 12 bag'}, 'Damaged packaging', 'Receiving · 17 Nov', ['q', 'Quarantine']], [['From', 'Delivery DO-55120']], actions=[dict(label='Release', set=['ok', 'Released', 'off']), dict(label='Write off', href='WriteOff.dc.html')]),
        R('b3', 'on', [{'t': 'Diclofenac gel 1%', 's': 'DG-0219 · 8 tube'}, 'Temperature excursion', 'Siti · 12 Nov', ['r', 'On hold']], [['Note', 'Fridge log missing for 2 days']], actions=[dict(label='Release', set=['ok', 'Released', 'off'])]),
        R('b4', 'on', [{'t': 'Cetirizine 10 mg', 's': 'CE-0870 · 40 tablet'}, 'Expiry check', 'Aina R. · 10 Nov', ['r', 'On hold']], [['Note', 'Label smudged']], actions=[dict(label='Release', set=['ok', 'Released', 'off'])]),
        R('b5', 'off', [{'t': 'Loratadine 10 mg', 's': 'LO-1102'}, 'Expiry check', 'Hafiz · 2 Nov', ['ok', 'Released']], [['Released by', 'Mei Lin (Finance) · 3 Nov']]),
    ],
    detail_label='Hold',
))

SPECS.append(dict(
    id='StockValue', kind='list', title='Stock value', area='stock', crumbs=[['Reports', 'Reports.dc.html'], 'Stock value'],
    banner=['w', 'Management report · cost policy to be confirmed by the clinic\'s accountant', 'Weighted average cost from supplier invoices. Estimates are shown separately and are never mixed with actual cost.'],
    header_actions=[dict(label='Journal summary CSV', toast='Downloaded journal-summary-2026-10.csv (prototype). No accounting integration.'), dict(label='Download CSV', primary=True, toast='Downloaded stock-value-2026-11-19.csv (prototype).')],
    stats=[['RM 48,210', 'stock value'], ['RM 6,880', 'cost of goods this month'], ['RM 412', 'losses (write-off, expiry)', 'loss'], ['3', 'items on estimates', 'est']],
    table_title='Value by item · Cheras', table_note='Director only',
    filters=[['all', 'All'], ['est', 'On estimates'], ['loss', 'With losses']],
    cols=[['Item', 'minmax(200px,2fr)'], ['On hand', '100px', 'r'], ['Avg cost', '100px', 'r'], ['Value', '110px', 'r'], ['Cost basis', '130px']], statusCol=4, min_width=720,
    rows=[
        R('v1', 'all', [{'t': 'Paracetamol 500 mg', 's': 'tablet'}, '1,820', 'RM 0.04', 'RM 72.80', ['ok', 'Actual']], [['Last invoice', 'INV-MS-8812']]),
        R('v2', 'all', [{'t': 'Amoxicillin 250 mg', 's': 'capsule'}, '412', 'RM 0.22', 'RM 90.64', ['ok', 'Actual']], [['On hold', '94 (recall)']]),
        R('v3', 'est', [{'t': 'Salbutamol inhaler', 's': 'inhaler'}, '3', 'RM 13.70', 'RM 41.10', ['w', 'Estimate']], [['Why', 'Opening balance without a supplier cost']]),
        R('v4', 'loss', [{'t': 'Normal saline 500 ml', 's': 'bag'}, '36', 'RM 3.10', 'RM 111.60', ['ok', 'Actual']], [['Written off', '12 bag · RM 37.20']]),
        R('v5', 'est', [{'t': 'Gauze swab 10 x 10', 's': 'piece'}, '40', 'RM 0.18', 'RM 7.20', ['w', 'Estimate']], [['Why', 'PO estimate, invoice not matched yet']]),
        R('v6', 'all', [{'t': 'Omeprazole 20 mg', 's': 'capsule'}, '84', 'RM 0.35', 'RM 29.40', ['ok', 'Actual']], []),
    ],
    detail_label='Item',
    notes=[dict(title='What the numbers mean', items=[['Value', 'On hand × weighted average cost'], ['COGS', 'Cost of what was dispensed and used']])],
))

SPECS.append(dict(
    id='StockHealth', kind='list', title='Stock health', area='stock', crumbs=[['Reports', 'Reports.dc.html'], 'Stock health'],
    header_actions=[dict(label='Last 30 days'), dict(label='All branches')],
    stats=[['96.4%', 'lines filled first time'], ['2.1%', 'expiry loss', 'bad'], ['31 days', 'average stock cover'], ['4', 'stock-outs', 'bad']],
    table_title='Measures by branch', table_note='Last 30 days',
    filters=[['all', 'All'], ['bad', 'Needs attention']],
    cols=[['Measure', 'minmax(200px,2fr)'], ['Cheras', '110px', 'r'], ['Puchong', '110px', 'r'], ['Sungai Besi', '110px', 'r'], ['Trend', '130px']], statusCol=4, min_width=720,
    rows=[
        R('k1', 'all', ['Lines filled first time', '96.4%', '97.8%', '95.1%', ['ok', 'Steady']], [['Meaning', 'Dispensed without a substitute or shortfall']]),
        R('k2', 'bad', ['Expiry loss (value)', '2.1%', '0.8%', '1.2%', ['w', 'Up']], [['Biggest', 'Normal saline at Cheras']]),
        R('k3', 'all', ['Average stock cover', '31 days', '27 days', '35 days', ['ok', 'Steady']], []),
        R('k4', 'bad', ['Stock-outs', '4', '1', '2', ['w', 'Up']], [['Items', 'Salbutamol, Gauze, Cetirizine, Omeprazole']]),
        R('k5', 'all', ['Counts done on time', '92%', '88%', '95%', ['ok', 'Steady']], []),
        R('k6', 'all', ['Supplier on-time deliveries', '84%', '90%', '86%', ['i', 'Down']], []),
    ],
    detail_label='Measure',
))

SPECS.append(dict(
    id='ControlledRegister', kind='list', title='Controlled-drug register', area='stock', crumbs=[STOCK, 'Controlled register'], badge=['w', 'Pending pharmacist review'],
    header_actions=[dict(label='Print register', toast='Opens the printable register for the month (prototype).'), dict(label='New entry', primary=True, toast='Entries come from receiving, dispensing and write-offs; a manual entry needs a reason (prototype).')],
    stats=[['3', 'controlled items'], ['18', 'entries this month'], ['0', 'differences', 'diff'], ['1', 'review due', 'rev']],
    table_title='Codeine linctus 100 ml · November', table_note='Every entry keeps who, when and the running balance',
    filters=[['all', 'All'], ['in', 'Received'], ['out', 'Supplied'], ['rev', 'To review']],
    cols=[['Date', '110px'], ['Entry', 'minmax(200px,2fr)'], ['In', '70px', 'r'], ['Out', '70px', 'r'], ['Balance', '90px', 'r'], ['Check', '130px']], statusCol=5, min_width=760,
    rows=[
        R('e1', 'in', ['3 Nov 2026', {'t': 'Received · DO-55031', 's': 'Batch CL-0091 · Siti'}, '20', '', '28', ['ok', 'Checked']], [['Witness', 'Mei Lin']]),
        R('e2', 'out', ['5 Nov 2026', {'t': 'Supplied · KPV-00003980', 's': 'Dr. Lim · Hafiz'}, '', '1', '27', ['ok', 'Checked']], []),
        R('e3', 'out', ['9 Nov 2026', {'t': 'Supplied · KPV-00004011', 's': 'Dr. Farah · Aina R.'}, '', '1', '26', ['ok', 'Checked']], []),
        R('e4', 'rev', ['14 Nov 2026', {'t': 'Monthly balance check', 's': 'Counted 26 · register 26'}, '', '', '26', ['w', 'Review due']], [['Reviewer', 'Pharmacist (to be named)']], actions=[dict(label='Mark reviewed', set=['ok', 'Reviewed', 'all'])]),
        R('e5', 'out', ['18 Nov 2026', {'t': 'Supplied · KPV-00004087', 's': 'Dr. Lim · Hafiz'}, '', '1', '25', ['ok', 'Checked']], []),
    ],
    detail_label='Entry',
    notes=[dict(title='Draft', items=[['Format', 'Pending pharmacist review; the legal register format is not confirmed'], ['Quarantine', 'Controlled medicines are never quarantined on return']])],
))

SPECS.append(dict(
    id='SupplierInvoices', kind='list', title='Supplier invoices', area='stock', crumbs=[STOCK, 'Supplier invoices'],
    header_actions=[dict(label='Add invoice', primary=True, toast='Enter the invoice number, date, lines and total (prototype).')],
    stats=[['4', 'to match', 'm'], ['2', 'differences', 'x'], ['11', 'matched this month', 'ok'], ['No payment', 'in KPOne']],
    table_title='Three-way match', table_note='Purchase order, delivery and invoice must agree',
    filters=[['all', 'All'], ['m', 'To match'], ['x', 'Differences'], ['ok', 'Matched']],
    cols=[['Invoice', '130px'], ['Supplier', 'minmax(150px,1.4fr)'], ['PO / delivery', 'minmax(150px,1.2fr)'], ['Total', '100px', 'r'], ['Match', '150px']], statusCol=4, min_width=760,
    rows=[
        R('i1', 'x', ['INV-MS-8812', 'MedSupply (fictional)', {'t': 'PO-CHR-0186', 's': 'DO-55120'}, 'RM 431.20', ['w', 'Price differs']], [['PO price', 'RM 4.00 a box'], ['Invoice price', 'RM 4.20 a box'], ['Lines', '1 of 3 differs']], actions=[dict(label='Accept the difference', set=['ok', 'Matched', 'ok']), dict(label='Query supplier', toast='Marked as queried (prototype).')]),
        R('i2', 'x', ['INV-PL-3301', 'PharmaLink (fictional)', {'t': 'PO-CHR-0188', 's': 'DO-55131'}, 'RM 210.00', ['w', 'Quantity differs']], [['Delivered', '20 box'], ['Invoiced', '21 box']], actions=[dict(label='Query supplier', toast='Marked as queried (prototype).')]),
        R('i3', 'm', ['INV-MS-8820', 'MedSupply (fictional)', {'t': 'PO-CHR-0190', 's': 'DO-55140'}, 'RM 152.40', ['n', 'To match']], [], actions=[dict(label='Match', set=['ok', 'Matched', 'ok'])]),
        R('i4', 'ok', ['INV-PL-3290', 'PharmaLink (fictional)', {'t': 'PO-CHR-0181', 's': 'DO-55002'}, 'RM 96.00', ['ok', 'Matched']], [['Matched by', 'Mei Lin']]),
    ],
    detail_label='Invoice',
    notes=[dict(title='Why match', items=[['Actual cost', 'A matched invoice sets the actual cost for the stock value report'], ['Payment', 'Paid outside KPOne']])],
))

SPECS.append(dict(
    id='SupplierTerms', kind='list', title='Supplier price lists', area='stock', crumbs=[['Settings', 'Settings.dc.html'], ['Suppliers', 'Suppliers.dc.html'], 'Price lists and lead times'],
    header_actions=[dict(label='Add price', primary=True, toast='Prices are versioned; the old price stays in history (prototype).')],
    stats=[['3', 'suppliers'], ['42', 'prices'], ['2', 'prices out of date', 'old']],
    table_title='Prices and lead times', table_note='Used for estimates and reorder timing',
    filters=[['all', 'All'], ['old', 'Out of date']],
    cols=[['Item', 'minmax(200px,2fr)'], ['Supplier', 'minmax(140px,1.2fr)'], ['Pack price', '110px', 'r'], ['Lead time', '100px', 'r'], ['Updated', '130px']], statusCol=4, min_width=720,
    rows=[
        R('t1', 'all', [{'t': 'Paracetamol 500 mg', 's': 'Box of 100'}, 'MedSupply (fictional)', 'RM 4.20', '5 days', ['ok', 'Oct 2026']], [['Minimum order', '10 box']]),
        R('t2', 'all', [{'t': 'Amoxicillin 250 mg', 's': 'Box of 100'}, 'PharmaLink (fictional)', 'RM 21.00', '7 days', ['ok', 'Sep 2026']], []),
        R('t3', 'old', [{'t': 'Salbutamol inhaler', 's': 'Each'}, 'PharmaLink (fictional)', 'RM 13.70', '14 days', ['w', 'Mar 2026']], [['Note', 'Older than 6 months']], actions=[dict(label='Mark as checked', set=['ok', 'Checked today', 'all'])]),
        R('t4', 'all', [{'t': 'Gauze swab 10 x 10', 's': 'Pack of 100'}, 'MedSupply (fictional)', 'RM 18.00', '5 days', ['ok', 'Nov 2026']], []),
        R('t5', 'old', [{'t': 'Normal saline 500 ml', 's': 'Carton of 20'}, 'CareMed (fictional)', 'RM 62.00', '10 days', ['w', 'Feb 2026']], [], actions=[dict(label='Mark as checked', set=['ok', 'Checked today', 'all'])]),
    ],
    detail_label='Price',
))

SPECS.append(dict(
    id='BranchReplenishment', kind='list', title='Branch replenishment', area='stock', crumbs=[STOCK, 'Branch replenishment'],
    header_actions=[dict(label='Create transfers', primary=True, href='Transfers.dc.html')],
    stats=[['6', 'suggested moves'], ['Puchong', 'most surplus'], ['Cheras', 'most short']],
    table_title='Between branches', table_note='The sending branch always stays at or above its own reorder level',
    filters=[['all', 'All'], ['s', 'Suggested'], ['d', 'Done']],
    cols=[['Item', 'minmax(200px,2fr)'], ['From', '120px'], ['To', '120px'], ['Quantity', '100px', 'r'], ['Status', '140px']], statusCol=4, min_width=720,
    rows=[
        R('x1', 's', [{'t': 'Cetirizine 10 mg', 's': 'tablet'}, 'Puchong', 'Cheras', '220', ['n', 'Suggested']], [['Puchong after', '680 (level 300)']], actions=[dict(label='Create transfer', set=['ok', 'Transfer created', 'd'])]),
        R('x2', 's', [{'t': 'Omeprazole 20 mg', 's': 'capsule'}, 'Sungai Besi', 'Cheras', '116', ['n', 'Suggested']], [], actions=[dict(label='Create transfer', set=['ok', 'Transfer created', 'd'])]),
        R('x3', 's', [{'t': 'Paracetamol 500 mg', 's': 'tablet'}, 'Puchong', 'Sungai Besi', '300', ['n', 'Suggested']], [], actions=[dict(label='Create transfer', set=['ok', 'Transfer created', 'd'])]),
        R('x4', 'd', [{'t': 'Gauze swab 10 x 10', 's': 'piece'}, 'Puchong', 'Cheras', '100', ['ok', 'Received']], [['Received by', 'Hafiz · 15 Nov']]),
    ],
    detail_label='Move',
))

SPECS.append(dict(
    id='OpeningImport', kind='list', title='Opening stock import', area='stock', crumbs=[STOCK, ['Pilot readiness', 'PilotReadiness.dc.html'], 'Opening stock import'],
    badge=['i', 'opening-cheras.csv · 214 rows'], header_actions=[dict(label='Upload another file'), dict(label='Send for sign-off', primary=True, toast='Sent: a different person signs the opening off (prototype).')],
    stats=[['198', 'ready', 'ok'], ['11', 'need a fix', 'fix'], ['5', 'duplicates', 'dup']],
    table_title='Rows from the file', table_note='Nothing is recorded until the opening is signed off',
    filters=[['all', 'All'], ['ok', 'Ready'], ['fix', 'Need a fix'], ['dup', 'Duplicates']],
    cols=[['Row', '60px', 'r'], ['Item', 'minmax(200px,2fr)'], ['Batch / expiry', '150px'], ['Quantity', '90px', 'r'], ['Check', '170px']], statusCol=4, min_width=720,
    rows=[
        R('o1', 'ok', ['2', 'Paracetamol 500 mg', 'PC-0412 · 12/2026', '80', ['ok', 'Ready']], []),
        R('o2', 'fix', ['7', 'Amoxicillin 250 mg', 'AM-2207 · —', '96', ['r', 'Expiry missing']], [['Rule', 'A real printed expiry date is required']], actions=[dict(label='Mark fixed', set=['ok', 'Ready', 'ok'])]),
        R('o3', 'fix', ['12', 'Cetirizine 10mg box', 'CE-0931 · 01/2027', '2', ['r', 'Unit is a box']], [['Rule', 'Use the base unit (tablet)']], actions=[dict(label='Mark fixed', set=['ok', 'Ready', 'ok'])]),
        R('o4', 'dup', ['19', 'Paracetamol 500 mg', 'PC-0412 · 12/2026', '80', ['w', 'Same as row 2']], [], actions=[dict(label='Skip this row', set=['n', 'Skipped', 'ok'])]),
        R('o5', 'ok', ['23', 'Loratadine 10 mg', 'LO-1180 · 08/2027', '60', ['ok', 'Ready']], []),
    ],
    detail_label='Row',
    notes=[dict(title='Sign-off', items=[['Recorder', 'Siti (CA Supervisor)'], ['Signs off', 'Someone else: Finance Officer or Director']])],
))

SPECS.append(dict(
    id='Skus', kind='list', title='Stock units (SKUs)', area='stock', crumbs=[STOCK, ['Pilot readiness', 'PilotReadiness.dc.html'], 'Stock units'],
    header_actions=[dict(label='Medicine catalogue', href='MedicineCatalogue.dc.html')],
    stats=[['186', 'verified', 'ok'], ['9', 'to verify', 'todo'], ['2', 'unit mismatch', 'bad'], ['1', 'duplicate barcode', 'bad']],
    table_title='SKUs', table_note='One verified base unit per SKU; dispensing unit must match',
    filters=[['all', 'All'], ['ok', 'Verified'], ['todo', 'To verify'], ['bad', 'Problems']],
    cols=[['SKU', 'minmax(200px,2fr)'], ['Base unit', '100px'], ['Dispensing unit', '120px'], ['Barcode', '140px'], ['Status', '150px']], statusCol=4, min_width=760,
    rows=[
        R('k1', 'ok', ['Paracetamol 500 mg', 'tablet', 'tablet', '9555000100124', ['ok', 'Verified']], []),
        R('k2', 'todo', ['Salbutamol inhaler', 'inhaler', 'inhaler', '9555000200331', ['n', 'To verify']], [], actions=[dict(label='Verify base unit', set=['ok', 'Verified', 'ok'])]),
        R('k3', 'bad', ['Cough syrup 100 ml', 'ml', 'bottle', '9555000300555', ['r', 'Unit mismatch']], [['Effect', 'Dispensing is refused until units match']], actions=[dict(label='Open medicine', href='MedicineCatalogue.dc.html')]),
        R('k4', 'bad', ['Cetirizine 10 mg', 'tablet', 'tablet', '9555000100124', ['w', 'Duplicate barcode']], [['Also on', 'Paracetamol 500 mg'], ['Effect', 'Scanning refuses this code']], actions=[dict(label='Clear the wrong code', set=['ok', 'Verified', 'ok'])]),
        R('k5', 'ok', ['Amoxicillin 250 mg', 'capsule', 'capsule', '9555000400712', ['ok', 'Verified']], []),
    ],
    detail_label='SKU',
))

SPECS.append(dict(
    id='PurchaseOrderPrint', kind='doc', title='Purchase order', area='stock', crumbs=[STOCK, ['Purchase orders', 'PurchaseOrders.dc.html'], 'PO-CHR-0188'],
    header_actions=[dict(label='Back to orders', href='PurchaseOrders.dc.html'), dict(label='Print', print=True, primary=True)],
    doc=dict(title='PURCHASE ORDER', meta=[['PO number', 'PO-CHR-0188'], ['Date', '16/11/2026'], ['Deliver by', '23/11/2026'], ['Supplier', 'PharmaLink Sdn Bhd (fictional)'], ['Deliver to', 'Klinik Putrijaya (Cheras)'], ['Requested by', 'Siti (CA Supervisor)']],
             cols=[['Item', '1fr'], ['Pack', '120px'], ['Qty', '60px', 'r'], ['Price (RM)', '90px', 'r'], ['Total (RM)', '100px', 'r']],
             rows=[['Amoxicillin 250 mg', 'Box of 100', '10', '21.00', '210.00'], ['Salbutamol inhaler', 'Each', '7', '13.70', '95.90']],
             totals=[['Subtotal', '305.90'], ['Estimated total', '305.90']],
             paras=['Prices are estimates from the supplier price list. Please quote this PO number on the delivery order and invoice.'],
             sign=['Approved by (Finance Officer)', 'Supplier acknowledgement'], footer='Printed from KPOne. Payment is made outside KPOne.'),
    notes=[dict(title='Status', items=[['Approved', 'Mei Lin (Finance) · 16 Nov'], ['Delivery', 'Not received yet']])],
))

SPECS.append(dict(
    id='PriceHistory', kind='list', title='Price history', area='set', crumbs=[['Settings', 'Settings.dc.html'], ['Pricing', 'Pricing.dc.html'], 'Consultation (general)'],
    header_actions=[dict(label='Back to pricing', href='Pricing.dc.html')],
    stats=[['RM 30.00', 'Self-pay now'], ['RM 35.00', 'default Panel'], ['1', 'scheduled change', 'sch']],
    table_title='Every published version', table_note='Newest first · times in Kuala Lumpur',
    filters=[['all', 'All'], ['use', 'In use'], ['sch', 'Scheduled'], ['old', 'Replaced']],
    cols=[['Price book', 'minmax(160px,1.5fr)'], ['Version', '80px', 'r'], ['Amount', '100px', 'r'], ['Effective', '170px'], ['Status', '150px']], statusCol=4, min_width=720,
    rows=[
        R('v5', 'sch', ['Self-pay (all branches)', '5', 'RM 32.00', '1 Jan 2027, 00:00', ['i', 'Scheduled']], [['Published by', 'Mei Lin (Finance)']]),
        R('v4', 'use', ['Self-pay (all branches)', '4', 'RM 30.00', '1 Jul 2026, 00:00', ['ok', 'In use']], [['Published by', 'Mei Lin (Finance)']]),
        R('v3', 'use', ['Default Panel', '2', 'RM 35.00', '1 Jul 2026, 00:00', ['ok', 'In use']], [['Published by', 'Siti (CA Supervisor)']]),
        R('v2', 'old', ['Self-pay (all branches)', '3', 'RM 28.00', '1 Jan 2026, 00:00', ['n', 'Replaced']], []),
        R('v1', 'old', ['AIA (own price book)', '1', 'RM 40.00', '1 Mar 2026, 00:00', ['n', 'Not in use (book inactive)']], []),
    ],
    detail_label='Version',
    notes=[dict(title='Rule', items=[['Billing', 'Uses the price in effect when the invoice is built'], ['History', 'Versions are never edited or deleted']])],
))
