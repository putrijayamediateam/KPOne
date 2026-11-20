"""HR, settings, public and website screens (fictional data)."""
from specs_a import R, F

SPECS = []
HR = ['HR', 'HR.dc.html']
PAYNOTE = dict(title='Before real use', items=[['Statutory tables', 'Entered and checked by the clinic, confirmed by the accountant'], ['Payment', 'KPOne makes no payment and files nothing']], foot='To confirm with the clinic\'s accountant before real use. Synthetic data only.')

SPECS.append(dict(
    id='PayrollRun', kind='list', title='Payroll run', area='hr', crumbs=[HR, ['Payroll', 'Payroll.dc.html'], 'October 2026 run'],
    badge=['w', 'Waiting for approval'], header_actions=[dict(label='Recalculate'), dict(label='Approve run', primary=True, toast='Approved by a Director who did not prepare it (prototype). Payslips can now be released.')],
    stats=[['18', 'staff in this run'], ['RM 61,240', 'gross'], ['RM 52,118', 'net'], ['2', 'warnings', 'warn']],
    table_title='Staff in the run', table_note='Monthly pay group · prepared by Haikal',
    filters=[['all', 'All'], ['warn', 'Warnings'], ['ok', 'Checked']],
    cols=[['Staff', 'minmax(180px,1.6fr)'], ['Gross', '110px', 'r'], ['EPF + SOCSO + EIS', '140px', 'r'], ['MTD', '90px', 'r'], ['Net', '110px', 'r'], ['Check', '140px']], statusCol=5, min_width=820,
    rows=[
        R('s1', 'ok', [{'t': 'Aina R.', 's': 'CA Supervisor · Cheras'}, 'RM 3,650.00', 'RM 409.85', 'RM 21.00', 'RM 3,219.15', ['ok', 'Checked']], [['Overtime', '6 h approved'], ['Allowances', 'Transport RM 150']], actions=[dict(label='Open payslip', href='Payslip.dc.html')]),
        R('s2', 'warn', [{'t': 'Hafiz', 's': 'Clinic Assistant · Cheras'}, 'RM 2,480.00', 'RM 334.10', 'RM 0.00', 'RM 2,145.90', ['w', 'Unpaid leave 1 day']], [['Leave', '1 day unpaid, 12 Oct'], ['Check', 'Confirm the deduction']], actions=[dict(label='Mark checked', set=['ok', 'Checked', 'ok']), dict(label='Open payslip', href='Payslip.dc.html')]),
        R('s3', 'ok', [{'t': 'Siti', 's': 'CA Supervisor · Cheras'}, 'RM 3,700.00', 'RM 505.20', 'RM 24.50', 'RM 3,170.30', ['ok', 'Checked']], [], actions=[dict(label='Open payslip', href='Payslip.dc.html')]),
        R('s4', 'warn', [{'t': 'Mei Lin', 's': 'Finance Officer'}, 'RM 4,900.00', 'RM 668.40', 'RM 118.00', 'RM 4,113.60', ['w', 'New profile']], [['Check', 'Profile confirmed this month']], actions=[dict(label='Mark checked', set=['ok', 'Checked', 'ok'])]),
        R('s5', 'ok', [{'t': 'Deepa', 's': 'Panel Officer'}, 'RM 3,300.00', 'RM 451.00', 'RM 12.00', 'RM 2,837.00', ['ok', 'Checked']], []),
        R('s6', 'ok', [{'t': 'Dr. Lim', 's': 'Resident Doctor'}, 'RM 9,800.00', 'RM 1,212.60', 'RM 820.00', 'RM 7,767.40', ['ok', 'Checked']], []),
    ],
    detail_label='Staff line', notes=[dict(title='Kept outside KPOne', items=[['Director and HR Manager', 'Their own pay stays outside KPOne in this version'], ['Locums', 'Paid outside KPOne; see Locum pay']]), PAYNOTE],
))

