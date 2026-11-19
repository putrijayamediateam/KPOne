"""Front desk, consultation, pharmacy, new-workflow steps 3-4, billing and Panel screens (fictional data)."""


def R(id, tag, cells, facts=None, **kw):
    d = dict(id=id, tag=tag, cells=cells)
    if facts:
        d['facts'] = facts
    d.update(kw)
    return d


def F(id, label, value='', **kw):
    return dict(id=id, label=label, value=value, **kw)


SPECS = []

# ---------------------------------------------------------------- Front desk
SPECS.append(dict(
    id='PatientList', kind='list', title='Patients', area='front', crumbs=[['Front desk', 'Registration.dc.html'], 'Patients'],
    search='Name, IC, passport or KP number', header_actions=[dict(label='Merge requests', href='PatientMerge.dc.html'), dict(label='New patient', primary=True, href='NewPatient.dc.html')],
    stats=[['12,480', 'patients'], ['36', 'new this week', 'new'], ['2', 'merge requests waiting', None, 'Open Patient merge to review them.'], ['4', 'minors with guardian', 'minor']],
    table_title='Patient directory', table_note='Masked identifiers · newest visit first',
    filters=[['all', 'All'], ['new', 'New this week'], ['minor', 'Minors'], ['merged', 'Merged records']],
    cols=[['Patient', 'minmax(200px,2fr)'], ['KP number', '110px'], ['IC / passport', '120px'], ['Phone', '120px'], ['Last visit', '120px'], ['Status', '130px']], statusCol=5, min_width=760,
    rows=[
        R('p1', 'new', [{'t': 'Aminah Yusof', 's': 'Female · 34 y'}, 'KP-10342', '•••• 4417', '•••• 2210', 'Today 09:34', ['ok', 'Active']], [['Allergy', 'Penicillin'], ['Panel', 'None (self-pay)'], ['Visits', '6 in 2 years']], sub='Female · 34 y'),
        R('p2', 'all', [{'t': 'Raj Kumar', 's': 'Male · 51 y'}, 'KP-08816', '•••• 0183', '•••• 7741', 'Today 09:41', ['ok', 'Active']], [['Allergy', 'No known allergy'], ['Panel', 'AIA · AIA-55120-08'], ['Visits', '14 in 2 years']]),
        R('p3', 'merged', [{'t': 'Nor Hidayah Ahmad', 's': 'Merged from KP-11902'}, 'KP-07730', '•••• 9921', '•••• 0034', '2 Nov 2026', ['q', 'Kept record']], [['Merged from', 'KP-11902'], ['Approved by', 'Siti (CA Supervisor)'], ['Visits', 'Both records shown']]),
        R('p4', 'minor', [{'t': 'Adam Faris', 's': 'Male · 7 y · guardian on file'}, 'KP-11240', '•••• 1108', 'Guardian', '29 Oct 2026', ['i', 'Minor']], [['Guardian', 'Mother · phone saved'], ['Allergy', 'Unknown'], ['WhatsApp', 'Never uses the guardian number']]),
        R('p5', 'all', [{'t': 'Tan Wei Ming', 's': 'Male · 44 y'}, 'KP-05521', '•••• 3390', '•••• 5512', '28 Oct 2026', ['ok', 'Active']], [['Allergy', 'Sulfa drugs'], ['Panel', 'PMCare'], ['Visits', '9 in 2 years']]),
        R('p6', 'new', [{'t': 'Lee Mei Ling', 's': 'Female · 29 y'}, 'KP-12011', '•••• 7712', '•••• 9001', 'Yesterday', ['ok', 'Active']], [['Allergy', 'No known allergy'], ['Date of birth', 'Adult confirmed by staff'], ['Visits', '1']]),
        R('p7', 'all', [{'t': 'Mohd Hafiz Rahman', 's': 'Male · 38 y'}, 'KP-04410', '•••• 5520', '•••• 3318', '21 Oct 2026', ['ok', 'Active']], [['Allergy', 'NSAIDs'], ['Panel', 'Etiqa'], ['Visits', '22 in 2 years']]),
        R('p8', 'all', [{'t': 'Priya Devi', 's': 'Female · 62 y'}, 'KP-02287', '•••• 6634', '•••• 4420', '18 Oct 2026', ['n', 'Merged away']], [['Kept record', 'KP-02290'], ['New visits', 'Refused on this record'], ['Unmerge', 'Two-person flow']]),
    ],
    row_actions=[dict(label='Open patient', href='Patient.dc.html'), dict(label='Register visit', href='RegisterVisit.dc.html'), dict(label='Visit history', href='VisitHistory.dc.html')],
    detail_note='IC and phone stay masked. Opening the full identifier is recorded in the audit log.',
    notes=[dict(title='Search tips', items=[['Name or KP number', 'Partial names work: "amin" finds Aminah'], ['IC or passport', 'Type the full number; it is never shown in the list']])],
))

