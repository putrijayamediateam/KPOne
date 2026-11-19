"""Generate KPOne Design Component (.dc.html) screens from short specs.

Every screen uses the same shell as the hand-made screens (Sidebar import, header,
stat strip, white 26px cards, chips) so new mockups look identical.
Kinds: "list" (stat strip + table with selectable rows + detail panel),
"form" (numbered sections of fields, choices and switches + summary panel),
"doc" (printable A4 sheet), and form/doc with public=True (no sidebar).
"""
import html
import json

E = html.escape

HELMET = '''<helmet>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&amp;family=Inter:wght@400;500;600;700&amp;display=swap">
<style>
[style*="overflow: auto"]:not([style*="padding"]),[style*="overflow:auto"]:not([style*="padding"]),[style*="overflow-y: auto"]:not([style*="padding"]),[style*="overflow-x: auto"]:not([style*="padding"]){padding:4px;margin:-4px}
.kp-page{container-type:inline-size;container-name:kppage}
.kp-work>*{min-width:0}
@container kppage (max-width: 1000px){
  .kp-work.kp-2{flex-wrap:wrap !important;overflow-y:auto !important;align-content:flex-start !important}
  .kp-work.kp-2>.kp-mid{flex:1 1 100% !important;max-height:none !important}
  .kp-work.kp-2>.kp-right{flex:1 1 100% !important;min-height:auto !important;overflow:visible !important;display:grid !important;grid-template-columns:repeat(auto-fit,minmax(280px,1fr)) !important;align-items:start !important}
}
@container kppage (max-width: 760px){ .kp-grid2{grid-template-columns:minmax(0,1fr) !important} .kp-hide-sm{display:none !important} }
@media (prefers-reduced-motion: reduce){*{transition:none !important}}
@media print{ .kp-noprint{display:none !important} body{background:#ffffff !important} .kp-sheet{box-shadow:none !important;margin:0 !important} }
button:focus-visible,a:focus-visible{outline:2px solid #0a56d1;outline-offset:2px}
body{margin:0;background:#f4f4f6}
a{color:inherit;text-decoration:none}
</style>
</helmet>'''

CARD = 'background: #ffffff; border-radius: 26px; box-shadow: 0 1px 2px rgba(17,17,26,0.05), 0 0 0 1px rgba(17,17,26,0.04)'
H2 = 'margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -0.01em'
BTN_DARK = 'display: inline-flex; align-items: center; justify-content: center; border: 0; background: #111114; color: #ffffff; border-radius: 999px; padding: 0 20px; min-height: 38px; font: inherit; font-weight: 600; cursor: pointer; white-space: nowrap'
BTN_LIGHT = 'display: inline-flex; align-items: center; justify-content: center; border: 0; background: #ffffff; color: #111114; border-radius: 999px; padding: 0 16px; min-height: 38px; font: inherit; font-weight: 500; box-shadow: 0 0 0 1px rgba(17,17,26,0.08); cursor: pointer; white-space: nowrap'

TONES = {
    'w': ('#fff4dc', '#8a5a00'), 'd': ('#fde7ef', '#c2185b'), 'ok': ('#e9f8ee', '#1d7a3a'),
    'i': ('#e8f0fe', '#1a4fa8'), 'q': ('#f1ecfd', '#5a32a8'), 'n': ('#f4f4f6', '#6b6b73'),
    'r': ('#fff1f0', '#a1281c'), 'k': ('#111114', '#ffffff'),
}

USERS = {
    'front': ('Aina R.', 'CA Supervisor', 'AR'), 'consult': ('Dr. Farah N.', 'Resident Doctor', 'FN'),
    'pharm': ('Hafiz', 'Clinic Assistant', 'HF'), 'bill': ('Aina R.', 'CA Supervisor', 'AR'),
    'panel': ('Deepa', 'Panel Officer', 'DP'), 'stock': ('Siti', 'CA Supervisor', 'SI'),
    'hr': ('Haikal', 'HR Manager', 'HK'), 'rep': ('Mei Lin', 'Finance Officer', 'ML'),
    'set': ('Dr. Director', 'Director', 'DR'), 'today': ('Dr. Director', 'Director', 'DR'),
}


def action_markup(a, key):
    """A header action: a link to another screen, or a button that shows a toast."""
    style = BTN_DARK if a.get('primary') else BTN_LIGHT
    if a.get('href'):
        return f'<a href="{E(a["href"])}" style="{style}">{E(a["label"])}</a>'
    if a.get('print'):
        return f'<button type="button" onClick="{{{{H.print}}}}" style="{style}">{E(a["label"])}</button>'
    return f'<button type="button" onClick="{{{{H.{key}}}}}" style="{style}">{E(a["label"])}</button>'


