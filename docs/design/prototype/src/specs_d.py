"""Batch 2: the remaining real KPOne pages without a mockup (fictional data)."""
from specs_a import R, F

SPECS = []

SPECS.append(dict(
    id='SopRead', kind='list', title='SOPs to read', area='hr', user=('Hafiz', 'Clinic Assistant', 'HF'), crumbs=[['Me', 'Me.dc.html'], 'SOPs to read'],
    stats=[['2', 'to read', 'todo'], ['5', 'acknowledged', 'done'], ['1', 'new version', 'todo']],
    table_title='SOPs for your role', table_note='Open an SOP before you can acknowledge it',
    filters=[['all', 'All'], ['todo', 'To read'], ['done', 'Acknowledged']],
    cols=[['SOP', 'minmax(220px,2fr)'], ['Version', '80px'], ['Published', '120px'], ['Status', '150px']], statusCol=3,
    rows=[
        R('s1', 'todo', [{'t': 'Dispensing and label check', 's': 'PDF · 6 pages'}, 'v4', '12 Nov 2026', ['w', 'To read']], [['Why again', 'New version: step 5 changed'], ['Opened', 'Not yet']],
          actions=[dict(label='Open SOP', set=['i', 'Opened', 'todo'], toast='Opened (recorded). You can now acknowledge.'), dict(label='I have read and understood', set=['ok', 'Acknowledged', 'done'], when=['Opened'])]),
        R('s2', 'todo', [{'t': 'Sharps and needle-stick', 's': 'PDF · 3 pages'}, 'v1', '14 Nov 2026', ['w', 'To read']], [['For', 'CA, CA Supervisor, doctors']],
          actions=[dict(label='Open SOP', set=['i', 'Opened', 'todo'], toast='Opened (recorded). You can now acknowledge.'), dict(label='I have read and understood', set=['ok', 'Acknowledged', 'done'], when=['Opened'])]),
        R('s3', 'done', [{'t': 'Cash handling and closing', 's': 'PDF · 4 pages'}, 'v2', '1 Sep 2026', ['ok', 'Acknowledged']], [['Acknowledged', '3 Sep 2026']], actions=[dict(label='Open SOP', toast='Opened (recorded).')]),
        R('s4', 'done', [{'t': 'QR check-in review', 's': 'PDF · 2 pages'}, 'v1', '10 Aug 2026', ['ok', 'Acknowledged']], [['Acknowledged', '11 Aug 2026']]),
        R('s5', 'done', [{'t': 'Patient privacy at the counter', 's': 'PDF · 2 pages'}, 'v3', '2 Jul 2026', ['ok', 'Acknowledged']], [['Acknowledged', '2 Jul 2026']]),
    ],
    detail_label='SOP', detail_note='Acknowledging is only possible after you open the SOP. The server checks it.',
    notes=[dict(title='Managers', items=[['All SOPs', 'HR publishes and sees who has not acknowledged']], foot='Nothing here blocks clinical work.')],
))

SPECS.append(dict(
    id='IssuedCopies', kind='list', title='Issued copies', area='bill', crumbs=[['Billing', 'Billing.dc.html'], ['Invoice INV-CHR-2026-004102', 'InvoiceView.dc.html'], 'Issued copies'],
    header_actions=[dict(label='Print invoice', href='InvoiceView.dc.html'), dict(label='Print receipt', primary=True, href='ReceiptView.dc.html')],
    stats=[['3', 'documents'], ['5', 'prints'], ['1', 'revised', 'rev']],
    table_title='Documents for KPV-00004102', table_note='Each first print stores a frozen, encrypted copy',
    filters=[['all', 'All'], ['inv', 'Invoices'], ['rc', 'Receipts'], ['rev', 'Revised']],
    cols=[['Document', 'minmax(200px,2fr)'], ['Version', '80px', 'r'], ['First issued', 'minmax(150px,1.2fr)'], ['Prints', '70px', 'r'], ['Label', '140px']], statusCol=4, min_width=720,
    rows=[
        R('d1', 'inv', [{'t': 'Invoice INV-CHR-2026-004102', 's': 'RM 65.00'}, '1', {'t': '19 Nov 2026, 10:20', 's': 'by Aina R.'}, '2', ['n', 'ORIGINAL']], [['Reprint', 'Prints as COPY']], actions=[dict(label='Open frozen copy', href='InvoiceView.dc.html')]),
        R('d2', 'rev', [{'t': 'Invoice INV-CHR-2026-004102', 's': 'RM 58.50 after discount'}, '2', {'t': '19 Nov 2026, 10:32', 's': 'by Aina R.'}, '1', ['i', 'REVISED']], [['Why revised', 'Adjustment added after the first print'], ['Older copy', 'Says it is an older version']], tags=['inv'], actions=[dict(label='Open frozen copy', href='InvoiceView.dc.html')]),
        R('d3', 'rc', [{'t': 'Receipt RC-CHR-2026-003318', 's': 'RM 58.50 cash'}, '1', {'t': '19 Nov 2026, 10:33', 's': 'by Aina R.'}, '2', ['n', 'ORIGINAL']], [['Later payments', 'Never revise a receipt']], actions=[dict(label='Open frozen copy', href='ReceiptView.dc.html')]),
    ],
    detail_label='Issued document', detail_note='"Prints" counts presses of Print, not sheets of paper.',
))