SPECS.append(dict(
    id='NewPatient', kind='form', title='New patient', area='front', crumbs=[['Front desk', 'Registration.dc.html'], ['Patients', 'PatientList.dc.html'], 'New patient'],
    header_actions=[dict(label='Cancel', href='PatientList.dc.html')],
    sections=[
        dict(title='1 · Identity', note='Search first so you do not create a duplicate', items=[
            F('name', 'Full name as on IC or passport', 'Nurul Aisyah binti Kamal', wide=True),
            dict(id='idtype', label='Identifier', choice=['MyKad (IC)', 'Passport']),
            F('ic', 'IC number', '', ph='Format YYMMDD-PB-####', show=[['idtype', 'MyKad (IC)']], tail='Checked for duplicates'),
            F('pp', 'Passport number', '', ph='Passport number', show=[['idtype', 'Passport']]),
            F('ppc', 'Issuing country', '', ph='2-letter code, e.g. ID', show=[['idtype', 'Passport']]),
            dict(id='sex', label='Gender', choice=['Female', 'Male', 'Unknown']),
            dict(id='dobk', label='Date of birth', choice=['Known', 'Not known']),
            F('dob', 'Date of birth', '14/03/1994', show=[['dobk', 'Known']]),
            dict(id='adult', label='I confirm this patient is an adult', sw=False, note='Required when the date of birth is not known. Kept as evidence; it is not a verified age.', show=[['dobk', 'Not known']]),
            F('nat', 'Nationality', 'Malaysian', optional=True),
        ]),
        dict(title='2 · Contact', note='Fields marked optional follow Registration settings', items=[
            F('phone', 'Phone', '+60 12-345 6789', tail='MY'),
            F('email', 'Email', '', ph='name@example.com', optional=True),
            F('addr', 'Address', '', ph='Line 1, city, state, postcode', optional=True, wide=True),
            dict(id='minor', label='Patient is under 18', sw=False, note='A guardian contact is then required.'),
            F('gname', 'Guardian name', 'Kamal bin Osman', show=[['minor', 'True']]),
            dict(id='grel', label='Relationship', choice=['Mother', 'Father', 'Guardian'], show=[['minor', 'True']]),
            F('gphone', 'Guardian phone', '+60 13-222 0101', show=[['minor', 'True']], hint='Never used for WhatsApp, search or matching.'),
        ]),
        dict(title='3 · Allergies', note='The doctor confirms at consultation', items=[
            dict(id='allg', label='Allergy status', choice=['Not asked yet', 'No known allergy', 'Has allergies']),
            F('allgx', 'Allergen', 'Penicillin', show=[['allg', 'Has allergies']], hint='Add more on the patient page after saving.'),
        ]),
    ],
    summary_title='Before you save', summary=[['Identifier', '@idtype'], ['Gender', '@sex'], ['Date of birth', '@dobk'], ['Allergy', '@allg']],
    checks=[['Searched for an existing record', True], ['Name and identifier entered', True], ['Phone entered', True]],
    finals=[dict(label='Save patient', primary=True, toast='Patient saved as KP-12044 (prototype).'), dict(label='Save and register visit', href='RegisterVisit.dc.html')],
    summary_foot='A new KP number is given when you save. Nothing is saved in this prototype.',
    notes=[dict(title='Duplicate check', items=[['No match found', 'Checked by IC, passport and name with date of birth']], foot='If a duplicate is found later, propose a merge; a second person approves.')],
))