def header(spec):
    crumbs = spec.get('crumbs', [])
    parts = []
    for i, c in enumerate(crumbs):
        if i:
            parts.append('<span aria-hidden="true" style="color: #c4c4cc">/</span>')
        last = i == len(crumbs) - 1
        if isinstance(c, list):  # [label, href]
            parts.append(f'<a href="{E(c[1])}" style="font-size: 13px; color: #6b6b73">{E(c[0])}</a>')
        else:
            parts.append(f'<div style="font-size: 13px;{" font-weight: 600" if last else " color: #6b6b73"}">{E(c)}</div>')
    badge = ''
    if spec.get('badge'):
        bg, fg = TONES[spec['badge'][0]]
        badge = f'<span style="display: inline-flex; align-items: center; margin-left: 8px; font-size: 12px; font-weight: 600; color: {fg}; background: {bg}; border-radius: 999px; padding: 4px 10px">{E(spec["badge"][1])}</span>'
    search = ''
    if spec.get('search'):
        search = (f'<button type="button" class="kp-hide-sm" onClick="{{{{H.search}}}}" style="margin-left: auto; display: flex; align-items: center; gap: 10px; border: 0; background: #ffffff; border-radius: 999px; padding: 0 14px; min-height: 38px; font: inherit; color: #6b6b73; box-shadow: 0 0 0 1px rgba(17,17,26,0.06); cursor: pointer">'
                  f'{E(spec["search"])}<span style="font-size: 11.5px; background: #f4f4f6; border-radius: 6px; padding: 1px 6px">⌘K</span></button>')
    acts = ''.join(action_markup(a, f'h{i}') for i, a in enumerate(spec.get('header_actions', [])))
    spacer = '' if search else '<span style="margin-left: auto"></span>'
    return (f'<header class="kp-noprint" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 28px; background: rgba(244,244,246,0.78); backdrop-filter: saturate(160%) blur(14px); -webkit-backdrop-filter: saturate(160%) blur(14px); border-bottom: 1px solid rgba(17,17,20,0.05)">'
            f'{"".join(parts)}{badge}{search}{spacer}{acts}</header>')


def strip(spec):
    out = []
    for i, s in enumerate(spec.get('stats', [])):
        out.append(f'<button type="button" aria-pressed="{{{{SP.s{i}}}}}" onClick="{{{{SF.s{i}}}}}" style="{{{{SS.s{i}}}}}">'
                   f'<span style="flex: none; white-space: nowrap; font-size: 18px; font-weight: 600; letter-spacing: -0.02em">{E(s[0])}</span>'
                   f'<span style="{{{{SK.s{i}}}}}">{E(s[1])}</span></button>')
    if not out:
        return ''
    return f'<div style="display: flex; gap: 10px; flex-wrap: wrap">{"".join(out)}</div>'


def notes(spec):
    out = []
    for n in spec.get('notes', []):
        items = ''.join(
            f'<div style="padding: 10px 12px; border-radius: 16px; background: #f8f8fa; display: flex; flex-direction: column; gap: 2px"><span style="font-weight: 500">{E(k)}</span><span style="font-size: 12.5px; color: #6b6b73">{E(v)}</span></div>'
            for k, v in n.get('items', []))
        foot = f'<span style="font-size: 12px; color: #8a8a93">{E(n["foot"])}</span>' if n.get('foot') else ''
        out.append(f'<section style="{CARD}; padding: 18px 16px; display: flex; flex-direction: column; gap: 8px"><h2 style="margin: 0; font-size: 15px; font-weight: 600">{E(n["title"])}</h2>{items}{foot}</section>')
    return ''.join(out)


def sidebar(spec):
    area = spec.get('area', 'today')
    name, role, ini = spec.get('user') or USERS.get(area, USERS['today'])
    collapsed = ' start-collapsed="{{true}}"' if spec.get('collapsed') else ''
    return (f'<div class="kp-noprint" style="flex: none; position: sticky; top: 0; align-self: flex-start; background: #f4f4f6">'
            f'<dc-import name="Sidebar" active="{area}" accent="{{{{accent}}}}" branch="{E(spec.get("branch", "Cheras"))}" user-name="{E(name)}" user-role="{E(role)}" user-ini="{E(ini)}" duty="07:55"{collapsed} hint-size="350px,1080px"></dc-import></div>')


TOAST = ('<sc-if value="{{toastOn}}" hint-placeholder-val="{{ false }}"><div role="status" class="kp-noprint" style="position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 50; max-width: min(560px, calc(100vw - 32px)); background: #111114; color: #ffffff; border-radius: 16px; padding: 12px 18px; font-size: 13.5px; font-weight: 500; box-shadow: 0 12px 32px rgba(17,17,20,0.28)">{{toast}}</div></sc-if>')