SPECS.append(dict(
    id='Payslip', kind='doc', title='Payslip', area='hr', crumbs=[HR, ['Payroll', 'Payroll.dc.html'], ['October 2026 run', 'PayrollRun.dc.html'], 'Aina R.'],
    header_actions=[dict(label='Back to run', href='PayrollRun.dc.html'), dict(label='Print', print=True, primary=True)],
    doc=dict(title='PAYSLIP · OCTOBER 2026', stamp='DRAFT', meta=[['Name', 'Aina R.'], ['Position', 'CA Supervisor'], ['Branch', 'Cheras'], ['Pay date', '31/10/2026'], ['Pay group', 'Monthly'], ['Bank', '•••• 2231']],
             cols=[['Earnings and deductions', '1fr'], ['Amount (RM)', '130px', 'r']],
             rows=[['Basic salary', '3,200.00'], ['Overtime (6 h at 1.5x)', '300.00'], ['Transport allowance', '150.00'], ['EPF (employee 11%)', '-385.00'], ['SOCSO (employee)', '-17.75'], ['EIS (employee)', '-7.10'], ['MTD (PCB)', '-21.00']],
             totals=[['Gross pay', '3,650.00'], ['Total deductions', '430.85'], ['Net pay', '3,219.15']],
             paras=['Employer contributions: EPF RM 474.50 · SOCSO RM 62.15 · EIS RM 7.10. Figures are synthetic and the statutory tables are to be confirmed by the clinic\'s accountant.']),
    notes=[dict(title='Release', items=[['Visible to staff', 'Only after the run is approved'], ['Staff view', 'Each person sees only their own payslip in Me']]), PAYNOTE],
))

SPECS.append(dict(
    id='LocumPay', kind='list', title='Locum pay', area='hr', crumbs=[HR, 'Locum pay'],
    header_actions=[dict(label='Add session', primary=True, toast='Add a locum session: date, branch, hours and agreed rate (prototype).')],
    stats=[['14', 'sessions this month'], ['RM 5,880', 'to pay'], ['3', 'waiting for approval', 'wait'], ['Paid outside', 'KPOne']],
    table_title='November 2026 sessions', table_note='Locums have a record but no KPOne sign-in',
    filters=[['all', 'All'], ['wait', 'Waiting'], ['ok', 'Approved'], ['paid', 'Marked paid']],
    cols=[['Locum', 'minmax(160px,1.4fr)'], ['Date', '110px'], ['Branch', '110px'], ['Hours', '70px', 'r'], ['Amount', '100px', 'r'], ['Status', '150px']], statusCol=5, min_width=760,
    rows=[
        R('l1', 'wait', [{'t': 'Dr. Arif (locum)', 's': 'Rate RM 60 an hour'}, '16 Nov 2026', 'Cheras', '8', 'RM 480.00', ['w', 'Waiting']], [['Roster', 'Matches the open shift'], ['Clock', 'No clock-in for locums']], actions=[dict(label='Approve', set=['ok', 'Approved', 'ok'])]),
        R('l2', 'wait', [{'t': 'Dr. Wong (locum)', 's': 'Rate RM 60 an hour'}, '15 Nov 2026', 'Puchong', '6', 'RM 360.00', ['w', 'Waiting']], [], actions=[dict(label='Approve', set=['ok', 'Approved', 'ok'])]),
        R('l3', 'ok', [{'t': 'Dr. Arif (locum)', 's': 'Rate RM 60 an hour'}, '9 Nov 2026', 'Cheras', '8', 'RM 480.00', ['ok', 'Approved']], [['Approved by', 'Haikal']], actions=[dict(label='Mark paid outside KPOne', set=['i', 'Marked paid', 'paid'])]),
        R('l4', 'paid', [{'t': 'Dr. Wong (locum)', 's': 'Rate RM 60 an hour'}, '2 Nov 2026', 'Sungai Besi', '10', 'RM 600.00', ['i', 'Marked paid']], [['Reference', '•••• 8812']]),
        R('l5', 'wait', [{'t': 'Dr. Kavya (locum)', 's': 'Rate RM 65 an hour'}, '14 Nov 2026', 'Cheras', '4', 'RM 260.00', ['w', 'Waiting']], [], actions=[dict(label='Approve', set=['ok', 'Approved', 'ok'])]),
    ],
    detail_label='Session', notes=[PAYNOTE],
))