SPECS.append(dict(
    id='VisitDetail', kind='list', title='Visit details', area='front', crumbs=[['Front desk', 'Registration.dc.html'], 'Visit KPV-00004102'],
    badge=['w', 'Waiting · A-031'], header_actions=[dict(label='Edit visit'), dict(label='Open billing', href='Billing.dc.html'), dict(label='Send to waiting', primary=True, toast='Already in the waiting list as A-031.')],
    stats=[['A-031', 'queue number'], ['9 min', 'waiting'], ['Dr. Lim', 'Room 2'], ['Self-pay', 'who pays']],
    table_title='Visit timeline', table_note='Every step is recorded with who and when',
    filters=[['all', 'All'], ['queue', 'Queue'], ['doc', 'Documents'], ['money', 'Money']],
    cols=[['Time', '80px'], ['What happened', 'minmax(220px,2fr)'], ['By', 'minmax(120px,1fr)'], ['Type', '120px']], statusCol=3,
    rows=[
        R('t1', 'queue', ['09:34', {'t': 'Registered', 's': 'Consultation · reason: fever, 2 days'}, 'Aina R. (CA)', ['n', 'Visit']], [['Visit number', 'KPV-00004102'], ['Branch', 'Cheras']]),
        R('t2', 'queue', ['09:35', {'t': 'Sent to waiting', 's': 'Queue number A-031'}, 'Aina R. (CA)', ['i', 'Queue']], [['Position', '3 ahead'], ['Long wait', 'Amber at 30 min, red at 60 min']]),
        R('t3', 'queue', ['09:36', {'t': 'Not ready to call: cleared', 's': 'Reason was "Awaiting patient details"'}, 'Aina R. (CA)', ['w', 'Flag']], [['Set at', '09:35'], ['Cleared at', '09:36']]),
        R('t4', 'money', ['09:36', {'t': 'Payment intent: Self-pay', 's': 'Can change until billing'}, 'Aina R. (CA)', ['ok', 'Money']], [['Price book', 'Self-pay (Cheras)']]),
        R('t5', 'doc', ['—', {'t': 'Medical certificate', 's': 'Available once the doctor starts the consultation'}, '—', ['n', 'Document']], [['Status', 'Not started']]),
    ],
    row_actions=[dict(label='Open patient', href='Patient.dc.html'), dict(label='Cancel visit', toast='Cancel needs a reason; the queue entry is removed (prototype).')],
    detail_label='Timeline entry',
    notes=[dict(title='Patient', items=[['Aminah Yusof · KP-10342', 'Female · 34 y · Allergy: Penicillin'], ['Phone', '•••• 2210 · WhatsApp button on the patient page']])],
))

SPECS.append(dict(
    id='QrIntakeReview', kind='list', title='QR check-in review', area='front', crumbs=[['Front desk', 'Registration.dc.html'], ['QR check-ins', 'QrCheckins.dc.html'], 'Review QR-2611'],
    badge=['w', 'In review · 12 min left'], header_actions=[dict(label='Correction required', toast='The patient sees "Sila betulkan maklumat" on their phone (prototype).'), dict(label='Reject', toast='Rejected with a reason code (prototype).'), dict(label='Accept and register', primary=True, toast='Accepted: visit KPV-00004110, queue A-032 (prototype).')],
    stats=[['1', 'possible match', 'match'], ['Panel', 'patient says AIA'], ['Doctor', 'see doctor'], ['30 days', 'then purged']],
    table_title='What the patient sent', table_note='Patient-supplied values are not verified until you check them',
    filters=[['all', 'All'], ['id', 'Identity'], ['match', 'Matches'], ['cov', 'Coverage']],
    cols=[['Field', '160px'], ['Patient sent', 'minmax(180px,1.5fr)'], ['On file', 'minmax(160px,1.2fr)'], ['Check', '130px']], statusCol=3,
    rows=[
        R('f1', 'id', ['Name', 'SITI AMINAH BINTI ALI', 'Siti Aminah binti Ali', ['ok', 'Same']], [['Rule', 'Case and spacing ignored']]),
        R('f2', 'id', ['IC number', '•••• 7721', '•••• 7721 (KP-10342)', ['ok', 'Same']], [['Matched record', 'KP-10342']], tags=['match']),
        R('f3', 'id', ['Phone', '+60 12-880 1144', '+60 12-880 1100', ['w', 'Different']], [['Choose', 'Keep on file or use incoming']], actions=[dict(label='Use incoming phone', set=['ok', 'Use incoming', 'id']), dict(label='Keep phone on file', set=['n', 'Keep on file', 'id'])]),
        R('f4', 'cov', ['Who pays', 'Panel: AIA', '—', ['i', 'Confirm']], [['Member reference', 'AIA-77310-02 (unverified)'], ['Visit gets', 'Only what you confirm']], actions=[dict(label='Confirm Panel coverage', set=['ok', 'Confirmed', 'cov']), dict(label='Change to self-pay', set=['n', 'Self-pay', 'cov'])]),
        R('f5', 'all', ['Visit', 'Jumpa doktor · demam', '—', ['ok', 'OK']], [['Purpose', 'Fever, 2 days']]),
        R('f6', 'all', ['Consent', 'Privacy notice v2 accepted', '—', ['ok', 'OK']], [['Notice version', 'uat-draft-set04b-email-address-v2']]),
    ],
    row_actions=[dict(label='Mark as checked', set=['ok', 'Checked', 'all'])],
    detail_label='Field',
    notes=[dict(title='Possible match', items=[['Siti Aminah binti Ali · KP-10342', 'Same IC · last visit 12 Oct 2026']], foot='Accepting links the visit to this record. Nothing exists before you accept.')],
))

