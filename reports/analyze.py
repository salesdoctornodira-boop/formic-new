#!/usr/bin/env python3
# Faithful port of index.html scoring/scope logic. Read-only over a COPY of ccs.db.
import sqlite3, json, sys, math

DB = sys.argv[1]
VKEYS = ['commitment','communication','expertise','personality']

db = sqlite3.connect(DB)
db.row_factory = sqlite3.Row

# ---- config: read live from settings table (falls back to defaults) ----
def _setting(k, d=None):
    r = db.execute("SELECT value FROM settings WHERE key=?", (k,)).fetchone()
    return r['value'] if r else d
CFG = {k: (_setting(k, dv)) for k, dv in {
    'allowEmpRateHead':'0','allowHeadRateEmp':'1','empRateHeadScope':'direct',
    'globalAlwaysRecommended':'1','globalRatedByAll':'0','showExecInRecommended':'0',
}.items()}
_rules = json.loads(_setting('rulesConfig','{}') or '{}')
RJ = int(_rules.get('rejectScore', 5))
_w = json.loads(_setting('weightsConfig','{}') or '{}')
WEIGHTS = {'enabled':bool(_w.get('enabled',False)),
           'finalShare':int(_w.get('finalShare',50)),'execShare':int(_w.get('execShare',50)),
           'useValueWeights':bool(_w.get('useValueWeights',False)),
           'useValueFinalShare':bool(_w.get('useValueFinalShare',False)),
           'minPeerForFinalBlend':int(_w.get('minPeerForFinalBlend',1))}
PERIOD = _setting('currentPeriod','')
emps = [dict(r) for r in db.execute("SELECT * FROM employees").fetchall()]
evals_raw = [dict(r) for r in db.execute("SELECT * FROM evaluations").fetchall()]

# ---- helpers (ported) ----
def jarr(v):
    if isinstance(v, list): return [x for x in v if x]
    if isinstance(v, str) and v.strip():
        try:
            x = json.loads(v); return [y for y in x if y] if isinstance(x, list) else []
        except: return []
    return []
def empProjects(e):
    seen=[];
    for p in [e.get('project')] + jarr(e.get('projects')):
        if p and p not in seen: seen.append(p)
    return seen
def shareProject(a,b):
    pb=set(empProjects(b)); return any(p in pb for p in empProjects(a))
def isExecHead(e):
    return bool(e and int(e.get('is_head') or 0)==1 and (int(e.get('is_executive') or 0)==1))
def roleKeyOf(e):
    if isExecHead(e): return 'exec'
    if e and int(e.get('is_head') or 0)==1: return 'head'
    return 'employee'