SPECS.append(dict(
    id='StaffFile', kind='list', title='Staff file', area='hr', crumbs=[HR, ['Staff files', 'StaffFiles.dc.html'], 'Hafiz'],
    badge=['ok', 'Active · Clinic Assistant'], header_actions=[dict(label='Show IC', toast='Recorded in the audit log before the value is shown (prototype).'), dict(label='Add document', primary=True, toast='PDF, JPG or PNG up to 10 MB, checked and encrypted (prototype).')],
    stats=[['2 y 4 m', 'with the clinic'], ['6', 'documents', 'doc'], ['1', 'expiring soon', 'exp'], ['3/5', 'onboarding done', 'chk']],
    table_title='Hafiz · Cheras', table_note='Employment, documents and checklist',
    filters=[['all', 'All'], ['emp', 'Employment'], ['doc', 'Documents'], ['chk', 'Checklist']],
    cols=[['Item', 'minmax(200px,2fr)'], ['Detail', 'minmax(160px,1.4fr)'], ['Date', '120px'], ['Status', '140px']], statusCol=3,
    rows=[
        R('e1', 'emp', [{'t': 'Employment period', 's': 'Clinic Assistant · full time'}, 'Cheras (primary)', 'From 1 Jul 2024', ['ok', 'Current']], [['Account', 'HR never changes accounts or roles']]),
        R('d1', 'doc', [{'t': 'Offer letter', 's': 'PDF · 212 KB'}, 'Signed copy', '28 Jun 2024', ['ok', 'Kept']], [['Opens', 'Each open is recorded']], actions=[dict(label='Open document', toast='Opened (recorded in the audit log). Prototype.')]),
        R('d2', 'doc', [{'t': 'First aid certificate', 's': 'JPG · 1.1 MB'}, 'Expires 2 Jan 2027', '2 Jan 2025', ['w', 'Expiring soon']], [['Reminder', 'Shown in KPOne only; nothing is sent']], tags=['exp']),
        R('d3', 'doc', [{'t': 'Bank details', 's': '•••• 2231'}, 'Confirmed by Haikal', '30 Jun 2024', ['ok', 'Confirmed']], [['Rule', 'Confirmed by someone other than the entrant and the staff member']]),
        R('c1', 'chk', [{'t': 'Uniform issued', 's': 'Onboarding'}, 'Ticked by Haikal', '1 Jul 2024', ['ok', 'Done']], []),
        R('c2', 'chk', [{'t': 'SOP: dispensing read', 's': 'Onboarding'}, '—', '—', ['n', 'Not done']], [], actions=[dict(label='Tick', set=['ok', 'Done', 'chk'])]),
    ],
    detail_label='Item', notes=[dict(title='Personal (masked)', items=[['IC number', '•••• 5520 · Show is recorded'], ['Emergency contact', 'Wife · •••• 0911']])],
))

SPECS.append(dict(
    id='AppraisalForm', kind='form', title='Appraisal', area='hr', crumbs=[HR, ['Appraisals', 'Appraisals.dc.html'], 'Hafiz · 2026'],
    header_actions=[dict(label='Save draft')],
    stats=[['3.8', 'average so far'], ['Self review', 'done 4 Nov'], ['Manager review', 'in progress']],
    sections=[
        dict(title='1 · Ratings', note='1 needs support · 3 meets · 5 exceeds', items=[
            dict(id='r1', label='Patient care and courtesy', choice=['1', '2', '3', '4', '5'], def_='4'),
            dict(id='r2', label='Dispensing accuracy', choice=['1', '2', '3', '4', '5']),
            dict(id='r3', label='Teamwork', choice=['1', '2', '3', '4', '5']),
            dict(id='r4', label='Following SOPs', choice=['1', '2', '3', '4', '5']),
        ]),
        dict(title='2 · Comments', items=[
            F('str', 'Strengths', 'Calm with busy queues; helps new CAs', wide=True),
            F('imp', 'To improve', 'Label checks before handing over', wide=True),
            F('goal', 'Goal for next year', 'Lead the monthly stock count', wide=True),
        ]),
        dict(title='3 · Sign-off', items=[
            dict(id='share', label='Share with Hafiz', sw=False, note='Hafiz can read and acknowledge it in Me.'),
            dict(text='Nothing here changes pay by itself. Pay changes go through a payroll profile change.', tone='i'),
        ]),
    ],
    summary_title='Appraisal', summary=[['Patient care', '@r1'], ['Dispensing accuracy', '@r2'], ['Teamwork', '@r3'], ['SOPs', '@r4']],
    checks=[['Self review done', True], ['All ratings given', True], ['Comments written', True]],
    finals=[dict(label='Submit appraisal', primary=True, toast='Submitted; Hafiz is asked to acknowledge (prototype).'), dict(label='Back to appraisals', href='Appraisals.dc.html')],
))

