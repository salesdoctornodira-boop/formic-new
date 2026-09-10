#!/usr/bin/env python3
# Build analytics from the REAL de-anonymized export (real_recs.json).
# Config-independent: simple/effective averages + Final/Peer split (no unknown weights).
import json, sys, re, unicodedata
from collections import Counter, defaultdict

RECS=sys.argv[1]; OUT=sys.argv[2]   # RECS = records json (from xlsx_to_json.py), OUT = data json
recs=json.load(open(RECS,encoding='utf-8'))
VK=['commitment','communication','expertise','personality']
VLABEL={'commitment':'Приверженность','communication':'Коммуникация','expertise':'Экспертиза','personality':'Личность'}
VSHORT={'commitment':'Прив.','communication':'Комм.','expertise':'Эксп.','personality':'Личн.'}
EXEC_POS=re.compile(r'\b(CEO|CTO|CCO|CPO|CFO|COO)\b', re.I)

INV=dict.fromkeys(map(ord,'​‌‍‎‏‪‫‬‭‮⁠﻿\xa0'),None)
def norm(t): return unicodedata.normalize('NFKC',(t or '')).translate(INV).strip()
def fnum(x):
    try: return float(x)
    except: return None

# ---------- comment classifier (real case vs junk/template) ----------
DK=re.compile(r"(ko'?rmagan|ishlab\s+ko'?rma|birga\s+ishlama|ishlamagan|"
              r"bilmayman|bilmiman|bilmadim|tanimayman|tanish\w*mayman|"
              r"ma'?lumot\w*\s*yo'?q|aloqa\w*\s*(bo'?lmagan|yo'?q)|muloqot\w*\s*(bo'?lmagan|yo'?q)|"
              r"не\s+знаю|не\s+работал|не\s+сталкива|не\s+знаком|"
              r"затрудняюсь|не\s+могу\s+оцен|нет\s+взаимодейств|не\s+было\s+взаимодейств)", re.I)
GEN=re.compile(r"^(excellent|good|ok+|nice|super|zo'?r|yaxshi|yahshi|yaxwi|yaxwiroq|ijobiy|"
               r"samimiy|ajoyib|expert|extrovert|zo'?r\s*yaxshi|ishlash\s*yahshi|positiv\w*|"
               r"хорошо|хороший|хорошая|отлично|отличный|норм\w*|позитивн\w*|инициативн\w*|тайёр|активн\w*|\W+)$", re.I)
# global counts for template detection
GLOB=Counter(norm(r[c+'_comment']) for r in recs for c in VK if norm(r[c+'_comment']))
def classify(t):
    t=norm(t)
    if not t: return ('drop','пусто / невидимые символы')
    if re.fullmatch(r"[\W_0-9]+", t, re.U): return ('drop','без слов (пунктуация/цифры)')
    if len(t) < 10: return ('drop','слишком коротко (<10)')
    if GEN.fullmatch(t): return ('drop','одно общее слово («хорошо»/«yaxshi»)')
    if len(t) < 55 and DK.search(t): return ('drop','«не знаю / не работал с ним»')
    if len(t) >= 40 and GLOB[t] >= 5: return ('drop','шаблон / копипаст (повтор ≥5)')
    return ('keep','')

# ---------- people & heads ----------
people={}   # id -> person aggregate
head_pos={} # name -> position
for r in recs:
    pid=r['evaluated_id'];
    if r['evaluated_is_head']=='1': head_pos[r['evaluated_name']]=r['evaluated_position']
    if pid not in people:
        people[pid]={'id':pid,'name':r['evaluated_name'],'dept':r['evaluated_dept'],
            'project':r['evaluated_project'],'is_head':1 if r['evaluated_is_head']=='1' else 0,
            'head_role':r['evaluated_position'] if r['evaluated_is_head']=='1' else '',
            'is_exec':1 if (r['evaluated_is_head']=='1' and EXEC_POS.search(r['evaluated_position'] or '')) else 0,
            'fin':[],'peer':[],'all':[],'bv':{k:[] for k in VK},'evs':set(),'comments':[]}

def is_head_author(r):
    en=r['evaluator_name'] or ''
    return r['evaluator_role']=='manager' and en and not en.startswith('[Сотрудник') and en!='—'