DETAIL = f'''<section style="{CARD}; padding: 18px 16px; display: flex; flex-direction: column; gap: 10px">
          <div style="display: flex; align-items: flex-start; gap: 10px"><span style="flex: 1; min-width: 0"><span style="display: block; font-size: 12px; font-weight: 600; color: #8a8a93; letter-spacing: 0.04em">{{{{detailLabel}}}}</span><h2 style="margin: 2px 0 0; font-size: 17px; font-weight: 600; letter-spacing: -0.01em">{{{{sel.title}}}}</h2><span style="font-size: 12.5px; color: #6b6b73">{{{{sel.sub}}}}</span></span><sc-if value="{{{{sel.hasStatus}}}}" hint-placeholder-val="{{{{ true }}}}"><span style="{{{{sel.statusStyle}}}}">{{{{sel.status}}}}</span></sc-if></div>
          <div style="display: flex; flex-direction: column">
            <sc-for list="{{{{sel.facts}}}}" as="f" hint-placeholder-count="4"><div style="display: flex; justify-content: space-between; gap: 12px; min-height: 36px; align-items: center; border-top: 1px solid #f4f4f6"><span style="font-size: 13px; color: #6b6b73">{{{{f.k}}}}</span><span style="font-size: 13px; font-weight: 500; text-align: right">{{{{f.v}}}}</span></div></sc-for>
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px">
            <sc-for list="{{{{sel.actions}}}}" as="a" hint-placeholder-count="2"><sc-if value="{{{{a.isLink}}}}" hint-placeholder-val="{{{{ false }}}}"><a href="{{{{a.href}}}}" style="{{{{a.style}}}}">{{{{a.label}}}}</a></sc-if><sc-if value="{{{{a.isBtn}}}}" hint-placeholder-val="{{{{ true }}}}"><button type="button" onClick="{{{{a.go}}}}" style="{{{{a.style}}}}">{{{{a.label}}}}</button></sc-if></sc-for>
          </div>
          <sc-if value="{{{{sel.hasNote}}}}" hint-placeholder-val="{{{{ false }}}}"><span style="font-size: 12px; color: #8a8a93">{{{{sel.note}}}}</span></sc-if>
        </section>'''


def list_body(spec):
    cols = spec['cols']
    grid = ' '.join(c[1] for c in cols)
    heads = ''.join(f'<span role="columnheader" style="font-size: 11.5px; font-weight: 600; color: #8a8a93; letter-spacing: 0.04em; white-space: nowrap;{" text-align: right;" if len(c) > 2 and c[2] == "r" else ""}">{E(c[0].upper())}</span>' for c in cols)
    tabs = ''
    if spec.get('filters'):
        tabs = ('<span style="margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap"><sc-for list="{{tabs}}" as="tb" hint-placeholder-count="3">'
                '<button type="button" aria-pressed="{{tb.pressed}}" onClick="{{tb.pick}}" style="{{tb.style}}">{{tb.label}} <span style="opacity: 0.7">{{tb.n}}</span></button></sc-for></span>')
    banner = ''
    if spec.get('banner'):
        tone, title, text = spec['banner']
        bg, fg = TONES[tone]
        banner = (f'<div role="note" style="display: flex; align-items: center; gap: 14px; background: {bg}; border-radius: 22px; padding: 14px 18px">'
                  f'<span style="flex: 1; min-width: 0"><span style="display: block; font-weight: 700; color: {fg}">{E(title)}</span><span style="font-size: 13px; color: #3a3a40">{E(text)}</span></span></div>')
    table = f'''<section style="flex: 1; min-height: 0; overflow: auto; {CARD}; padding: 16px 14px 10px; display: flex; flex-direction: column; gap: 6px">
          <div style="display: flex; align-items: center; gap: 10px; margin: 0 6px 6px; flex-wrap: wrap">
            <h1 style="{H2}">{E(spec.get('table_title', spec['title']))}</h1>
            <span style="font-size: 12.5px; color: #8a8a93">{E(spec.get('table_note', ''))}</span>
            {tabs}
          </div>
          <div role="table" aria-label="{E(spec.get('table_title', spec['title']))}" style="overflow-x: auto; display: flex; flex-direction: column">
            <div role="row" style="display: grid; grid-template-columns: {grid}; gap: 12px; align-items: center; padding: 0 10px 8px; min-width: {spec.get('min_width', 640)}px">{heads}</div>
            <sc-for list="{{{{rows}}}}" as="r" hint-placeholder-count="8">
              <button type="button" role="row" aria-pressed="{{{{r.on}}}}" onClick="{{{{r.pick}}}}" style="{{{{r.style}}}}"><sc-for list="{{{{r.cells}}}}" as="c" hint-placeholder-count="5"><span role="cell" style="{{{{c.wrap}}}}"><span style="{{{{c.s}}}}">{{{{c.t}}}}</span><sc-if value="{{{{c.hasSub}}}}" hint-placeholder-val="{{{{ false }}}}"><span style="display: block; font-size: 12px; color: #8a8a93; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{{{c.sub}}}}</span></sc-if></span></sc-for></button>
            </sc-for>
            <sc-if value="{{{{empty}}}}" hint-placeholder-val="{{{{ false }}}}"><p style="margin: 0; padding: 24px 10px; color: #8a8a93; text-align: center">Nothing in this list.</p></sc-if>
          </div>
          <p style="margin: 6px 10px 0; font-size: 12px; color: #8a8a93">{E(spec.get('table_foot', ''))}</p>
        </section>'''
    return f'''<div class="kp-work kp-2" style="flex: 1; min-height: 0; padding: 16px 20px 20px; display: flex; gap: 16px; align-items: stretch">
      <main class="kp-mid" style="flex: 1 1 0; min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: 14px">
        {banner}
        {strip(spec)}
        {table}
      </main>
      <aside class="kp-right" style="flex: 0 0 {spec.get('aside_w', 320)}px; min-width: 0; min-height: 0; overflow: auto; display: flex; flex-direction: column; gap: 14px">
        {DETAIL}
        {notes(spec)}
      </aside>
    </div>'''


