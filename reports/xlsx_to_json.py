#!/usr/bin/env python3
# Convert a CCS de-anonymized evaluations .xlsx (app export, deanon=1) → records JSON.
# Handles the app's sparse cells (empty cells omitted) by reading the cell reference (r="B2").
import zipfile, xml.etree.ElementTree as ET, json, sys, re
NS='{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
def colnum(ref):
    s=re.match(r'([A-Z]+)',ref).group(1); n=0
    for ch in s: n=n*26+(ord(ch)-64)
    return n-1
def read_xlsx(path):
    z=zipfile.ZipFile(path); root=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
    rows=[]
    for r in root.iter(NS+'row'):
        d={}
        for c in r.iter(NS+'c'):
            ci=colnum(c.get('r'))
            t=c.find(NS+'is/'+NS+'t')
            val=t.text if t is not None else (c.find(NS+'v').text if c.find(NS+'v') is not None else '')
            d[ci]=val or ''
        rows.append([d.get(i,'') for i in range(max(d)+1 if d else 0)])
    return rows
rows=read_xlsx(sys.argv[1]); hdr=rows[0]; idx={h:i for i,h in enumerate(hdr)}
def g(r,k):
    i=idx.get(k); return r[i] if (i is not None and i<len(r)) else ''
recs=[{h:g(r,h) for h in hdr} for r in rows[1:]]
json.dump(recs, open(sys.argv[2],'w'), ensure_ascii=False)
named=len({r['evaluator_name'] for r in recs if r['evaluator_role']=='manager'} - {'—',''})
print(f"records: {len(recs)} | deanon (named managers): {named>0}")