# ---------------------------------------------------------------- Consultation and pharmacy
SPECS.append(dict(
    id='QueueBoard', kind='list', title='Consultation queue', area='consult', crumbs=[['Consultation', 'MyQueue.dc.html'], 'Queue board'],
    badge=['i', 'Live · every 3 seconds'], header_actions=[dict(label='My room: Room 2', toast='Room chosen for today: Consultation Room 2.'), dict(label='Call next', primary=True, toast='Call A-031 Aminah Yusof? Confirmed: called to Room 2 (prototype).')],
    stats=[['12', 'waiting', 'wait'], ['4', 'serving', 'serve'], ['1', 'on hold', 'hold'], ['2', 'not ready', 'nr']],
    table_title='Today', table_note='Urgent first, then by arrival · carry-over only when nobody today can be called',
    filters=[['all', 'All'], ['wait', 'Waiting'], ['serve', 'Serving'], ['hold', 'On hold'], ['nr', 'Not ready']],
    cols=[['No.', '70px'], ['Patient', 'minmax(180px,1.6fr)'], ['Doctor', 'minmax(110px,1fr)'], ['Waiting', '110px'], ['Status', '170px']], statusCol=4,
    rows=[
        R('q1', 'wait', ['A-031', {'t': 'Aminah Yusof', 's': 'Fever, 2 days · Self-pay'}, 'Dr. Lim', '9 min', ['n', 'Waiting']], [['Arrived', '09:35'], ['Allergy', 'Penicillin']], actions=[dict(label='Call in', set=['i', 'Serving · Room 2', 'serve']), dict(label='Mark not ready', set=['w', 'Not ready · Panel', 'nr'])]),
        R('q2', 'wait', ['A-030', {'t': 'Raj Kumar', 's': 'Back pain · Panel AIA'}, 'Dr. Lim', '22 min', ['n', 'Waiting']], [['Arrived', '09:22']], actions=[dict(label='Call in', set=['i', 'Serving · Room 2', 'serve'])]),
        R('q3', 'wait', ['A-029', {'t': 'Nor Hidayah', 's': 'Cough · Self-pay'}, 'Dr. Farah', '41 min', ['w', 'Long wait']], [['Arrived', '09:03'], ['Colour', 'Amber from 30 min']], tags=['wait'], actions=[dict(label='Call in', set=['i', 'Serving · Room 1', 'serve'])]),
        R('q4', 'nr', ['A-027', {'t': 'Siti Khadijah', 's': 'Follow-up · Panel PMCare'}, 'Dr. Farah', '18 min', ['w', 'Not ready · Panel']], [['Reason', 'Awaiting Panel confirmation'], ['Set by', 'Aina R.']], actions=[dict(label='Clear: ready to call', set=['n', 'Waiting', 'wait'])]),
        R('q5', 'serve', ['A-028', {'t': 'Tan Wei Ming', 's': 'MC follow-up'}, 'Dr. Farah', '—', ['i', 'Serving · Room 1']], [['Called', '10:02'], ['Treatment step', 'Nebuliser done · waiting for doctor']], actions=[dict(label='Call again', toast='Panggil semula: shown on the TV again.'), dict(label='Call to treatment room', toast='Called to Treatment Room 1 on the TV.')]),
        R('q6', 'hold', ['A-026', {'t': 'Lee Mei Ling', 's': 'On nebuliser'}, 'Dr. Lim', 'held 12 min', ['q', 'On hold']], [['Held at', '09:58'], ['Rule', 'At most 3 held per doctor']], actions=[dict(label='Resume', set=['i', 'Serving · Room 2', 'serve'])]),
        R('q7', 'wait', ['A-024', {'t': 'Priya Devi', 's': 'Carry-over from yesterday'}, 'Dr. Lim', '1 day', ['n', 'Carry-over']], [['Original arrival', 'Yesterday 17:40']]),
    ],
    detail_label='Queue entry',
    notes=[dict(title='Rules', items=[['Call next', 'Always names the patient and asks you to confirm'], ['Not ready', 'Call In is refused until cleared; the attending doctor may override with a reason']])],
))