# ---------- accumulate scores + comments ----------
for r in recs:
    p=people[r['evaluated_id']]
    got=False
    for k in VK:
        skipped = (r[k+'_skipped'] in ('1','true','True'))
        status  = r[k+'_status']
        sc=fnum(r[k+'_score'])
        if skipped or status=='rejected' or sc is None or sc<=0:
            valid=False
        else:
            valid=True
        if valid:
            got=True
            p['all'].append(sc); p['bv'][k].append(sc)
            (p['fin'] if r['evaluator_role']=='manager' else p['peer']).append(sc)
        # comment (real case only, not rejected/skipped)
        t=norm(r[k+'_comment'])
        if t and not skipped and status!='rejected':
            verdict,_=classify(t)
            if verdict=='keep':
                if is_head_author(r):
                    p['comments'].append({'value':k,'value_label':VLABEL[k],'score':sc,'text':t,
                        'author':r['evaluator_name'],'author_kind':'head','author_role':head_pos.get(r['evaluator_name'],'')})
                else:
                    p['comments'].append({'value':k,'value_label':VLABEL[k],'score':sc,'text':t,
                        'author':'Коллега','author_kind':'peer','author_role':''})
    if got: p['evs'].add(r['eval_id'])

def mean(a): return round(sum(a)/len(a),1) if a else 0
def overall(fin,peer):
    f=sum(fin)/len(fin) if fin else None
    pr=sum(peer)/len(peer) if peer else None
    if f is not None and pr is not None: return round(0.5*f+0.5*pr,1)
    return round(f if f is not None else (pr if pr is not None else 0),1)

plist=[]
for p in people.values():
    if not p['all']: continue
    p['dcs']=overall(p['fin'],p['peer'])           # "Общий балл" = 50/50 Final/Peer (config-independent)
    p['avg']=mean(p['all'])
    p['sum']=round(sum(p['all']),1)
    p['cnt']=len(p['all'])
    p['evCnt']=len(p['evs'])
    p['finN']=len(p['fin']); p['peerN']=len(p['peer'])
    p['finAvg']=mean(p['fin']); p['peerAvg']=mean(p['peer'])
    p['coverage']=None; p['eligible']=0
    p['cA']=mean(p['bv']['commitment']); p['coA']=mean(p['bv']['communication'])
    p['exA']=mean(p['bv']['expertise']); p['peA']=mean(p['bv']['personality'])
    # dedup comments already handled at render; keep sorted heads-first then score
    p['comments'].sort(key=lambda c:(0 if c['author_kind']=='head' else 1, -(c['score'] or 0)))
    p['n_comments']=len(p['comments']); p['n_head_comments']=sum(1 for c in p['comments'] if c['author_kind']=='head')
    plist.append(p)
plist.sort(key=lambda x:-x['dcs'])

# ---------- company ----------
allv=[s for p in plist for s in p['all']]
finall=[s for p in plist for s in p['fin']]; peerall=[s for p in plist for s in p['peer']]
company={'dcs':overall(finall,peerall),'avg':round(sum(allv)/len(allv),1),
    'people':len(plist),'evals':len(set(r['eval_id'] for r in recs)),
    'final':round(sum(finall)/len(finall),1) if finall else 0,
    'peer':round(sum(peerall)/len(peerall),1) if peerall else 0,
    'finalCount':len(finall),'peerCount':len(peerall),
    'finalEvals':len(set(r['eval_id'] for r in recs if r['evaluator_role']=='manager')),
    'peerEvals':len(set(r['eval_id'] for r in recs if r['evaluator_role']!='manager')),
    'perValue':{}}
for k in VK:
    vals=[fnum(r[k+'_score']) for r in recs if not (r[k+'_skipped'] in('1','true')) and r[k+'_status']!='rejected' and fnum(r[k+'_score']) and fnum(r[k+'_score'])>0]
    company['perValue'][k]={'avg':round(sum(vals)/len(vals),1) if vals else 0,'count':len(vals)}