def getMandatoryTargets(user, allemps, cfg):
    ok=[e for e in allemps if int(e.get('active') or 0)!=0]
    ehr = cfg.get('allowEmpRateHead')=='1'
    hre = cfg.get('allowHeadRateEmp')!='0'
    isH = int(user.get('is_head') or 0)==1
    role = user.get('head_role') or ''
    scopeMode = user.get('scope_mode') or ''
    manualHead = isH and (int(user.get('head_manual') or 0)==1)
    t=[]
    if isH:
        if manualHead: t=[]
        elif scopeMode:
            uProj=set(empProjects(user))
            if scopeMode=='all':
                t = ok if role=='CEO' else [e for e in ok if e.get('dept')==user.get('dept') and any(p in uProj for p in empProjects(e))]
            elif scopeMode=='project':
                t=[e for e in ok if any(p in uProj for p in empProjects(e))]
            elif scopeMode=='custom':
                sp=set(jarr(user.get('scope_projects'))); sd=set(jarr(user.get('scope_depts')))
                t=[e for e in ok if ((any(p in sp for p in empProjects(e)) if sp else True) and (e.get('dept') in sd if sd else True))] if (sp or sd) else []
            else:
                t=[e for e in ok if e.get('dept')==user.get('dept') and any(p in uProj for p in empProjects(e))]
        else:
            if role=='CEO': t=list(ok)
            elif role=='CTO': t=[e for e in ok if e.get('dept')=='Development']
            elif role=='HR Manager': t=[e for e in ok if e.get('dept')=='HR']
            elif role=='Head of Marketing': t=[e for e in ok if e.get('dept')=='Marketing']
            elif role=='Head of Sales': t=[e for e in ok if (e.get('dept')=='Sales' and e.get('project') in ('Sales Doctor','iBox')) or e.get('dept')=='Churn']
            elif role=='Head of Customer Care': t=[e for e in ok if e.get('dept')=='Customer Care' and e.get('project') in ('Sales Doctor','iBox')]
            elif role=='Head of iDokon': t=[e for e in ok if e.get('dept') in ('Sales','Customer Care') and e.get('project')=='iDokon']
            elif role=='Product Manager (iBox)': t=[e for e in ok if e.get('dept')=='Development' and e.get('project')=='iBox']
            elif role=='Senior KA Manager': t=[e for e in ok if e.get('dept')=='Key Account' and e.get('project')=='Sales Doctor']
            else: t=[e for e in ok if e.get('dept')==user.get('dept') and e.get('project')==user.get('project')]
        if not hre: t=[e for e in t if int(e.get('is_head') or 0)==1]
    else:
        erhScope=cfg.get('empRateHeadScope') or 'direct'
        peers=[e for e in ok if not (int(e.get('is_head') or 0)==1) and shareProject(e,user)]
        heads=[]
        if ehr:
            if erhScope=='direct':
                heads=[e for e in ok if int(e.get('is_head') or 0)==1 and headManages(e,user,ok)]
            else:
                heads=[e for e in ok if int(e.get('is_head') or 0)==1 and shareProject(e,user)]
        t=peers+heads
    if (cfg.get('globalAlwaysRecommended')=='1' or cfg.get('globalRatedByAll')=='1') and not manualHead:
        gl=[e for e in ok if 'Global' in empProjects(e)]
        if isH: gl=[]
        else:
            if not ehr: gl=[e for e in gl if not (int(e.get('is_head') or 0)==1)]
            else: gl=[e for e in gl if not (int(e.get('is_head') or 0)==1) or headManages(e,user,ok)]
        seen=set(e['id'] for e in t)
        for e in gl:
            if e['id'] not in seen: t.append(e); seen.add(e['id'])
    res=[e for e in t if e['id']!=user['id'] and not isExecHead(e)]
    xtra=jarr(user.get('rate_extra'))
    if xtra:
        have=set(e['id'] for e in res)
        for id_ in xtra:
            if id_==user['id'] or id_ in have: continue
            e=next((x for x in ok if x['id']==id_),None)
            if e: res.append(e); have.add(id_)
    blk=set(jarr(user.get('rate_block')))
    if blk: res=[e for e in res if e['id'] not in blk]
    return res

def headManages(head, emp, allok):
    if not (head and int(head.get('is_head') or 0)==1): return False
    probe={'id':'__erh_probe__','project':emp.get('project'),'dept':emp.get('dept'),
           'projects':emp.get('projects'),'is_head':0,'active':1}
    sc=getMandatoryTargets(head, allok+[probe], {'allowHeadRateEmp':'1','allowEmpRateHead':'1',
        'globalAlwaysRecommended':'0','globalRatedByAll':'0'})
    return any(tg['id']=='__erh_probe__' for tg in sc)

def effScore(v, rj=5):
    if not v or v.get('skipped'): return None
    if v.get('status')=='rejected': return rj
    sc=v.get('score')
    return sc if (isinstance(sc,(int,float)) and sc>0) else None

def mean(arr): return sum(arr)/len(arr) if arr else None

def weightedDCS(peer,fE,fL,w):
    # useValueWeights False, useValueFinalShare False path
    def groupVal(byVal):
        s=0;c=0
        for k in VKEYS:
            for v in byVal.get(k,[]): s+=v;c+=1
        return s/c if c>0 else None
    peerVal=groupVal(peer); execVal=groupVal(fE); linVal=groupVal(fL)
    es=w['execShare']/100
    if execVal is not None and linVal is not None: finalVal=execVal*es+linVal*(1-es)
    else: finalVal=execVal if execVal is not None else linVal
    peerCnt=max([0]+[len(peer.get(k,[])) for k in VKEYS])
    fs=w['finalShare']/100
    if finalVal is not None and peerVal is not None:
        if peerCnt<w['minPeerForFinalBlend']: return finalVal
        return finalVal*fs+peerVal*(1-fs)
    return finalVal if finalVal is not None else (peerVal if peerVal is not None else None)