SPECS.append(dict(
    id='WebsiteDraftPreview', kind='list', title='Website draft preview', area='set', crumbs=[['Settings', 'Settings.dc.html'], ['Website releases', 'WebsiteReleases.dc.html'], 'Draft preview'],
    badge=['n', 'Private · nothing is public'], header_actions=[dict(label='English'), dict(label='Bahasa Melayu'), dict(label='Freeze as release', primary=True, toast='Frozen as release 8 for the Director to approve (prototype).')],
    stats=[['24', 'pages'], ['2', 'missing Bahasa Melayu', 'miss'], ['1', 'photo without consent', 'miss']],
    table_title='Pages in this draft', table_note='A page is included only when both languages are complete',
    filters=[['all', 'All'], ['ok', 'Ready'], ['miss', 'Needs work']],
    cols=[['Page', 'minmax(200px,2fr)'], ['English', '110px'], ['Bahasa Melayu', '130px'], ['Status', '140px']], statusCol=3,
    rows=[
        R('p1', 'ok', [{'t': 'Home', 's': '/ and /bm/'}, 'Complete', 'Complete', ['ok', 'Ready']], [['Title', 'Klinik Putrijaya · Your family clinic']]),
        R('p2', 'ok', [{'t': 'Doctors', 's': '/doctors'}, 'Complete', 'Complete', ['ok', 'Ready']], [['Profiles', '6 doctors · registration numbers private']]),
        R('p3', 'miss', [{'t': 'Service: Child vaccination', 's': '?slug=child-vaccination'}, 'Complete', 'Missing', ['w', 'Needs BM']], [['Effect', 'Left out of the release']], actions=[dict(label='Open service draft', href='WebsiteServices.dc.html')]),
        R('p4', 'miss', [{'t': 'Promotion: Health screening', 's': '/promotions'}, 'Complete', 'Missing', ['w', 'Needs BM']], [], actions=[dict(label='Open promotion draft', href='WebsitePromotions.dc.html')]),
        R('p5', 'miss', [{'t': 'Little Shield', 's': '/little-shield'}, 'Complete', 'Complete', ['r', 'Photo consent']], [['Photo', 'Poster 3 has no consent record']], actions=[dict(label='Open media library', href='WebsiteMedia.dc.html')]),
        R('p6', 'ok', [{'t': 'Branches', 's': '/branches'}, 'Complete', 'Complete', ['ok', 'Ready']], []),
    ],
    detail_label='Page',
))

SPECS.append(dict(
    id='CareersStatus', kind='form', public=True, title='Application status', public_sub='Careers · Kerjaya',
    header_actions=[dict(label='Apply for another job', href='CareersApply.dc.html')],
    sections=[
        dict(title='Check your application', items=[
            F('ref', 'Reference number', 'JA-2026-0142'), F('ph', 'Phone used to apply', '•••• 6789'),
            dict(list=[['Received', '19 Nov 2026', 'ok', 'Done'], ['Reviewed by HR', 'Usually within 5 working days', 'i', 'In progress'], ['Interview', 'We will call you if shortlisted', 'n', 'Not yet']], wide=True),
        ]),
    ],
    summary_title='Clinic Assistant · Cheras', summary=[['Status', 'Under review'], ['Kept until', 'May 2027, then deleted']],
    checks=[['CV received', True], ['Consent given', True]],
    finals=[dict(label='Withdraw my application', toast='Withdrawn. Your details will be deleted (prototype).')],
    summary_foot='For questions, call the clinic during opening hours.',
))