SPECS.append(dict(
    id='DispensaryCompleted', kind='list', title='Dispensary completed', area='pharm', crumbs=[['Pharmacy', 'Pharmacy.dc.html'], 'Completed · A-022'],
    badge=['ok', 'Completed 10:14'], header_actions=[dict(label='Print labels', href='DrugLabel.dc.html'), dict(label='Open invoice', href='InvoiceView.dc.html')],
    stats=[['4', 'medicines dispensed'], ['1', 'return recorded', 'ret'], ['0', 'waiting for check'], ['No refund', 'in KPOne']],
    table_title='Dispensed lines', table_note='CA-final lines · the doctor\'s order is kept beside them',
    filters=[['all', 'All'], ['ret', 'With returns'], ['edit', 'Edited by CA']],
    cols=[['Medicine', 'minmax(200px,2fr)'], ['Dispensed', '100px', 'r'], ['Batch', '120px'], ['Returned', '90px', 'r'], ['Line', '140px']], statusCol=4, min_width=700,
    rows=[
        R('d1', 'all', [{'t': 'Paracetamol 500 mg', 's': '2 tablets · 3 times a day · 5 days'}, '30 tablet', 'PC-0412', '0', ['ok', 'Dispensed']], [['Doctor ordered', '30 tablet'], ['Label', 'Printed once']], actions=[dict(label='Record a return', toast='Return recorded: waits for a CA Supervisor to check (prototype).', set=['w', 'Return waiting', 'ret'])]),
        R('d2', 'ret', [{'t': 'Amoxicillin 250 mg', 's': '1 capsule · 3 times a day · 7 days'}, '21 capsule', 'AM-2207', '7', ['w', 'Return waiting']], [['Reason', 'Patient reaction'], ['Recorded by', 'Hafiz (CA)'], ['Check by', 'Another CA Supervisor']], actions=[dict(label='Quarantine', set=['q', 'Quarantined', 'ret']), dict(label='Dispose', set=['n', 'Disposed', 'ret']), dict(label='Refuse', set=['r', 'Refused', 'ret'])]),
        R('d3', 'edit', [{'t': 'Cetirizine 10 mg', 's': '1 tablet at night · 7 days'}, '7 tablet', 'CE-0931', '0', ['i', 'Edited by CA']], [['Doctor ordered', '10 tablet'], ['Change', 'Quantity 10 → 7']]),
        R('d4', 'all', [{'t': 'Salbutamol inhaler', 's': '2 puffs when needed'}, '1 inhaler', 'SB-1102', '0', ['ok', 'Dispensed']], [['Controlled', 'No']]),
    ],
    detail_label='Line',
    notes=[dict(title='Medicine returns', items=[['Record', 'Quantity, reason and condition. Nothing moves in stock yet.'], ['Check', 'Quarantine credits the patient returns location; controlled medicines are never quarantined.']], foot='No refund is recorded in KPOne.')],
))

