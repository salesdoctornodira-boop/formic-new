#!/usr/bin/env python3
# Build an infographic HTML report from data.json (produced by analyze.py).
import json, sys, html, datetime

data = json.load(open(sys.argv[1], encoding='utf-8'))
OUT = sys.argv[2]
GEN_DATE = sys.argv[3] if len(sys.argv) > 3 else ''

company = data['company']; people = data['people']
deptStats = data['deptStats']; projStats = data['projStats']
top15_ids = set(data['top15']); exec_ids = set(data['execIds'])
period = data['period']
by_id = {p['id']: p for p in people}
top15 = [by_id[i] for i in data['top15']]
execs = [by_id[i] for i in data['execIds']]

VKEYS = ['commitment','communication','expertise','personality']
VLABEL = {'commitment':'Приверженность','communication':'Коммуникация','expertise':'Экспертиза','personality':'Личность'}
VSHORT = {'commitment':'Прив.','communication':'Комм.','expertise':'Эксп.','personality':'Личн.'}
def esc(s): return html.escape(str(s if s is not None else ''))

def clr(v):
    v = v or 0
    if v >= 8.5: return '#00C76E'
    if v >= 7:   return '#F59E0B'
    return '#EF4444'

# ---------- SVG widgets ----------
def donut(value, size=92, label='', maxv=10, sub=''):
    r = size/2 - 8; cx = cy = size/2; circ = 2*3.14159265*r
    frac = max(0, min(1, (value or 0)/maxv)); dash = circ*frac
    color = clr(value)
    return f'''<div class="donut">
      <svg width="{size}" height="{size}" viewBox="0 0 {size} {size}">
        <circle cx="{cx}" cy="{cy}" r="{r}" fill="none" stroke="#e8edf0" stroke-width="9"/>
        <circle cx="{cx}" cy="{cy}" r="{r}" fill="none" stroke="{color}" stroke-width="9"
          stroke-linecap="round" stroke-dasharray="{dash:.2f} {circ:.2f}"
          transform="rotate(-90 {cx} {cy})"/>
        <text x="50%" y="50%" text-anchor="middle" dy=".34em" font-size="{size*0.28:.0f}"
          font-weight="800" fill="{color}" font-family="monospace">{value if value else '—'}</text>
      </svg>
      <div class="donut-lbl">{esc(label)}</div>{f'<div class="donut-sub">{esc(sub)}</div>' if sub else ''}
    </div>'''

def bar(value, maxv=10, w=None):
    frac = max(0, min(1, (value or 0)/maxv))*100
    return f'<div class="bar"><div class="bar-fill" style="width:{frac:.1f}%;background:{clr(value)}"></div></div>'

def cov_bar(pct):
    return f'<div class="bar cov"><div class="bar-fill" style="width:{pct}%;background:#3B82F6"></div></div>'

def role_badge(p):
    if p['is_exec']: return f'<span class="badge exec">Executive · {esc(p["head_role"])}</span>'
    if p['is_head']: return f'<span class="badge head">Руководитель · {esc(p["head_role"])}</span>'
    return ''

def delta_badge(v, base):
    d = round((v or 0) - base, 1)
    if d > 0:  return f'<span class="delta up">▲ +{d:.1f} к компании</span>'
    if d < 0:  return f'<span class="delta down">▼ {d:.1f} к компании</span>'
    return f'<span class="delta eq">= на уровне компании</span>'

# ---------- comment dedup per person ----------
def person_comments(p):
    heads = {}   # (author,text) -> {score, role, values}
    peers = {}   # text -> {count, scores}
    for c in p['comments']:
        if c['author_kind'] == 'head':
            k = (c['author'], c['text'])
            e = heads.setdefault(k, {'score': c['score'], 'role': c['author_role'], 'values': set()})
            e['values'].add(VSHORT[c['value']])
            if (c['score'] or 0) > (e['score'] or 0): e['score'] = c['score']
        else:
            e = peers.setdefault(c['text'], {'count': 0, 'scores': []})
            e['count'] += 1; e['scores'].append(c['score'])
    head_list = []
    for (author, text), e in sorted(heads.items(), key=lambda x: -(x[1]['score'] or 0)):
        head_list.append((author, e['role'], text, e['score']))
    peer_list = []
    for text, e in sorted(peers.items(), key=lambda x: -x[1]['count']):
        scs = [s for s in e['scores'] if isinstance(s, int)]
        avg = round(sum(scs)/len(scs), 1) if scs else '—'
        peer_list.append((text, e['count'], avg))
    return head_list, peer_list