FORM_ITEMS = '''<sc-for list="{{sec.items}}" as="it" hint-placeholder-count="4">
              <sc-if value="{{it.isField}}" hint-placeholder-val="{{ true }}"><div style="{{it.cell}}"><span style="font-size: 13px; font-weight: 500; color: #3a3a40">{{it.label}} <span style="color: #8a8a93; font-weight: 400">{{it.opt}}</span></span><button type="button" aria-label="{{it.label}}: {{it.shown}}" onClick="{{it.focus}}" style="{{it.box}}"><span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left">{{it.shown}}</span><sc-if value="{{it.caret}}" hint-placeholder-val="{{ false }}"><span aria-hidden="true" style="display: inline-block; width: 1.5px; height: 16px; background: #111114"></span></sc-if><span style="font-size: 12px; color: #8a8a93">{{it.tail}}</span></button><sc-if value="{{it.hasHint}}" hint-placeholder-val="{{ false }}"><span style="font-size: 12px; color: #8a8a93">{{it.hint}}</span></sc-if></div></sc-if>
              <sc-if value="{{it.isChoice}}" hint-placeholder-val="{{ false }}"><div style="{{it.cell}}"><span style="font-size: 13px; font-weight: 500; color: #3a3a40">{{it.label}}</span><div style="display: inline-flex; flex-wrap: wrap; align-self: flex-start; background: #f4f4f6; border-radius: 22px; padding: 4px; gap: 2px"><sc-for list="{{it.opts}}" as="o" hint-placeholder-count="2"><button type="button" aria-pressed="{{o.pressed}}" onClick="{{o.pick}}" style="{{o.style}}">{{o.label}}</button></sc-for></div><sc-if value="{{it.hasHint}}" hint-placeholder-val="{{ false }}"><span style="font-size: 12px; color: #8a8a93">{{it.hint}}</span></sc-if></div></sc-if>
              <sc-if value="{{it.isSwitch}}" hint-placeholder-val="{{ false }}"><div style="grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 52px; border-top: 1px solid #f1f1f4; padding-top: 6px"><span><span style="display: block; font-weight: 500">{{it.label}}</span><span style="font-size: 12.5px; color: #6b6b73">{{it.note}}</span></span><button type="button" role="switch" aria-checked="{{it.on}}" aria-label="{{it.label}}" onClick="{{it.toggle}}" style="{{it.track}}"><span style="{{it.knob}}"></span></button></div></sc-if>
              <sc-if value="{{it.isNote}}" hint-placeholder-val="{{ false }}"><div style="{{it.cell}}"><div style="{{it.noteStyle}}">{{it.text}}</div></div></sc-if>
              <sc-if value="{{it.isList}}" hint-placeholder-val="{{ false }}"><div style="grid-column: 1 / -1; display: flex; flex-direction: column"><sc-for list="{{it.rows}}" as="lr" hint-placeholder-count="3"><button type="button" onClick="{{lr.pick}}" style="{{lr.style}}"><span style="flex: 1; min-width: 0; text-align: left"><span style="display: block; font-weight: 500">{{lr.t}}</span><span style="font-size: 12px; color: #8a8a93">{{lr.s}}</span></span><span style="{{lr.chipStyle}}">{{lr.chip}}</span></button></sc-for></div></sc-if>
            </sc-for>'''


def form_body(spec):
    secs = f'''<sc-for list="{{{{sections}}}}" as="sec" hint-placeholder-count="3">
        <section style="{CARD}; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px">
          <div style="display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap"><h2 style="{H2}">{{{{sec.title}}}}</h2><span style="font-size: 12.5px; color: #8a8a93">{{{{sec.note}}}}</span></div>
          <div class="kp-grid2" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 14px">
            {FORM_ITEMS}
          </div>
        </section>
      </sc-for>'''
    summary = f'''<section style="{CARD}; padding: 18px 16px; display: flex; flex-direction: column; gap: 10px">
          <h2 style="{H2}">{E(spec.get('summary_title', 'Summary'))}</h2>
          <div style="display: flex; flex-direction: column"><sc-for list="{{{{summary}}}}" as="f" hint-placeholder-count="4"><div style="display: flex; justify-content: space-between; gap: 12px; min-height: 36px; align-items: center; border-top: 1px solid #f4f4f6"><span style="font-size: 13px; color: #6b6b73">{{{{f.k}}}}</span><span style="font-size: 13px; font-weight: 500; text-align: right">{{{{f.v}}}}</span></div></sc-for></div>
          <div style="display: flex; flex-direction: column; gap: 6px"><sc-for list="{{{{checks}}}}" as="ck" hint-placeholder-count="3"><div style="display: flex; align-items: center; gap: 8px; font-size: 13px"><span aria-hidden="true" style="{{{{ck.dot}}}}"></span><span style="{{{{ck.ts}}}}">{{{{ck.t}}}}</span></div></sc-for></div>
          <sc-for list="{{{{finals}}}}" as="a" hint-placeholder-count="2"><sc-if value="{{{{a.isLink}}}}" hint-placeholder-val="{{{{ false }}}}"><a href="{{{{a.href}}}}" style="{{{{a.style}}}}">{{{{a.label}}}}</a></sc-if><sc-if value="{{{{a.isBtn}}}}" hint-placeholder-val="{{{{ true }}}}"><button type="button" onClick="{{{{a.go}}}}" style="{{{{a.style}}}}">{{{{a.label}}}}</button></sc-if></sc-for>
          <span style="font-size: 12px; color: #8a8a93">{E(spec.get('summary_foot', ''))}</span>
        </section>'''
    if spec.get('public'):
        return f'''<div style="flex: 1; min-height: 0; overflow: auto; padding: 24px 16px 40px; display: flex; justify-content: center">
      <div style="width: 100%; max-width: 640px; display: flex; flex-direction: column; gap: 14px">
        {strip(spec)}
        {secs}
        {summary}
        {notes(spec)}
      </div>
    </div>'''
    return f'''<div class="kp-work kp-2" style="flex: 1; min-height: 0; padding: 16px 20px 20px; display: flex; gap: 16px; align-items: stretch">
      <main class="kp-mid" style="flex: 1 1 0; min-width: 0; min-height: 0; overflow: auto; display: flex; flex-direction: column; gap: 14px">
        {strip(spec)}
        {secs}
      </main>
      <aside class="kp-right" style="flex: 0 0 {spec.get('aside_w', 320)}px; min-width: 0; min-height: 0; overflow: auto; display: flex; flex-direction: column; gap: 14px">
        {summary}
        {notes(spec)}
      </aside>
    </div>'''