SPECS.append(dict(
    id='StaffCreate', kind='form', title='Add staff account', area='set', crumbs=[['Settings', 'Settings.dc.html'], ['Staff and access', 'Staff.dc.html'], 'Add staff account'],
    header_actions=[dict(label='Cancel', href='Staff.dc.html')],
    sections=[
        dict(title='1 · Person', items=[
            F('n', 'Full name', 'Nurul Huda binti Rahim', wide=True), F('em', 'Work email', 'nurul.h@kpone.test', hint='Google sign-in works only for this existing account.'),
            F('ph', 'Phone', '+60 12-777 1020', optional=True), dict(id='dep', label='Department', choice=['Clinic', 'Finance', 'HR', 'Marketing']),
        ]),
        dict(title='2 · Role and branch', items=[
            dict(id='role', label='Role', choice=['Clinic Assistant', 'CA Supervisor', 'Resident Doctor', 'Panel Officer'], wide=True),
            dict(id='br', label='Primary branch', choice=['Cheras', 'Puchong', 'Sungai Besi']),
            F('from', 'Starts on', '1 Dec 2026'),
            dict(id='more', label='Also works at another branch', sw=False, note='Adds a secondary assignment with its own dates.'),
        ]),
        dict(title='3 · Sign-in', items=[
            dict(id='pw', label='Sign-in method', choice=['Google only', 'Password set once']),
            F('bp', 'One-time password', '••••••••', show=[['pw', 'Password set once']], hint='Hashed at once; never shown, logged or emailed.'),
            dict(text='You can only give roles you hold yourself. Director-level roles are given by a Director.', tone='i'),
        ]),
    ],
    summary_title='New account', summary=[['Role', '@role'], ['Primary branch', '@br'], ['Sign-in', '@pw']],
    checks=[['Name and work email', True], ['Exactly one primary branch', True], ['Role within your authority', True]],
    finals=[dict(label='Create account', primary=True, href='StaffShow.dc.html')],
    summary_foot='Creating, roles and branch changes are audited without any password.',
))

SPECS.append(dict(
    id='StaffShow', kind='list', title='Staff access summary', area='set', crumbs=[['Settings', 'Settings.dc.html'], ['Staff and access', 'Staff.dc.html'], 'Nurul Huda'],
    badge=['ok', 'Active'], header_actions=[dict(label='Edit details'), dict(label='Change roles', toast='Roles you do not hold cannot be given (prototype).'), dict(label='Deactivate', toast='Deactivated accounts cannot sign in; history stays (prototype).')],
    stats=[['Clinic Assistant', 'role'], ['Cheras', 'primary branch'], ['Google', 'sign-in'], ['42', 'permissions']],
    table_title='Access', table_note='Branch assignments and what the role allows',
    filters=[['all', 'All'], ['br', 'Branches'], ['perm', 'Permissions'], ['log', 'Changes']],
    cols=[['Item', 'minmax(220px,2fr)'], ['Scope', '140px'], ['Dates', '170px'], ['Status', '120px']], statusCol=3,
    rows=[
        R('b1', 'br', [{'t': 'Cheras', 's': 'Primary assignment'}, 'Branch', 'From 1 Dec 2026, no end date', ['ok', 'Effective']], [['Rule', 'Exactly one effective primary branch']]),
        R('b2', 'br', [{'t': 'Puchong', 's': 'Secondary assignment'}, 'Branch', '1 Dec 2026 to 31 Mar 2027', ['i', 'Scheduled']], [], actions=[dict(label='End assignment', set=['n', 'Ended', 'br'])]),
        R('p1', 'perm', [{'t': 'Register visits and run the queue', 's': 'visits, queue.call.branch'}, 'Branch', '—', ['ok', 'Allowed']], []),
        R('p2', 'perm', [{'t': 'Dispense and print labels', 's': 'dispensary.*.branch'}, 'Branch', '—', ['ok', 'Allowed']], []),
        R('p3', 'perm', [{'t': 'Clinical notes and diagnoses', 's': 'Doctor only'}, '—', '—', ['n', 'Not allowed']], []),
        R('l1', 'log', [{'t': 'Account created', 's': 'by Dr. Director'}, 'Audit', '19 Nov 2026, 11:02', ['ok', 'Recorded']], []),
    ],
    detail_label='Access item',
))

