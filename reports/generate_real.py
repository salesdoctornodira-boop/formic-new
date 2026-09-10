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
comment_audit = data['commentAudit']; comment_shown = data['commentShown']
distribution = data['distribution']; bayesP = data['bayes']
ranking_all = [by_id[i] for i in data['rankingAll']]

VKEYS = ['commitment','communication','expertise','personality']
VLABEL = {'commitment':'Приверженность','communication':'Коммуникация','expertise':'Экспертиза','personality':'Личность'}
VSHORT = {'commitment':'Прив.','communication':'Комм.','expertise':'Эксп.','personality':'Личн.'}
def esc(s): return html.escape(str(s if s is not None else ''))

def clr(v):
    if not isinstance(v,(int,float)): return '#94a3b8'
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
    for text, e in peers.items():
        scs = [s for s in e['scores'] if isinstance(s, (int, float))]
        avg = round(sum(scs)/len(scs), 1) if scs else '—'
        peer_list.append((text, e['count'], avg))
    # most substantive first (longer, specific), then by frequency
    peer_list.sort(key=lambda x: (-len(x[0]), -x[1]))
    return head_list, peer_list

HEAD_CAP = 6; PEER_CAP = 5

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
            f'<span class="ctext">«{esc(txt)}»</span><span class="cscore" style="color:{clr(sc)}">{sc if sc is not None else "—"}</span></li>'
            for a, role, txt, sc in heads[:HEAD_CAP])
        more = f'<li class="cmore">…и ещё {len(heads)-HEAD_CAP} от руководителей</li>' if len(heads)>HEAD_CAP else ''
        hhtml = f'<div class="csect"><div class="chdr">Отзывы руководителей <span class="cnamed">— с указанием имени</span></div><ul class="clist">{items}{more}</ul></div>'
    phtml = ''
    if peers:
        items = ''.join(
            f'<li><span class="ctext">«{esc(txt)}»</span>'
            f'<span class="cmeta">{("× "+str(cnt)+" · ") if cnt>1 else ""}<b style="color:{clr(avg)}">{avg}</b></span></li>'
            for txt, cnt, avg in peers[:PEER_CAP])
        more = f'<li class="cmore">…и ещё {len(peers)-PEER_CAP} отзывов коллег</li>' if len(peers)>PEER_CAP else ''
        phtml = f'<div class="csect"><div class="chdr">Отзывы коллег <span class="canon">— анонимно</span> <span class="ccnt">({p["n_comments"]-p["n_head_comments"]})</span></div><ul class="clist peer">{items}{more}</ul></div>'
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
          <div class="ps main"><div class="ps-v" style="color:{clr(p['dcs'])}">{p['dcs']}</div><div class="ps-l">Общий балл</div></div>
          <div class="ps"><div class="ps-v">{p['avg']}</div><div class="ps-l">Средний</div></div>
          <div class="ps"><div class="ps-v" style="color:{clr(p['finAvg'])}">{p['finAvg'] or '—'}</div><div class="ps-l">Final (рук.)</div></div>
          <div class="ps"><div class="ps-v" style="color:{clr(p['peerAvg'])}">{p['peerAvg'] or '—'}</div><div class="ps-l">Peer (кол.)</div></div>
          <div class="ps"><div class="ps-v">{p['sum']}</div><div class="ps-l">Всего</div></div>
          <div class="ps"><div class="ps-v">{p['evCnt']}</div><div class="ps-l">Оценок</div></div>
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
      <td class="num">{d['votes']}</td><td class="num">{d['headcount']}</td>
    </tr>''' for i, d in enumerate(deptStats))

proj_rows = ''.join(
    f'''<div class="projrow"><div class="projname">{esc(d['name'])}</div>
      <div class="projbarwrap">{bar(d['dcs'])}</div>
      <div class="projval" style="color:{clr(d['dcs'])}">{d['dcs'] or '—'}</div>
      <div class="projmeta">{d['votes']} оценок · {d['headcount']} чел.</div></div>'''
    for d in projStats)

def top_row(i, p):
    return f'''<tr>
      <td class="rank"><span class="medal m{i}">{i}</span></td>
      <td class="name">{esc(p['name'])}<div class="tmeta">{esc(p['dept'])} · {esc(p['project'])}</div></td>
      <td class="num big" style="color:{clr(p['bayes'])}">{p['bayes']}</td>
      <td class="barcell">{bar(p['bayes'])}</td>
      <td class="num">{p['dcs']}</td>
      <td class="num">{p['avg']}</td>
      <td class="num" style="color:{clr(p['finAvg'])}">{p['finAvg'] or '—'}</td>
      <td class="num" style="color:{clr(p['peerAvg'])}">{p['peerAvg'] or '—'}</td>
      <td class="num">{p['evCnt']}</td>
    </tr>'''
top_rows = ''.join(top_row(i+1, p) for i, p in enumerate(top15))

# ---- full audit ranking table (all non-exec, by Bayesian score) ----
def rank_row(i, p):
    hot = ' class="hot"' if p['id'] in top15_ids else ''
    return (f'<tr{hot}><td class="rank">{i}</td>'
      f'<td class="name">{esc(p["name"])}<div class="tmeta">{esc(p["dept"])} · {esc(p["project"])}</div></td>'
      f'<td class="num" style="color:{clr(p["bayes"])};font-weight:800">{p["bayes"]}</td>'
      f'<td class="num">{p["dcs"]}</td><td class="num">{p["avg"]}</td>'
      f'<td class="num">{p["finAvg"] or "—"}</td><td class="num">{p["peerAvg"] or "—"}</td>'
      f'<td class="num">{p["evCnt"]}</td></tr>')
rank_rows = ''.join(rank_row(i+1, p) for i, p in enumerate(ranking_all))

# ---- comment audit: drop-reasons table + flagged templates ----
REASON_CLR={'шаблон / копипаст (повтор ≥5)':'#8B5CF6','«не знаю / не работал с ним»':'#EF4444',
    'слишком коротко (<10)':'#94a3b8','одно общее слово («хорошо»/«yaxshi»)':'#F59E0B',
    'пусто / невидимые символы':'#94a3b8','без слов (пунктуация/цифры)':'#94a3b8'}
reason_rows = ''.join(
    f'<tr><td><i class="sw" style="background:{REASON_CLR.get(r,"#94a3b8")}"></i> {esc(r)}</td>'
    f'<td class="num rej">{n}</td></tr>' for r,n in comment_audit['reasons'])
template_rows = ''.join(
    f'<tr><td class="ctext2">«{esc(t)}»</td><td class="num rej">×{n}</td></tr>'
    for t,n in comment_audit['templates'])

exec_rows = ''.join(
    f'''<tr><td class="name">{esc(p['name'])}<div class="tmeta">{esc(p['head_role'])}</div></td>
      <td class="num" style="color:{clr(p['dcs'])};font-weight:800">{p['dcs']}</td>
      <td class="barcell">{bar(p['dcs'])}</td>
      <td class="num">{p['avg']}</td><td class="num">{p['evCnt']}</td></tr>'''
    for p in execs)

# per-person: ALL people, sorted by DCS desc (execs get their Executive badge)
import os
allp = sorted(people, key=lambda x: -x['dcs'])
people_cards = '' if os.environ.get('APPENDIX_ONLY') else ''.join(person_card(p) for p in allp)

# distribution panel (3 bands)
_dt = max(1, distribution['high']+distribution['mid']+distribution['low'])
def dist_panel():
    segs = [('high','#00C76E','≥ 8.5 · высокий'),('mid','#F59E0B','7.0–8.4 · средний'),('low','#EF4444','< 7.0 · низкий')]
    bar_html = ''.join(f'<div style="width:{distribution[k]/_dt*100:.1f}%;background:{c}"></div>' for k,c,_ in segs if distribution[k])
    chips = ''.join(f'<span class="dchip"><i class="sw" style="background:{c}"></i>{lbl}: <b>{distribution[k]}</b></span>' for k,c,lbl in segs)
    return f'<div class="distbar">{bar_html}</div><div class="dchips">{chips}</div>'

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
.pcard{ border:1px solid var(--line); border-radius:12px; padding:10px 13px; margin-bottom:8px; page-break-inside:avoid; }
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
.clist{ list-style:none; } .clist li{ display:flex; gap:8px; align-items:baseline; padding:1.5px 0; border-bottom:1px dashed #eef2f3; }
.pcard-body{ margin-top:2px; } .csect{ margin-bottom:6px; }
.cauthor{ font-size:11px; font-weight:700; white-space:nowrap; } .crole{ display:block; font-size:9px; color:var(--muted); font-weight:500; }
.ctext{ flex:1; font-size:11px; color:#2b3f40; } .cscore{ font-family:monospace; font-weight:800; font-size:12px; }
.cmeta{ font-size:10px; color:var(--muted); white-space:nowrap; }
.cmore{ font-size:10px; color:var(--muted); font-style:italic; border-bottom:none!important; }
.ccnt{ color:var(--muted); font-weight:600; }
.clist.peer li{ }
/* footer legend */
.legend{ font-size:10.5px; color:var(--muted); line-height:1.6; }
.legend b{ color:var(--ink); }
.scalekey{ display:flex; gap:16px; margin:8px 0 0; font-size:10.5px; }
.scalekey span{ display:inline-flex; align-items:center; gap:5px; }
.sw{ width:12px; height:12px; border-radius:3px; display:inline-block; }
.secthead{ display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px; }
.mono{ font-family:monospace; background:#eef4f4; padding:1px 5px; border-radius:4px; font-size:11px; }
.audit td, .audit th{ padding:5px 8px; font-size:11px; }
.audit .ctext2{ font-size:11px; color:#2b3f40; }
tr.hot td{ background:#eafaf1; } tr.hot td.rank{ color:#0a9d5a; font-weight:900; }
.hotmark{ background:#eafaf1; padding:0 4px; border-radius:3px; }
.keepyes{ color:#0a9d5a; } td.keepyes{ font-weight:800; }
.keepno{ color:#8595a3; } .rej{ color:#dc2626; }
tr.totalrow td{ border-top:2px solid var(--line); font-weight:800; background:#fafbfb; }
.distbar{ display:flex; height:22px; border-radius:8px; overflow:hidden; border:1px solid var(--line); }
.distbar div{ height:100%; }
.dchips{ display:flex; gap:18px; margin-top:10px; font-size:11.5px; }
.dchip{ display:inline-flex; align-items:center; gap:6px; } .dchip b{ font-family:monospace; }
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
    <div class="csub">Полный инфографический отчёт по реальным результатам оценки · Опросник аналитикаси</div>
    <div class="meta">
      <div>Период<br><b>{esc(period)}</b></div>
      <div>Сотрудников оценено<br><b>{company['people']}</b></div>
      <div>Всего оценок<br><b>{company['evals']}</b></div>
      <div>Общий балл<br><b>{company['dcs']}</b></div>
      <div>Сформирован<br><b>{esc(GEN_DATE)}</b></div>
    </div>
  </div>

  <div class="kpis">
    {kpi('Всего оценок', company['evals'], 'по компании за период', '#00C76E')}
    {kpi('Сотрудников оценено', company['people'], 'активных участников', '#3B82F6')}
    {kpi('Оценок руководителей', company['finalEvals'], 'Final — авторитетный голос', '#F59E0B')}
    {kpi('Общий балл', company['dcs'], 'Final 50% / Peer 50%, цель > 8.5', clr(company['dcs']))}
  </div>

  <h2><span class="dot">◈</span> Рейтинг компании</h2>
  <div class="hero">
    <div class="hero-main">
      <div class="hv" style="color:{clr(company['dcs'])}">{company['dcs']}</div>
      <div class="hl">Общий балл компании (Final 50% / Peer 50%)</div>
    </div>
    <div class="hero-sep"></div>
    <div class="hero-fp">
      <div class="fp"><div class="v" style="color:{clr(company['final'])}">{company['final']}</div><div class="l">Final (рук.)</div><div class="c">{company['finalEvals']} оценок</div></div>
      <div class="fp"><div class="v" style="color:{clr(company['peer'])}">{company['peer']}</div><div class="l">Peer (коллеги)</div><div class="c">{company['peerEvals']} оценок</div></div>
    </div>
    <div class="donuts">{company_donuts}</div>
  </div>
  {scale_key()}

  <h2 style="margin-top:24px"><span class="dot">▤</span> Распределение сотрудников по баллам</h2>
  <div class="card">{dist_panel()}</div>
</div>

<div class="page pb">
  <h2><span class="dot">▦</span> Отделы — общий балл</h2>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>#</th><th>Отдел</th><th style="text-align:right">Общий</th><th></th>
      <th style="text-align:right">Final</th><th style="text-align:right">Peer</th>
      <th style="text-align:right">Оценок</th><th style="text-align:right">Чел.</th></tr></thead>
    <tbody>{dept_rows}</tbody>
  </table>
  </div>

  <h2 style="margin-top:24px"><span class="dot">◧</span> Проекты</h2>
  <div class="card">{proj_rows}</div>
</div>

<div class="page pb">
  <div class="secthead">
    <h2 style="margin:0"><span class="dot">★</span> ТОП-15 сотрудников</h2>
    <span class="sub">лучший балл, подтверждённый наибольшим числом людей</span>
  </div>
  <div class="note">
    <b>Как считается «Рейтинг».</b> Это <b>рейтинг доверия</b> (Байесовская взвешенная оценка):
    <span class="mono">Рейтинг = (V/(V+M))·Балл + (M/(V+M))·C</span>, где V — число оценивших,
    Балл — общий балл сотрудника, C = {bayesP['C']} (средний балл компании), M = {bayesP['M']} (вес доверия).
    Высокий балл от <b>малого</b> числа людей подтягивается к среднему компании (доверия ещё мало),
    а хороший балл, подтверждённый <b>многими</b> оценивающими, — поднимается. Это и есть
    «лучший балл от наибольшего числа людей». Полная таблица — в конце отчёта (Приложение Б).
    Топ-руководители (Executive) вынесены в отдельный блок ниже.
  </div>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>#</th><th>Сотрудник</th><th style="text-align:right">Рейтинг</th><th></th>
      <th style="text-align:right">Общий</th><th style="text-align:right">Средний</th>
      <th style="text-align:right">Final</th><th style="text-align:right">Peer</th>
      <th style="text-align:right">Оценок</th></tr></thead>
    <tbody>{top_rows}</tbody>
  </table>
  </div>

  <h2 style="margin-top:24px"><span class="dot">♛</span> Руководство (Executive)</h2>
  <div class="card" style="padding:6px 14px">
  <table>
    <thead><tr><th>Руководитель</th><th style="text-align:right">Общий</th><th></th>
      <th style="text-align:right">Средний</th><th style="text-align:right">Оценок</th></tr></thead>
    <tbody>{exec_rows}</tbody>
  </table>
  </div>
</div>

<div class="page pb">
  <div class="secthead">
    <h2 style="margin:0"><span class="dot">≣</span> Полный анализ по каждому сотруднику</h2>
    <span class="sub">{len(allp)} сотрудников · сортировка по общему баллу</span>
  </div>
  <div class="note">
    Показаны только <b>реальные кейсы</b> — содержательные отзывы; шаблоны/копипаст, «не знаю / не работал»,
    односложные («хорошо») и пустые убраны (см. Приложение А). Отзывы <b>руководителей — с именем и фамилией</b>;
    отзывы коллег — <b>анонимно</b> (агрегированы: «текст» × число раз).
  </div>
  {people_cards}
</div>

<div class="page pb">
  <h2><span class="dot">✎</span> Приложение А — Аудит комментариев <span class="sub">(что оставлено, что нет)</span></h2>
  <div class="note">
    Всего написанных комментариев: <b>{comment_audit['total']}</b> ({comment_audit['distinct']} уникальных текстов).
    <b class="keepyes">Оставлено реальных кейсов: {comment_audit['kept']}</b>.
    <b class="rej">Убрано: {comment_audit['dropped']}</b> — не являются реальными кейсами.
    Ниже — по каким правилам убирали и сколько; таблица шаблонов (копипаст) приведена полностью.
  </div>
  <div class="pcard-body" style="grid-template-columns:1fr 1fr; gap:18px; display:grid">
    <div>
      <div class="chdr" style="margin-bottom:6px">Причины исключения</div>
      <div class="card" style="padding:4px 12px; margin:0">
      <table class="audit"><thead><tr><th>Причина</th><th style="text-align:right">Убрано</th></tr></thead>
        <tbody>{reason_rows}
        <tr class="totalrow"><td>ИТОГО убрано</td><td class="num rej">{comment_audit['dropped']}</td></tr></tbody>
      </table></div>
      <div class="legend" style="margin-top:8px">
        <b>«Реальный кейс»</b> — содержательный отзыв о конкретном человеке. Убираем: пустые/невидимые,
        &lt;10 символов, односложные («хорошо», «yaxshi»), «не знаю / не работал с ним», и
        <b>шаблоны</b> (один и тот же длинный текст, вставленный ≥5 раз разным людям — копипаст, а не кейс).
        Баллы при этом <u>не меняются</u> — фильтр касается только текста отзывов.
      </div>
    </div>
    <div>
      <div class="chdr" style="margin-bottom:6px">Убранные шаблоны / копипаст (полный список)</div>
      <div class="card" style="padding:4px 12px; margin:0">
      <table class="audit"><thead><tr><th>Повторяющийся текст</th><th style="text-align:right">Раз</th></tr></thead>
        <tbody>{template_rows}</tbody>
      </table></div>
    </div>
  </div>
</div>

<div class="page pb">
  <h2><span class="dot">✦</span> Приложение Б — Как построен ТОП <span class="sub">(полная таблица)</span></h2>
  <div class="note">
    <b>Рейтинг</b> = Байесовская (взвешенная по доверию) оценка:
    <span class="mono">(V/(V+{bayesP['M']})) · Балл + ({bayesP['M']}/(V+{bayesP['M']})) · {bayesP['C']}</span>,
    V — число оценивших, Балл — общий балл сотрудника, C = {bayesP['C']} — средний по компании.
    Ниже — <b>все {len(ranking_all)} сотрудников</b> (кроме Executive), по этому рейтингу;
    <b class="hotmark">ТОП-15 выделен</b>. Так «лучший балл» поднимается только если подтверждён многими людьми,
    а высокая оценка от 2–3 человек не попадает наверх случайно.
  </div>
  <div class="card" style="padding:6px 14px">
  <table class="audit">
    <thead><tr><th>#</th><th>Сотрудник</th><th style="text-align:right">Рейтинг</th>
      <th style="text-align:right">Общий</th><th style="text-align:right">Средний</th>
      <th style="text-align:right">Final</th><th style="text-align:right">Peer</th>
      <th style="text-align:right">Оценок</th></tr></thead>
    <tbody>{rank_rows}</tbody>
  </table>
  </div>
</div>

<div class="page pb">
  <h2><span class="dot">ℹ</span> Методология и обозначения</h2>
  <div class="card legend">
    <p><b>Источник:</b> реальная де-анонимизированная выгрузка опроса (период {esc(period)}): {company['evals']} оценок,
       {company['people']} сотрудников. Баллы и текст отзывов взяты как есть, ничего не дописано.</p>
    <p><b>Общий балл</b> — Final (оценки руководителей) и Peer (оценки коллег) в пропорции
       <b>{data['weights']['finalShare']}% / {100-data['weights']['finalShare']}%</b> (сбалансированный балл).
       <b>Средний балл</b> — простое среднее всех оценок по 4 ценностям. <b>Всего</b> — сумма всех полученных оценок.
       <b>Оценок</b> — сколько человек оценило сотрудника.</p>
    <p><b>Пропущенные</b> («не знаю») и <b>отклонённые</b> модератором оценки в баллы не входят.</p>
    <p><b>4 ценности:</b> Приверженность, Коммуникация, Экспертиза, Личность.</p>
    <p><b>ТОП-15 (рейтинг доверия):</b> Байесовская оценка
       <span class="mono">(V/(V+{bayesP['M']}))·Балл + ({bayesP['M']}/(V+{bayesP['M']}))·{bayesP['C']}</span> —
       поднимает хороший балл, подтверждённый многими оценивающими, и не даёт малой выборке (2–3 голоса)
       случайно попасть наверх. Полная таблица — Приложение Б.</p>
    <p><b>Комментарии:</b> оставлены только <b>реальные кейсы</b> (содержательные отзывы). Убраны шаблоны/копипаст,
       «не знаю / не работал», односложные и пустые (полный аудит — Приложение А). Руководители названы по имени;
       рядовые сотрудники анонимны.</p>
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
