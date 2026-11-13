#!/usr/bin/env python3
"""Compare two shot folders pixel by pixel. Usage: compare.py shots-base shots-branch
Exit code 1 if any shot differs (or one folder lacks a shot). Needs Pillow."""
import os, sys
from PIL import Image, ImageChops

a, b = sys.argv[1], sys.argv[2]
names = sorted(set(os.listdir(a)) | set(os.listdir(b)))
bad = []
for n in names:
    if not (os.path.exists(f'{a}/{n}') and os.path.exists(f'{b}/{n}')):
        bad.append((n, 'missing in one folder'))
        continue
    x, y = Image.open(f'{a}/{n}').convert('RGB'), Image.open(f'{b}/{n}').convert('RGB')
    if x.size != y.size:
        bad.append((n, f'size {x.size} vs {y.size}'))
    elif (bb := ImageChops.difference(x, y).getbbox()):
        bad.append((n, f'differs in {bb}'))
print(f'{len(names)} shots, {len(names) - len(bad)} identical')
for n, why in bad:
    print('  DIFF', n, why)
sys.exit(1 if bad else 0)