SPECS.append(dict(
    id='DisciplineCase', kind='list', title='Discipline case', area='hr', crumbs=[HR, ['Discipline', 'Discipline.dc.html'], 'DC-2026-004'],
    badge=['w', 'Show-cause letter sent'], header_actions=[dict(label='Add note'), dict(label='Record outcome', primary=True, toast='Outcome options: no action, warning, final warning (prototype).')],
    stats=[['DC-2026-004', 'case'], ['Late arrivals', 'reason'], ['7 days', 'reply due'], ['Restricted', 'HR Manager and Director only']],
    table_title='Case timeline', table_note='Append-only · each step keeps who and when',
    filters=[['all', 'All'], ['step', 'Steps'], ['doc', 'Documents']],
    cols=[['Date', '120px'], ['Step', 'minmax(220px,2fr)'], ['By', 'minmax(120px,1fr)'], ['Status', '140px']], statusCol=3,
    rows=[
        R('s1', 'step', ['2 Nov 2026', {'t': 'Case opened', 's': '5 late arrivals in October (attendance flags)'}, 'Haikal', ['ok', 'Done']], [['Evidence', 'Attendance review, 5 flags']]),
        R('s2', 'doc', ['5 Nov 2026', {'t': 'Show-cause letter', 's': 'Letter LT-2026-019'}, 'Haikal', ['ok', 'Sent']], [], actions=[dict(label='Open letter', href='Letters.dc.html')]),
        R('s3', 'step', ['12 Nov 2026', {'t': 'Reply from staff', 's': 'Due 12 Nov'}, '—', ['w', 'Waiting']], [], actions=[dict(label='Record reply received', set=['ok', 'Received', 'step'])]),
        R('s4', 'step', ['—', {'t': 'Hearing', 's': 'Only if needed'}, '—', ['n', 'Not scheduled']], []),
    ],
    detail_label='Step', notes=[dict(title='Fairness', items=[['Right to reply', 'Staff always see the letter and can answer'], ['Decider', 'Not the person who opened the case']])],
))

SPECS.append(dict(
    id='IncidentDetail', kind='list', title='Incident', area='hr', crumbs=[HR, ['Incidents', 'Incidents.dc.html'], 'IN-2026-031'],
    badge=['r', 'Needle-stick · open'], header_actions=[dict(label='Add action'), dict(label='Close incident', primary=True, toast='Closing needs every action done (prototype).')],
    stats=[['IN-2026-031', 'incident'], ['Treatment room', 'where'], ['14 Nov 10:20', 'when'], ['2 of 4', 'actions done']],
    table_title='Actions', table_note='Who does what by when',
    filters=[['all', 'All'], ['open', 'Open'], ['done', 'Done']],
    cols=[['Action', 'minmax(220px,2fr)'], ['Owner', 'minmax(120px,1fr)'], ['Due', '110px'], ['Status', '120px']], statusCol=3,
    rows=[
        R('a1', 'done', [{'t': 'First aid and wash', 's': 'Done at the time'}, 'Hafiz', '14 Nov', ['ok', 'Done']], []),
        R('a2', 'done', [{'t': 'Doctor assessment', 's': 'Risk assessment recorded'}, 'Dr. Farah', '14 Nov', ['ok', 'Done']], []),
        R('a3', 'open', [{'t': 'Follow-up blood test', 's': 'At 6 weeks'}, 'Hafiz', '26 Dec', ['w', 'Open']], [], actions=[dict(label='Mark done', set=['ok', 'Done', 'done'])]),
        R('a4', 'open', [{'t': 'Sharps bin moved to the counter', 's': 'Prevent a repeat'}, 'Siti', '21 Nov', ['w', 'Open']], [], actions=[dict(label='Mark done', set=['ok', 'Done', 'done'])]),
    ],
    detail_label='Action', notes=[dict(title='Report', items=[['Reported by', 'Hafiz · 14 Nov 10:35'], ['Patient involved', 'No patient details are kept in HR']])],
))