# ---------- dept / project ----------
def grp(field):
    g=defaultdict(lambda:{'fin':[],'peer':[],'ids':set()})
    for p in plist:
        pass
    # aggregate from person-level lists mapped by their group
    out={}
    for p in plist:
        key=p[field]
        d=out.setdefault(key,{'name':key,'fin':[],'peer':[],'ids':set()})
        d['fin']+=p['fin']; d['peer']+=p['peer']; d['ids'].add(p['id'])
    res=[]
    for k,d in out.items():
        res.append({'name':k,'dcs':overall(d['fin'],d['peer']),'mgr':mean(d['fin']),'peer':mean(d['peer']),
                    'votes':len(d['fin'])+len(d['peer']),'coverage':None,'headcount':len(d['ids'])})
    res.sort(key=lambda x:-x['dcs']); return res
deptStats=grp('dept'); projStats=grp('project')

# ---------- rankings (Bayesian credibility) ----------
C=company['dcs']; M=10
for p in plist:
    v=p['evCnt']
    p['bayes']=round((v/(v+M))*p['dcs'] + (M/(v+M))*C, 2)
    p['rankAvgCov']=p['avg']   # no coverage; keep column meaningful as avg
non_exec=[p for p in plist if not p['is_exec']]
top15=sorted(non_exec,key=lambda x:(-x['bayes'],-x['evCnt'],-x['dcs']))[:15]
execs=sorted([p for p in plist if p['is_exec']],key=lambda x:-x['dcs'])
ranking_all=sorted(non_exec,key=lambda x:(-x['bayes'],-x['evCnt']))

# ---------- comment audit ----------
audit_reasons=Counter(); kept=0; templates=[]
cell_total=0
for r in recs:
    for k in VK:
        t=norm(r[k+'_comment'])
        if not t: continue
        cell_total+=1
        # note: audit reflects the content filter (independent of moderation) for transparency
        v,reason=classify(t)
        if v=='keep': kept+=1
        else: audit_reasons[reason]+=1
templates=sorted(((t,n) for t,n in GLOB.items() if len(t)>=40 and n>=5), key=lambda x:-x[1])
comment_audit={'reasons':[[r,n] for r,n in audit_reasons.most_common()],
    'kept':kept,'dropped':cell_total-kept,'total':cell_total,'distinct':len(GLOB),
    'templates':[[t,n] for t,n in templates]}
# per-person comment counts already in comments (already real-case + not rejected)
kept_shown=sum(p['n_comments'] for p in plist)
head_shown=sum(p['n_head_comments'] for p in plist)

distribution={'high':0,'mid':0,'low':0}
for p in plist:
    distribution['high' if p['dcs']>=8.5 else ('mid' if p['dcs']>=7 else 'low')]+=1

for p in plist:
    for junk in ('evs','fin','peer','all','bv'): p.pop(junk,None)
data={'company':company,'people':plist,'deptStats':deptStats,'projStats':projStats,
    'top15':[p['id'] for p in top15],'execIds':[p['id'] for p in execs],
    'rankingAll':[p['id'] for p in ranking_all],
    'commentAudit':comment_audit,'commentShown':{'kept':kept_shown,'head':head_shown},
    'distribution':distribution,'bayes':{'C':C,'M':M},
    'period':recs[0]['period'] if recs else '','rejectScore':None,'showCoverage':False,
    'weights':{'finalShare':50,'execShare':50},'source':'real-export'}
json.dump(data, open(OUT,'w'), ensure_ascii=False)

print("company:",{k:company[k] for k in ['dcs','avg','final','peer','people','evals','finalEvals','peerEvals']})
print("people:",len(plist),"| execs:",[p['name']+' '+p['head_role'] for p in execs])
print("comment cells:",cell_total,"| KEEP:",kept,"| shown(real+not-rejected):",kept_shown,"| head-authored:",head_shown)
print("drop reasons:",dict(audit_reasons))
print("templates flagged:",len(templates))
print("\nTOP-15 (Bayes):")
for i,p in enumerate(top15,1):
    print(f"  {i:2d}. {p['name']:24.24s} bayes={p['bayes']:>5} overall={p['dcs']:>4} avg={p['avg']:>4} votes={p['evCnt']:>3} fin/peer={p['finAvg']}/{p['peerAvg']}")
print("\nDEPTS:", [(d['name'],d['dcs'],d['votes']) for d in deptStats])
print("distribution:",distribution)