# ---------- HTML pieces ----------
def kpi(label, value, sub, color='#00C76E'):
    return f'''<div class="kpi">
      <div class="kpi-bar" style="background:linear-gradient(90deg,{color},{color}22)"></div>
      <div class="kpi-val" style="color:{color}">{value}</div>
      <div class="kpi-lbl">{esc(label)}</div>
      <div class="kpi-sub">{esc(sub)}</div>
    </div>'''

def person_card(p, rank=None):
    heads, peers = person_comments(p)
    vrows = ''.join(
        f'<div class="vrow"><span class="vname">{VLABEL[k]}</span>{bar(p[key])}'
        f'<span class="vval" style="color:{clr(p[key])}">{p[key] or "—"}</span></div>'
        for k, key in zip(VKEYS, ['cA','coA','exA','peA']))
    hhtml = ''
    if heads:
        items = ''.join(
            f'<li><span class="cauthor">{esc(a)}<span class="crole">{esc(role)}</span></span>'
            f'<span class="ctext">«{esc(txt)}»</span><span class="cscore" style="color:{clr(sc)}">{sc}</span></li>'
            for a, role, txt, sc in heads)
        hhtml = f'<div class="csect"><div class="chdr">Отзывы руководителей <span class="cnamed">— с указанием имени</span></div><ul class="clist">{items}</ul></div>'
    phtml = ''
    if peers:
        items = ''.join(
            f'<li><span class="ctext">«{esc(txt)}»</span>'
            f'<span class="cmeta">× {cnt} · ср. <b style="color:{clr(avg)}">{avg}</b></span></li>'
            for txt, cnt, avg in peers)
        phtml = f'<div class="csect"><div class="chdr">Отзывы коллег <span class="canon">— анонимно</span></div><ul class="clist peer">{items}</ul></div>'
    if not heads and not peers:
        phtml = '<div class="csect nocom">Одобренных отзывов с текстом нет</div>'
    rankhtml = f'<span class="pcard-rank">#{rank}</span>' if rank else ''
    return f'''<div class="pcard">
      <div class="pcard-head">
        {rankhtml}
        <div class="pcard-id">
          <div class="pcard-name">{esc(p['name'])} {role_badge(p)}</div>
          <div class="pcard-meta">{esc(p['dept'])} · {esc(p['project'])}</div>
        </div>
        <div class="pcard-scores">
          <div class="ps main"><div class="ps-v" style="color:{clr(p['dcs'])}">{p['dcs']}</div><div class="ps-l">Общий балл (DCS)</div></div>
          <div class="ps"><div class="ps-v">{p['avg']}</div><div class="ps-l">Средний</div></div>
          <div class="ps"><div class="ps-v">{p['sum']}</div><div class="ps-l">Всего баллов</div></div>
          <div class="ps"><div class="ps-v">{p['evCnt']}</div><div class="ps-l">Оценок</div></div>
          <div class="ps"><div class="ps-v">{p['coverage']}%</div><div class="ps-l">Охват</div></div>
        </div>
      </div>
      <div class="pcard-delta">{delta_badge(p['dcs'], company['dcs'])}</div>
      <div class="pcard-body">
        <div class="pcard-vals">{vrows}</div>
        <div class="pcard-coms">{hhtml}{phtml}</div>
      </div>
    </div>'''

# ---------- assemble ----------
company_donuts = ''.join(
    donut(company['perValue'][k]['avg'], label=VLABEL[k], sub=f"{company['perValue'][k]['count']} оц.")
    for k in VKEYS)

dept_rows = ''.join(
    f'''<tr class="{'muted' if d['votes']==0 else ''}">
      <td class="rank">{i+1}</td><td class="name">{esc(d['name'])}</td>
      <td class="num" style="color:{clr(d['dcs'])};font-weight:800">{d['dcs'] or '—'}</td>
      <td class="barcell">{bar(d['dcs'])}</td>
      <td class="num" style="color:{clr(d['mgr'])}">{d['mgr'] or '—'}</td>
      <td class="num" style="color:{clr(d['peer'])}">{d['peer'] or '—'}</td>
      <td class="num">{d['votes']}</td><td class="num">{d['coverage']}%</td>
    </tr>''' for i, d in enumerate(deptStats))