def emptyBV(): return {k:[] for k in VKEYS}

def poolScore(subset,rj,w):
    sm=0;cnt=0; peer=emptyBV();fE=emptyBV();fL=emptyBV()
    for ev in subset:
        for k in VKEYS:
            s=effScore(ev['scores'].get(k) or {'skipped':True}, rj)
            if s is None: continue
            sm+=s;cnt+=1
            if ev['evaluator_role']=='manager':
                (fE if ev.get('evaluator_level')=='exec' else fL)[k].append(s)
            else: peer[k].append(s)
    if cnt==0: return 0.0
    if w['enabled']:
        d=weightedDCS(peer,fE,fL,w)
        if d is not None: return round(d,1)
    return round(sm/cnt,1)

# ---- parse eval scores ----
for ev in evals_raw:
    try: ev['scores']=json.loads(ev['scores'])
    except: ev['scores']={}

empById={e['id']:e for e in emps}
activeEmps=[e for e in emps if int(e.get('active') or 0)!=0]
activeIds=set(e['id'] for e in activeEmps)
liveEvals=[ev for ev in evals_raw if ev['eval_to'] in activeIds]

# ---- eligibleMap (coverage denominator) ----
eligibleMap={}
for V in activeEmps:
    for tg in getMandatoryTargets(V, emps, CFG):
        eligibleMap[tg['id']]=eligibleMap.get(tg['id'],0)+1
def covPct(recv,elig): return min(100,round(recv/elig*100)) if elig>0 else 0

# ---- per-person empRank ----
st={}
for e in activeEmps:
    st[e['id']]={'e':e,'sum':0,'cnt':0,'evCnt':0,
      'bv':emptyBV(),'peer':emptyBV(),'finExec':emptyBV(),'finLin':emptyBV()}
for ev in evals_raw:
    tgt=st.get(ev['eval_to'])
    if not tgt: continue
    es=0;ec=0
    for k in VKEYS:
        s=effScore(ev['scores'].get(k) or {'skipped':True}, RJ)
        if s is not None:
            es+=s;ec+=1;tgt['bv'][k].append(s)
            if ev['evaluator_role']=='manager':
                (tgt['finExec'] if ev.get('evaluator_level')=='exec' else tgt['finLin'])[k].append(s)
            else: tgt['peer'][k].append(s)
    if ec>0: tgt['sum']+=es;tgt['cnt']+=ec;tgt['evCnt']+=1

people=[]
for id_,d in st.items():
    if d['cnt']==0: continue
    e=d['e']
    simpleAvg=round(d['sum']/d['cnt'],1)
    dcs=simpleAvg
    if WEIGHTS['enabled']:
        v=weightedDCS(d['peer'],d['finExec'],d['finLin'],WEIGHTS)
        if v is not None: dcs=round(v,1)
    elig=eligibleMap.get(id_,0)
    cov=covPct(d['evCnt'],elig)
    people.append({
      'id':id_,'name':e['name'],'dept':e['dept'],'project':e['project'],
      'is_head':int(e.get('is_head') or 0),'head_role':e.get('head_role') or '',
      'is_exec':1 if isExecHead(e) else 0,
      'dcs':dcs,'avg':simpleAvg,'sum':d['sum'],'cnt':d['cnt'],'evCnt':d['evCnt'],
      'eligible':elig,'coverage':cov,
      'cA':round(mean(d['bv']['commitment']),1) if d['bv']['commitment'] else 0,
      'coA':round(mean(d['bv']['communication']),1) if d['bv']['communication'] else 0,
      'exA':round(mean(d['bv']['expertise']),1) if d['bv']['expertise'] else 0,
      'peA':round(mean(d['bv']['personality']),1) if d['bv']['personality'] else 0,
    })