def doc_body(spec):
    d = spec['doc']
    meta = ''.join(f'<div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; font-weight: 600; letter-spacing: 0.04em; color: #6b6b73">{E(k.upper())}</span><span style="font-weight: 500">{E(v)}</span></div>' for k, v in d.get('meta', []))
    cols = d.get('cols', [])
    grid = ' '.join(c[1] for c in cols)
    head = ''.join(f'<span style="font-size: 11px; font-weight: 700; letter-spacing: 0.04em;{" text-align: right;" if len(c) > 2 else ""}">{E(c[0].upper())}</span>' for c in cols)
    rows = ''.join(
        f'<div style="display: grid; grid-template-columns: {grid}; gap: 10px; padding: 7px 0; border-bottom: 1px solid #ececf0">' +
        ''.join(f'<span style="{"text-align: right; font-variant-numeric: tabular-nums;" if len(cols[j]) > 2 else ""}">{E(v)}</span>' for j, v in enumerate(r)) + '</div>'
        for r in d.get('rows', []))
    totals = ''.join(f'<div style="display: flex; justify-content: space-between; gap: 20px; padding: 4px 0;{" font-weight: 700; font-size: 15px; border-top: 1.5px solid #111114; margin-top: 4px; padding-top: 8px" if i == len(d.get("totals", [])) - 1 else ""}"><span>{E(k)}</span><span style="font-variant-numeric: tabular-nums">{E(v)}</span></div>' for i, (k, v) in enumerate(d.get('totals', [])))
    paras = ''.join(f'<p style="margin: 0; font-size: 12.5px; color: #3a3a40">{E(p)}</p>' for p in d.get('paras', []))
    sign = ''.join(f'<div style="flex: 1; display: flex; flex-direction: column; gap: 4px"><span style="height: 36px; border-bottom: 1px solid #111114"></span><span style="font-size: 12px; color: #3a3a40">{E(s)}</span></div>' for s in d.get('sign', []))
    stamp = ''
    if d.get('stamp'):
        stamp = f'<span style="align-self: flex-start; border: 2px solid #111114; border-radius: 6px; padding: 2px 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.08em">{E(d["stamp"])}</span>'
    sheet = f'''<article class="kp-sheet" style="width: 100%; max-width: 794px; box-sizing: border-box; background: #ffffff; color: #111114; border-radius: 6px; box-shadow: 0 1px 2px rgba(17,17,26,0.06), 0 12px 32px rgba(17,17,26,0.08); padding: 48px 52px; display: flex; flex-direction: column; gap: 18px; font-family: Inter, -apple-system, 'Segoe UI', sans-serif; font-size: 13px">
          <div style="display: flex; align-items: flex-start; gap: 14px; border-bottom: 1.5px solid #111114; padding-bottom: 14px"><span style="width: 44px; height: 44px; border-radius: 12px; background: #c2185b; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 800">KP</span><span style="flex: 1"><span style="display: block; font-weight: 800; font-size: 15px; letter-spacing: 0.02em">KLINIK PUTRIJAYA (CHERAS)</span><span style="display: block; font-size: 12px; color: #3a3a40">[CLINIC ADDRESS LINE] · Email: [CLINIC EMAIL] · Tel: 03-2000 0001</span></span>{stamp}</div>
          <h1 style="margin: 0; text-align: center; font-size: 17px; font-weight: 800; letter-spacing: 0.06em; text-decoration: underline">{E(d['title'])}</h1>
          <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px 20px">{meta}</div>
          <div style="display: flex; flex-direction: column"><div style="display: grid; grid-template-columns: {grid}; gap: 10px; padding: 6px 0; border-top: 1.5px solid #111114; border-bottom: 1.5px solid #111114">{head}</div>{rows}</div>
          <div style="align-self: flex-end; width: 300px; display: flex; flex-direction: column">{totals}</div>
          {paras}
          <div style="display: flex; gap: 40px; margin-top: 18px">{sign}</div>
          <p style="margin: 8px 0 0; text-align: center; font-size: 11.5px; font-style: italic; color: #6b6b73">{E(d.get('footer', 'This is a computer-generated document. No signature is required.'))}</p>
        </article>'''
    return f'''<div class="kp-work kp-2" style="flex: 1; min-height: 0; padding: 16px 20px 20px; display: flex; gap: 16px; align-items: stretch">
      <main class="kp-mid" style="flex: 1 1 0; min-width: 0; min-height: 0; overflow: auto; display: flex; justify-content: center; padding: 8px 0">
        {sheet}
      </main>
      <aside class="kp-right kp-noprint" style="flex: 0 0 300px; min-width: 0; min-height: 0; overflow: auto; display: flex; flex-direction: column; gap: 14px">
        {notes(spec)}
      </aside>
    </div>'''