proj_rows = ''.join(
    f'''<div class="projrow"><div class="projname">{esc(d['name'])}</div>
      <div class="projbarwrap">{bar(d['dcs'])}</div>
      <div class="projval" style="color:{clr(d['dcs'])}">{d['dcs'] or '—'}</div>
      <div class="projmeta">{d['votes']} оц. · охват {d['coverage']}%</div></div>'''
    for d in projStats)

def top_row(i, p):
    return f'''<tr>
      <td class="rank"><span class="medal m{i}">{i}</span></td>
      <td class="name">{esc(p['name'])}<div class="tmeta">{esc(p['dept'])} · {esc(p['project'])}</div></td>
      <td class="num big" style="color:{clr(p['avg'])}">{p['avg']}</td>
      <td class="barcell">{bar(p['avg'])}</td>
      <td class="num">{p['dcs']}</td>
      <td class="num">{p['evCnt']}</td>
      <td class="covcell">{cov_bar(p['coverage'])}<span class="covnum">{p['coverage']}%</span></td>
    </tr>'''
top_rows = ''.join(top_row(i+1, p) for i, p in enumerate(top15))

exec_rows = ''.join(
    f'''<tr><td class="name">{esc(p['name'])}<div class="tmeta">{esc(p['head_role'])}</div></td>
      <td class="num" style="color:{clr(p['dcs'])};font-weight:800">{p['dcs']}</td>
      <td class="barcell">{bar(p['dcs'])}</td>
      <td class="num">{p['avg']}</td><td class="num">{p['evCnt']}</td></tr>'''
    for p in execs)

# per-person: sorted by DCS desc, execs excluded here (shown in leadership block)
allp = [p for p in people if not p['is_exec']]
allp.sort(key=lambda x: -x['dcs'])
people_cards = ''.join(person_card(p) for p in allp)