people.sort(key=lambda x:-x['dcs'])

# ---- comments per person: only APPROVED, non-empty, non-skipped ("real & honest") ----
# Head (manager) authors are named (name+surname); rank-and-file stay anonymous.
VLABEL={'commitment':'Приверженность','communication':'Коммуникация','expertise':'Экспертиза','personality':'Личность'}
comments_by_person={p['id']:[] for p in people}
for ev in evals_raw:
    if ev['eval_to'] not in comments_by_person: continue
    frm=empById.get(ev['eval_from'])
    is_mgr = ev['evaluator_role']=='manager'
    if is_mgr and frm:
        author=frm['name']; author_kind='head'; author_role=frm.get('head_role') or ''
    else:
        author='Коллега'; author_kind='peer'; author_role=''
    for k in VKEYS:
        v=ev['scores'].get(k) or {}
        if v.get('skipped'): continue
        if v.get('status')!='approved': continue          # keep ONLY moderator-validated ("honest")
        txt=(v.get('text') or '').strip()
        if not txt: continue
        comments_by_person[ev['eval_to']].append({
            'value':k,'value_label':VLABEL[k],'score':v.get('score'),
            'text':txt,'author':author,'author_kind':author_kind,'author_role':author_role,
        })
for p in people:
    cs=comments_by_person[p['id']]
    # sort: heads first, then by score desc
    cs.sort(key=lambda c:(0 if c['author_kind']=='head' else 1, -(c['score'] or 0)))
    p['comments']=cs
    p['n_comments']=len(cs)
    p['n_head_comments']=sum(1 for c in cs if c['author_kind']=='head')

print("\n=== comments sanity ===")
print("total approved comments attached:",sum(p['n_comments'] for p in people))
print("head-authored approved comments:",sum(p['n_head_comments'] for p in people))
ex=next((p for p in people if p['n_head_comments']>0),None)
if ex:
    print("example person:",ex['name'])
    for c in ex['comments'][:6]:
        who=c['author']+(' ['+c['author_role']+']' if c['author_role'] else '') if c['author_kind']=='head' else 'Коллега (аноним)'
        print(f"   {c['value_label']}={c['score']} — «{c['text']}» — {who}")

# ---- company ----
company={
  'dcs':poolScore(liveEvals,RJ,WEIGHTS),
  'avg':round(sum(p['sum'] for p in people)/sum(p['cnt'] for p in people),1),
  'people':len(people),'evals':len(liveEvals),
  'perValue':{}
}
for k in VKEYS:
    s=0;c=0
    for ev in liveEvals:
        sc=effScore(ev['scores'].get(k) or {'skipped':True},RJ)
        if sc is not None: s+=sc;c+=1
    company['perValue'][k]={'avg':round(s/c,1) if c else 0,'count':c}

# ---- company Final vs Peer split ----
pS=pC=mS=mC=0
for ev in liveEvals:
    isM=ev['evaluator_role']=='manager'
    for k in VKEYS:
        s=effScore(ev['scores'].get(k) or {'skipped':True},RJ)
        if s is None: continue
        if isM: mS+=s;mC+=1
        else: pS+=s;pC+=1
company['final']=round(mS/mC,1) if mC else 0
company['peer']=round(pS/pC,1) if pC else 0
company['finalCount']=mC; company['peerCount']=pC
# eval-row counts (clearer than value-level for KPI/hero labels)
company['finalEvals']=sum(1 for ev in liveEvals if ev['evaluator_role']=='manager')
company['peerEvals']=sum(1 for ev in liveEvals if ev['evaluator_role']!='manager')