# ---------------------------------------------------------------- Settings
SPECS.append(dict(
    id='BusinessDetails', kind='form', title='Business details', area='set', crumbs=[['Settings', 'Settings.dc.html'], 'Business details'],
    header_actions=[dict(label='Preview letterhead', href='InvoiceView.dc.html')],
    stats=[['2', 'branches ready'], ['1', 'needs footer'], ['0', 'letters blocked']],
    sections=[
        dict(title='1 · Clinic', items=[
            F('legal', 'Legal name', 'Putrijaya Medical Sdn Bhd (fictional)', wide=True), F('reg', 'Registration number', '201501000999'),
            dict(id='br', label='Branch', choice=['Cheras', 'Puchong', 'Sungai Besi']),
            F('cn', 'Clinic name on documents', 'KLINIK PUTRIJAYA (CHERAS)', wide=True),
            F('ad', 'Address', '[ADDRESS LINE], Cheras, 56000 Kuala Lumpur', wide=True),
            F('ph1', 'Phone', '03-2000 0001'), F('ph2', 'Second phone', '', ph='Optional', optional=True), F('em', 'Email', 'cheras@example.test', wide=True),
        ]),
        dict(title='2 · Logo and footer', items=[
            dict(text='Logo: kp-logo.png · 512 × 512 · checked and stored privately. Choose logo to replace it.', tone='n'),
            F('foot', 'Invoice and receipt footer', 'This is a computer-generated document. No signature is required.', wide=True, hint='Printed after the totals. A branch with no footer prints nothing extra.'),
        ]),
    ],
    summary_title='Document readiness', summary=[['Branch', '@br'], ['Cheras', 'Ready'], ['Puchong', 'Needs footer'], ['Sungai Besi', 'Ready']],
    checks=[['Clinic name and address', True], ['Phone or email', True], ['Logo checked', True]],
    finals=[dict(label='Save', primary=True, toast='Saved. Only field names are audited, never phone values (prototype).')],
    summary_foot='Issued copies keep the letterhead they were printed with.',
))

SPECS.append(dict(
    id='ProfileSecurity', kind='form', title='My account', area='set', crumbs=[['Me', 'Me.dc.html'], 'Account and security'],
    sections=[
        dict(title='1 · Profile', items=[F('dn', 'Display name', 'Aina R.'), F('we', 'Work email', 'aina.r@kpone.test', tail='Set by HR')]),
        dict(title='2 · Sign-in', items=[
            dict(text='Google sign-in works only for an existing active account. Unknown Google accounts never create users.', tone='i'),
            F('pw', 'Password', '••••••••••', tail='Changed 2 months ago'),
            dict(id='tfa', label='Two-step verification', sw=True, note='Asks for a code from your authenticator app.'),
            dict(list=[['This laptop · Chrome', 'Cheras · now', 'ok', 'This device'], ['Front desk PC · Edge', 'Cheras · yesterday', 'n', 'Sign out']]),
        ]),
        dict(title='3 · Appearance', items=[dict(id='th', label='Theme', choice=['Light', 'Dark', 'System'])]),
    ],
    summary_title='Account', summary=[['Role', 'CA Supervisor'], ['Primary branch', 'Cheras'], ['Theme', '@th']],
    checks=[['Two-step verification on', True], ['Recovery codes saved', False]],
    finals=[dict(label='Save', primary=True, toast='Saved (prototype).'), dict(label='Sign out everywhere', toast='All other sessions signed out (prototype).')],
    summary_foot='Your role and branches are changed by an administrator, not here.',
))