CSS = '''
* { margin:0; padding:0; box-sizing:border-box; }
:root{ --g:#00C76E; --ink:#0f2b2c; --muted:#6b7f80; --line:#e4ebec; }
body{ font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
  color:var(--ink); background:#fff; font-size:12px; line-height:1.45; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.page{ padding:30px 34px; }
.pb{ page-break-before:always; }
h1{ font-size:30px; font-weight:900; letter-spacing:-0.02em; }
h2{ font-size:18px; font-weight:800; margin:0 0 14px; display:flex; align-items:center; gap:9px; letter-spacing:-0.01em; }
h2 .dot{ width:26px; height:26px; border-radius:8px; background:rgba(0,199,110,.14); display:inline-flex; align-items:center; justify-content:center; color:var(--g); font-size:15px; }
.sub{ color:var(--muted); font-size:12px; }
/* Cover */
.cover{ background:linear-gradient(135deg,#0a2627 0%,#0f3a3c 60%,#0a2627 100%); color:#fff;
  border-radius:20px; padding:36px 38px; position:relative; overflow:hidden; margin-bottom:22px; }
.cover:after{ content:''; position:absolute; top:-90px; right:-70px; width:280px; height:280px; border-radius:50%;
  background:radial-gradient(circle,rgba(0,199,110,.22),transparent 70%); }
.cover .eyebrow{ color:var(--g); font-weight:800; letter-spacing:.14em; font-size:11px; text-transform:uppercase; }
.cover h1{ color:#fff; margin:8px 0 6px; }
.cover .csub{ color:#b9cdcd; font-size:13px; }
.cover .meta{ margin-top:18px; display:flex; gap:26px; color:#cfe0e0; font-size:12px; position:relative; z-index:2; }
.cover .meta b{ color:#fff; font-size:15px; }
/* KPI */
.kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:22px; }
.kpi{ border:1px solid var(--line); border-radius:15px; padding:15px 15px 13px; position:relative; overflow:hidden; background:#fff; }
.kpi-bar{ position:absolute; top:0; left:0; right:0; height:4px; }
.kpi-val{ font-size:30px; font-weight:900; font-family:monospace; line-height:1; margin-top:4px; }
.kpi-lbl{ font-size:12px; font-weight:700; margin-top:7px; }
.kpi-sub{ font-size:10.5px; color:var(--muted); margin-top:2px; }
/* Hero */
.hero{ background:linear-gradient(135deg,#0a2627,#0f3a3c 60%,#0a2627); color:#fff; border-radius:18px;
  padding:24px 26px; margin-bottom:22px; display:flex; gap:34px; align-items:center; flex-wrap:wrap; }
.hero-main{ text-align:center; }
.hero-main .hv{ font-size:56px; font-weight:900; font-family:monospace; line-height:1; }
.hero-main .hl{ font-size:12px; color:#cfe0e0; margin-top:4px; }
.hero-fp{ display:flex; gap:22px; }
.fp{ text-align:center; } .fp .v{ font-size:30px; font-weight:900; font-family:monospace; }
.fp .l{ font-size:11px; color:#cfe0e0; } .fp .c{ font-size:10px; color:#9fb6b6; margin-top:2px; }
.hero-sep{ width:1px; align-self:stretch; background:rgba(255,255,255,.12); }
.donuts{ display:flex; gap:16px; margin-left:auto; }
.donut{ text-align:center; } .donut-lbl{ font-size:10.5px; color:#cfe0e0; margin-top:4px; font-weight:700; }
.donut-sub{ font-size:9.5px; color:#9fb6b6; }
.donut svg circle:first-child{ stroke:rgba(255,255,255,.14); }
/* generic bar */
.bar{ height:8px; background:#eef2f3; border-radius:6px; overflow:hidden; }
.bar.cov{ background:#e8eef7; }
.bar-fill{ height:100%; border-radius:6px; }
/* tables */
table{ width:100%; border-collapse:collapse; }
th{ text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
  padding:8px 8px; border-bottom:2px solid var(--line); }
td{ padding:8px 8px; border-bottom:1px solid var(--line); font-size:12px; vertical-align:middle; }
td.num{ text-align:right; font-family:monospace; font-weight:700; white-space:nowrap; }
td.num.big{ font-size:15px; }
td.rank{ width:34px; color:var(--muted); font-weight:800; }
td.name{ font-weight:700; }
td.barcell{ width:120px; } .covcell{ width:110px; white-space:nowrap; }
.covcell .bar{ display:inline-block; width:70px; vertical-align:middle; }
.covnum{ font-family:monospace; font-size:11px; margin-left:6px; color:var(--muted); }
tr.muted td{ color:#b6c2c2; }
.tmeta{ font-size:10px; color:var(--muted); font-weight:500; }
.medal{ display:inline-flex; width:22px; height:22px; border-radius:50%; align-items:center; justify-content:center;
  font-size:11px; font-weight:800; background:#eef2f3; color:var(--muted); }
.medal.m1{ background:#F59E0B; color:#fff; } .medal.m2{ background:#c9ccd1; color:#fff; } .medal.m3{ background:#cd7f32; color:#fff; }
.card{ border:1px solid var(--line); border-radius:16px; padding:18px 20px; margin-bottom:18px; }
/* projects */
.projrow{ display:grid; grid-template-columns:150px 1fr 44px; grid-template-rows:auto auto; gap:2px 12px; align-items:center; margin-bottom:12px; }
.projname{ font-weight:800; grid-row:1/3; } .projbarwrap{} .projval{ font-family:monospace; font-weight:800; text-align:right; grid-row:1/3; font-size:15px; }
.projmeta{ font-size:10px; color:var(--muted); grid-column:2; }
/* note */
.note{ background:#f4f8f8; border:1px solid var(--line); border-left:3px solid var(--g); border-radius:10px;
  padding:12px 15px; font-size:11px; color:#3f5556; margin-bottom:18px; }
.note b{ color:var(--ink); }
/* person cards */
.pcard{ border:1px solid var(--line); border-radius:14px; padding:14px 16px; margin-bottom:12px; page-break-inside:avoid; }
.pcard-head{ display:flex; align-items:flex-start; gap:12px; }
.pcard-rank{ font-weight:900; color:#c3cecd; font-size:16px; font-family:monospace; }
.pcard-id{ flex:0 0 auto; min-width:190px; }
.pcard-name{ font-size:14px; font-weight:800; }
.pcard-meta{ font-size:10.5px; color:var(--muted); margin-top:2px; }
.pcard-scores{ margin-left:auto; display:flex; gap:14px; align-items:flex-start; }
.ps{ text-align:center; } .ps-v{ font-family:monospace; font-weight:800; font-size:16px; }
.ps.main .ps-v{ font-size:22px; } .ps-l{ font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
.badge{ font-size:9px; font-weight:800; padding:2px 7px; border-radius:20px; vertical-align:middle; margin-left:5px; }
.badge.head{ background:rgba(59,130,246,.12); color:#2563eb; } .badge.exec{ background:rgba(245,158,11,.14); color:#b45309; }
.delta{ font-size:10px; font-weight:700; } .delta.up{ color:#0a9d5a; } .delta.down{ color:#dc2626; } .delta.eq{ color:var(--muted); }
.pcard-delta{ margin:6px 0 10px; }
.pcard-body{ display:grid; grid-template-columns:270px 1fr; gap:18px; }
.pcard-vals{}
.vrow{ display:grid; grid-template-columns:96px 1fr 30px; align-items:center; gap:8px; margin-bottom:6px; }
.vname{ font-size:11px; color:#40595a; font-weight:600; } .vval{ font-family:monospace; font-weight:800; text-align:right; font-size:12px; }
.csect{ margin-bottom:9px; } .chdr{ font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#40595a; margin-bottom:4px; }
.cnamed{ color:#2563eb; } .canon{ color:var(--muted); font-weight:600; } .nocom{ font-size:11px; color:var(--muted); font-style:italic; }
.clist{ list-style:none; } .clist li{ display:flex; gap:8px; align-items:baseline; padding:3px 0; border-bottom:1px dashed #eef2f3; }
.cauthor{ font-size:11px; font-weight:700; white-space:nowrap; } .crole{ display:block; font-size:9px; color:var(--muted); font-weight:500; }
.ctext{ flex:1; font-size:11px; color:#2b3f40; } .cscore{ font-family:monospace; font-weight:800; font-size:12px; }
.cmeta{ font-size:10px; color:var(--muted); white-space:nowrap; }
.clist.peer li{ }
/* footer legend */
.legend{ font-size:10.5px; color:var(--muted); line-height:1.6; }
.legend b{ color:var(--ink); }
.scalekey{ display:flex; gap:16px; margin:8px 0 0; font-size:10.5px; }
.scalekey span{ display:inline-flex; align-items:center; gap:5px; }
.sw{ width:12px; height:12px; border-radius:3px; display:inline-block; }
.secthead{ display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px; }
@page{ size:A4; margin:12mm 10mm; }
'''

