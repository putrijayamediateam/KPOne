"""Bundle every project/*.dc.html screen into one self-contained clickable HTML file."""
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROJECT = os.path.join(ROOT, 'project')
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'kpone-designs.html')
NEW = set(json.load(open(os.path.join(ROOT, 'src', 'new_screens.json')))) if os.path.exists(os.path.join(ROOT, 'src', 'new_screens.json')) else set()

GROUPS = ['Today and AI agents', 'Front desk', 'Consultation and pharmacy', 'Billing', 'Panel', 'Stock and catalogue', 'HR',
          'Reports', 'Settings and management', 'Website drafts (nothing public)', 'Patient-facing, TV and sign in', 'New workflow (proposal)']
AREA_GROUP = {'today': 0, 'front': 1, 'consult': 2, 'pharm': 2, 'bill': 3, 'panel': 4, 'stock': 5, 'hr': 6, 'rep': 7, 'set': 8}
# Screens whose sidebar area does not match where people look for them.
OVERRIDE = {'AgentOffice': 0, 'AgentDailySummary': 0, 'QuickAnswers': 0, 'AllAgents': 0, 'MedicineCatalogue': 5, 'ServiceCatalogue': 5,
            'Pricing': 5, 'PriceCheck': 5, 'PriceHistory': 5, 'MedicineDetails': 5, 'Suppliers': 5, 'Insights': 7}

screens, titles, groups = {}, {}, {}
for f in sorted(glob.glob(PROJECT + '/*.dc.html')):
    n = os.path.basename(f)[:-len('.dc.html')]
    if n.startswith('Smoke') and not os.environ.get('SMOKE'):
        continue
    h = open(f, encoding='utf-8').read()
    screens[n] = h
    m = re.search(r'<title>([^<]*)', h)
    titles[n] = (m.group(1).strip() if m else n)
    a = re.search(r'<dc-import name="Sidebar" active="([a-z]+)"', h)
    if n.startswith('Wf'):
        g = 11
    elif n.startswith('Website'):
        g = 9
    elif n in OVERRIDE:
        g = OVERRIDE[n]
    elif a:
        g = AREA_GROUP.get(a.group(1), 8)
    else:
        g = 10
    groups[n] = g

canvas = json.load(open(os.path.join(PROJECT, 'canvas.json')))
board_titles = {k[:-len('.dc.html')]: v.get('title') for k, v in canvas['boards'].items()}
order = [k[:-len('.dc.html')] for k in canvas['order']]
rank = {n: i for i, n in enumerate(order)}
meta = []
for n in screens:
    if n == 'Sidebar':
        continue
    meta.append({'id': n, 'title': titles[n], 'group': GROUPS[groups[n]], 'g': groups[n], 'new': n in NEW,
                 'about': board_titles.get(n) or ''})
meta.sort(key=lambda m: (m['g'], m['new'], rank.get(m['id'], 9999), m['title'].lower()))

runtime = open(os.path.join(ROOT, 'src', 'dc-runtime.js'), encoding='utf-8').read()
labels = json.load(open(os.path.join(ROOT, 'src', 'label_map.json')))


def js(o):
    return json.dumps(o, ensure_ascii=False).replace('<', '\\u003c').replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')


page = open(os.path.join(ROOT, 'src', 'shell.html'), encoding='utf-8').read()
page = (page.replace('__COUNT__', str(len(meta)))
            .replace('__NEWCOUNT__', str(sum(1 for m in meta if m['new'])))
            .replace('__META__', js(meta))
            .replace('__LABELS__', js(labels))
            .replace('__SCREENS__', js(screens))
            .replace('__RUNTIME__', js(runtime)))
open(OUT, 'w', encoding='utf-8').write(page)
print(len(meta), 'screens,', sum(1 for m in meta if m['new']), 'new;', len(page) // 1024, 'KB ->', OUT)