SPECS.append(dict(
    id='WfConsultation', kind='form', title='Consultation, new workflow', area='consult', collapsed=True, user=('Dr. Farah N.', 'Resident Doctor', 'FN'),
    crumbs=[['Consultation', 'MyQueue.dc.html'], 'A-033 · Siti Aminah'], badge=['d', 'Proposal'],
    header_actions=[dict(label='Hold'), dict(label='Send to pharmacy', primary=True, href='WfPharmacy.dc.html')],
    stats=[['Yellow', 'zone · seen sooner'], ['38.4 °C', 'by CA at 10:31'], ['BP 148/92', 'flagged high'], ['Penicillin', 'allergy']],
    sections=[
        dict(title='1 · Vital signs', note='Taken by the CA at the queue; you can add or correct', items=[
            F('t', 'Temperature', '38.4 °C', tail='Aina R. · 10:31'), F('bp', 'Blood pressure', '148/92 mmHg', tail='High', error=True),
            F('pr', 'Pulse', '96 /min', tail='Aina R. · 10:31'), F('spo', 'SpO2', '97 %', tail='Aina R. · 10:31'),
            dict(text='Each reading keeps who took it and when. A correction adds a new reading; the first one stays.', tone='i'),
        ]),
        dict(title='2 · Diagnosis and order set', note='Picking a diagnosis suggests the clinic\'s approved order set', items=[
            dict(id='dx', label='Diagnosis', choice=['Upper respiratory tract infection', 'Acute gastroenteritis', 'Hypertension review'], wide=True),
            dict(list=[['Paracetamol 500 mg', '2 tablets · 3 times a day · 5 days', 'ok', 'In order set'], ['Cetirizine 10 mg', '1 tablet at night · 5 days', 'ok', 'In order set'], ['Amoxicillin 250 mg', 'Not in the set for this diagnosis', 'w', 'Off-protocol']], show=[['dx', 'Upper respiratory tract infection']]),
            dict(list=[['Oral rehydration salts', '1 sachet after each loose stool', 'ok', 'In order set'], ['Domperidone 10 mg', '1 tablet 3 times a day · 3 days', 'ok', 'In order set']], show=[['dx', 'Acute gastroenteritis']]),
            dict(list=[['Amlodipine 5 mg', '1 tablet daily · 30 days', 'ok', 'In order set']], show=[['dx', 'Hypertension review']]),
        ]),
        dict(title='3 · Off-protocol reason', note='Needed only when you add a medicine outside the set', items=[
            dict(id='rsn', label='Reason', choice=['Clinical judgement', 'Patient request', 'Set item out of stock']),
            F('rsnx', 'Short note for the pharmacy', 'Bacterial cause suspected; review in 3 days', wide=True, hint='Shown to the CA at the pharmacy. Not printed on the label.'),
            dict(id='alg', label='I checked the allergy profile', sw=True, note='Penicillin allergy on file: amoxicillin is a penicillin. Confirm you still want it.'),
        ]),
    ],
    summary_title='This consultation', summary=[['Diagnosis', '@dx'], ['Off-protocol reason', '@rsn'], ['Zone', 'Yellow']],
    checks=[['Vitals reviewed', True], ['Allergy checked', True], ['Off-protocol reason given', True]],
    finals=[dict(label='Send to pharmacy', primary=True, href='WfPharmacy.dc.html'), dict(label='Issue MC', href='MC.dc.html')],
    summary_foot='Proposal: step 3 of the new workflow. Step 4 shows what the pharmacy sees.',
    notes=[dict(title='Why this step', items=[['Order sets', 'Fewer typed orders and fewer missed checks'], ['Off-protocol', 'Allowed, but the reason travels with the order']])],
))

SPECS.append(dict(
    id='WfPharmacy', kind='list', title='Pharmacy, new workflow', area='pharm', collapsed=True, user=('Hafiz', 'Clinic Assistant', 'HF'),
    crumbs=[['Pharmacy', 'Pharmacy.dc.html'], 'A-033 · Siti Aminah'], badge=['d', 'Proposal'],
    banner=['w', 'Off-protocol order · Amoxicillin 250 mg', 'Dr. Farah N.: "Bacterial cause suspected; review in 3 days." Allergy on file: Penicillin. The doctor confirmed the allergy check at 10:44.'],
    header_actions=[dict(label='Return to doctor', toast='Returned to Dr. Farah with your question (prototype).'), dict(label='Complete dispensing', primary=True, href='DispensaryCompleted.dc.html')],
    stats=[['3', 'medicines'], ['1', 'off-protocol', 'off'], ['1', 'allergy warning', 'off'], ['Yellow', 'zone']],
    table_title='Order from the doctor', table_note='Check each line; edit quantity or batch if needed',
    filters=[['all', 'All'], ['off', 'Off-protocol'], ['ok', 'In order set']],
    cols=[['Medicine', 'minmax(200px,2fr)'], ['Quantity', '100px', 'r'], ['Batch', '140px'], ['Check', '150px']], statusCol=3,
    rows=[
        R('m1', 'ok', [{'t': 'Paracetamol 500 mg', 's': '2 tablets · 3 times a day · 5 days'}, '30 tablet', 'PC-0412 · exp 12/2026', ['n', 'To check']], [['Source', 'In order set']], actions=[dict(label='Checked', set=['ok', 'Checked', 'ok']), dict(label='Scan box', toast='Scanned: matches this line (prototype).')]),
        R('m2', 'ok', [{'t': 'Cetirizine 10 mg', 's': '1 tablet at night · 5 days'}, '5 tablet', 'CE-0931 · exp 01/2027', ['n', 'To check']], [['Source', 'In order set']], actions=[dict(label='Checked', set=['ok', 'Checked', 'ok'])]),
        R('m3', 'off', [{'t': 'Amoxicillin 250 mg', 's': '1 capsule · 3 times a day · 7 days'}, '21 capsule', 'AM-2207 · exp 03/2027', ['w', 'Off-protocol']], [['Doctor reason', 'Clinical judgement'], ['Note', 'Review in 3 days'], ['Allergy', 'Penicillin on file']], actions=[dict(label='I have checked with the doctor', set=['ok', 'Checked', 'ok']), dict(label='Return to doctor', toast='Returned to Dr. Farah (prototype).')]),
    ],
    detail_label='Line',
    notes=[dict(title='What changes for the CA', items=[['Flag', 'Off-protocol lines are marked with the doctor\'s reason'], ['Allergy', 'The warning still needs your tick before saving']], foot='Proposal: step 4 of the new workflow.')],
))