# ---------------------------------------------------------------- Public
SPECS.append(dict(
    id='CareersApply', kind='form', public=True, title='Apply for a job', public_sub='Careers · Kerjaya',
    stats=[['Clinic Assistant', 'Cheras · full time'], ['Closes', '30 Nov 2026']],
    sections=[
        dict(title='1 · About you', items=[
            F('n', 'Full name', 'Nur Iman binti Zakaria', wide=True), F('ph', 'Phone', '+60 11-2345 6789'), F('em', 'Email', 'iman@example.test'),
            dict(id='pos', label='Position', choice=['Clinic Assistant', 'Resident Doctor', 'Panel Officer'], wide=True),
        ]),
        dict(title='2 · Experience', items=[
            dict(id='exp', label='Clinic experience', choice=['None', 'Under 1 year', '1 to 3 years', 'Over 3 years']),
            F('cv', 'CV', '', ph='Choose a PDF (up to 5 MB)', wide=True),
            dict(id='con', label='I agree my details are kept for this application', sw=False, note='Kept for 6 months, then deleted. Read the privacy notice.'),
        ]),
    ],
    summary_title='Your application', summary=[['Position', '@pos'], ['Experience', '@exp']],
    checks=[['Name and contact', True], ['CV attached', False], ['Consent given', False]],
    finals=[dict(label='Send application', primary=True, toast='Thank you. Your reference is JA-2026-0142 (prototype).')],
    summary_foot='You can check your application status with your reference number.',
))

SPECS.append(dict(
    id='ForgotPassword', kind='form', public=True, title='Reset password', public_sub='Staff sign in',
    header_actions=[dict(label='Back to sign in', href='SignIn.dc.html')],
    sections=[dict(title='Reset your password', note='For staff accounts only', items=[
        F('em', 'Work email', 'aina.r@kpone.test', wide=True),
        dict(text='If the email belongs to an active account, a reset link is sent. For your safety we never say whether an account exists.', tone='i', wide=True),
    ])],
    summary_title='What happens next', summary=[['Link valid for', '60 minutes'], ['Used once', 'Yes']],
    checks=[['Email entered', True]],
    finals=[dict(label='Send reset link', primary=True, toast='If the account exists, a link is on its way (prototype).'), dict(label='Back to sign in', href='SignIn.dc.html')],
))

# ---------------------------------------------------------------- Website
SPECS.append(dict(
    id='WebsiteOutputPlans', kind='list', title='Website output plans', area='set', crumbs=[['Settings', 'Settings.dc.html'], ['Website releases', 'WebsiteReleases.dc.html'], 'Output plans'],
    badge=['n', 'Nothing is public'], header_actions=[dict(label='New plan', primary=True, toast='A plan binds to one approved release (prototype).')],
    stats=[['3', 'plans'], ['1', 'waiting for the Director', 'wait'], ['1', 'approved', 'ok']],
    table_title='Plans', table_note='Each plan freezes the address, pages, aliases, footer and look',
    filters=[['all', 'All'], ['wait', 'Waiting'], ['ok', 'Approved'], ['draft', 'Draft']],
    cols=[['Plan', '100px'], ['Release', 'minmax(160px,1.4fr)'], ['Pages', '80px', 'r'], ['Design', '130px'], ['Status', '150px']], statusCol=4, min_width=700,
    rows=[
        R('p3', 'wait', ['Plan 3', {'t': 'Release 7', 's': 'hash 8f3f…aedce'}, '24', 'kp-soft-v1', ['w', 'Waiting']], [['Origin', 'https://www.example.test'], ['Service addresses', '?slug= with frozen aliases']], actions=[dict(label='Approve (Director)', set=['ok', 'Approved', 'ok']), dict(label='Refuse', set=['r', 'Refused', 'draft'])]),
        R('p2', 'ok', ['Plan 2', {'t': 'Release 6', 's': 'hash 2c74…0ee3'}, '22', 'kp-soft-v1', ['ok', 'Approved']], [['Approved by', 'Director · 2 Nov']], actions=[dict(label='Build review files', toast='Built privately; nothing is published (prototype).')]),
        R('p1', 'draft', ['Plan 1', {'t': 'Release 5', 's': 'Withdrawn'}, '20', 'kp-soft-v1', ['n', 'Withdrawn']], []),
    ],
    detail_label='Plan', notes=[dict(title='Not built yet', items=[['Publishing', 'Not authorised'], ['Deployment', 'Not authorised']])],
))