LOGIC = r'''
class Component extends DCLogic {
  renderVals() {
    const S = __SPEC__;
    const st = this.state || {};
    const accent = this.props.accent ?? '#c2185b';
    const T = { w: ['#fff4dc', '#8a5a00'], d: ['#fde7ef', '#c2185b'], ok: ['#e9f8ee', '#1d7a3a'], i: ['#e8f0fe', '#1a4fa8'], q: ['#f1ecfd', '#5a32a8'], n: ['#f4f4f6', '#6b6b73'], r: ['#fff1f0', '#a1281c'], k: ['#111114', '#ffffff'] };
    const chip = t => { const c = T[t] || T.n; return 'display:inline-flex;align-items:center;font-size:12px;font-weight:600;border-radius:999px;padding:3px 10px;white-space:nowrap;background:' + c[0] + ';color:' + c[1] + ';'; };
    const say = m => { clearTimeout(this.__tt); this.setState({ toast: m }); this.__tt = setTimeout(() => this.setState({ toast: null }), 2800); };
    const btn = (primary, full) => 'display:flex;align-items:center;justify-content:center;min-height:44px;border:0;border-radius:999px;padding:0 18px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;box-sizing:border-box;' + (full ? 'width:100%;' : '') + (primary ? 'background:#111114;color:#ffffff;' : 'background:#f4f4f6;color:#111114;');
    const H = { search: () => say('Search: type a name, IC or number in the real app.'), print: () => { try { window.print(); } catch (e) {} } };
    (S.header_actions || []).forEach((a, i) => { H['h' + i] = () => say(a.toast || (a.label + ': done in the prototype. Nothing is saved.')); });
    const out = { accent, H, toast: st.toast || '', toastOn: !!st.toast };
    const mkAct = (a, onSet, primaryIdx, i) => {
      const href = a.href || '';
      return { label: a.label, isLink: !!href, isBtn: !href, href, style: btn(a.primary || i === primaryIdx, true), go: () => { if (onSet) onSet(a); say(a.toast || (a.label + ': done in the prototype. Nothing is saved.')); } };
    };

    // Stat strip: each card can filter the table (or just show a message).
    const f = st.f || 'all';
    const SP = {}, SF = {}, SS = {}, SK = {};
    (S.stats || []).forEach((s, i) => {
      const key = s[2] || null, hot = i === 0, on = !!key && f === key;
      SP['s' + i] = on ? 'true' : 'false';
      SF['s' + i] = () => key ? this.setState({ f: on ? 'all' : key, sel: null }) : say(s[3] || (s[0] + ' ' + s[1]));
      SS['s' + i] = 'flex:1 1 140px;display:flex;align-items:baseline;gap:8px;border:0;border-radius:18px;padding:10px 16px;font:inherit;text-align:left;cursor:pointer;min-height:44px;box-sizing:border-box;' + (hot ? 'background:linear-gradient(120deg,#e0457f,' + accent + ' 45%,#7a2bd1);color:#ffffff;' : 'background:#ffffff;color:#111114;box-shadow:0 1px 2px rgba(17,17,26,0.05),0 0 0 1px rgba(17,17,26,0.04);') + (on ? 'box-shadow:0 0 0 2px #111114;' : '');
      SK['s' + i] = 'font-size:12.5px;' + (hot ? 'opacity:0.85;' : 'color:#6b6b73;');
    });
    Object.assign(out, { SP, SF, SS, SK });

    if (S.kind === 'list') {
      const ov = st.ov || {};
      const cols = S.cols;
      const all = S.rows.map(r => {
        const cells = r.cells.slice();
        if (S.statusCol != null && ov[r.id]) cells[S.statusCol] = ov[r.id];
        return Object.assign({}, r, { cells, tagNow: (ov[r.id] && ov[r.id][2]) || r.tag });
      });
      const vis = all.filter(r => f === 'all' || r.tagNow === f || (r.tags || []).indexOf(f) >= 0);
      const selId = vis.some(r => r.id === st.sel) ? st.sel : (vis[0] && vis[0].id);
      const grid = 'display:grid;grid-template-columns:' + cols.map(c => c[1]).join(' ') + ';gap:12px;align-items:center;padding:6px 10px;min-height:52px;min-width:' + (S.min_width || 640) + 'px;';
      out.rows = vis.map(r => {
        const on = r.id === selId;
        return { on: on ? 'true' : 'false', pick: () => this.setState({ sel: r.id }),
          style: grid + 'border:0;border-top:1px solid #f1f1f4;border-radius:12px;font:inherit;color:inherit;text-align:left;cursor:pointer;width:100%;box-sizing:border-box;' + (on ? 'background:#f4f4f6;' : 'background:transparent;'),
          cells: r.cells.map((v, j) => {
            const right = cols[j] && cols[j][2] === 'r';
            const wrap = 'min-width:0;' + (right ? 'text-align:right;' : '');
            if (Array.isArray(v)) return { t: v[1], s: chip(v[0]), wrap, hasSub: false, sub: '' };
            const o = typeof v === 'object' && v !== null ? v : { t: String(v) };
            return { t: o.t, sub: o.s || '', hasSub: !!o.s, wrap, s: 'display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' + (j === 0 ? 'font-weight:600;color:#111114;' : 'color:#3a3a40;') + (right ? 'font-variant-numeric:tabular-nums;' : '') };
          }) };
      });
      out.empty = vis.length === 0;
      out.tabs = (S.filters || []).map(([k, label]) => ({ label, n: String(k === 'all' ? all.length : all.filter(r => r.tagNow === k || (r.tags || []).indexOf(k) >= 0).length), pressed: String(f === k), pick: () => this.setState({ f: k, sel: null }),
        style: 'border:0;cursor:pointer;font:inherit;font-size:12.5px;border-radius:999px;padding:6px 12px;min-height:32px;' + (f === k ? 'background:#ffffff;color:#111114;font-weight:600;box-shadow:inset 0 0 0 1.5px #111114;' : 'background:#f4f4f6;color:#3a3a40;') }));
      const r = all.find(x => x.id === selId);
      out.detailLabel = (S.detail_label || 'SELECTED').toUpperCase();
      if (r) {
        const sc = S.statusCol != null ? r.cells[S.statusCol] : null;
        const acts = (r.actions || S.row_actions || []).filter(a => !a.when || (sc && a.when.indexOf(sc[1]) >= 0));
        out.sel = { title: r.title || (typeof r.cells[0] === 'object' && !Array.isArray(r.cells[0]) ? r.cells[0].t : String(r.cells[0])), sub: r.sub || '', hasStatus: !!sc, status: sc ? sc[1] : '', statusStyle: sc ? chip(sc[0]) : '',
          facts: (r.facts || []).map(([k, v]) => ({ k, v })), note: r.note || S.detail_note || '', hasNote: !!(r.note || S.detail_note),
          actions: acts.map((a, i) => mkAct(a, a.set ? (x => this.setState({ ov: Object.assign({}, ov, { [r.id]: x.set }) })) : null, 0, i)) };
      } else {
        out.sel = { title: 'Nothing selected', sub: '', hasStatus: false, facts: [], actions: [], hasNote: false, note: '' };
      }
    }

    if (S.kind === 'form') {
      const ch = st.ch || {}, sw = st.sw || {}, focus = st.focus || null;
      const val = (id, def) => ch[id] !== undefined ? ch[id] : def;
      const swv = (id, def) => sw[id] !== undefined ? sw[id] : !!def;
      const ctx = { val: id => { for (const s of S.sections) for (const it of s.items) if (it.id === id) { if (it.choice) return val(id, it.def != null ? it.def : it.choice[0]); if (it.sw !== undefined) return swv(id, it.sw); return it.value; } return ''; } };
      const visible = it => !it.show || it.show.every(([id, v]) => String(ctx.val(id)).toLowerCase() === String(v).toLowerCase());
      out.sections = S.sections.filter(visible).map(s => ({ title: s.title, note: s.note || '', items: s.items.filter(visible).map(it => {
        const cell = 'display:flex;flex-direction:column;gap:6px;min-width:0;' + (it.wide ? 'grid-column:1 / -1;' : '');
        const base = { isField: false, isChoice: false, isSwitch: false, isNote: false, isList: false, cell, label: it.label || '', hint: it.hint || '', hasHint: !!it.hint };
        if (it.choice) {
          const cur = val(it.id, it.def != null ? it.def : it.choice[0]);
          return Object.assign(base, { isChoice: true, opts: it.choice.map(o => ({ label: o, pressed: String(cur === o), pick: () => this.setState({ ch: Object.assign({}, ch, { [it.id]: o }) }),
            style: 'border:0;border-radius:999px;padding:8px 14px;min-height:36px;font:inherit;font-size:13px;white-space:nowrap;cursor:pointer;' + (cur === o ? 'background:#111114;color:#ffffff;font-weight:600;' : 'background:transparent;color:#3a3a40;') })) });
        }
        if (it.sw !== undefined) {
          const on = swv(it.id, it.sw);
          return Object.assign(base, { isSwitch: true, note: it.note || '', on: String(on), toggle: () => this.setState({ sw: Object.assign({}, sw, { [it.id]: !on }) }),
            track: 'flex:none;width:46px;height:28px;border:0;border-radius:999px;padding:2px;box-sizing:border-box;display:flex;cursor:pointer;justify-content:' + (on ? 'flex-end' : 'flex-start') + ';background:' + (on ? accent : '#e3e3e8') + ';',
            knob: 'width:24px;height:24px;border-radius:50%;background:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,0.2);' });
        }
        if (it.text) {
          const c = T[it.tone || 'n'];
          return Object.assign(base, { isNote: true, text: it.text, cell: cell + 'grid-column:1 / -1;', noteStyle: 'border-radius:16px;padding:12px 14px;font-size:13px;background:' + c[0] + ';color:' + (it.tone && it.tone !== 'n' ? c[1] : '#3a3a40') + ';' });
        }
        if (it.list) {
          return Object.assign(base, { isList: true, rows: it.list.map(([t, s, tone, c]) => ({ t, s, chip: c || '', chipStyle: c ? chip(tone) : 'display:none;', pick: () => say(t + (c ? ' · ' + c : '')),
            style: 'display:flex;align-items:center;gap:10px;min-height:52px;padding:4px 8px;border:0;border-top:1px solid #f4f4f6;background:transparent;font:inherit;color:inherit;cursor:pointer;text-align:left;' })) });
        }
        const on = focus === it.id;
        const empty = !it.value;
        return Object.assign(base, { isField: true, opt: it.optional ? '· optional' : '', shown: empty ? (it.ph || '') : it.value, tail: it.tail || '', caret: on,
          focus: () => this.setState({ focus: on ? null : it.id }),
          box: 'display:flex;align-items:center;gap:8px;min-height:44px;padding:0 14px;border:0;border-radius:14px;font:inherit;cursor:text;width:100%;box-sizing:border-box;background:#f4f4f6;' + (empty ? 'color:#8a8a93;' : 'color:#111114;') + (on ? 'box-shadow:inset 0 0 0 2px #111114;' : (it.error ? 'box-shadow:inset 0 0 0 2px #e5383b;' : '')) });
      }) }));
      out.summary = (S.summary || []).map(([k, v]) => ({ k, v: typeof v === 'string' && v.charAt(0) === '@' ? String(ctx.val(v.slice(1))) : v }));
      out.checks = (S.checks || []).map(([t, ok]) => ({ t, dot: 'flex:none;width:8px;height:8px;border-radius:50%;background:' + (ok ? '#34c759' : '#e3e3e8') + ';', ts: ok ? 'color:#111114;' : 'color:#8a8a93;' }));
      out.finals = (S.finals || []).map((a, i) => mkAct(a, null, 0, i));
    }
    return out;
  }
}
'''