# ---------------------------------------------------------------- Billing
SPECS.append(dict(
    id='CashClosingMonth', kind='list', title='Cash closing by month', area='bill', crumbs=[['Billing', 'Billing.dc.html'], ['Cash closing', 'CashClosing.dc.html'], 'November 2026'],
    header_actions=[dict(label='‹ October'), dict(label='December ›'), dict(label='Today\'s count', primary=True, href='CashClosing.dc.html')],
    stats=[['RM 12,460', 'counted'], ['RM 12,455', 'expected'], ['RM 5.00 over', 'difference'], ['2', 'flags', 'flag']],
    table_title='November 2026 · Cheras', table_note='Latest count per day, or the approved count',
    filters=[['all', 'All days'], ['flag', 'Needs attention'], ['ok', 'Approved']],
    cols=[['Day', '110px'], ['Counted', '110px', 'r'], ['Expected', '110px', 'r'], ['Difference', '130px'], ['Counted by', 'minmax(110px,1fr)'], ['Status', '170px']], statusCol=5, min_width=780,
    rows=[
        R('c19', 'flag', ['Thu 19 Nov', '—', 'RM 640.00', 'No counts', '—', ['i', 'Open for today']], [['Note', 'Count after the last patient']]),
        R('c18', 'flag', ['Wed 18 Nov', 'RM 1,210.00', 'RM 1,212.00', 'RM 2.00 short', 'Hafiz', ['w', 'Waiting for approval']], [['Reason', 'Coin rounding'], ['Approver', 'Not the counter']], actions=[dict(label='Approve', set=['ok', 'Approved', 'ok']), dict(label='Return for recount', set=['r', 'Returned', 'flag'])]),
        R('c17', 'ok', ['Tue 17 Nov', 'RM 980.00', 'RM 980.00', 'Matches', 'Aina R.', ['ok', 'Approved']], [['Approved by', 'Mei Lin (Finance)']]),
        R('c16', 'ok', ['Mon 16 Nov', 'RM 1,455.00', 'RM 1,448.00', 'RM 7.00 over', 'Hafiz', ['ok', 'Approved']], [['Reason', 'Change not given'], ['Approved by', 'Siti (CA Supervisor)']]),
        R('c15', 'flag', ['Sun 15 Nov', '—', 'RM 0.00', 'No counts', '—', ['n', 'Not counted']], [['Branch', 'Closed on Sunday']]),
        R('c14', 'ok', ['Sat 14 Nov', 'RM 2,105.00', 'RM 2,105.00', 'Matches', 'Aina R.', ['ok', 'Approved']], [['Refunds paid', 'RM 0.00']]),
        R('c13', 'flag', ['Fri 13 Nov', 'RM 1,330.00', 'RM 1,330.00', 'Matches', 'Hafiz', ['r', 'Changed after approval']], [['Why', 'A cash receipt was reversed later'], ['Action', 'Review the day again']]),
    ],
    row_actions=[dict(label='Open this day', href='CashClosing.dc.html')], detail_label='Day',
    notes=[dict(title='How the month reads', items=[['Expected', 'Posted cash receipts less cash refunds paid'], ['Changed after approval', 'Something moved after the day was approved']])],
))