def scale_key():
    return ('<div class="scalekey">'
      '<span><i class="sw" style="background:#00C76E"></i> ≥ 8.5 — высокий</span>'
      '<span><i class="sw" style="background:#F59E0B"></i> 7.0–8.4 — средний</span>'
      '<span><i class="sw" style="background:#EF4444"></i> &lt; 7.0 — низкий</span></div>')

HTMLDOC = f'''<!doctype html><html lang="ru"><head><meta charset="utf-8">
<title>CCS — Аналитика опроса · {esc(period)}</title><style>{CSS}</style></head><body>

<div class="page">
  <div class="cover">
    <div class="eyebrow">CCS Platform · Corporate Culture Survey</div>
    <h1>Аналитика опроса</h1>
    <div class="csub">Полный инфографический отчёт по результатам оценки · Опросник аналитикаси</div>
    <div class="meta">
      <div>Период<br><b>{esc(period)}</b></div>
      <div>Сотрудников оценено<br><b>{company['people']}</b></div>
      <div>Всего оценок<br><b>{company['evals']}</b></div>
      <div>Средний балл (DCS)<br><b>{company['dcs']}</b></div>
      <div>Сформирован<br><b>{esc(GEN_DATE)}</b></div>
    </div>
  </div>

  <div class="kpis">
    {kpi('Всего оценок', company['evals'], 'по компании за период', '#00C76E')}
    {kpi('Сотрудников оценено', company['people'], 'активных участников', '#3B82F6')}
    {kpi('Оценок руководителей', company['finalEvals'], 'Final — авторитетный голос', '#F59E0B')}
    {kpi('Средний балл (DCS)', company['dcs'], 'взвешенный, цель > 8.5', clr(company['dcs']))}
  </div>

  <h2><span class="dot">◈</span> Рейтинг компании</h2>
  <div class="hero">
    <div class="hero-main">
      <div class="hv" style="color:{clr(company['dcs'])}">{company['dcs']}</div>
      <div class="hl">DCS · общий балл компании</div>
    </div>
    <div class="hero-sep"></div>
    <div class="hero-fp">
      <div class="fp"><div class="v" style="color:{clr(company['final'])}">{company['final']}</div><div class="l">Final (рук.)</div><div class="c">{company['finalEvals']} оценок</div></div>
      <div class="fp"><div class="v" style="color:{clr(company['peer'])}">{company['peer']}</div><div class="l">Peer (коллеги)</div><div class="c">{company['peerEvals']} оценок</div></div>
    </div>
    <div class="donuts">{company_donuts}</div>
  </div>
  {scale_key()}
</div>

<div class="page pb">
  <h2><span class="dot">▦</span> Отделы — рейтинг DCS</h2>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>#</th><th>Отдел</th><th style="text-align:right">DCS</th><th></th>
      <th style="text-align:right">Final</th><th style="text-align:right">Peer</th>
      <th style="text-align:right">Оценок</th><th style="text-align:right">Охват</th></tr></thead>
    <tbody>{dept_rows}</tbody>
  </table>
  </div>

  <h2 style="margin-top:24px"><span class="dot">◧</span> Проекты</h2>
  <div class="card">{proj_rows}</div>
</div>

<div class="page pb">
  <div class="secthead">
    <h2 style="margin:0"><span class="dot">★</span> ТОП-15 сотрудников</h2>
    <span class="sub">лучший балл × охват (от наибольшего числа людей)</span>
  </div>
  <div class="note">
    <b>Как считается рейтинг.</b> Ранг = <b>средний балл × охват</b> (доля оценивших из числа тех, кто обязан был оценить).
    В рейтинг включены сотрудники с <b>≥ 5 оценками</b> — «лучший балл от наибольшего числа людей».
    Топ-руководители (Executive) оцениваются только руководством и вынесены в отдельный блок ниже.
  </div>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>#</th><th>Сотрудник</th><th style="text-align:right">Средний</th><th></th>
      <th style="text-align:right">DCS</th><th style="text-align:right">Оценок</th><th style="text-align:right">Охват</th></tr></thead>
    <tbody>{top_rows}</tbody>
  </table>
  </div>

  <h2 style="margin-top:24px"><span class="dot">♛</span> Руководство (Executive)</h2>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>Руководитель</th><th style="text-align:right">DCS</th><th></th>
      <th style="text-align:right">Средний</th><th style="text-align:right">Оценок</th></tr></thead>
    <tbody>{exec_rows}</tbody>
  </table>
  </div>
</div>

<div class="page pb">
  <div class="secthead">
    <h2 style="margin:0"><span class="dot">≣</span> Полный анализ по каждому сотруднику</h2>
    <span class="sub">{len(allp)} сотрудников · сортировка по DCS</span>
  </div>
  <div class="note">
    Отзывы показаны только <b>прошедшие модерацию (одобренные)</b> — реальные и честные.
    Отзывы <b>руководителей — с указанием имени и фамилии</b>; отзывы коллег — <b>анонимно</b> (агрегированы: «текст» × число раз).
  </div>
  {people_cards}
</div>

<div class="page pb">
  <h2><span class="dot">ℹ</span> Методология и обозначения</h2>
  <div class="card legend">
    <p><b>DCS (общий балл)</b> — взвешенная композитная оценка. Final (оценки руководителей) и Peer (оценки коллег)
       складываются в пропорции <b>{data['weights']['finalShare']}% / {100-data['weights']['finalShare']}%</b>;
       внутри Final оценки Executive и линейных руководителей — в пропорции {data['weights']['execShare']}% / {100-data['weights']['execShare']}%.</p>
    <p><b>Средний балл</b> — простое среднее всех оценок сотрудника по 4 ценностям.
       <b>Всего баллов</b> — сумма всех полученных оценок. <b>Охват</b> — доля оценивших из числа обязанных оценить.</p>
    <p><b>Отклонённые</b> оценки (модерация) учитываются в баллах как {data['rejectScore']}; пропущенные («не знаю») — исключаются.</p>
    <p><b>4 ценности:</b> Приверженность, Коммуникация, Экспертиза, Личность.</p>
    <p><b>Комментарии:</b> в отчёт включены только одобренные модератором отзывы (реальные и честные);
       отклонённые и непроверенные исключены. Руководители названы по имени; рядовые сотрудники анонимны.</p>
    {scale_key()}
  </div>
  <div class="sub" style="margin-top:14px; text-align:center">
    CCS Platform · Аналитика опроса · период {esc(period)} · сформировано {esc(GEN_DATE)}
  </div>
</div>

</body></html>'''

open(OUT, 'w', encoding='utf-8').write(HTMLDOC)
print('Wrote', OUT, 'bytes:', len(HTMLDOC))
print('people cards:', len(allp), '| top15:', len(top15), '| execs:', len(execs))