# ---- dept stats ----
def dept_or_proj_stats(keyfn):
    groups={}
    for e in activeEmps:
        groups.setdefault(keyfn(e),set()).add(e['id'])
    out=[]
    for g,ids in groups.items():
        sub=[ev for ev in liveEvals if ev['eval_to'] in ids]
        if not sub:
            out.append({'name':g,'dcs':0,'mgr':0,'peer':0,'votes':0,'coverage':0,'headcount':len(ids)}); continue
        ps=pc=ms=mc=0
        for ev in sub:
            isM=ev['evaluator_role']=='manager'
            for k in VKEYS:
                s=effScore(ev['scores'].get(k) or {'skipped':True},RJ)
                if s is None: continue
                if isM: ms+=s;mc+=1
                else: ps+=s;pc+=1
        elig=sum(eligibleMap.get(i,0) for i in ids)
        out.append({'name':g,'dcs':poolScore(sub,RJ,WEIGHTS),'mgr':round(ms/mc,1) if mc else 0,
                    'peer':round(ps/pc,1) if pc else 0,'votes':len(sub),
                    'coverage':covPct(len(sub),elig),'headcount':len(ids)})
    out.sort(key=lambda x:-x['dcs'])
    return out
deptStats=dept_or_proj_stats(lambda e:e['dept'])
projStats=dept_or_proj_stats(lambda e:e['project'])

# ---- rankings ----
for p in people: p['rankScore']=round(p['avg']*(p['coverage']/100),3)
# Top-15 pool: solid sample (>=5 votes) and defined coverage (excludes 4 execs whose mandated-coverage is undefined)
eligible_top=[p for p in people if p['evCnt']>=5 and p['coverage']>0]
top15=sorted(eligible_top,key=lambda x:(-x['rankScore'],-x['evCnt']))[:15]
execs=[p for p in people if p['is_exec']==1]
execs.sort(key=lambda x:-x['dcs'])

# ---- reporting to stdout for inspection ----
print("=== DEPTS ==="); [print(f"  {d['name']:22.22s} dcs={d['dcs']} final={d['mgr']} peer={d['peer']} votes={d['votes']} cov={d['coverage']}%") for d in deptStats]
print("=== PROJECTS ==="); [print(f"  {d['name']:22.22s} dcs={d['dcs']} votes={d['votes']} cov={d['coverage']}%") for d in projStats]
print("=== EXECS ==="); [print(f"  {p['name']:22.22s} {p['head_role']:20.20s} dcs={p['dcs']} votes={p['evCnt']}") for p in execs]
print("=== COMPANY ===")
print(json.dumps(company,ensure_ascii=False,indent=1))
print("\n=== coverage/eligible sanity ===")
zero_elig=[p for p in people if p['eligible']==0]
print("people with eligible=0:",len(zero_elig), [ (p['name'],p['evCnt']) for p in zero_elig])
print("evCnt range:",min(p['evCnt'] for p in people),"..",max(p['evCnt'] for p in people))
print("coverage range:",min(p['coverage'] for p in people),"..",max(p['coverage'] for p in people))

print("\n=== TOP-15 by avg*coverage ===")
for p in people: p['rankScore']=round(p['avg']*(p['coverage']/100),3)
top=sorted(people,key=lambda x:-x['rankScore'])[:15]
for i,p in enumerate(top,1):
    print(f"{i:2d}. {p['name']:26.26s} avg={p['avg']:>4} dcs={p['dcs']:>4} votes={p['evCnt']:>3} cov={p['coverage']:>3}% rank={p['rankScore']}")

print("\n=== TOP-15 by dcs*coverage (alt) ===")
top2=sorted(people,key=lambda x:-(x['dcs']*x['coverage']/100))[:15]
for i,p in enumerate(top2,1):
    print(f"{i:2d}. {p['name']:26.26s} dcs={p['dcs']:>4} votes={p['evCnt']:>3} cov={p['coverage']:>3}%")

# dump full data
with open(sys.argv[2],'w',encoding='utf-8') as f:
    json.dump({'company':company,'people':people,'deptStats':deptStats,'projStats':projStats,
               'top15':[p['id'] for p in top15],'execIds':[p['id'] for p in execs],
               'period':PERIOD,'rejectScore':RJ,
               'weights':{'finalShare':WEIGHTS['finalShare'],'execShare':WEIGHTS['execShare']}},
              f,ensure_ascii=False,indent=1)
print("\nDumped people:",len(people))