SPECS.append(dict(
    id='ReceiptView', kind='doc', title='Receipt', area='bill', crumbs=[['Billing', 'Billing.dc.html'], ['Invoice INV-CHR-2026-004102', 'InvoiceView.dc.html'], 'Receipt RC-CHR-2026-003318'],
    header_actions=[dict(label='Back to invoice', href='InvoiceView.dc.html'), dict(label='Print receipt', print=True, primary=True)],
    doc=dict(title='OFFICIAL RECEIPT', stamp='ORIGINAL', meta=[['Receipt no.', 'RC-CHR-2026-003318'], ['Date', '19/11/2026 10:21'], ['Status', 'Posted'], ['Received from', 'Aminah Yusof'], ['KP number', 'KP-10342'], ['Invoice', 'INV-CHR-2026-004102']],
             cols=[['Description', '1fr'], ['Method', '140px'], ['Amount (RM)', '120px', 'r']], rows=[['Payment towards invoice INV-CHR-2026-004102', 'Cash', '65.00']],
             totals=[['Invoice total', '65.00'], ['Paid before', '0.00'], ['This receipt', '65.00']],
             paras=['Received for Aminah Yusof the sum of RM 65.00 via Cash towards Invoice INV-CHR-2026-004102.']),
    notes=[dict(title='Issued copies', items=[['First print', 'ORIGINAL · Aina R. · 10:21'], ['Reprints', 'Print as COPY; a changed receipt is never re-issued']], foot='Each print is recorded. Tax and e-Invoice suitability is to confirm with the clinic\'s accountant.')],
))

# ---------------------------------------------------------------- Panel
SPECS.append(dict(
    id='PanelForm', kind='form', title='Edit panel', area='panel', crumbs=[['Settings', 'Settings.dc.html'], ['Checkout settings', 'CheckoutSettings.dc.html'], 'AIA (TPA)'],
    header_actions=[dict(label='Archive panel', toast='Archived panels keep their history; they cannot be used for new visits.'), dict(label='Cancel', href='CheckoutSettings.dc.html')],
    sections=[
        dict(title='1 · Company', items=[
            dict(id='ptype', label='Panel type', choice=['Third Party Administrator', 'Direct company']),
            F('code', 'Code', 'AIA', tail='Cannot change'), F('cname', 'Company name', 'AIA Shared Services Sdn Bhd (fictional)', wide=True),
            F('pname', 'Panel name shown to staff', 'AIA'), F('ssm', 'SSM number', '201901000123', tail='12 digits'),
            F('tin', 'Income Tax Number', 'C 1234567890', optional=True),
        ]),
        dict(title='2 · Contact', items=[
            F('pic', 'Person in charge', 'Claims desk'), F('ph', 'Phone', '03-2000 7788'),
            F('em', 'Email', 'claims@example.test', wide=True), F('ad', 'Address', 'Level 3, [ADDRESS], Kuala Lumpur', wide=True),
        ]),
        dict(title='3 · Claims', note='Used for Pending, Overdue and follow-up', items=[
            dict(id='due', label='Claim due rule', choice=['N days after visit', 'Daily', 'Weekly', 'Monthly']),
            F('days', 'Days after the visit', '14', show=[['due', 'N days after visit']]),
            F('time', 'By time', '17:00', show=[['due', 'Daily']]),
            dict(id='wd', label='Weekday', choice=['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], show=[['due', 'Weekly']]),
            F('md', 'Day of month', '5', show=[['due', 'Monthly']]),
            F('fu', 'Follow up after (days)', '30'),
            dict(id='ver', label='Verification', choice=['Portal link', 'Manual remark']),
            F('url', 'Portal link', 'https://portal.example.test', show=[['ver', 'Portal link']]),
            F('rmk', 'Remark', 'Call the claims desk to verify membership', show=[['ver', 'Manual remark']], wide=True),
        ]),
    ],
    summary_title='Panel', summary=[['Type', '@ptype'], ['Due rule', '@due'], ['Verification', '@ver'], ['Price', 'Default Panel price book']],
    checks=[['Code and name', True], ['SSM number 12 digits', True], ['Due rule set', True]],
    finals=[dict(label='Save panel', primary=True, toast='Panel saved. Changes are audited by field name only (prototype).')],
    summary_foot='Prices are not set here: the Panel uses the default Panel price book unless it has its own.',
))