SPECS.append(dict(
    id='PanelClaimDetail', kind='list', title='Panel claim', area='panel', crumbs=[['Panel', 'PanelClaims.dc.html'], 'Claim PC-CHR-2026-0418'],
    badge=['i', 'Submitted · AIA'], header_actions=[dict(label='Copy message', toast='Claim message copied with smart fields filled in (prototype).'), dict(label='Print claim pack', href='ClaimPack.dc.html'), dict(label='Record payment', primary=True, href='PanelPayments.dc.html')],
    stats=[['RM 55.00', 'claimed'], ['RM 0.00', 'paid'], ['14 days', 'until overdue'], ['2', 'photos']],
    table_title='Claim activity', table_note='Raj Kumar · KP-08816 · invoice INV-CHR-2026-004088',
    filters=[['all', 'All'], ['ev', 'Events'], ['doc', 'Documents'], ['item', 'Invoice items']],
    cols=[['When', '150px'], ['What', 'minmax(220px,2fr)'], ['By', 'minmax(110px,1fr)'], ['Type', '120px']], statusCol=3,
    rows=[
        R('e1', 'ev', ['18 Nov 2026, 15:10', {'t': 'Submitted on the AIA portal', 's': 'Submitted date 18 Nov 2026'}, 'Deepa', ['i', 'Submitted']], []),
        R('d1', 'doc', ['18 Nov 2026, 15:08', {'t': 'Photo 1 · claim form', 's': 'JPG · 820 KB'}, 'Deepa', ['n', 'Document']], [], actions=[dict(label='Open photo', toast='Opened (recorded in the audit log).')]),
        R('d2', 'doc', ['18 Nov 2026, 15:08', {'t': 'Photo 2 · member card', 's': 'JPG · 610 KB'}, 'Deepa', ['n', 'Document']], [], actions=[dict(label='Open photo', toast='Opened (recorded in the audit log).')]),
        R('i1', 'item', ['—', {'t': 'Consultation (general)', 's': '1 × RM 35.00'}, '—', ['n', 'Item']], []),
        R('i2', 'item', ['—', {'t': 'Paracetamol 500 mg', 's': '30 tablet · RM 20.00'}, '—', ['n', 'Item']], []),
        R('e2', 'ev', ['—', {'t': 'Panel decision', 's': 'Accepted, partly accepted or rejected'}, '—', ['w', 'Waiting']], [], actions=[dict(label='Record: accepted', set=['ok', 'Accepted', 'ev']), dict(label='Record a query', set=['q', 'Queried', 'ev'])]),
    ],
    detail_label='Claim entry',
    notes=[dict(title='Patient identity', items=[['IC number', '•••• 0183 · Show is recorded'], ['Member reference', 'AIA-55120-08']], foot='No diagnosis or clinical notes are part of a claim.')],
))

SPECS.append(dict(
    id='StockReview', kind='list', title='Stock review', area='stock', crumbs=[['Stock', 'Stock.dc.html'], 'Stock review'],
    stats=[['5', 'low stock', 'low'], ['3', 'expiring in 90 days', 'exp'], ['1', 'expired on hand', 'gone']],
    table_title='Worklists · Cheras', table_note='Acknowledge each line with an outcome and a review-by date',
    filters=[['all', 'All'], ['low', 'Low stock'], ['exp', 'Expiring'], ['gone', 'Expired'], ['ack', 'Acknowledged']],
    cols=[['Item', 'minmax(200px,2fr)'], ['Location', '130px'], ['Stock / expiry', '140px'], ['Status', '170px']], statusCol=3,
    rows=[
        R('l1', 'low', [{'t': 'Salbutamol inhaler', 's': 'Level 10'}, 'Dispensary', '3 inhaler', ['w', 'Low stock']], [], actions=[dict(label='Ordered externally', set=['ok', 'Ordered externally', 'ack']), dict(label='Monitoring', set=['i', 'Monitoring', 'ack'])]),
        R('l2', 'low', [{'t': 'Gauze swab 10 x 10', 's': 'Level 200'}, 'Treatment room', '40 piece', ['w', 'Low stock']], [], actions=[dict(label='Ordered externally', set=['ok', 'Ordered externally', 'ack']), dict(label='Create request', href='PurchaseRequests.dc.html')]),
        R('x1', 'exp', [{'t': 'Paracetamol 500 mg', 's': 'Batch PC-0412'}, 'Dispensary', 'exp 12/2026', ['w', 'Expiring · 42 days']], [['Hint', 'Use first (FEFO)']], actions=[dict(label='No action needed', set=['n', 'No action needed', 'ack'])]),
        R('x2', 'exp', [{'t': 'Cetirizine 10 mg', 's': 'Batch CE-0931'}, 'Dispensary', 'exp 01/2027', ['w', 'Expiring · 73 days']], [], actions=[dict(label='No action needed', set=['n', 'No action needed', 'ack'])]),
        R('g1', 'gone', [{'t': 'Normal saline 500 ml', 's': 'Batch NS-7001'}, 'Store room', 'expired 10/2026', ['r', 'Expired on hand']], [], actions=[dict(label='Write off', href='WriteOff.dc.html'), dict(label='Disposal requested', set=['ok', 'Disposal requested', 'ack'])]),
    ],
    detail_label='Line',
))