def page(spec):
    kind = spec['kind']
    body = {'list': list_body, 'form': form_body, 'doc': doc_body}[kind](spec)
    public = spec.get('public')
    js_spec = {k: spec[k] for k in ('kind', 'stats', 'rows', 'cols', 'statusCol', 'filters', 'row_actions', 'detail_label', 'detail_note', 'min_width',
                                    'sections', 'summary', 'checks', 'finals', 'header_actions') if k in spec}
    logic = LOGIC.replace('__SPEC__', json.dumps(js_spec, ensure_ascii=False))
    if public:
        brand = (f'<header class="kp-noprint" style="display: flex; align-items: center; gap: 12px; padding: 16px 24px; background: #ffffff; border-bottom: 1px solid #ececf0">'
                 f'<span style="width: 40px; height: 40px; border-radius: 12px; background: #c2185b; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px">KP</span>'
                 f'<span style="flex: 1"><span style="display: block; font-weight: 700">Klinik Putrijaya</span><span style="font-size: 12.5px; color: #6b6b73">{E(spec.get("public_sub", ""))}</span></span>'
                 + ''.join(action_markup(a, f'h{i}') for i, a in enumerate(spec.get('header_actions', []))) + '</header>')
        root = (f'<div class="kp-page" style="height: 100vh; min-height: 720px; display: flex; flex-direction: column; background: #f4f4f6; color: #111114; font-family: Geist, Inter, -apple-system, \'Segoe UI\', sans-serif; font-size: 14px; line-height: 1.45">'
                f'{brand}{body}{TOAST}</div>')
    else:
        root = (f'<div style="height: 100vh; min-height: 720px; overflow: hidden; background: #f4f4f6; color: #111114; font-family: Geist, Inter, -apple-system, \'Segoe UI\', sans-serif; font-size: 14px; line-height: 1.45; display: flex">'
                f'{sidebar(spec)}'
                f'<div class="kp-page" style="flex: 1 1 0; min-width: 0; height: 100vh; min-height: 720px; display: flex; flex-direction: column">'
                f'{header(spec)}{body}</div>{TOAST}</div>')
    props = json.dumps({"accent": {"editor": "color", "default": "#c2185b", "options": ["#c2185b", "#111114", "#0a66d8"]}, "$preview": {"width": 1440, "height": 1080}})
    return f'''<!doctype html>
<html lang="{spec.get('lang', 'en')}">
<head>
<meta charset="utf-8">
<title>{E(spec['title'])}</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
{HELMET}
{root}
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{props}'>{logic}</script>
</body>
</html>
'''
