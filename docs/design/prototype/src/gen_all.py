import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gen import page
import specs_a, specs_b, specs_c, specs_d
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def fix(o):
    if isinstance(o, dict):
        return {('def' if k == 'def_' else k): fix(v) for k, v in o.items()}
    if isinstance(o, list):
        return [fix(v) for v in o]
    return o

ids = []
for s in specs_a.SPECS + specs_b.SPECS + specs_c.SPECS + specs_d.SPECS:
    s = fix(s)
    path = os.path.join(ROOT, 'project', s['id'] + '.dc.html')
    assert s['id'] not in ids, s['id']
    open(path, 'w', encoding='utf-8').write(page(s))
    ids.append(s['id'])
json.dump(ids, open(os.path.join(ROOT, 'src', 'new_screens.json'), 'w'), indent=0)
print(len(ids), 'screens generated')