SPECS.append(dict(
    id='McHistory', kind='list', title='Medical certificate history', area='consult', crumbs=[['Consultation', 'MyQueue.dc.html'], ['Medical certificates', 'MC.dc.html'], 'History'],
    header_actions=[dict(label='Issue MC', primary=True, href='MC.dc.html')],
    stats=[['6', 'issued this week'], ['1', 'replaced', 'rep'], ['1', 'void', 'void']],
    table_title='MCs you issued', table_note='Times in Kuala Lumpur · a void closes the chain',
    filters=[['all', 'All'], ['cur', 'Current'], ['rep', 'Replaced'], ['void', 'Void']],
    cols=[['Number', '190px'], ['Patient', 'minmax(160px,1.4fr)'], ['Days', '110px'], ['Issued', '170px'], ['Status', '120px']], statusCol=4, min_width=760,
    rows=[
        R('m1', 'cur', ['MC/KP/CHR/2026/000412', 'Aminah Yusof', '2 days', '19 Nov 2026, 10:15 AM', ['ok', 'Current']], [['Dates', '19/11/2026 to 20/11/2026']], actions=[dict(label='Print copy', toast='Prints with COPY · current (prototype).'), dict(label='Replace', toast='A replacement gets a new number (prototype).'), dict(label='Void', set=['r', 'Void', 'void'])]),
        R('m2', 'rep', ['MC/KP/CHR/2026/000409', 'Tan Wei Ming', '1 day', '18 Nov 2026, 4:02 PM', ['n', 'Replaced']], [['Replaced by', 'MC/KP/CHR/2026/000410']]),
        R('m3', 'cur', ['MC/KP/CHR/2026/000410', 'Tan Wei Ming', '2 days', '18 Nov 2026, 4:10 PM', ['ok', 'Current']], [], actions=[dict(label='Print copy', toast='Prints with COPY · current (prototype).')]),
        R('m4', 'void', ['MC/KP/CHR/2026/000405', 'Lee Mei Ling', '1 day', '17 Nov 2026, 11:30 AM', ['r', 'Void']], [['Reason', 'Issued for the wrong visit']]),
    ],
    detail_label='Medical certificate', detail_note='Wording is a DRAFT pending a doctor\'s approval. No diagnosis is printed by default.',
))

SPECS.append(dict(
    id='PatientEdit', kind='form', title='Edit patient', area='front', crumbs=[['Front desk', 'Registration.dc.html'], ['Aminah Yusof', 'Patient.dc.html'], 'Edit'],
    header_actions=[dict(label='Cancel', href='Patient.dc.html')],
    sections=[
        dict(title='1 · Details', note='KP-10342', items=[
            F('n', 'Full name', 'Aminah binti Yusof', wide=True), dict(id='sex', label='Gender', choice=['Female', 'Male', 'Unknown']),
            F('dob', 'Date of birth', '02/05/1992'), F('ph', 'Phone', '+60 12-880 2210'), F('em', 'Email', '', ph='name@example.com', optional=True),
        ]),
        dict(title='2 · Identifiers', note='History is kept; an identifier is retired, never deleted', items=[
            dict(list=[['MyKad •••• 4417', 'Current · added 12 Mar 2024', 'ok', 'Current'], ['Passport •••• 8810', 'Retired 2 Jan 2025 · reason: replaced', 'n', 'Retired']], wide=True),
            dict(text='Changing an identifier is checked for duplicates first.', tone='i'),
        ]),
    ],
    summary_title='Change history', summary=[['Last change', '2 Nov 2026 by Aina R.'], ['Fields', 'Phone']],
    checks=[['No duplicate found', True], ['Record is not merged away', True]],
    finals=[dict(label='Save changes', primary=True, href='Patient.dc.html')],
    summary_foot='The history lists field names only, never values.',
))
