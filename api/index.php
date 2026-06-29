<?php
header('Content-Type: application/json; charset=utf-8');
// ── CORS (F0-8): the app is same-origin (fetch uses credentials:'same-origin'), so NO CORS header is
// needed for normal use. Cross-origin is allowed ONLY for origins explicitly listed in env
// CCS_ALLOWED_ORIGINS (comma-separated). Never reflect a client-controlled Host/Origin. '*' is invalid with credentials.
$__origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$__allowed = array_filter(array_map('trim', explode(',', getenv('CCS_ALLOWED_ORIGINS') ?: '')));
if ($__origin !== '' && in_array($__origin, $__allowed, true)) {
    header('Access-Control-Allow-Origin: '.$__origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// s12 (HRD): a friendly-but-firm reply for requests that try to BYPASS the survey from outside the normal UI —
// tampering with a score (out of 1–10), rating a forbidden target, or skipping where skipping is not allowed. These
// states are unreachable through the real buttons, so they signal someone poking the API directly. The frontend turns
// `tamper:true` into a popup ("Отдел HR…"); a curl/DevTools peeker reads `message` straight from the JSON. Normal
// validation (already_submitted, comment_required, survey_paused, limit_reached…) is NOT flagged — those are honest users.
define('CCS_TAMPER_MSG','Отдел HR сожалеет, что вы пытаетесь обойти систему. Она создавалась не для взлома, а для честной и анонимной оценки. Пожалуйста, просто пройдите опрос. Спасибо!');
function tamperJson($code,$extra=[]){ return json_encode(array_merge(['error'=>$code,'tamper'=>true,'message'=>CCS_TAMPER_MSG],$extra),JSON_UNESCAPED_UNICODE); }

$db_path = __DIR__ . '/../data/ccs.db';
if (!file_exists(dirname($db_path))) mkdir(dirname($db_path), 0755, true);
try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL;');
    // Audit s12 (#13): wait up to 5s for a write lock instead of failing a concurrent request with SQLITE_BUSY → HTTP 500.
    // Matters during a live "замер" when many respondents submit at once (WAL serializes writers).
    $db->exec('PRAGMA busy_timeout=5000;');
} catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>'DB:'.$e->getMessage()]); exit(); }

// ── Schema/seed gate (B-3): the one-time DDL + column migrations + seed + boot migrations below
// used to run on EVERY request. They now run only when the stored schema_version is behind.
// Every statement in the gated blocks is idempotent (CREATE IF NOT EXISTS / guarded ALTER /
// INSERT OR IGNORE / seed-if-empty), so a single re-run on an existing prod DB is harmless;
// afterwards normal requests skip all of it. Bump $SCHEMA_VERSION when adding a new migration.
$SCHEMA_VERSION = 10;   // v3: R2-CODES reveal_log audit table; v4: admin_sessions.auth_token (real session revoke, audit s9); v5: access_codes.project/dept (anonymous dept-pool codes, s11); v6: survey_progress (s14 §3 anonymity-safe progress monitoring); v7: s16 normalize is_executive=1 for CEO/CTO/CCO/CPO (Executive Management category); v8: Req1 seed showExecInRecommended default (Executive Managers hidden from Recommended unless enabled); v9: employees.rate_extra/rate_block (per-employee visibility/voting overrides); v10: employees.head_manual (per-head manual evaluation routing — Панель руководителей)
$needMigrate = true;
try { if((int)$db->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() >= $SCHEMA_VERSION) $needMigrate = false; }
catch (Exception $e) { $needMigrate = true; }   // settings table absent → fresh DB

if($needMigrate){
$db->exec("
CREATE TABLE IF NOT EXISTS evaluations (
    id TEXT PRIMARY KEY, eval_from TEXT NOT NULL, from_dept TEXT, from_project TEXT,
    evaluator_role TEXT NOT NULL, eval_to TEXT NOT NULL, to_dept TEXT,
    scores TEXT NOT NULL, period TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS employees (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, dept TEXT NOT NULL, project TEXT NOT NULL,
    is_head INTEGER DEFAULT 0, head_role TEXT DEFAULT '', active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
    position TEXT DEFAULT '', start_date TEXT DEFAULT '', end_date TEXT DEFAULT '',
    on_probation INTEGER DEFAULT 0, official_employed INTEGER DEFAULT 1,
    phone TEXT DEFAULT '', email TEXT DEFAULT '', birth_date TEXT DEFAULT '',
    notes TEXT DEFAULT '', photo TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS dept_submissions (
    project TEXT NOT NULL, dept TEXT NOT NULL, period TEXT NOT NULL, count INTEGER DEFAULT 0,
    PRIMARY KEY (project, dept, period)
);
CREATE TABLE IF NOT EXISTS admin_sessions (
    id TEXT PRIMARY KEY, role TEXT NOT NULL, ip TEXT, user_agent TEXT,
    login_time DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1
);
CREATE TABLE IF NOT EXISTS auth_sessions (
    token TEXT PRIMARY KEY, role TEXT NOT NULL, employee_id TEXT,
    ip TEXT, user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME
);
CREATE TABLE IF NOT EXISTS access_codes (
    code TEXT NOT NULL, employee_id TEXT NOT NULL, period TEXT NOT NULL,
    active INTEGER DEFAULT 1, edited_by_admin INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(code,period)
);
CREATE TABLE IF NOT EXISTS login_attempts (
    ip TEXT PRIMARY KEY, cnt INTEGER DEFAULT 0, first_at INTEGER DEFAULT 0, locked_until INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS reveal_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, eval_id TEXT, eval_from TEXT,
    period TEXT, by_role TEXT, by_ip TEXT, at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS survey_progress (
    holder TEXT NOT NULL, period TEXT NOT NULL, project TEXT DEFAULT '', dept TEXT DEFAULT '',
    rated INTEGER DEFAULT 0, total INTEGER DEFAULT 0, status TEXT DEFAULT 'in_progress',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(holder,period)
);
");

// Migrate: add columns if missing.
// Audit s12 (#8): each guarded ALTER is wrapped — under a concurrent first-boot two requests can both pass the
// in_array() check before either commits, so the second ADD COLUMN would otherwise throw "duplicate column" → 500.
// Swallowing the duplicate makes the column-adds idempotent under the race (busy_timeout already serializes writers).
$safeAlter=function($sql)use($db){ try{ $db->exec($sql); }catch(Exception $e){ /* duplicate column on concurrent boot → already present, ignore */ } };
$cols = array_column($db->query("PRAGMA table_info(employees)")->fetchAll(PDO::FETCH_ASSOC),'name');
$addCols = ['position'=>'TEXT DEFAULT ""','start_date'=>'TEXT DEFAULT ""','end_date'=>'TEXT DEFAULT ""',
            'on_probation'=>'INTEGER DEFAULT 0','official_employed'=>'INTEGER DEFAULT 1',
            'phone'=>'TEXT DEFAULT ""','email'=>'TEXT DEFAULT ""','birth_date'=>'TEXT DEFAULT ""',
            'notes'=>'TEXT DEFAULT ""','photo'=>'TEXT DEFAULT ""',
            // M1 — flexible hierarchy/multi-project. Empty defaults = current behaviour (legacy role switch).
            'projects'=>'TEXT DEFAULT ""','is_executive'=>'INTEGER DEFAULT 0',
            'scope_mode'=>'TEXT DEFAULT ""','scope_projects'=>'TEXT DEFAULT ""','scope_depts'=>'TEXT DEFAULT ""',
            // s17 §2 — per-employee visibility/voting overrides (superadmin constructor). JSON arrays of employee ids:
            // rate_extra = people this employee is ADDITIONALLY required to evaluate (force-shown + votable, even if the
            //   auto-routing would hide them); rate_block = people HIDDEN from this employee entirely (never shown in the
            //   survey, and the server rejects any vote cast on them). rate_block wins over rate_extra. Empty = legacy behaviour.
            'rate_extra'=>'TEXT DEFAULT ""','rate_block'=>'TEXT DEFAULT ""',
            // s17 §4 — "Панель руководителей": when 1, this HEAD has manual evaluation routing — no auto scope/role
            // targets and Global is NOT forced into their mandatory set; rate_extra IS their entire mandatory list,
            // everything else (incl. Global) falls to the optional "Recommended" step. Per-head, opt-in. Heads only.
            'head_manual'=>'INTEGER DEFAULT 0'];
foreach($addCols as $col=>$def) if(!in_array($col,$cols)) $safeAlter("ALTER TABLE employees ADD COLUMN $col $def");
// M1 — evaluator_level on evaluations (exec|linear|''), set server-side at submit; enables weighted exec/linear DCS.
$ecols = array_column($db->query("PRAGMA table_info(evaluations)")->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('evaluator_level',$ecols)) $safeAlter("ALTER TABLE evaluations ADD COLUMN evaluator_level TEXT DEFAULT ''");

$scols = array_column($db->query("PRAGMA table_info(admin_sessions)")->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('id',$scols)) $db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (id TEXT PRIMARY KEY, role TEXT NOT NULL, ip TEXT, user_agent TEXT, login_time DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, active INTEGER DEFAULT 1)");
// v4 (audit s9): link the session-monitor row to its real auth token so revoke_session can actually kill the login.
if(!in_array('auth_token',$scols)) $safeAlter("ALTER TABLE admin_sessions ADD COLUMN auth_token TEXT DEFAULT ''");
if(!in_array('employee_id',$scols)) $safeAlter("ALTER TABLE admin_sessions ADD COLUMN employee_id TEXT DEFAULT ''");

// v5 (s11): anonymous dept-pool codes. A self-issued code is bound to a PROJECT+DEPT (not to a person) — these
// columns store that binding so login/dedup/edit work per-code without ever knowing WHO the holder is. Legacy
// identity-bound rows (pre-v5) keep these NULL and still resolve via the employees join (backward compatible).
$ccols = array_column($db->query("PRAGMA table_info(access_codes)")->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('project',$ccols)) $safeAlter("ALTER TABLE access_codes ADD COLUMN project TEXT DEFAULT ''");
if(!in_array('dept',$ccols))    $safeAlter("ALTER TABLE access_codes ADD COLUMN dept TEXT DEFAULT ''");

$defaults = [
    // P-1: initial credentials may be seeded from env on a FRESH install (INSERT OR IGNORE below
    // never overrides an existing value, so this only affects first-run). Defaults preserve current behaviour.
    'adminLogin'=>getenv('CCS_ADMIN_LOGIN')?:'admin','adminPass'=>getenv('CCS_ADMIN_PASS')?:'admin123',
    'superLogin'=>getenv('CCS_SUPER_LOGIN')?:'superadmin','superPass'=>getenv('CCS_SUPER_PASS')?:'super123',
    'headPass'=>getenv('CCS_HEAD_PASS')?:'head123','isSurveyActive'=>'1','lang'=>'ru',
    'currentPeriod'=>date('Y-m'),'deptLimits'=>'{}',
    'allowEmpRateHead'=>'0','allowHeadRateEmp'=>'1',
    'voluntaryClosedWhenMandatoryDone'=>'1',
    // Phase 1 — access codes (M4). Defaults keep the OLD anonymous login (codeLoginEnabled=0 = safe rollback).
    'codeLoginEnabled'=>'0','codeLength'=>'6','codeAlphabet'=>'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
    'codeCaseSensitive'=>'0',
    // Edit window (minutes): a code lives & stays editable for this long FROM ISSUE (s13 timer-from-claim).
    // 0 = locked immediately (classic). Only matters when codes are enabled.
    'codeEditWindowMin'=>'300',  // s14 §2.1: code lifetime raised to 5 hours (was 90 min). Editable in UI.
    // s14 §2.2 — single-active-session per code (one code = one device at a time). codeDeviceMode:
    //   'kick'  = newest login wins, old device's token is killed (preserves cross-device edit window);
    //   'block' = first device wins, 2nd device gets code_in_use.
    'codeSingleSession'=>'1','codeDeviceMode'=>'kick',
    // s14 §1.2 — Global participants surface in EVERYONE's "Highly Recommended" block (skippable, unlike globalRatedByAll).
    'globalAlwaysRecommended'=>'1',
    // Req1 — Executive Managers (is_executive: CEO/CTO/CCO/CPO) are NEVER mandatory (removed from Step 1 for everyone).
    // They appear in the optional Recommended step ONLY when this toggle is ON. Default '0' = hidden from Recommended.
    'showExecInRecommended'=>'0',
    // s16 §1 — when employees rate heads (allowEmpRateHead=1), which heads are MANDATORY: 'all' = every head in their
    // project + Global (legacy); 'direct' = only the heads whose scope covers the employee's dept (their line manager +
    // any covering Executive), the rest move to the optional Recommended step. Client-side bucketing only (server
    // already allows any head when allowEmpRateHead=1). Default 'direct' (the §6 model).
    'empRateHeadScope'=>'direct',
    // s14 §3 — anonymity-safe progress monitoring (server stores counts/status per anon holder, never names/content).
    'progressMonitoring'=>'1',
    // s14 §4.2 — relative "smart" score (% of eligible voters) available as a selectable analytics metric.
    'smartAnalytics'=>'1',
    // s14 §2.4 — optional per-dept code-generation cap ('project|dept' => max codes). Empty/absent => cap = headcount.
    'codeLimits'=>'{}',
    // NAT-tolerant rate limits (office fix): both the code login and the self-gen throttles key on the PUBLIC ip, so a
    // whole office behind one WiFi/NAT shares a single counter. With the old tight limits (5 fails / 25 gens per 15 min)
    // a handful of typos — or 26 colleagues issuing codes at once — locked out EVERYONE on that IP. These defaults are
    // sized for a shared-NAT office; admin can lower them per deployment. login = FAILED attempts only (a correct code
    // resets the counter), so the ceiling is "typos per IP per window", not total logins. gen counts every issue.
    'codeLoginRateMax'=>'50','codeLoginRateWindowSec'=>'900','codeLoginRateLockSec'=>'300',
    'codeGenRateMax'=>'300','codeGenRateWindowSec'=>'900','codeGenRateLockSec'=>'300',
    // Custom value names and descriptions
    'value_commitment_name'=>'Commitment',
    'value_commitment_desc'=>'',
    'value_communication_name'=>'Communication',
    'value_communication_desc'=>'',
    'value_expertise_name'=>'Expertise',
    'value_expertise_desc'=>'',
    'value_personality_name'=>'Personality',
    'value_personality_desc'=>'',
];
$ins=$db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
foreach($defaults as $k=>$v) $ins->execute([$k,$v]);

// Seed employees if empty
$cnt=$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
if($cnt==0){
    $seed=[
      ['h1','Abidov Davron','Руководство','Global',1,'CEO'],['h2',"Xo'janazarov Xasan",'Руководство','Global',1,'CTO'],
      ['h3','Madaminov Feruz','HR','Global',1,'HR Manager'],['h4','Samadov Diyorbek','Marketing','Global',1,'Head of Marketing'],
      ['h5','Sulaymonov Otabek','Sales','Sales Doctor',1,'Head of Sales'],['h6','Yuldasheva Madina','Customer Care','Sales Doctor',1,'Head of Customer Care'],
      ['h7','Head of iDokon','Руководство','iDokon',1,'Head of iDokon'],['h8','Anvarbekov Asadbek','Development','iBox',1,'Product Manager (iBox)'],
      ['h9','Asanov Abdurashid','Key Account','Sales Doctor',1,'Senior KA Manager'],
      ['e1','Adilov Ilhom','Accounting and Finance','Global',0,''],['e2','Ibragimova Irina','Accounting and Finance','Global',0,''],
      ['e3','Xayrullina Valeriya','Accounting and Finance','Global',0,''],['e4','Umarova Mashhura','HR','Global',0,''],
      ['e5','Yodgorova Nodira','HR','Global',0,''],['e6','Kuzieva Anna','HR','Global',0,''],
      ['e7','Uktamova Sohiba','Business Development','Global',0,''],['e8','Arutyunyan Anna','Business Development','Global',0,''],
      ['e9','Bichkova Anastasiya','Marketing','Global',0,''],['e10','Xayrullayev Jafar','Marketing','Global',0,''],
      ['e11','Muhsimov Azizbek','Customer Care','Sales Doctor',0,''],['e12','Ruziyeva Madina','Customer Care','Sales Doctor',0,''],
      ['e13','Sattorov Sanjar','Customer Care','Sales Doctor',0,''],['e14','Toksanbayeva Albina','Customer Care','Sales Doctor',0,''],
      ['e15','Bahtiyorova Intizor','Customer Care','Sales Doctor',0,''],['e16','Normurodova Sabina','Customer Care','Sales Doctor',0,''],
      ['e17','Sabirdjanov Suroj','Customer Care','Sales Doctor',0,''],['e18','Burxonov Ulugbek','Customer Care','Sales Doctor',0,''],
      ['e19','Xusanov Muxammadali','Customer Care','Sales Doctor',0,''],['e50','Abdulvohid Abdulaziz (Churn Manager)','Customer Care','Sales Doctor',0,''],
      ['e20','Jumayev Alisher','Development','Sales Doctor',0,''],['e21','Kucharov Inomjon','Development','Sales Doctor',0,''],
      ['e22','Usmonov Sherzod','Development','Sales Doctor',0,''],['e23','Sobirov Bilol','Development','Sales Doctor',0,''],
      ['e24','Ashiraliyev Zokirjon','Development','Sales Doctor',0,''],['e25','Tursunaliyev Sardorbek','Development','Sales Doctor',0,''],
      ['e26','Saidnabiyev Saidazim','Development','Sales Doctor',0,''],['e27','Ubaydullayev Avazxon','Development','Sales Doctor',0,''],
      ['e28','Abduvosikov Abdulbosit','Development','Sales Doctor',0,''],['e29','Tulaganov Jamshid','Development','Sales Doctor',0,''],
      ['e30','Tojiyev Shaxzod','Development','Sales Doctor',0,''],['e31','Izzattillayev Jamshid','Development','Sales Doctor',0,''],
      ['e32','Maxkamov Behzodxon','Development','Sales Doctor',0,''],['e33','Yarashboyev Baxodir','Development','Sales Doctor',0,''],
      ['e34','Mirabbosov Ismoil','Development','Sales Doctor',0,''],['e35','Muminov Davron','Development','Sales Doctor',0,''],
      ['e36','Ubaydullayev Bilolxon','Development','Sales Doctor',0,''],['e37',"Xojaxonov G'ayratxon",'Development','Sales Doctor',0,''],
      ['e39','Ummatov Davron','Key Account','Sales Doctor',0,''],['e40',"Jo'rayev Sardor",'Key Account','Sales Doctor',0,''],
      ['e41','Mirkasimov Oybek','Key Account','Sales Doctor',0,''],['e42','Nazarov Nodir','Key Account','Sales Doctor',0,''],
      ['e44','Rahimov Abubakir','Sales','Sales Doctor',0,''],['e45','Shadiyev Jasur','Sales','Sales Doctor',0,''],
      ['e46','Tojiyev Ikrom','Sales','Sales Doctor',0,''],['e47','Norboyev Abdulla','Sales','Sales Doctor',0,''],
      ['e48','Toirjonov Humoyun','Sales','Sales Doctor',0,''],['e49','Kamarov Jamoliddin','Sales','Sales Doctor',0,''],
      ['e51','Tursunboyev Ibrohim','Customer Care','iBox',0,''],['e52','Egamberdiyev Sanjar','Customer Care','iBox',0,''],
      ['e53','Muhammadiev Zohid','Customer Care','iBox',0,''],['e54','Qahramonov Shaxzodbek','Customer Care','iBox',0,''],
      ['e55','Sobirova Mamura','Customer Care','iBox',0,''],['e56','Vaxobov Saloxiddin','Customer Care','iBox',0,''],
      ['e57','Jurayev Sanjar','Customer Care','iBox',0,''],['e58','Ibragimov Muhammadamin','Development','iBox',0,''],
      ['e59','Ashuraliyev Xoshimxon','Development','iBox',0,''],['e60',"Tillayev Ulug'bek",'Development','iBox',0,''],
      ['e61','Toshpulatov Rustam','Development','iBox',0,''],['e62','Xursanaliyev Abdullajon','Development','iBox',0,''],
      ['e63','Xujamatov Obid','Development','iBox',0,''],['e64','Mirgiyosov Ibragim','Development','iBox',0,''],
      ['e65','Mukumjanov Mansur','Development','iBox',0,''],['e66','Ashuraliyev Polatxon','Sales','iBox',0,''],
      ['e67','Tulanov Abdulloh','Sales','iBox',0,''],['e68','Abduraxmonov Abdulaziz','Sales','iBox',0,''],
      ['e69','Shukurillayev Oybek','Sales','iBox',0,''],['e70','Bazaraliyev Sohib','Sales','iBox',0,''],
      ['e71','Orifjonov Rahmatullo','Sales','iBox',0,''],['e72','Bobomirzayev Tulkin','Customer Care','iDokon',0,''],
      ['e73','Xayitbayeva Madina','Customer Care','iDokon',0,''],['e74','Yolchiboyev Komiljon','Sales','iDokon',0,''],
      ['e75','Rustamova Samira','Sales','iDokon',0,''],['e76','Abdullayev Alisher','Development','iDokon',0,''],
      ['e77','Boboqulov Jasur','Development','iDokon',0,''],
    ];
    $si=0;
    // Audit s12 (#14): OR IGNORE so a concurrent first-request race (two boots both passing the schema gate) can't
    // throw a duplicate-PRIMARY-KEY PDOException and 500 — the second insert of the same seed id is silently skipped.
    $st=$db->prepare("INSERT OR IGNORE INTO employees(id,name,dept,project,is_head,head_role,active,sort_order) VALUES(?,?,?,?,?,?,1,?)");
    foreach($seed as $r){$si++;$st->execute([$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$si]);}
}

// Normalize existing data: rename old "Management" dept
$db->exec("UPDATE employees SET dept='Руководство' WHERE dept='Management'");
} // ── end schema/seed gate (B-3) ──────────────────────────────────────────────

function getSetting($db,$key,$default=''){$r=$db->prepare("SELECT value FROM settings WHERE key=?");$r->execute([$key]);$v=$r->fetchColumn();return $v===false?$default:$v;}
function setSetting($db,$key,$value){$db->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)")->execute([$key,$value]);}
// audit s9: dept limit counts distinct PARTICIPANTS (people), not submission rows — one evaluator submitting many
// target-rows must not drain a people-sized quota and lock out the whole dept (incl. themselves).
function deptParticipants($db,$project,$dept,$period){ $s=$db->prepare("SELECT COUNT(DISTINCT eval_from) FROM evaluations WHERE from_project=? AND from_dept=? AND period=?"); $s->execute([$project,$dept,$period]); return (int)$s->fetchColumn(); }
function hasParticipated($db,$from,$project,$dept,$period){ $s=$db->prepare("SELECT 1 FROM evaluations WHERE eval_from=? AND from_project=? AND from_dept=? AND period=? LIMIT 1"); $s->execute([$from,$project,$dept,$period]); return $s->fetchColumn()!==false; }

// ── Auth layer (Phase 0) ───────────────────────────────────────────────────
function isHttps(){
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])!=='off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https')
        || (($_SERVER['SERVER_PORT']??'')=='443');
}
// Cookie Secure flag: env override (CCS_COOKIE_SECURE=1/0) for deploy behind a TLS proxy; default = auto-detect.
function cookieSecure(){
    $env=getenv('CCS_COOKIE_SECURE');
    if($env==='1'||strtolower((string)$env)==='true')  return true;
    if($env==='0'||strtolower((string)$env)==='false') return false;
    return isHttps();
}
// s17 §3 — share the device-lock cookies (ccs_gen_*/ccs_done_*) across a site's sub-origins so opening the survey from
// a SECOND URL of the SAME site (apex vs www, or two subdomains of one registrable domain) can't dodge the one-code-per-
// device lock. Returns the registrable parent with a leading dot (".salesdoc.io"); '' = host-only (current behaviour) for
// localhost / bare IPs / single-label hosts, where a cross-origin Domain cookie is invalid. Two GENUINELY unrelated
// domains still can't share state — inherent to the anonymous pool (same residual as incognito/another device).
function lockCookieDomain(){
    $host=preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??''));
    if($host===''||strcasecmp($host,'localhost')===0||filter_var($host,FILTER_VALIDATE_IP)) return '';
    $labels=explode('.',$host);
    if(count($labels)<2) return '';
    return '.'.implode('.',array_slice($labels,-2));   // last two labels — good enough for 2-label TLDs
}
// Real client IP for rate-limiting / audit. Default = REMOTE_ADDR (spoof-proof, current behaviour).
// Behind a reverse proxy/NAT, set CCS_TRUSTED_PROXIES=ip1,ip2 — then ONLY if the request actually
// arrived from a trusted proxy do we read X-Forwarded-For, walking right→left and skipping trusted
// hops to the first untrusted address (the real client). XFF is otherwise ignored, so a client can't
// spoof it to dodge the brute-force throttle.
function clientIp(){
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $trusted = array_filter(array_map('trim', explode(',', getenv('CCS_TRUSTED_PROXIES') ?: '')));
    if($trusted && in_array($remote, $trusted, true)){
        // Cloudflare orqasida haqiqiy mehmon IP'si shu yerda — yagona va ishonchli (CF edge to'ldiradi).
        $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
        // Zaxira: X-Forwarded-For'ni o'ngdan chapga yurib, ishonchli hop'larni o'tkazib.
        $parts = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        while($parts){ $cand = array_pop($parts); if(!in_array($cand, $trusted, true)) return $cand; }
    }
    return $remote;
}
function currentSession($db){
    $tok=$_COOKIE['ccs_token']??'';
    if($tok==='') return null;
    $st=$db->prepare("SELECT token,role,employee_id,expires_at FROM auth_sessions WHERE token=?");
    $st->execute([$tok]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    if(!$r) return null;
    if($r['expires_at']!==null && strtotime($r['expires_at']) < time()){
        $db->prepare("DELETE FROM auth_sessions WHERE token=?")->execute([$tok]);
        return null;
    }
    $db->prepare("UPDATE auth_sessions SET last_seen=CURRENT_TIMESTAMP WHERE token=?")->execute([$tok]);
    return $r;
}
// Timer-from-claim (HRD s13): a code's survey window runs `win` minutes from when the code was ISSUED
// (access_codes.created_at), NOT from the first submission. Returns a unix deadline, or null if there is no
// active code for this holder/period (e.g. a head password session — codes never bind to heads). $win<=0 = no limit.
function codeClaimDeadline($db,$employeeId,$period,$win){
    if($win<=0 || !$employeeId) return null;
    $st=$db->prepare("SELECT created_at FROM access_codes WHERE employee_id=? AND active=1 AND period=? ORDER BY created_at ASC LIMIT 1");
    $st->execute([$employeeId,$period]);
    $ca=$st->fetchColumn();
    if($ca===false || $ca===null) return null;
    $ts=strtotime(((string)$ca).' UTC');
    return $ts===false ? null : $ts+$win*60;
}
// superadmin is implicitly allowed everywhere; otherwise role must be in $roles
function requireRole($db,$roles){
    $s=currentSession($db);
    $ok=$s && (in_array($s['role'],$roles,true) || $s['role']==='superadmin');
    if(!$ok){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit(); }
    return $s;
}
function issueToken($db,$role,$empId=null){
    $tok=bin2hex(random_bytes(32));
    $ttl=43200; // 12h
    $exp=date('Y-m-d H:i:s', time()+$ttl);
    $db->prepare("INSERT INTO auth_sessions(token,role,employee_id,ip,user_agent,created_at,last_seen,expires_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?)")
       ->execute([$tok,$role,$empId,clientIp(),$_SERVER['HTTP_USER_AGENT']??'',$exp]);
    setcookie('ccs_token',$tok,['expires'=>time()+$ttl,'path'=>'/','httponly'=>true,'secure'=>cookieSecure(),'samesite'=>'Lax']);
    return $tok;
}
function clearToken($db){
    $tok=$_COOKIE['ccs_token']??'';
    if($tok!=='') $db->prepare("DELETE FROM auth_sessions WHERE token=?")->execute([$tok]);
    setcookie('ccs_token','',['expires'=>time()-3600,'path'=>'/','httponly'=>true,'secure'=>cookieSecure(),'samesite'=>'Lax']);
}
function verifyPass($db,$key,$candidate){
    if((string)$candidate==='') return false;   // never accept an empty password (even vs a stored hash of "")
    $stored=getSetting($db,$key,'');
    if($stored==='') return false;
    $info=password_get_info($stored);
    if(!empty($info['algo'])) return password_verify((string)$candidate,$stored);
    return hash_equals($stored,(string)$candidate); // legacy plaintext fallback (pre-migration)
}
// Audit s12 (#1/#2): the bulk corpus must not carry a pseudonym that is STABLE across an author's rows — a stable
// token lets any admin GROUP BY it to reconstruct a whole ballot (and re-identify singleton heads from their target
// set), de-anonymizing Final/Peer authorship in DevTools with NO reveal_log entry. Passing the row id as $salt makes
// every row's pseudonym unique → rows can no longer be linked into a ballot. Distinct-voter COUNTS are computed
// server-side instead (get_evaluations voterStats), so the analytics keep working without a linkable token.
function anonPseudo($db,$from,$period,$salt=''){
    static $secret=null;
    if($secret===null) $secret=getSetting($db,'anonSecret','ccs-fallback');
    return 'anon#'.substr(hash_hmac('sha256',$from.'|'.$period.'|'.$salt,$secret),0,12);
}

// ── Config-as-data validators (Phase 1) ─────────────────────────────────────
// Mirror of the frontend parsers. EVERY parse returns a SAFE_DEFAULT on any invalidity and never throws,
// so a corrupt/partial/hostile config can never break the engine or be stored out of range.
function jclampNum($v,$min,$max,$def){ if(!is_numeric($v)) return $def; $v=$v+0; if($v<$min)$v=$min; if($v>$max)$v=$max; return $v; }
function jbool($arr,$key,$def){ return is_array($arr)&&array_key_exists($key,$arr)?(bool)$arr[$key]:$def; }

// rulesConfig — SAFE_DEFAULT == the current hardcoded behaviour (case ≥9/≤4, moderation ≥9, reject→5).
// Is an employee row part of the 'Global' line? Multiproject-aware (primary project ∪ projects[]) — mirrors the
// frontend empProjects().includes('Global') so the globalNoSkip / globalRatedByAll backstops agree with the UI.
function rowInGlobal($row){
    if(!is_array($row)) return false;
    if((string)($row['project']??'')==='Global') return true;
    $p=json_decode((string)($row['projects']??'[]'),true);
    return is_array($p) && in_array('Global',$p,true);
}
// s14 §3 — decode a JSON-array column (scope_projects/scope_depts/projects) to a clean string list.
function jdecodeArr($v){ if(is_array($v)) return array_values(array_filter($v,'strlen')); $x=json_decode((string)$v,true); return is_array($x)?array_values(array_filter($x,'strlen')):[]; }
// s14 §3.2 — the set of 'project|dept' keys a manager may see in the monitoring dashboard, from their scope (mirrors the
// client getMandatoryTargets head branch). Returns NULL = all depts (scope 'all'); [] = nothing (unknown head).
function headScopeKeys($db,$hr){
    if(!is_array($hr)||!$hr) return [];
    $mode=(string)($hr['scope_mode']??'');
    if($mode==='all') return null;
    $all=[]; foreach($db->query("SELECT DISTINCT project,dept FROM employees WHERE active=1") as $r){ $all[]=$r['project'].'|'.$r['dept']; }
    if($mode==='project'){
        $projs=array_values(array_filter(array_merge([(string)($hr['project']??'')],jdecodeArr($hr['projects']??'')),'strlen'));
        return array_values(array_filter($all,function($k)use($projs){ return in_array(explode('|',$k,2)[0],$projs,true); }));
    }
    if($mode==='custom'){
        $sp=jdecodeArr($hr['scope_projects']??''); $sd=jdecodeArr($hr['scope_depts']??'');
        if(!$sp && !$sd) return [];   // s14 audit #1: empty custom scope = NOTHING (mirror client getMandatoryTargets), never "all"
        return array_values(array_filter($all,function($k)use($sp,$sd){ $pp=explode('|',$k,2); return (!$sp||in_array($pp[0],$sp,true)) && (!$sd||in_array($pp[1],$sd,true)); }));
    }
    if($mode===''){
        // s15 fix: a legacy head (no scope_mode) is routed by head_role in getMandatoryTargets — the monitoring scope
        // MUST mirror that switch, not fall back to the head's OWN dept (for CEO/CTO that is "Руководство", where they
        // have no subordinates → they'd monitor the wrong team / nothing). Map each role to the project|dept keys of the
        // team it oversees (counts only, same predicates as the client switch; Global is intentionally NOT added —
        // monitoring is "strictly subordinates", §3.2). Unknown role → own dept (same as getMandatoryTargets default).
        $role=(string)($hr['head_role']??'');
        $pick=function($f)use($all){ return array_values(array_filter($all,function($k)use($f){ $pp=explode('|',$k,2); return $f($pp[0],$pp[1]??''); })); };
        switch($role){
            case'CEO': return null;                                                              // oversees everyone (all depts)
            case'CTO': return $pick(fn($p,$d)=>$d==='Development');
            case'HR Manager': return $pick(fn($p,$d)=>$d==='HR');
            case'Head of Marketing': return $pick(fn($p,$d)=>$d==='Marketing');
            case'Head of Sales': return $pick(fn($p,$d)=>($d==='Sales'&&in_array($p,['Sales Doctor','iBox'],true))||$d==='Churn');
            case'Head of Customer Care': return $pick(fn($p,$d)=>$d==='Customer Care'&&in_array($p,['Sales Doctor','iBox'],true));
            case'Head of iDokon': return $pick(fn($p,$d)=>in_array($d,['Sales','Customer Care'],true)&&$p==='iDokon');
            case'Product Manager (iBox)': return $pick(fn($p,$d)=>$d==='Development'&&$p==='iBox');
            case'Senior KA Manager': return $pick(fn($p,$d)=>$d==='Key Account'&&$p==='Sales Doctor');
        }
    }
    return [((string)($hr['project']??'')).'|'.((string)($hr['dept']??''))];   // explicit 'dept' / unknown role → own project+dept
}
// s14 audit #2: coarse device label (server-side) for the anonymous Active-Devices view. We must NOT ship the raw
// User-Agent or IP of code respondents (the s13 #3 unlogged de-anon side channel) — a full UA fingerprints a person
// and the shared corporate IP is both useless (§2.2) and correlatable. Only a broad device class is shown.
function coarseDevice($ua){ $ua=(string)$ua;
    if(preg_match('/iPhone|iPad|iPod/i',$ua)) return 'iOS';
    if(preg_match('/Android/i',$ua)) return 'Android';
    if(preg_match('/Windows/i',$ua)) return 'Windows';
    if(preg_match('/Mac OS X|Macintosh/i',$ua)) return 'macOS';
    if(preg_match('/Linux/i',$ua)) return 'Linux';
    return $ua!=='' ? 'Browser' : 'Unknown';
}
// s14 §4.4 — shared export builder. Returns ['header'=>[...35 names], 'rows'=>[[...35 cells],...]] used by BOTH
// export_csv (proven, byte-identical) and export_xlsx. Anonymization, deanon-under-log, ANALYTICS-1 skip and the
// formula-injection guard live HERE once — so CSV and XLSX can never diverge in what they reveal. $reveal is decided
// by the caller (superadmin + logged) BEFORE calling this.
function ccsExportData($db,$reveal){
    $empRows=$db->query("SELECT id, name, dept, project, position, is_head, active FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    $empMap=[]; foreach($empRows as $e) $empMap[$e['id']]=$e;
    $rows=$db->query("SELECT * FROM evaluations ORDER BY period ASC, created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    $rjCsv=getRules($db)['rejectScore'];
    $safe=function($v){ $s=(string)$v; if($s!=='' && strpos("=+-@\t\r", $s[0])!==false) return "'".$s; return $s; };   // formula-injection guard (CSV + XLSX)
    $header=['eval_id','period','created_at','evaluator_id','evaluator_name','evaluator_dept','evaluator_project','evaluator_role','evaluator_is_head','evaluator_position','evaluated_id','evaluated_name','evaluated_dept','evaluated_project','evaluated_is_head','evaluated_position','commitment_score','commitment_skipped','commitment_status','commitment_comment','communication_score','communication_skipped','communication_status','communication_comment','expertise_score','expertise_skipped','expertise_status','expertise_comment','personality_score','personality_skipped','personality_status','personality_comment','avg_score','criteria_rated','effective_avg_score'];
    $out=[];
    foreach($rows as $r){
        $s=json_decode($r['scores'],true) ?? [];
        $evtr=$empMap[$r['eval_from']] ?? null;
        $evaluatorRole=$r['evaluator_role'] ?? 'employee';
        $evaluatorDept=$evtr ? $evtr['dept'] : ($r['from_dept'] ?? '');
        $evaluatorProject=$evtr ? $evtr['project'] : ($r['from_project'] ?? '');
        if($reveal){
            $evaluatorId=$r['eval_from'] ?? '';
            $evaluatorName=$safe($evtr ? $evtr['name'] : ($r['from_dept'] ? '[Сотрудник: '.$r['from_dept'].']' : '—'));
            $evaluatorIsHead=$evtr ? (int)$evtr['is_head'] : 0;
            $evaluatorPos=$evtr ? ($evtr['position'] ?? '') : '';
        } else {
            $evaluatorId=anonPseudo($db, $r['eval_from'] ?? '', $r['period'] ?? '', $r['id'] ?? '');
            $evaluatorName='—'; $evaluatorDept=''; $evaluatorProject=''; $evaluatorIsHead=0; $evaluatorPos='';
        }
        $evtd=$empMap[$r['eval_to']] ?? null;
        if($evtd && (int)$evtd['active']===0) continue;   // ANALYTICS-1: deactivated target dropped
        $evaluatedId=$r['eval_to'] ?? '';
        $evaluatedName=$safe($evtd ? $evtd['name'] : $evaluatedId);
        $evaluatedDept=$evtd ? $evtd['dept'] : ($r['to_dept'] ?? '');
        $evaluatedProject=$evtd ? $evtd['project'] : '';
        $evaluatedIsHead=$evtd ? (int)$evtd['is_head'] : 0;
        $evaluatedPos=$evtd ? ($evtd['position'] ?? '') : '';
        $scoreSum=0; $scoreCnt=0;
        foreach(['commitment','communication','expertise','personality'] as $k){
            $sk=$s[$k] ?? [];
            if(!($sk['skipped'] ?? false) && isset($sk['score']) && (int)$sk['score']>0){ $scoreSum+=(int)$sk['score']; $scoreCnt++; }
        }
        $avgScore=$scoreCnt>0 ? round($scoreSum/$scoreCnt,2) : '';
        $effSum=0; $effCnt=0;
        foreach(['commitment','communication','expertise','personality'] as $k){
            $sk=$s[$k] ?? []; if($sk['skipped'] ?? false) continue;
            $sc=isset($sk['score']) ? (int)$sk['score'] : 0; if($sc<=0) continue;
            $effSum+=(($sk['status'] ?? '')==='rejected') ? (int)$rjCsv : $sc; $effCnt++;
        }
        $effAvg=$effCnt>0 ? round($effSum/$effCnt,2) : '';
        $col=function($key) use ($s,$safe){
            $v=$s[$key] ?? []; $skipped=(bool)($v['skipped'] ?? false);
            $score=!$skipped && isset($v['score']) ? (int)$v['score'] : '';
            if($score===0) $score='';
            $status=$skipped ? 'skipped' : ($v['status'] ?? 'approved');
            $comment=$skipped ? '' : $safe(trim($v['text'] ?? ''));
            return [$score, $skipped ? 1 : 0, $status, $comment];
        };
        [$cmS,$cmK,$cmT,$cmX]=$col('commitment'); [$coS,$coK,$coT,$coX]=$col('communication');
        [$exS,$exK,$exT,$exX]=$col('expertise');  [$peS,$peK,$peT,$peX]=$col('personality');
        $out[]=[
            $r['id'], $r['period'] ?? '', $reveal ? $r['created_at'] : '',
            $safe($evaluatorId), $evaluatorName, $safe($evaluatorDept), $safe($evaluatorProject), $evaluatorRole, $evaluatorIsHead, $safe($evaluatorPos),
            $safe($evaluatedId), $evaluatedName, $safe($evaluatedDept), $safe($evaluatedProject), $evaluatedIsHead, $safe($evaluatedPos),
            $cmS,$cmK,$cmT,$cmX, $coS,$coK,$coT,$coX, $exS,$exK,$exT,$exX, $peS,$peK,$peT,$peX,
            $avgScore, $scoreCnt, $effAvg,
        ];
    }
    return ['header'=>$header,'rows'=>$out];
}
// s14 §4.4 — minimal, dependency-free .xlsx (Office Open XML = a zip of XML; PHP ships ZipArchive). Strings go in as
// inlineStr (always text → formula-injection-immune by construction), numbers as <v>. No styles/sharedStrings = small
// and Excel/Sheets-compatible. Returns the .xlsx bytes.
function ccsXlsx($header,$rows){
    $colRef=function($n){ $s=''; $n++; while($n>0){ $m=($n-1)%26; $s=chr(65+$m).$s; $n=intdiv($n-1,26); } return $s; };
    // s14 audit #10: strip XML-1.0-illegal control chars (0x00-08,0B,0C,0E-1F) — even escaped they make the whole
    // workbook unopenable. A single crafted comment could otherwise corrupt the entire export.
    $esc=function($v){ $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$v); return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $all=array_merge([$header],$rows);
    $sd='';
    foreach($all as $ri=>$row){
        $rn=$ri+1; $sd.='<row r="'.$rn.'">';
        foreach($row as $ci=>$cell){
            if($cell===''||$cell===null) continue;
            $ref=$colRef($ci).$rn;
            if(is_int($cell)||is_float($cell)){
                $sd.='<c r="'.$ref.'"><v>'.$esc($cell).'</v></c>';
            } else {
                // inlineStr is inherently text (formula-injection-immune), so drop ONLY the CSV formula-guard apostrophe
                // (one prepended before = + - @ \t \r) — never a user's genuine leading apostrophe (s14 audit #11).
                $str=(string)$cell; if(strlen($str)>1 && $str[0]==="'" && strpos("=+-@\t\r",$str[1])!==false) $str=substr($str,1);
                $sd.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($str).'</t></is></c>';
            }
        }
        $sd.='</row>';
    }
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sd.'</sheetData></worksheet>';
    $ct='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $wb='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Evaluations" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbr='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    $tmp=tempnam(sys_get_temp_dir(),'ccsx'); $zip=new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',$ct);
    $zip->addFromString('_rels/.rels',$rels);
    $zip->addFromString('xl/workbook.xml',$wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels',$wbr);
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    $zip->close();
    $bytes=file_get_contents($tmp); @unlink($tmp);
    return $bytes;
}
// caseHighReq = score at/above which a written case is MANDATORY; decoupled from caseHigh (green/color/moderation).
// Default == caseHigh → legacy behaviour. HRD R2: caseHighReq=8 (case at 8) while green/moderation stay 9.
function rulesDefault(){ return ['caseHigh'=>9,'caseLow'=>4,'caseHighReq'=>9,'moderationThreshold'=>9,'rejectScore'=>5,'caseRequiredHigh'=>true,'caseRequiredLow'=>true]; }
function parseRulesArr($raw){
    $d=rulesDefault();
    if(!is_array($raw)) return $d;
    $o=$d;
    $o['caseHigh']=(int)jclampNum($raw['caseHigh']??$d['caseHigh'],1,10,$d['caseHigh']);
    $o['caseLow'] =(int)jclampNum($raw['caseLow'] ??$d['caseLow'], 1,10,$d['caseLow']);
    $o['moderationThreshold']=(int)jclampNum($raw['moderationThreshold']??$d['moderationThreshold'],1,11,$d['moderationThreshold']); // 11 = never moderate
    $o['rejectScore']=(int)jclampNum($raw['rejectScore']??$d['rejectScore'],1,10,$d['rejectScore']);
    if($o['caseLow']>=$o['caseHigh']){ $o['caseLow']=$d['caseLow']; $o['caseHigh']=$d['caseHigh']; }  // invalid range → safe default
    $o['caseHighReq']=(int)jclampNum($raw['caseHighReq']??$o['caseHigh'],1,10,$o['caseHigh']);        // default = caseHigh (legacy)
    if($o['caseHighReq']<=$o['caseLow']) $o['caseHighReq']=$o['caseHigh'];                            // must sit above the red band
    if($o['caseHighReq']>$o['caseHigh']) $o['caseHighReq']=$o['caseHigh'];                            // CASEREQ-CLAMP: green flag (≥caseHigh) always needs a case
    $o['caseRequiredHigh']=jbool($raw,'caseRequiredHigh',true);
    $o['caseRequiredLow'] =jbool($raw,'caseRequiredLow', true);
    return $o;
}
function getRules($db){ return parseRulesArr(json_decode((string)getSetting($db,'rulesConfig',''),true)); }

// weightsConfig — SAFE_DEFAULT disabled == current simple averages.
// M2b: valueWeights holds three per-evaluated-role profiles — exec / head / employee. A legacy flat
// {commitment,…} payload migrates by copying the single set to all three roles (behaviour unchanged).
function vwDefaultSet(){ return ['commitment'=>25,'communication'=>25,'expertise'=>25,'personality'=>25]; }
function parseVWSetArr($obj){
    $o=[]; foreach(['commitment','communication','expertise','personality'] as $k) $o[$k]=(int)jclampNum(is_array($obj)?($obj[$k]??25):25,0,100,25);
    if(array_sum($o)<=0) return vwDefaultSet();  // NaN-guard: never divide by zero downstream
    return $o;
}
function parseValueWeightsArr($raw){
    $r=is_array($raw)?$raw:[];
    if(array_key_exists('exec',$r)||array_key_exists('head',$r)||array_key_exists('employee',$r))
        return ['exec'=>parseVWSetArr($r['exec']??[]),'head'=>parseVWSetArr($r['head']??[]),'employee'=>parseVWSetArr($r['employee']??[])];
    $flat=parseVWSetArr($r);                      // legacy single set → same weights for every role
    return ['exec'=>$flat,'head'=>$flat,'employee'=>$flat];
}
// Req4: per-VALUE Final(manager) share set — 4 keys, each 0..100; a missing key falls back to $fallback (global finalShare).
function vfsDefaultSet(){ return ['commitment'=>50,'communication'=>50,'expertise'=>50,'personality'=>50]; }
function parseVFSArr($raw,$fallback){
    $r=is_array($raw)?$raw:[];
    $o=[]; foreach(['commitment','communication','expertise','personality'] as $k) $o[$k]=(int)jclampNum($r[$k]??$fallback,0,100,$fallback);
    return $o;
}
function weightsDefault(){ $vw=vwDefaultSet(); return ['enabled'=>false,'finalShare'=>50,'execShare'=>50,
    'useValueFinalShare'=>false,'valueFinalShare'=>vfsDefaultSet(),'useValueWeights'=>false,
    'valueWeights'=>['exec'=>$vw,'head'=>$vw,'employee'=>$vw],'minPeerForFinalBlend'=>1]; }
function parseWeightsArr($raw){
    $d=weightsDefault();
    if(!is_array($raw)) return $d;
    $o=$d;
    $o['enabled']=jbool($raw,'enabled',false);
    $o['finalShare']=(int)jclampNum($raw['finalShare']??50,0,100,50);
    $o['execShare'] =(int)jclampNum($raw['execShare'] ??50,0,100,50);
    $o['useValueFinalShare']=jbool($raw,'useValueFinalShare',false);
    $o['valueFinalShare']=parseVFSArr($raw['valueFinalShare']??null,$o['finalShare']);
    $o['useValueWeights']=jbool($raw,'useValueWeights',false);
    $o['minPeerForFinalBlend']=(int)jclampNum($raw['minPeerForFinalBlend']??1,0,99,1);
    $o['valueWeights']=parseValueWeightsArr($raw['valueWeights']??null);
    return $o;
}

// ── Access codes (M4) ───────────────────────────────────────────────────────
function codeCfg($db){
    $alpha=preg_replace('/[^A-Za-z0-9]/','',getSetting($db,'codeAlphabet','ABCDEFGHJKMNPQRSTUVWXYZ23456789'));
    if(strlen($alpha)<10) $alpha='ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    return [
        'len'=>(int)jclampNum(getSetting($db,'codeLength','6'),6,12,6),  // hard server minimum 6
        'alphabet'=>$alpha,
        'caseSensitive'=>getSetting($db,'codeCaseSensitive','0')==='1',
        'enabled'=>getSetting($db,'codeLoginEnabled','0')==='1',
    ];
}
function normCode($code,$caseSensitive){ $c=trim((string)$code); return $caseSensitive?$c:strtoupper($c); }
function codeCharsetOk($c,$cfg){
    $alpha=$cfg['caseSensitive']?$cfg['alphabet']:strtoupper($cfg['alphabet']);
    $len=strlen($c); for($i=0;$i<$len;$i++){ if(strpos($alpha,$c[$i])===false) return false; } return true;
}
function genCode($db,$cfg,$period){
    $alpha=$cfg['alphabet']; $len=$cfg['len']; $n=strlen($alpha);
    for($try=0;$try<60;$try++){
        $c=''; for($i=0;$i<$len;$i++) $c.=$alpha[random_int(0,$n-1)];
        if(!$cfg['caseSensitive']) $c=strtoupper($c);
        $chk=$db->prepare("SELECT 1 FROM access_codes WHERE code=? AND period=?"); $chk->execute([$c,$period]);
        if(!$chk->fetch()) return $c;   // unique within period (active OR inactive — never reuse → no PK clash)
    }
    return null;
}
// AUDIT-DEAD: regenAllCodes() (admin mass-regeneration) removed — codes are now self-generated by employees
// (generate_my_code). It had no callers and could only resurrect an admin-known codebook (a deadlock + anonymity
// regression). codeAutoRegenOnPeriod was its dead toggle and is gone too.

// ── Per-request housekeeping (cheap, time-based — MUST run every request, not gated) ─────────
$db->exec("DELETE FROM auth_sessions WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");      // drop expired auth tokens
$db->exec("DELETE FROM admin_sessions WHERE login_time < datetime('now', '-30 days')");                    // prune old monitor rows
try{ $db->exec("DELETE FROM login_attempts WHERE locked_until < ".time()." AND first_at < ".(time()-3600)); }catch(Exception $__e){}  // clear stale brute-force counters

// ── Phase 0/1 boot migrations (idempotent) — gated by schema_version (B-3) ───────────────────
if($needMigrate){
// Hash any plaintext passwords in place; same passwords keep working via password_verify
foreach(['adminPass','superPass','headPass'] as $__pk){
    $__cur=getSetting($db,$__pk,'');
    if($__cur!=='' && empty(password_get_info($__cur)['algo'])) setSetting($db,$__pk,password_hash($__cur,PASSWORD_DEFAULT));
}
// Per-install secret for anonymized evaluator pseudonyms — generated once, never exposed via API
if(getSetting($db,'anonSecret','')==='') setSetting($db,'anonSecret',bin2hex(random_bytes(16)));
// Physical dedup guard (eval_from, eval_to, period); tolerate any pre-existing duplicates
try{ $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eval_unique ON evaluations(eval_from,eval_to,period)"); }catch(Exception $__e){}
// M4: one active code per (employee,period). Audit fix — drop any pre-existing active duplicates FIRST
// (keep newest by rowid) so CREATE UNIQUE INDEX can't fail silently and leave uniqueness unenforced.
try{
    $db->exec("UPDATE access_codes SET active=0 WHERE active=1 AND rowid NOT IN (SELECT MAX(rowid) FROM access_codes WHERE active=1 GROUP BY employee_id,period)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_codes_emp_active ON access_codes(employee_id,period) WHERE active=1");
}catch(Exception $__e){}
// M5: seed bilingual value keys (value_X_field_ru/_uz) from the legacy single keys ONCE (idempotent).
foreach(['commitment','communication','expertise','personality'] as $__vk){
    foreach(['name','desc'] as $__field){
        $__legacy=getSetting($db,"value_{$__vk}_{$__field}",'');
        foreach(['ru','uz'] as $__lng){
            if(getSetting($db,"value_{$__vk}_{$__field}_{$__lng}",'__none__')==='__none__')
                setSetting($db,"value_{$__vk}_{$__field}_{$__lng}",$__legacy);
        }
    }
}
// s16 v7: normalize the Executive Management category. CEO/CTO/CCO/CPO are top execs whose vote weights as exec
// (M2 weightedDCS) and who form the "Executive Management" group in the survey (§6). Some were seeded is_executive=0,
// so flag them once. Idempotent; the super-admin can still toggle is_executive per person in the constructor afterwards.
try{ $db->exec("UPDATE employees SET is_executive=1 WHERE is_head=1 AND head_role IN ('CEO','CTO','CCO','CPO') AND COALESCE(is_executive,0)=0"); }catch(Exception $__e){}
// All DDL/seed/migrations are done → stamp the version so the whole block is skipped next request.
setSetting($db,'schema_version',(string)$SCHEMA_VERSION);
}

$action=$_GET['action']??'';
$input=json_decode(file_get_contents('php://input'),true)??[];

switch($action){

case 'login':
    // Server-side credential check; issues an httpOnly-cookie session token (F0-1)
    $mode=$input['mode']??'admin';
    $pwd=(string)($input['password']??'');
    // NAT-friendly (shared-WiFi office: ≈130 people on ONE public IP): the privileged password login is deliberately
    // NOT rate-limited and NEVER returns HTTP 429. A per-IP lockout would freeze the WHOLE office the moment a few
    // colleagues mistype — so a wrong password just returns {"error":"invalid"}, every time, with no lockout. Brute
    // force is instead made infeasible by credential STRENGTH: admin/super/head use long random passwords (verifyPass
    // + the rotated secrets), so even an unthrottled guesser cannot get in. KEEP THESE PASSWORDS STRONG.
    $loginOk=false; $resp=['error'=>'invalid'];
    if($mode==='head'){
        $hid=$input['headId']??'';
        $h=$db->prepare("SELECT id,name,dept,project FROM employees WHERE id=? AND is_head=1 AND active=1");
        $h->execute([$hid]); $he=$h->fetch(PDO::FETCH_ASSOC);
        if($he && verifyPass($db,'headPass',$pwd)){
            issueToken($db,'manager',$he['id']);
            $loginOk=true; $resp=['success'=>true,'role'=>'manager','employee_id'=>$he['id'],'name'=>$he['name']];
        }
    } else {
        $login=(string)($input['login']??'');
        if($login===getSetting($db,'superLogin','superadmin') && verifyPass($db,'superPass',$pwd)){
            issueToken($db,'superadmin'); $loginOk=true; $resp=['success'=>true,'role'=>'superadmin','name'=>'Super Admin'];
        } else if($login===getSetting($db,'adminLogin','admin') && verifyPass($db,'adminPass',$pwd)){
            issueToken($db,'admin'); $loginOk=true; $resp=['success'=>true,'role'=>'admin','name'=>'Admin'];
        }
    }
    echo json_encode($resp);   // no per-IP lockout (shared-NAT office) — strong passwords are the brute-force defense
    break;

case 'logout':
    clearToken($db);
    echo json_encode(['success'=>true]);break;

case 'get_settings':
    // F0-3 + M5 generic mapper: SECRETS are never exposed; a few keys are admin/super-only; everything
    // else (lang, period, value_*, rulesConfig, weightsConfig, hierarchyConfig, codeLoginEnabled, codeLength…)
    // is public so new config keys reach the frontend automatically — deny-by-secret, not allow-by-list.
    // The anonymous survey itself NEEDS rulesConfig (case thresholds) and hierarchyConfig (mandatory targets).
    $sess=currentSession($db);
    $role=$sess['role']??null;
    $isAdmin=($role==='admin'||$role==='superadmin');
    $isSuper=($role==='superadmin');
    $all=$db->query("SELECT key,value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $secretKeys=['adminPass','superPass','headPass','anonSecret'];   // never exposed to anyone
    $adminOnly =['adminLogin','deptLimits','codeLimits'];            // admin + superadmin
    // Hardening: superLogin is superadmin-ONLY. A plain admin (or a leaked admin session) must not even learn the
    // super-admin's login NAME — knowing it is half of the super credential and aids targeted brute force. The super
    // login is only ever needed on the super-admin's own settings screen, which is already a superadmin session.
    $superOnly =['superLogin','codeAlphabet'];                       // superadmin only
    $out=[];
    foreach($all as $k=>$v){
        if(in_array($k,$secretKeys,true)) continue;
        if(in_array($k,$adminOnly,true)){ if($isAdmin) $out[$k]=$v; continue; }
        if(in_array($k,$superOnly,true)){ if($isSuper) $out[$k]=$v; continue; }
        $out[$k]=$v;
    }
    echo json_encode(['success'=>true,'data'=>$out]);break;

case 'save_settings':
    // F0-6: admin may write only "basic" keys; sensitive keys are superadmin-only; passwords are hashed (F0-2)
    $s=requireRole($db,['admin']);
    $role=$s['role'];
    // Audit s12 (#3): headPass is a SUPERADMIN-tier control. It governs Final-vote authority — anyone who knows it can
    // log in as ANY head (login mode=head) and cast authoritative manager/Final votes. A plain admin must NOT be able to
    // reset it (that let an admin manufacture leadership votes + lock every head out). Moved basic → super.
    $basicKeys=['adminLogin','adminPass','isSurveyActive','lang'];
    $superKeys=['superLogin','superPass','headPass','allowEmpRateHead','allowHeadRateEmp','voluntaryClosedWhenMandatoryDone',
                'showExecInRecommended',
                'globalNoSkip','globalRatedByAll','globalAlwaysRecommended','empRateHeadScope',
                'codeSingleSession','codeDeviceMode','progressMonitoring','smartAnalytics',
                'currentPeriod','deptLimits','codeLimits',
                'value_commitment_name','value_commitment_desc','value_communication_name','value_communication_desc',
                'value_expertise_name','value_expertise_desc','value_personality_name','value_personality_desc'];
    $pwKeys=['adminPass','superPass','headPass'];
    $stmt=$db->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)");
    foreach($input as $k=>$v){
        $allowed = in_array($k,$basicKeys,true) || ($role==='superadmin' && in_array($k,$superKeys,true));
        if(!$allowed) continue;
        if(in_array($k,$pwKeys,true)){
            if((string)$v==='') continue;                       // empty = "leave unchanged"; never store hash of ""
            $val = password_hash((string)$v,PASSWORD_DEFAULT);
        } else { $val = (string)$v; }
        $stmt->execute([$k,$val]);
    }
    echo json_encode(['success'=>true]);break;

// Dedicated endpoint for getting/saving value (criteria) settings — now bilingual (M5).
// Returns ALL value_* keys: legacy (value_X_name) + per-language (value_X_name_ru / _uz).
case 'get_values_config':
    $rows=$db->query("SELECT key,value FROM settings WHERE substr(key,1,6)='value_'")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'save_values_config':
    requireRole($db,['superadmin']);
    $stmt=$db->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)");
    // Accept value_<key>_<field> and value_<key>_<field>_<lang> only (prevents writing arbitrary settings here).
    foreach($input as $k=>$v)
        if(preg_match('/^value_(commitment|communication|expertise|personality)_(name|desc)(_(ru|uz))?$/',$k))
            $stmt->execute([$k,strval($v)]);
    echo json_encode(['success'=>true]);break;

case 'get_employees':
    // Names/dept/project are needed by the survey; PII (phone/email/notes/birth_date) is admin-only.
    // B-4: the heavy base64 `photo` is NO LONGER shipped here — clients fetch it once via get_photos
    // (cached) and render lazily. Only a light `has_photo` flag stays in the list.
    $sess=currentSession($db);
    $isAdmin=in_array($sess['role']??'',['admin','superadmin'],true);
    $rows=$db->query("SELECT * FROM employees ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r){
        $r['has_photo']=($r['photo']??'')!==''?1:0;
        unset($r['photo']);                                                  // drop heavy base64 from the bulk list
        // audit s9: HR/employment fields are admin-only; the survey roster needs only identity + routing fields
        // (name/dept/project/is_head/head_role/position/projects/scope_*/is_executive/has_photo).
        if(!$isAdmin){ unset($r['phone'],$r['email'],$r['notes'],$r['birth_date'],$r['start_date'],$r['end_date'],$r['on_probation'],$r['official_employed']); }
    }
    unset($r);
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'get_photos':
    // B-4: photos served separately so the employee list stays light. Same exposure as before
    // (the survey already showed colleague photos to everyone) — just fetched once and cached client-side.
    $rows=$db->query("SELECT id,photo FROM employees WHERE photo!=''")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'save_employee':
    requireRole($db,['superadmin']);
    $d=$input;
    if(empty($d['id'])){echo json_encode(['error'=>'no id']);break;}
    // M1: array fields arrive as JSON arrays (or strings) → normalize to a JSON string column.
    $jenc=function($v){ if(is_array($v)) return json_encode(array_values(array_filter($v,'strlen')),JSON_UNESCAPED_UNICODE);
                        if(is_string($v)&&$v!==''){ $x=json_decode($v,true); return is_array($x)?json_encode(array_values($x),JSON_UNESCAPED_UNICODE):''; } return ''; };
    $projects=$jenc($d['projects']??''); $scopeProjects=$jenc($d['scope_projects']??''); $scopeDepts=$jenc($d['scope_depts']??'');
    // s17 §2 — per-employee visibility/voting overrides (JSON id arrays). rate_block applies to ANY employee; rate_extra
    // too (a non-head can be told to additionally evaluate specific people). Both normalize via $jenc like the scope fields.
    $rateExtra=$jenc($d['rate_extra']??''); $rateBlock=$jenc($d['rate_block']??'');
    $scopeMode=in_array(($d['scope_mode']??''),['','dept','project','all','custom'],true)?($d['scope_mode']??''):'';
    // Constructor hardening: trim the routing-critical text fields. A stray space (" Sales ") would otherwise
    // split a real dept/project into a phantom group and break getMandatoryTargets + analytics grouping.
    // name/dept/project are mandatory (server backstop to the UI's required-field rule) — an employee with an
    // empty dept/project would see no colleagues and pollute the dept/project dashboards.
    $name=trim((string)($d['name']??'')); $dept=trim((string)($d['dept']??'')); $project=trim((string)($d['project']??''));
    if($name===''||$dept===''||$project===''){echo json_encode(['error'=>'missing_fields']);break;}
    $position=trim((string)($d['position']??'')); $headRole=trim((string)($d['head_role']??''));
    // Head-only fields are meaningless for a non-head. Normalize so a demoted head (is_head 1→0) doesn't keep a
    // stale is_executive / scope that would silently re-apply if re-promoted later (audit, LOW). projects[]
    // (multi-project) is kept — regular employees can legitimately span products. (Routing/analytics already
    // ignore these for non-heads, so this is pure data-hygiene, not a behaviour change for existing clean rows.)
    $isHead=(int)($d['is_head']??0);
    $isExec=$isHead===1?(int)($d['is_executive']??0):0;
    if($isHead!==1){ $scopeMode=''; $scopeProjects=''; $scopeDepts=''; }
    $ex=$db->prepare("SELECT id,dept,project,head_manual FROM employees WHERE id=?");$ex->execute([$d['id']]);
    $existingRow=$ex->fetch(PDO::FETCH_ASSOC);
    // s17 §4 — head_manual is heads-only. PRESERVE the stored value when the payload omits the key (EmpProfileModal
    // doesn't send it; the Панель руководителей does), so editing a head elsewhere never silently disables manual routing.
    $headManual = $isHead!==1 ? 0 : (array_key_exists('head_manual',$d) ? ((int)$d['head_manual']?1:0) : (int)($existingRow['head_manual']??0));
    if($existingRow){
        $oldDept=(string)$existingRow['dept']; $oldProject=(string)$existingRow['project'];
        // B-4 safety: only overwrite photo when the client explicitly sends the `photo` key. The light
        // employee list no longer carries photos, so an edit that doesn't touch the photo must KEEP the
        // stored one (never wipe). '' is still a valid explicit value (the "Remove photo" action).
        if(array_key_exists('photo',$d)){ $photoVal=(string)($d['photo']??''); }
        else { $pc=$db->prepare("SELECT photo FROM employees WHERE id=?"); $pc->execute([$d['id']]); $photoVal=(string)$pc->fetchColumn(); }
        $db->prepare("UPDATE employees SET name=?,dept=?,project=?,is_head=?,head_role=?,active=?,position=?,start_date=?,end_date=?,on_probation=?,official_employed=?,phone=?,email=?,birth_date=?,notes=?,photo=?,projects=?,is_executive=?,scope_mode=?,scope_projects=?,scope_depts=?,rate_extra=?,rate_block=?,head_manual=? WHERE id=?")
           ->execute([$name,$dept,$project,$isHead,$headRole,(int)($d['active']??1),
                      $position,$d['start_date']??'',$d['end_date']??'',(int)($d['on_probation']??0),(int)($d['official_employed']??1),
                      $d['phone']??'',$d['email']??'',$d['birth_date']??'',$d['notes']??'',$photoVal,
                      $projects,$isExec,$scopeMode,$scopeProjects,$scopeDepts,$rateExtra,$rateBlock,$headManual,$d['id']]);
        // AUDIT-RENAME-AUTO (s9): if this edit RENAMED a dept/project and NO active employee remains under the old
        // "project|dept", carry its quota config + counter to the new key so the limit isn't silently orphaned/reset.
        // (Fires only on a true rename — moving one person while others stay leaves the old key populated → no-op.)
        if(($oldDept!==$dept||$oldProject!==$project)&&$oldDept!==''&&$oldProject!==''){
            $rem=$db->prepare("SELECT COUNT(*) FROM employees WHERE active=1 AND dept=? AND project=?"); $rem->execute([$oldDept,$oldProject]);
            if((int)$rem->fetchColumn()===0){
                $oldKey=$oldProject.'|'.$oldDept; $newKey=$project.'|'.$dept;
                $limits=json_decode((string)getSetting($db,'deptLimits','{}'),true); if(!is_array($limits)) $limits=[];
                if(isset($limits[$oldKey])){ if(!isset($limits[$newKey])) $limits[$newKey]=$limits[$oldKey]; unset($limits[$oldKey]); setSetting($db,'deptLimits',json_encode($limits)); }
                // dept_submissions: merge per-period counters into the new key, then drop the orphaned old rows.
                $os=$db->prepare("SELECT period,count FROM dept_submissions WHERE project=? AND dept=?"); $os->execute([$oldProject,$oldDept]);
                foreach($os->fetchAll(PDO::FETCH_ASSOC) as $sr){
                    $db->prepare("INSERT INTO dept_submissions(project,dept,period,count) VALUES(?,?,?,?) ON CONFLICT(project,dept,period) DO UPDATE SET count=count+excluded.count")
                       ->execute([$project,$dept,$sr['period'],(int)$sr['count']]);
                }
                $db->prepare("DELETE FROM dept_submissions WHERE project=? AND dept=?")->execute([$oldProject,$oldDept]);
            }
        }
    } else {
        $mo=$db->query("SELECT MAX(sort_order) FROM employees")->fetchColumn()+1;
        $db->prepare("INSERT INTO employees(id,name,dept,project,is_head,head_role,active,sort_order,position,start_date,end_date,on_probation,official_employed,phone,email,birth_date,notes,photo,projects,is_executive,scope_mode,scope_projects,scope_depts,rate_extra,rate_block,head_manual) VALUES(?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$d['id'],$name,$dept,$project,$isHead,$headRole,$mo,
                      $position,$d['start_date']??'',$d['end_date']??'',(int)($d['on_probation']??0),(int)($d['official_employed']??1),
                      $d['phone']??'',$d['email']??'',$d['birth_date']??'',$d['notes']??'',$d['photo']??'',
                      $projects,$isExec,$scopeMode,$scopeProjects,$scopeDepts,$rateExtra,$rateBlock,$headManual]);
    }
    echo json_encode(['success'=>true]);break;

case 'upload_photo':
    requireRole($db,['superadmin']);
    $id=$input['id']??'';$photo=$input['photo']??'';
    if(!$id||!$photo){echo json_encode(['error'=>'missing']);break;}
    if(strlen($photo)>400000){echo json_encode(['error'=>'too_large']);break;}
    $db->prepare("UPDATE employees SET photo=? WHERE id=?")->execute([$photo,$id]);
    echo json_encode(['success'=>true]);break;

case 'delete_employee':
    requireRole($db,['superadmin']);
    $db->prepare("UPDATE employees SET active=0 WHERE id=?")->execute([$input['id']]);
    echo json_encode(['success'=>true]);break;

case 'restore_employee':
    requireRole($db,['superadmin']);
    $db->prepare("UPDATE employees SET active=1 WHERE id=?")->execute([$input['id']]);
    echo json_encode(['success'=>true]);break;

case 'check_submission':
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $project=$input['project']??'';$dept=$input['dept']??'';
    $limits=json_decode(getSetting($db,'deptLimits','{}'),true);
    $key=$project.'|'.$dept;$limitVal=$limits[$key]??0;
    $cnt=deptParticipants($db,$project,$dept,$period);   // distinct people (audit s9), not rows
    echo json_encode(['success'=>true,'count'=>$cnt,'limit'=>$limitVal,'limitReached'=>$limitVal>0&&$cnt>=$limitVal,'period'=>$period]);break;

case 'submit_evaluation':
    // §9.5 pause: a paused survey now FULLY freezes intake server-side (previously the UI only hid the "Start"
    // button while this endpoint kept accepting votes). Applies to EVERY path — anonymous, code-login and head.
    if(getSetting($db,'isSurveyActive','1')==='0'){ echo json_encode(['error'=>'survey_paused']); exit(); }
    $required=['id','from','fromDept','fromProject','evaluatorRole','to','toDept','scores'];
    foreach($required as $f) if(empty($input[$f])){echo json_encode(['error'=>"Missing: $f"]);exit();}
    $from=$input['from']; $role=$input['evaluatorRole'];
    $fromDept=$input['fromDept']; $fromProject=$input['fromProject'];
    $evalLevel='';   // M1: exec|linear|'' — set server-side from the manager's is_executive flag
    // F0-5: a manager's identity/role comes from the server session, never the client
    $sess=currentSession($db);
    if($sess && $sess['role']==='manager' && !empty($sess['employee_id'])){
        $from=$sess['employee_id'];
        $me=$db->prepare("SELECT dept,project,projects,is_head,is_executive,active FROM employees WHERE id=?"); $me->execute([$from]); $mr=$me->fetch(PDO::FETCH_ASSOC);
        // Constructor: a submitter deactivated mid-session (active=0) may no longer vote — their still-valid 12h
        // token would otherwise keep casting. Reject rather than record an orphan vote from someone off the roster.
        if(!$mr || (int)($mr['active']??0)!==1){ echo json_encode(['error'=>'inactive']); exit(); }
        // Final-vote authority follows the CURRENT is_head flag, not a stale 12h session. If the super-admin
        // demoted this head mid-session (constructor: is_head 1→0), their votes drop to Peer rather than
        // silently staying authoritative Final. (No symmetric auto-upgrade: a promoted employee must re-login
        // via head-login to gain Final authority — we never grant it from an existing employee session.)
        if((int)($mr['is_head']??0)===1){
            $role='manager'; $fromDept=$mr['dept']; $fromProject=$mr['project']; $evalLevel=((int)($mr['is_executive']??0)===1)?'exec':'linear';
        } else {
            $role='employee'; if($mr){ $fromDept=$mr['dept']; $fromProject=$mr['project']; }
        }
    } else if($sess && $sess['role']==='employee' && !empty($sess['employee_id'])){
        // Code-login: identity comes from the session, NOT the client (no spoofing another id). Evaluations stay
        // Peer and are anonymized on read-out (F0-4).
        $from=$sess['employee_id']; $role='employee';
        // Audit s12 (#9/#15 — verification): EVERY code session (role 'employee' — ac_* OR a legacy real-employee code)
        // must STILL hold an ACTIVE code for the CURRENT period. Deactivation (impersonation report) or a period reset
        // freezes voting for BOTH paths, not just the anonymous pool. (role 'employee' only ever comes from login_with_code.)
        $curPeriod=getSetting($db,'currentPeriod',date('Y-m'));
        $ac=$db->prepare("SELECT project,dept FROM access_codes WHERE employee_id=? AND active=1 AND period=?"); $ac->execute([$from,$curPeriod]);
        $acr=$ac->fetch(PDO::FETCH_ASSOC);
        if(!$acr){ echo json_encode(['error'=>'inactive']); exit(); }
        // Timer-from-claim (HRD s13): the code's survey window is `win` minutes from issuance — once elapsed the code
        // is dead, so new votes are refused too (mirror of login/update). Heads (manager branch) never reach here.
        $cwin=(int)jclampNum(getSetting($db,'codeEditWindowMin','120'),0,1440,120);
        $cdl=codeClaimDeadline($db,$from,$curPeriod,$cwin);
        if($cwin>0 && $cdl!==null && time()>=$cdl){ echo json_encode(['error'=>'code_expired']); exit(); }
        $me=$db->prepare("SELECT dept,project,projects,active FROM employees WHERE id=?"); $me->execute([$from]); $mr=$me->fetch(PDO::FETCH_ASSOC);
        if($mr){
            // legacy identity-bound code → a real employee row (deactivated mid-session → no vote)
            if((int)($mr['active']??0)!==1){ echo json_encode(['error'=>'inactive']); exit(); }
            $fromDept=$mr['dept']; $fromProject=$mr['project'];
        } else {
            // s11 anonymous dept-pool code: the holder (ac_*) maps to NO person → dept/project from the code binding.
            $fromDept=$acr['dept']; $fromProject=$acr['project']; $mr=null;
        }
    } else {
        // No session → caller may NOT submit an authoritative Manager/Final evaluation.
        // Force peer role regardless of what the client claims (anonymous employee path).
        // When codeLoginEnabled is ON, EVERY respondent is logged in (code or head password), so an anonymous
        // submit must be rejected — otherwise one person could vote once anonymously (client-chosen id) AND once
        // by code (server id), and UNIQUE(eval_from,eval_to,period) would miss the duplicate (audit, HIGH).
        if(codeCfg($db)['enabled']){ echo json_encode(['error'=>'login_required']); exit(); }
        $role='employee';
    }
    if($from===$input['to']){echo json_encode(['error'=>'self_rate']);exit();}              // cannot rate yourself
    $tt=$db->prepare("SELECT id,project,projects,is_head FROM employees WHERE id=? AND active=1"); $tt->execute([$input['to']]);
    $ttr=$tt->fetch(PDO::FETCH_ASSOC);
    if(!$ttr){echo tamperJson('invalid_target');exit();}                                       // target must be a real active employee (UI never offers a non-existent one)
    // audit s9 (HIGH): enforce the voting-rights policy SERVER-SIDE — the allowEmpRateHead/allowHeadRateEmp toggles
    // were UI-only, so a code session could rate a head via DevTools despite the toggle. $role is already server-derived.
    $targetIsHead=(int)($ttr['is_head']??0)===1; $iAmHead=($role==='manager');
    if(!$iAmHead && $targetIsHead && getSetting($db,'allowEmpRateHead','0')!=='1'){ echo tamperJson('rating_not_allowed'); exit(); }
    if($iAmHead && !$targetIsHead && getSetting($db,'allowHeadRateEmp','1')==='0'){ echo tamperJson('rating_not_allowed'); exit(); }
    // s17 §2 backstop (HIGH): the per-employee override is a real permission boundary, not just a UI hint — a target on
    // the evaluator's rate_block list is hidden in the survey AND unvotable here, so a DevTools/curl submit can't bypass
    // it. Keyed on the server-derived $from. Anonymous ac_* holders aren't in the roster → no overrides (returns []).
    $rbRow=$db->prepare("SELECT rate_block FROM employees WHERE id=?"); $rbRow->execute([$from]);
    if(in_array((string)$input['to'],jdecodeArr($rbRow->fetchColumn()),true)){ echo tamperJson('rating_not_allowed'); exit(); }
    // HRD R2 skip backstops (multiproject-aware, mirrors frontend). For a code/head session $mr holds the evaluator
    // row; on the anonymous path only the client-claimed $fromProject is known (best-effort, codes-OFF only).
    $gNoSkip=getSetting($db,'globalNoSkip','0')==='1';                                        // Global↔Global skip forbidden
    // s17 §1: globalRatedByAll now forbids skipping a Global target ONLY for a Global evaluator (within Global, no skip).
    // A NON-Global rater who sees a Global person in their "Highly Recommended" block CAN skip them ("Не могу оценить").
    $gRatedAll=getSetting($db,'globalRatedByAll','0')==='1';
    $fromInGlobal = (isset($mr)&&$mr) ? rowInGlobal($mr) : ($fromProject==='Global');
    $toInGlobal   = rowInGlobal($ttr);
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $chk=$db->prepare("SELECT id FROM evaluations WHERE eval_from=? AND eval_to=? AND period=?");
    $chk->execute([$from,$input['to'],$period]);
    if($chk->fetch()){echo json_encode(['error'=>'already_submitted']);exit();}
    // Dept submission limit enforced SERVER-SIDE. audit s9: counts distinct PARTICIPANTS (people), not rows, and a
    // person who already started is ALWAYS allowed to finish their own set — only a NEW (limit+1-th) person from a
    // full dept is blocked. deptLimits["project|dept"] = max number of evaluators from that project+dept this period.
    $limits=json_decode((string)getSetting($db,'deptLimits','{}'),true); if(!is_array($limits)) $limits=[];
    $limVal=(int)($limits[$fromProject.'|'.$fromDept]??0);
    if($limVal>0 && !hasParticipated($db,$from,$fromProject,$fromDept,$period)
        && deptParticipants($db,$fromProject,$fromDept,$period)>=$limVal){ echo json_encode(['error'=>'limit_reached']);exit(); }
    // M3: moderation status is computed SERVER-SIDE from rulesConfig — a client cannot pre-approve a high score
    // to skip moderation, nor force a low score into the queue. History stays immutable once written.
    $rules=getRules($db);
    $scores=is_array($input['scores'])?$input['scores']:[];
    // Audit s12 (#6 tamper): only the four real value keys may be stored — drop any injected extras so a client can't
    // smuggle arbitrary keys into the scores JSON.
    $scores=array_intersect_key($scores,['commitment'=>1,'communication'=>1,'expertise'=>1,'personality'=>1]);
    foreach($scores as $k=>$sv){
        if(!is_array($sv)) continue;
        $sk=!empty($sv['skipped']);
        $sc=isset($sv['score'])?(int)$sv['score']:0;
        // Audit s12 (#6): bound-check the score to 1–10 (0 = unrated/skip). The UI offers only 1–10 but the server is the
        // only guard — a raw DevTools/curl submit of score:-1000 or 9999 would otherwise poison DCS. Reject + write the
        // validated int back so the stored value can never sit outside range.
        // s13 audit #7/#8: a NON-skipped value MUST be a real 1–10. The old `$sc!==0` exemption let a tampered
        // score:0,skipped:false through — the dashboard folded it in as a literal 0 (DCS poisoning) and it was also a
        // covert skip that dodged the globalNoSkip/globalRatedByAll backstops. The UI never sends it (okVal requires a
        // score). 0 is only valid WITH skipped:true.
        if(!$sk && ($sc<1||$sc>10)){ echo tamperJson('bad_score',['key'=>$k]);exit(); }
        $scores[$k]['score']=$sk?0:$sc;
        // HRD R2 backstop: when globalNoSkip is ON a Global member cannot skip a Global colleague (client hides skip
        // too, but the API is the only real guard). Keyed on server-derived $fromProject + DB target project.
        if($sk && (($gNoSkip && $fromInGlobal && $toInGlobal) || ($gRatedAll && $fromInGlobal && $toInGlobal))){ echo tamperJson('skip_not_allowed',['key'=>$k]);exit(); }
        $scores[$k]['status']=(!$sk && $sc>=$rules['moderationThreshold']) ? 'pending' : 'approved';
        // Server-side comment-required (mirror frontend caseRequired): a written justification (>5 chars) is
        // mandatory at score>=caseHighReq (HRD R2: 8) or <=caseLow. Otherwise the API/edit-window could store a flag with no reason.
        if(!$sk && $sc>0){
            $needTxt=($rules['caseRequiredHigh'] && $sc>=$rules['caseHighReq']) || ($rules['caseRequiredLow'] && $sc<=$rules['caseLow']);
            if($needTxt && mb_strlen(trim((string)($sv['text']??'')))<=5){ echo json_encode(['error'=>'comment_required','key'=>$k]);exit(); }
        }
    }
    // s13 audit #2: the row id is generated SERVER-SIDE as an opaque token (the client id is ignored). A client id
    // embedded Date.now(), and a batch's millisecond run let an admin re-cluster one voter's rows into a ballot via
    // the CSV/JSON id field. Opaque + server-controlled closes that and removes client control of the PK.
    $evId='ev_'.bin2hex(random_bytes(9));
    try{
        $db->prepare("INSERT INTO evaluations(id,eval_from,from_dept,from_project,evaluator_role,eval_to,to_dept,scores,period,evaluator_level) VALUES(?,?,?,?,?,?,?,?,?,?)")
           ->execute([$evId,$from,$fromDept,$fromProject,$role,$input['to'],$input['toDept'],json_encode($scores,JSON_UNESCAPED_UNICODE),$period,$evalLevel]);
    }catch(Exception $e){ echo json_encode(['error'=>'already_submitted']);exit(); }       // UNIQUE(eval_from,eval_to,period) race
    $db->prepare("INSERT INTO dept_submissions(project,dept,period,count) VALUES(?,?,?,1) ON CONFLICT(project,dept,period) DO UPDATE SET count=count+1")
       ->execute([$fromProject,$fromDept,$period]);
    echo json_encode(['success'=>true]);break;

case 'get_evaluations':
    // F0-4 + audit s9 (CRITICAL leak fix): real eval_from is superadmin-only. Non-admin/non-superadmin sessions
    // (code-employee / head-manager) now receive ONLY THEIR OWN rows — returning the full (even pseudonymized)
    // corpus let ANY logged-in user read everyone's scores+comments in DevTools AND fingerprint a head by the SET
    // of targets they rated. The peer survey only needs the caller's own rows (doneIds); full analytics stay
    // admin/superadmin-only. Anonymous gets nothing.
    $sess=currentSession($db);
    $role=$sess['role']??null;
    if($role===null){ echo json_encode(['success'=>true,'data'=>[]]); break; }
    $selfId=$sess['employee_id']??null;
    $isAnalyst=($role==='admin'||$role==='superadmin');   // only these consume the full corpus + exec/linear analytics (M2)
    if($isAnalyst){
        $rows=$db->query("SELECT * FROM evaluations ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if($selfId===null){ echo json_encode(['success'=>true,'data'=>[]]); break; }   // anon employee (codes OFF) → nothing
        $st=$db->prepare("SELECT * FROM evaluations WHERE eval_from=? ORDER BY created_at DESC"); $st->execute([$selfId]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);                                          // own submissions only
    }
    // Audit s12 (#1/#2): distinct-voter COUNTS are computed here, from the RAW (real) eval_from, BEFORE the per-row
    // pseudonymization below. This is what lets us salt the pseudonym per-row (so an admin can't cluster a ballot)
    // while the dashboard's "N проголосовало" / KPI counters stay correct. Mirrors the client's old liveEvals math:
    // eval_to must be active (ANALYTICS-1); mgr/peer voters need ≥1 non-skipped value; uniq is over all live rows.
    $voterStats=null;
    if($isAnalyst){
        $activeSet=array_flip($db->query("SELECT id FROM employees WHERE active=1")->fetchAll(PDO::FETCH_COLUMN));
        $mgr=[];$peer=[];$uni=[];
        foreach($rows as $rr){
            if(!isset($activeSet[$rr['eval_to']])) continue;
            $ef=$rr['eval_from']; $uni[$ef]=1;
            $sc=json_decode($rr['scores'],true); $hasVal=false;
            if(is_array($sc)) foreach(['commitment','communication','expertise','personality'] as $vk){ if(isset($sc[$vk])&&empty($sc[$vk]['skipped'])){ $hasVal=true; break; } }
            if($hasVal){ if(($rr['evaluator_role']??'')==='manager') $mgr[$ef]=1; else $peer[$ef]=1; }
        }
        $voterStats=['mgrVoters'=>count($mgr),'peerVoters'=>count($peer),'uniq'=>count($uni)];
    }
    foreach($rows as &$r){
        $r['scores']=json_decode($r['scores'],true);
        // s13 audit #1: strip the submission timestamp — at 1-second granularity it re-clusters a voter's whole batch
        // into one ballot (defeating the per-row pseudonym). Analytics never use it; only the logged deanon CSV keeps it.
        $r['created_at']=null;
        // Anonymity (s11 — HRD-reported bug): the bulk corpus NEVER carries a real author id, not even for
        // superadmin. De-anonymization is a DELIBERATE, logged-only action (reveal_author / export_csv?deanon=1,
        // both → reveal_log). This keeps the "confidential + override-under-audit" promise even against a
        // superadmin's DevTools — the server is the only guard. A caller still sees their OWN row un-pseudonymized
        // (it's themselves, no leak), so the code-employee edit-window keeps working.
        if(!($selfId!==null && $r['eval_from']===$selfId)){
            // s12 (#1/#2): per-ROW pseudonym (salted with the row id) — non-linkable into a ballot, unlike the old
            // stable-per-author token. Counts moved to voterStats above.
            $r['eval_from']=anonPseudo($db,$r['eval_from'],$r['period'],$r['id']);
            // Author's dept+project alone identifies singleton heads / tiny teams → strip for everyone too.
            $r['from_dept']=''; $r['from_project']='';
        }
        // Audit fix (anonymity): exec/linear status narrows identity → only admin/superadmin (analytics) may see it.
        if(!$isAnalyst) $r['evaluator_level']='';
    }
    unset($r);
    echo json_encode(['success'=>true,'data'=>$rows,'voterStats'=>$voterStats]);break;

case 'reveal_author':
    // F0-4 / R2-CODES: only superadmin can de-anonymize a specific evaluation's author. This is the deliberate
    // "override" channel under the confidential model — every reveal is written to reveal_log (accountability
    // of the accountability), so de-anonymization can itself be audited.
    $rs=requireRole($db,['superadmin']);
    $row=$db->query("SELECT eval_from,evaluator_role,period FROM evaluations WHERE id=".$db->quote((string)($input['evalId']??'')))->fetch(PDO::FETCH_ASSOC);
    if(!$row){echo json_encode(['error'=>'not found']);break;}
    $e=$db->prepare("SELECT name,dept,project FROM employees WHERE id=?"); $e->execute([$row['eval_from']]); $emp=$e->fetch(PDO::FETCH_ASSOC);
    $db->prepare("INSERT INTO reveal_log(action,eval_id,eval_from,period,by_role,by_ip) VALUES('reveal_author',?,?,?,?,?)")
       ->execute([(string)($input['evalId']??''),$row['eval_from'],$row['period'],$rs['role']??'superadmin',clientIp()]);
    echo json_encode(['success'=>true,'eval_from'=>$row['eval_from'],'evaluator_role'=>$row['evaluator_role'],'name'=>$emp['name']??null,'dept'=>$emp['dept']??null]);break;

case 'get_reveal_log':
    // R2-CODES: super-admin views the audit trail of de-anonymizations / code rotations.
    requireRole($db,['superadmin']);
    $rows=$db->query("SELECT id,action,eval_id,eval_from,period,by_role,by_ip,at FROM reveal_log ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'moderate':
    // audit s9: validate input + only a PENDING value may be decided (was: any key/any action flippable → silent
    // rejected→rejectScore corruption of DCS/CSV with no trail). Decision is logged to reveal_log for accountability.
    $rs=requireRole($db,['admin']);
    $key=(string)($input['key']??''); $act=(string)($input['action']??'');
    if(!in_array($key,['commitment','communication','expertise','personality'],true)){echo json_encode(['error'=>'bad_key']);exit();}
    if($act!=='approve'&&$act!=='reject'){echo json_encode(['error'=>'bad_action']);exit();}
    $evalId=(string)($input['evalId']??'');
    $row=$db->query("SELECT scores,period FROM evaluations WHERE id=".$db->quote($evalId))->fetch(PDO::FETCH_ASSOC);
    if(!$row){echo json_encode(['error'=>'not found']);exit();}
    $scores=json_decode($row['scores'],true);
    if(!isset($scores[$key])){echo json_encode(['error'=>'key_not_present']);exit();}
    if(($scores[$key]['status']??'')!=='pending'){echo json_encode(['error'=>'not_pending']);exit();}  // immutable once decided
    $scores[$key]['status']=$act==='approve'?'approved':'rejected';
    $db->prepare("UPDATE evaluations SET scores=? WHERE id=?")->execute([json_encode($scores,JSON_UNESCAPED_UNICODE),$evalId]);
    $db->prepare("INSERT INTO reveal_log(action,eval_id,period,by_role,by_ip) VALUES(?,?,?,?,?)")
       ->execute(['moderate_'.$act.':'.$key,$evalId,(string)($row['period']??''),$rs['role']??'admin',clientIp()]);
    echo json_encode(['success'=>true]);break;

case 'edit_evaluation':
    requireRole($db,['superadmin']);
    $row=$db->query("SELECT scores FROM evaluations WHERE id=".$db->quote((string)($input['evalId']??'')))->fetch(PDO::FETCH_ASSOC);
    if(!$row){echo json_encode(['error'=>'not found']);exit();}
    $scores=json_decode($row['scores'],true);
    if(isset($input['key'])&&isset($scores[$input['key']])){
        if(isset($input['score'])) $scores[$input['key']]['score']=(int)$input['score'];
        if(isset($input['text']))  $scores[$input['key']]['text']=$input['text'];
        if(isset($input['status']))$scores[$input['key']]['status']=$input['status'];
        if(isset($input['skipped']))$scores[$input['key']]['skipped']=(bool)$input['skipped'];
    }
    $db->prepare("UPDATE evaluations SET scores=? WHERE id=?")->execute([json_encode($scores,JSON_UNESCAPED_UNICODE),$input['evalId']]);
    echo json_encode(['success'=>true]);break;

case 'delete_evaluation':
    requireRole($db,['superadmin']);
    $db->prepare("DELETE FROM evaluations WHERE id=?")->execute([$input['id']]);
    echo json_encode(['success'=>true]);break;

case 'reset_period':
    requireRole($db,['superadmin']);
    $newPeriod=$input['period']??date('Y-m',strtotime('+1 month'));
    setSetting($db,'currentPeriod',$newPeriod);
    if(!empty($input['clearEvals'])) $db->exec("DELETE FROM evaluations");
    $db->exec("DELETE FROM dept_submissions");
    // R2-CODES: codes are per-period (PK includes period) and self-generated by employees, so the new period
    // simply starts with NO codes — last period's codes don't apply, and everyone re-generates their own.
    // (Old admin mass-regeneration removed; it implied admin-known codes.)
    // Audit s12 (#15 — verification): hard-stop stale codes from the period(s) we just left — deactivate any not bound
    // to the NEW period and kill EVERY live code-holder session (role 'employee' = ac_* AND legacy real-employee codes),
    // so a still-open 12h token can't keep voting/editing under an old binding. Heads ('manager') re-login by password.
    $db->prepare("UPDATE access_codes SET active=0 WHERE period<>? AND active=1")->execute([$newPeriod]);
    $db->prepare("DELETE FROM auth_sessions WHERE role='employee'")->execute();
    $db->prepare("DELETE FROM survey_progress WHERE period<>?")->execute([$newPeriod]);   // s14 audit #4: drop stale progress of past periods
    echo json_encode(['success'=>true,'period'=>$newPeriod,'codesRegenerated'=>0]);break;

case 'reset_data':
    requireRole($db,['superadmin']);
    $db->exec("DELETE FROM evaluations");$db->exec("DELETE FROM dept_submissions");
    // FIX (re-measurement consistency): clearing the ratings must also clear the progress beacons — otherwise the
    // monitoring dashboard keeps showing people as completed/in-progress with ZERO evaluations behind them. And free
    // ALL codes (deactivate + kill code-holder sessions) so employees can self-generate a fresh code and re-take the
    // survey. reset_data is a full wipe (evaluations/progress are dropped for every period), so codes are deactivated
    // for every period too — no stale active code is left behind (mirrors the frontend localStorage cleanup).
    $db->exec("DELETE FROM survey_progress");
    $db->exec("UPDATE access_codes SET active=0 WHERE active=1");
    $db->prepare("DELETE FROM auth_sessions WHERE role='employee'")->execute();
    echo json_encode(['success'=>true]);break;

case 'get_submission_stats':
    // audit s9: report distinct PARTICIPANTS per dept (matches the "Прошедших" label + the people-based limit gate).
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $rows=$db->query("SELECT from_project AS project, from_dept AS dept, COUNT(DISTINCT eval_from) AS count FROM evaluations WHERE period=".$db->quote($period)." GROUP BY from_project,from_dept")->fetchAll(PDO::FETCH_ASSOC);
    $limits=json_decode(getSetting($db,'deptLimits','{}'),true);
    echo json_encode(['success'=>true,'data'=>$rows,'limits'=>$limits,'period'=>$period]);break;

case 'set_dept_limit':
    // AUDIT-RENAME (LOW): the limit + its dept_submissions counter are keyed by "project|dept" text. If a dept/
    // project is later RENAMED in the constructor, the old counter is orphaned (the new name starts at 0) — the
    // limit effectively resets. After any rename, re-set the limit here for the new name.
    requireRole($db,['superadmin']);
    $limits=json_decode(getSetting($db,'deptLimits','{}'),true);
    $key=($input['project']??'').'|'.($input['dept']??'');
    $limits[$key]=(int)($input['limit']??0);
    setSetting($db,'deptLimits',json_encode($limits));
    echo json_encode(['success'=>true]);break;

// Admin sessions
case 'log_session':
    // Audit fix: require a real auth session and trust the server-side role (was: unauthenticated + client-supplied
    // role → anyone could inject fake rows into the session monitor). Role now comes from the verified session.
    $ls=currentSession($db);
    if(!$ls){ http_response_code(403); echo json_encode(['error'=>'forbidden']); break; }
    $role=$ls['role'];
    // s13 audit #3: NEVER record an anonymous peer ('employee' = ac_* code) session in the monitor. Storing its IP /
    // user-agent / login-time next to the ac_* holder was an UNLOGGED de-anonymization side channel (super could line
    // up IP/device/time with that holder's ballot). The monitor is for STAFF sessions only (admin/super/head).
    if($role==='employee'){ echo json_encode(['success'=>true,'skipped'=>true]); break; }
    $id=$input['id']??uniqid('s_',true);
    $ip=clientIp();
    $ua=$_SERVER['HTTP_USER_AGENT']??'';
    // audit s9: store the AUTH token + employee_id so revoke_session can invalidate the real login (not just the monitor row).
    $db->prepare("INSERT OR REPLACE INTO admin_sessions(id,role,ip,user_agent,login_time,last_seen,active,auth_token,employee_id) VALUES(?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?,?)")
       ->execute([$id,$role,$ip,$ua,$ls['token']??'',$ls['employee_id']??'']);
    echo json_encode(['success'=>true,'sessionId'=>$id]);break;

case 'update_session':
    // Heartbeat (every ~60s) to keep the monitor row alive. audit s9: now requires a real auth session and only
    // bumps the caller's OWN row (was unauthenticated + any client id → spoofable "online" rows).
    $us=currentSession($db);
    if(!$us){ http_response_code(403); echo json_encode(['error'=>'forbidden']); break; }
    $id=$input['id']??'';
    if(!$id){echo json_encode(['error'=>'no id']);break;}
    $db->prepare("UPDATE admin_sessions SET last_seen=CURRENT_TIMESTAMP WHERE id=? AND auth_token=?")->execute([$id,$us['token']??'']);
    echo json_encode(['success'=>true]);break;

case 'get_sessions':
    requireRole($db,['superadmin']);
    // Sessions where last_seen < 5 minutes ago are considered offline (is_online=0)
    // Sessions older than 30 days are already deleted by the auto-cleanup above
    $rows=$db->query("
        SELECT
            id, role, ip, user_agent, login_time, last_seen, active,
            CASE
                WHEN active = 1 AND last_seen >= datetime('now', '-5 minutes')
                THEN 1
                ELSE 0
            END AS is_online
        FROM admin_sessions
        WHERE active = 1
        ORDER BY last_seen DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    // Cast integers
    foreach($rows as &$r){
        $r['active']=(int)$r['active'];
        $r['is_online']=(int)$r['is_online'];
    }
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'revoke_session':
    // audit s9 (CRITICAL fix): actually invalidate the AUTH token, not just flip the cosmetic monitor row — a
    // "revoked" admin previously kept full access until the 12h token expired. Mirrors rotate_code's auth kill.
    requireRole($db,['superadmin']);
    $sid=(string)($input['id']??'');
    $sr=$db->prepare("SELECT auth_token FROM admin_sessions WHERE id=?"); $sr->execute([$sid]); $atok=(string)($sr->fetchColumn()?:'');
    if($atok!=='') $db->prepare("DELETE FROM auth_sessions WHERE token=?")->execute([$atok]);   // real logout
    $db->prepare("UPDATE admin_sessions SET active=0 WHERE id=?")->execute([$sid]);
    echo json_encode(['success'=>true]);break;

case 'get_code_devices':
    // s14 §2.4 — anonymity-safe "Active Devices" for code respondents (the survey takers). Shows dept/project +
    // device (User-Agent) + login/last-seen + online flag, derived from live employee auth_sessions joined to the
    // code's dept/project binding. It NEVER exposes the holder's name (the pool is anonymous), the auth token, or the
    // code value — so a superadmin can neither hijack a respondent's session nor deanonymize a vote from this view.
    requireRole($db,['superadmin']);
    $rows=$db->query("
        SELECT a.user_agent, a.ip, a.created_at AS login_time, a.last_seen,
               COALESCE(NULLIF(c.project,''),'—') AS project, COALESCE(NULLIF(c.dept,''),'—') AS dept,
               CASE WHEN a.last_seen >= datetime('now','-5 minutes') THEN 1 ELSE 0 END AS is_online
        FROM auth_sessions a
        LEFT JOIN access_codes c ON c.employee_id=a.employee_id
        WHERE a.role='employee' AND (a.expires_at IS NULL OR a.expires_at>datetime('now'))
        ORDER BY a.last_seen DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    // s14 audit #2: never expose raw UA / IP of anonymous respondents — coarsen to a device class, drop both raw fields.
    foreach($rows as &$r){ $r['is_online']=(int)$r['is_online']; $r['device']=coarseDevice($r['user_agent']??''); unset($r['user_agent'],$r['ip']); }
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'save_progress':
    // s14 §3 — anonymity-safe progress beacon. The client reports ONLY counts (rated/total) + a finished flag; the
    // holder id and dept/project are taken SERVER-SIDE from the session (never trusted from the client), and NO names
    // or draft CONTENT are stored. Code respondents and heads (password session) are tracked; the anonymous codes-OFF
    // path (no session) is a silent no-op. Purely powers the dept-level monitoring dashboard — never per-person.
    if(getSetting($db,'progressMonitoring','1')!=='1'){ echo json_encode(['success'=>true,'skipped'=>true]); break; }
    $sess=currentSession($db);
    if(!$sess || empty($sess['employee_id'])){ echo json_encode(['success'=>true,'skipped'=>true]); break; }
    $holder=$sess['employee_id'];
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $proj=''; $dept='';
    if($sess['role']==='manager'){
        $e=$db->prepare("SELECT project,dept FROM employees WHERE id=? AND active=1"); $e->execute([$holder]); $er=$e->fetch(PDO::FETCH_ASSOC);
        if(!$er){ echo json_encode(['success'=>true,'skipped'=>true]); break; }
        $proj=$er['project']; $dept=$er['dept'];
    } else {
        $c=$db->prepare("SELECT project,dept FROM access_codes WHERE employee_id=? AND active=1 AND period=?"); $c->execute([$holder,$period]); $cr=$c->fetch(PDO::FETCH_ASSOC);
        if(!$cr){ echo json_encode(['success'=>true,'skipped'=>true]); break; }   // inactive/expired/foreign code → don't record
        $proj=$cr['project']; $dept=$cr['dept'];
    }
    // s14 audit #5: the client cannot inflate the dashboard — `rated` is the REAL submitted count (not the client).
    // s16 §6.2: this is now a PASSIVE progress beacon only — it records 'in_progress' and never sets 'completed'.
    // "Completed" is an explicit, deliberate action (the prominent «Завершить» button → finish_survey), which also
    // closes the code. So the passive beacon can no longer prematurely mark a dept completed when all mandatory votes
    // happen to be in. It still never DOWNGRADES an already-completed record.
    $sc=$db->prepare("SELECT COUNT(*) FROM evaluations WHERE eval_from=? AND period=?"); $sc->execute([$holder,$period]); $submitted=(int)$sc->fetchColumn();
    $rated=$submitted;
    $total=max($submitted,(int)($input['total']??0));
    $exq=$db->prepare("SELECT status FROM survey_progress WHERE holder=? AND period=?"); $exq->execute([$holder,$period]); $exStatus=(string)($exq->fetchColumn()?:'');
    $status=($exStatus==='completed')?'completed':'in_progress';   // never set completed here; never downgrade one
    $db->prepare("INSERT OR REPLACE INTO survey_progress(holder,period,project,dept,rated,total,status,updated_at) VALUES(?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
       ->execute([$holder,$period,$proj,$dept,$rated,$total,$status]);
    echo json_encode(['success'=>true]);break;

case 'finish_survey':
    // s16 §6.2 — the explicit, final «Завершить» action. Marks the holder COMPLETED and (for a code session) DEACTIVATES
    // the code so the session is closed: no re-login, no further edits — and the dept slot is now consumed (the
    // completed-based pool cap in generate_my_code counts exactly these). Requires ≥1 real submitted evaluation
    // (anti-inflation, mirrors s14 #5). Heads (manager session) just get marked completed — they hold no code.
    $sess=currentSession($db);
    if(!$sess || empty($sess['employee_id'])){ http_response_code(403); echo json_encode(['error'=>'forbidden']); break; }
    $holder=$sess['employee_id']; $period=getSetting($db,'currentPeriod',date('Y-m'));
    $sc=$db->prepare("SELECT COUNT(*) FROM evaluations WHERE eval_from=? AND period=?"); $sc->execute([$holder,$period]); $submitted=(int)$sc->fetchColumn();
    if($submitted<1){ echo json_encode(['error'=>'no_votes']); break; }   // cannot finish an empty survey
    $proj=''; $dept='';
    if($sess['role']==='manager'){
        $e=$db->prepare("SELECT project,dept FROM employees WHERE id=? AND active=1"); $e->execute([$holder]); $er=$e->fetch(PDO::FETCH_ASSOC);
        if($er){ $proj=$er['project']; $dept=$er['dept']; }
    } else {
        // code session: resolve the code's binding WITHOUT the active filter (s16 audit fix #1 — a 2nd/retried finish
        // runs after the code is already active=0, and an active-only read returned nothing → the dept was lost and the
        // completed row got blanked). Reading the latest binding regardless of active keeps re-finish dept-correct.
        $c=$db->prepare("SELECT project,dept FROM access_codes WHERE employee_id=? AND period=? ORDER BY created_at DESC LIMIT 1"); $c->execute([$holder,$period]); $cr=$c->fetch(PDO::FETCH_ASSOC);
        if($cr){ $proj=$cr['project']; $dept=$cr['dept']; }
        $db->prepare("UPDATE access_codes SET active=0 WHERE employee_id=? AND period=?")->execute([$holder,$period]);   // code now invalid (slot consumed)
    }
    if(getSetting($db,'progressMonitoring','1')==='1'){
        // Idempotent finalize: never overwrite an existing COMPLETED row with empty/worse dept info (s16 audit fix #1).
        $ex=$db->prepare("SELECT project,dept,status FROM survey_progress WHERE holder=? AND period=?"); $ex->execute([$holder,$period]); $exr=$ex->fetch(PDO::FETCH_ASSOC);
        if($exr && ($exr['status']??'')==='completed'){ if($proj==='') $proj=$exr['project']; if($dept==='') $dept=$exr['dept']; }
        $total=max($submitted,(int)($input['total']??0));
        $db->prepare("INSERT OR REPLACE INTO survey_progress(holder,period,project,dept,rated,total,status,updated_at) VALUES(?,?,?,?,?,?,'completed',CURRENT_TIMESTAMP)")
           ->execute([$holder,$period,$proj,$dept,$submitted,$total]);
    }
    // Hybrid device-lock (Req): mark THIS browser as "completed this period" so generate_my_code refuses a fresh
    // self-issued code from the same browser — closes the casual "finish → code deactivates → same browser
    // re-generates → vote again" repeat. The cookie holds only a flag, never an identity, so anonymity is preserved.
    // A genuinely different person on a shared device opens incognito (the accepted residual bypass — same tradeoff
    // as the whole anonymous pool; cross-browser/incognito cannot be closed without binding codes to a person).
    setcookie('ccs_done_'.$period,'1',['expires'=>time()+7776000,'path'=>'/','domain'=>lockCookieDomain(),'httponly'=>true,'secure'=>cookieSecure(),'samesite'=>'Lax']);   // s17 §3: same sub-origin sharing as ccs_gen_
    echo json_encode(['success'=>true]);break;

case 'get_progress':
    // s14 §3 — anonymity-safe monitoring aggregates. Superadmin/admin → all depts; a manager → ONLY the (project,dept)
    // keys in their scope (§3.2 "strictly own dept/subordinates"). Returns ONLY counts per project|dept (headcount /
    // claimed codes / completed / in_progress / not_started). No holder ids, no names — one cannot tell WHO did/didn't vote.
    $sess=requireRole($db,['admin','manager']);   // + superadmin implicitly
    if(getSetting($db,'progressMonitoring','1')!=='1'){ echo json_encode(['success'=>true,'data'=>[],'disabled'=>true]); break; }
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $allow=null;   // null = all depts
    if($sess['role']==='manager' && !empty($sess['employee_id'])){
        $h=$db->prepare("SELECT dept,project,projects,head_role,scope_mode,scope_projects,scope_depts FROM employees WHERE id=?"); $h->execute([$sess['employee_id']]); $hr=$h->fetch(PDO::FETCH_ASSOC);
        $allow=headScopeKeys($db,$hr);   // s15: head_role now selected so the legacy-role branch in headScopeKeys can fire
    }
    $hc=[]; foreach($db->query("SELECT project,dept,COUNT(*) c FROM employees WHERE active=1 AND is_head=0 GROUP BY project,dept") as $r){ $hc[$r['project'].'|'.$r['dept']]=(int)$r['c']; }
    $cl=[]; $cs=$db->prepare("SELECT project,dept,COUNT(*) c FROM access_codes WHERE active=1 AND period=? GROUP BY project,dept"); $cs->execute([$period]); foreach($cs as $r){ $cl[$r['project'].'|'.$r['dept']]=(int)$r['c']; }
    // s15 fix: the completed/in_progress numerator MUST count the SAME population as headcount (non-heads only).
    // save_progress also writes a row for every head (role 'manager') keyed to the head's OWN dept; counting those
    // inflated mixed depts past headcount and floored not_started to 0 → false "6/6 / 100%", masking ICs who haven't
    // voted. LEFT JOIN employees: anonymous ac_* holders aren't in the roster (e.is_head NULL → COALESCE 0 → counted);
    // only real head holders (is_head=1) are excluded. Heads are tracked separately if ever needed, never in IC bars.
    $comp=[]; $prog=[]; $ps=$db->prepare("SELECT sp.project,sp.dept,sp.status,COUNT(*) c FROM survey_progress sp LEFT JOIN employees e ON e.id=sp.holder WHERE sp.period=? AND COALESCE(e.is_head,0)=0 GROUP BY sp.project,sp.dept,sp.status"); $ps->execute([$period]);
    foreach($ps as $r){ $k=$r['project'].'|'.$r['dept']; if($r['status']==='completed') $comp[$k]=(int)$r['c']; else $prog[$k]=(int)$r['c']; }
    $keys=array_unique(array_merge(array_keys($hc),array_keys($cl),array_keys($comp),array_keys($prog)));
    $out=[];
    foreach($keys as $k){
        if($allow!==null && !in_array($k,$allow,true)) continue;
        $pp=explode('|',$k,2); $p=$pp[0]; $d=$pp[1]??'';
        if($p===''&&$d==='') continue;
        $h=$hc[$k]??0; $completed=$comp[$k]??0; $inprog=$prog[$k]??0; $claimed=$cl[$k]??0;
        $started=$completed+$inprog; $notStarted=max(0,$h-$started);
        $out[]=['project'=>$p,'dept'=>$d,'headcount'=>$h,'claimed'=>$claimed,'completed'=>$completed,'in_progress'=>$inprog,'not_started'=>$notStarted];
    }
    usort($out,function($a,$b){ return $a['project']===$b['project']?strcmp($a['dept'],$b['dept']):strcmp($a['project'],$b['project']); });
    echo json_encode(['success'=>true,'data'=>$out,'period'=>$period]);break;

case 'get_birthdays':
    requireRole($db,['admin']);
    $rows=$db->query("SELECT id,name,dept,project,birth_date FROM employees WHERE active=1 AND birth_date!='' ORDER BY substr(birth_date,6)")->fetchAll(PDO::FETCH_ASSOC);  // B-4: photo via get_photos, not here
    echo json_encode(['success'=>true,'data'=>$rows]);break;

case 'export_csv':
    // F0-7 / R2-CODES: admin gets an anonymized export; only superadmin may de-anonymize authors (?deanon=1),
    // and every bulk de-anonymization is written to reveal_log (auditable override). s14 §4.4: row-building lives in
    // ccsExportData() (shared with export_xlsx) — anonymization & formula-injection guard can't diverge between formats.
    $csvSess = requireRole($db,['admin']);
    $reveal  = (($_GET['deanon'] ?? '')==='1');
    if($reveal){ $rsx=requireRole($db,['superadmin']); $db->prepare("INSERT INTO reveal_log(action,period,by_role,by_ip) VALUES('bulk_export',?,?,?)")->execute([getSetting($db,'currentPeriod',date('Y-m')),$rsx['role']??'superadmin',clientIp()]); }
    $data = ccsExportData($db,$reveal);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="CCS_Evaluations_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));   // UTF-8 BOM — обязателен для Excel/Power BI
    fputcsv($out, $data['header'], ',', '"', '\\');
    foreach($data['rows'] as $row) fputcsv($out, $row, ',', '"', '\\');
    fclose($out);
    exit();

case 'export_xlsx':
    // s14 §4.4 — real .xlsx (Office Open XML) export. Same data/anonymization/deanon-log as CSV (shared ccsExportData);
    // dependency-free (ZipArchive + inline XML). Opens natively in Excel AND imports cleanly into Google Sheets.
    $xSess = requireRole($db,['admin']);
    $reveal = (($_GET['deanon'] ?? '')==='1');
    if($reveal){ $rsx=requireRole($db,['superadmin']); $db->prepare("INSERT INTO reveal_log(action,period,by_role,by_ip) VALUES('bulk_export_xlsx',?,?,?)")->execute([getSetting($db,'currentPeriod',date('Y-m')),$rsx['role']??'superadmin',clientIp()]); }
    if(!class_exists('ZipArchive')){ http_response_code(500); echo json_encode(['error'=>'zip_unavailable']); break; }
    $data = ccsExportData($db,$reveal);
    $bytes = ccsXlsx($data['header'],$data['rows']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="CCS_Evaluations_'.date('Y-m-d').'.xlsx"');
    header('Content-Length: '.strlen($bytes));
    echo $bytes;
    exit();

case 'login_with_code':
    // M4: anonymous endpoint (employees aren't logged in yet) → rate-limited against code brute-force.
    // Identity (id/role) comes ENTIRELY from the server; the client only proves it holds a valid code.
    $cfg=codeCfg($db);
    if(!$cfg['enabled']){ echo json_encode(['error'=>'disabled']); break; }
    // NAT-friendly: NO 429 lockout here. Employees (≈130) all log in by code through ONE office IP, so a per-IP
    // code-login lockout would freeze the whole company over a handful of mistyped codes. A wrong/expired code always
    // returns {"error":"invalid"} instead. (Abuse is structurally bounded: one active code per dept slot per period.)
    $code=normCode($input['code']??'',$cfg['caseSensitive']);
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $ok=false; $emp=null;
    if(strlen($code)>=6 && codeCharsetOk($code,$cfg)){
        $cr=$db->prepare("SELECT employee_id,project,dept FROM access_codes WHERE code=? AND period=? AND active=1"); $cr->execute([$code,$period]);
        $row=$cr->fetch(PDO::FETCH_ASSOC);
        if($row){
            if(($row['project']??'')!=='' || ($row['dept']??'')!==''){
                // s11 anonymous dept-pool code: identity IS the code (synthetic holder), bound to a project+dept only.
                // No employees join → there is no person behind a peer code. role is always 'employee' (peer).
                $emp=['id'=>$row['employee_id'],'name'=>'','dept'=>$row['dept'],'project'=>$row['project'],'is_head'=>0,'head_role'=>''];
                $ok=true;
            } else {
                // Legacy identity-bound code (pre-v5). Heads NEVER log in by code — they use the head password
                // (Final-vote authority lives there); a leftover/rotated head code must NOT grant manager access.
                $er=$db->prepare("SELECT id,name,dept,project,is_head,head_role FROM employees WHERE id=? AND active=1 AND is_head=0"); $er->execute([$row['employee_id']]); $emp=$er->fetch(PDO::FETCH_ASSOC);
                if($emp) $ok=true;
            }
        }
    }
    if($ok){
        // Timer-from-claim (HRD s13): the code is valid only `win` minutes from when it was issued. Past that it's
        // dead — no re-entry, no re-survey (HRD: "повторный опрос невозможен"). Block login on an expired code.
        $win=(int)jclampNum(getSetting($db,'codeEditWindowMin','120'),0,1440,120);
        $deadline=codeClaimDeadline($db,$emp['id'],$period,$win);
        if($win>0 && $deadline!==null && time()>=$deadline){ echo json_encode(['error'=>'code_expired']); break; }
        $role='employee';                                                        // code login is always a peer (heads excluded above)
        // s14 §2.2 — "one code = one device". A code's holder id (ac_*) is unique per code, so a holder having a live
        // auth_session means the code is already logged in somewhere. codeDeviceMode: 'kick' (default) = newest login
        // wins → drop the old device's token (it preserves the cross-device edit window — you just can't be on two at
        // once); 'block' = first device wins → 2nd device gets code_in_use. Only applies to code logins, never to
        // password (admin/super/head) sessions. Honest condition (not tamper).
        if(getSetting($db,'codeSingleSession','1')==='1'){
            $nowStr=date('Y-m-d H:i:s');
            // s14 audit #7/#8: 'block' only refuses if the OTHER device is ACTIVELY in use (last_seen within 10 min).
            // An idle/abandoned session is reclaimable — otherwise a user who cleared cookies or switched devices would
            // be locked out for up to the 12h token TTL, and the cross-device edit window would break. 'kick' is unaffected.
            $live=$db->prepare("SELECT COUNT(*) FROM auth_sessions WHERE employee_id=? AND (expires_at IS NULL OR expires_at>?) AND last_seen>?");
            $live->execute([$emp['id'],$nowStr,date('Y-m-d H:i:s',time()-600)]);
            $activeElsewhere=((int)$live->fetchColumn())>0;
            if(getSetting($db,'codeDeviceMode','kick')==='block' && $activeElsewhere){ echo json_encode(['error'=>'code_in_use']); break; }
            $db->prepare("DELETE FROM auth_sessions WHERE employee_id=?")->execute([$emp['id']]);   // kick (or reclaim idle in block mode): newest wins
        }
        issueToken($db,$role,$emp['id']);
        echo json_encode(['success'=>true,'role'=>$role,'employee_id'=>$emp['id'],'name'=>$emp['name'],'dept'=>$emp['dept'],'project'=>$emp['project'],'is_head'=>0,'head_role'=>$emp['head_role'],'deadline'=>$deadline,'secondsLeft'=>($deadline!==null?max(0,$deadline-time()):null),'windowMin'=>$win]);
    } else {
        echo json_encode(['error'=>'invalid']);   // wrong/expired code → honest error, never a per-IP lockout (see above)
    }
    break;

case 'generate_my_code':
    // R2-CODES: a not-yet-logged-in employee self-issues their OWN code for the current period (admin no
    // longer hands out codes). Identity = employee_id, sealed in access_codes — confidential, only super-admin
    // can later reveal the author (reveal_author, logged). First-come-lock via UNIQUE(employee_id,period):
    // a name that already has an active code is NOT re-issued (so a 2nd person can't grab it; the real owner
    // reuses the code saved in their browser, or HR rotates it). This also caps active codes per dept at the
    // headcount automatically (≤1 active code per employee).
    $cfg=codeCfg($db);
    if(!$cfg['enabled']){ echo json_encode(['error'=>'disabled']); break; }
    // NAT-friendly: code self-issue is NOT rate-limited / never returns HTTP 429. The whole office (≈130 people on one
    // public IP) generates codes around the same time, so a per-IP gen cap would lock out the Nth colleague to click
    // «получить код». Abuse is bounded STRUCTURALLY instead: exactly ONE active code per (employee/dept slot) per period
    // (UNIQUE index) + the per-device cookie lock below, so extra requests can't manufacture extra ballots.
    // s11 (anonymous dept-pool): bind a code to PROJECT+DEPT, never to a person. No name is taken, so a peer
    // vote's author can never be established — full anonymity by design (HRD decision).
    $proj=trim((string)($input['project']??'')); $dept=trim((string)($input['dept']??''));
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    // DEVICE-LOCK (HRD s13 multi-code fix): one self-generated code per browser/device per period. If this device
    // already holds a code (cookie) that is STILL active, refuse a 2nd generation — that closes the casual "I clicked
    // «получить» again and got a fresh anonymous holder → can re-vote" hole. Anonymity is preserved (the cookie
    // stores the random code, never a person). NOT a hard wall: incognito / another device bypass it (inherent to a
    // fully anonymous pool — HRD accepted). When HR deletes a submission the code is deactivated, so this check
    // passes again and the SAME device may re-generate — the lock self-releases when the code goes inactive.
    $genCk=(string)($_COOKIE['ccs_gen_'.$period]??'');
    if($genCk!==''){
        // s17 §3 (double-code fix): this device already holds an ACTIVE code → RETURN THE SAME code (idempotent) instead
        // of issuing a second one or erroring. Any context that shares this httponly cookie — a 2nd tab (same-origin
        // localStorage/cookies are shared) OR a 2nd URL under the same registrable domain (cookie Domain broadened
        // below) — therefore gets ONE code, never two. The holder presenting the cookie IS the device that generated it,
        // so returning its own code leaks nothing.
        $still=$db->prepare("SELECT project,dept FROM access_codes WHERE code=? AND period=? AND active=1"); $still->execute([$genCk,$period]);
        if($srow=$still->fetch(PDO::FETCH_ASSOC)){ echo json_encode(['success'=>true,'code'=>$genCk,'project'=>$srow['project'],'dept'=>$srow['dept'],'reused'=>true]); break; }
    }
    // Hybrid device-lock (Req): a browser that already COMPLETED a survey this period may not self-issue another code,
    // even though its previous code is now inactive (the active-code check above no longer fires). Closes the casual
    // "finish → re-generate in the same browser → re-vote" repeat that would steal a real colleague's dept slot.
    // Cross-browser / incognito / another device still bypass — anonymity-by-design residual the HRD accepted.
    if(($_COOKIE['ccs_done_'.$period]??'')==='1'){ echo json_encode(['error'=>'already_completed']); break; }
    // Validate the dept exists & get its headcount (active, non-head). Unknown project/dept OR empty dept →
    // ONE generic 'code_unavailable' (no oracle on which depts exist).
    $hc=$db->prepare("SELECT COUNT(*) FROM employees WHERE active=1 AND is_head=0 AND project=? AND dept=?");
    $hc->execute([$proj,$dept]); $headcount=(int)$hc->fetchColumn();
    if($headcount<=0){ echo json_encode(['error'=>'code_unavailable']); break; }
    // s16 §2 (HRD): a slot is consumed only by a COMPLETED survey, not by a draft/in-progress code. Codes may be
    // generated freely while the dept isn't yet fully covered — so an abandoned/unfinished code never blocks a real
    // participant from getting one (HRD: "пока черновик — ничего страшного"). The cap therefore counts FINISHED
    // holders (survey_progress.status='completed'), not issued codes. Once `cap` people have FINISHED, the dept is
    // covered → pool_full. (Cross-device draft drain is the accepted residual — HRD chose this; the per-device cookie
    // lock above still blocks casual same-browser re-gen.) If progressMonitoring is OFF there is no completion signal,
    // so we fall back to the legacy issued-codes cap to keep a hard ceiling.
    // s14 §2.4 — superadmin may set an explicit per-dept code cap (codeLimits['project|dept']); positive value
    // overrides the headcount default. absent/0 → cap = headcount.
    $cap=$headcount;
    $cl=json_decode(getSetting($db,'codeLimits','{}'),true);
    if(is_array($cl)){ $ck=$proj.'|'.$dept; if(isset($cl[$ck]) && (int)$cl[$ck]>0) $cap=(int)$cl[$ck]; }
    if(getSetting($db,'progressMonitoring','1')==='1'){
        // count FINISHED holders for this dept this period (anonymous ac_* + any legacy non-head code holder)
        $fc=$db->prepare("SELECT COUNT(*) FROM survey_progress sp LEFT JOIN employees e ON e.id=sp.holder
                          WHERE sp.period=? AND sp.project=? AND sp.dept=? AND sp.status='completed' AND COALESCE(e.is_head,0)=0");
        $fc->execute([$period,$proj,$dept]); $consumed=(int)$fc->fetchColumn();
    } else {
        $iss=$db->prepare("SELECT COUNT(*) FROM access_codes WHERE active=1 AND period=? AND project=? AND dept=?");
        $iss->execute([$period,$proj,$dept]); $consumed=(int)$iss->fetchColumn();
    }
    if($consumed>=$cap){ echo json_encode(['error'=>'pool_full']); break; }
    $c=genCode($db,$cfg,$period); if(!$c){ echo json_encode(['error'=>'gen_failed']); break; }
    $holder='ac_'.bin2hex(random_bytes(8));   // synthetic anonymous holder id — unique per code, maps to NO person
    try{
        $db->prepare("INSERT INTO access_codes(code,employee_id,period,active,edited_by_admin,created_at,project,dept) VALUES(?,?,?,1,0,CURRENT_TIMESTAMP,?,?)")->execute([$c,$holder,$period,$proj,$dept]);
    }catch(Exception $e){ echo json_encode(['error'=>'code_unavailable']); break; }   // PK(code,period) race → retry-worthy, treat as unavailable
    // Device-lock marker: remember THIS code on this device so a 2nd generate reuses it while it stays active (90d).
    // s17 §3: Domain broadened to the registrable parent so the lock survives across the site's sub-origins.
    setcookie('ccs_gen_'.$period,$c,['expires'=>time()+7776000,'path'=>'/','domain'=>lockCookieDomain(),'httponly'=>true,'secure'=>cookieSecure(),'samesite'=>'Lax']);
    echo json_encode(['success'=>true,'code'=>$c,'project'=>$proj,'dept'=>$dept]);
    break;

case 'get_my_evaluations':
    // A code-logged user fetches THEIR OWN submissions for the current period + whether the edit window is open.
    $sess=currentSession($db);
    $me=$sess['employee_id']??null;
    if(!$me){ echo json_encode(['success'=>true,'data'=>[],'editable'=>false]); break; }
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    $rows=$db->prepare("SELECT id,eval_to,to_dept,scores,created_at FROM evaluations WHERE eval_from=? AND period=? ORDER BY created_at ASC");
    $rows->execute([$me,$period]); $rows=$rows->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r2){ $r2['scores']=json_decode($r2['scores'],true); } unset($r2);
    $win=(int)jclampNum(getSetting($db,'codeEditWindowMin','120'),0,1440,120);
    // Timer-from-claim (HRD s13): window runs from when the code was issued, not the first submission.
    $deadline=codeClaimDeadline($db,$me,$period,$win);
    $editable=($win>0 && $deadline!==null && time()<$deadline);
    echo json_encode(['success'=>true,'data'=>$rows,'editable'=>$editable,'deadline'=>$deadline,'secondsLeft'=>($deadline!==null?max(0,$deadline-time()):null),'windowMin'=>$win,'period'=>$period]);break;

case 'update_my_evaluation':
    // Code user edits one of their OWN submissions, only while the edit window is open. Status recomputed server-side.
    // §9.5 pause: a paused survey freezes edits too — "приём оценок" is fully frozen, not just new submissions.
    if(getSetting($db,'isSurveyActive','1')==='0'){ echo json_encode(['error'=>'survey_paused']); break; }
    $sess=currentSession($db);
    $me=$sess['employee_id']??null;
    if(!$me){ http_response_code(403); echo json_encode(['error'=>'forbidden']); break; }
    $win=(int)jclampNum(getSetting($db,'codeEditWindowMin','120'),0,1440,120);
    if($win<=0){ echo json_encode(['error'=>'edit_disabled']); break; }
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    // Audit s12 (#9/#15): a code holder may edit ONLY while their binding is still valid — mirror submit_evaluation.
    // Distinguish by ROLE (the only guard): a HEAD session ('manager') must be an active employee; a CODE session
    // ('employee' — ac_* OR a legacy real-employee code) must still hold an ACTIVE code for the CURRENT period, so a
    // deactivation (impersonation report) or a period reset freezes edits too — covers the legacy code path, not just ac_*.
    if(($sess['role']??'')==='manager'){
        $meEmp=$db->prepare("SELECT active FROM employees WHERE id=?"); $meEmp->execute([$me]);
        if((int)($meEmp->fetchColumn()?:0)!==1){ echo json_encode(['error'=>'inactive']); break; }
    } else {
        $acc=$db->prepare("SELECT 1 FROM access_codes WHERE employee_id=? AND active=1 AND period=?"); $acc->execute([$me,$period]);
        if(!$acc->fetchColumn()){ echo json_encode(['error'=>'inactive']); break; }
    }
    $to=$input['to']??'';
    $row=$db->prepare("SELECT id,scores FROM evaluations WHERE eval_from=? AND eval_to=? AND period=?");
    $row->execute([$me,$to,$period]); $er=$row->fetch(PDO::FETCH_ASSOC);
    if(!$er){ echo json_encode(['error'=>'not_found']); break; }
    // Edit window: a head (password session) keeps the first-submission window; a CODE session runs from when the
    // code was ISSUED (HRD s13 timer-from-claim — same gate as login_with_code/submit_evaluation). Past it the code
    // is dead and edits are refused too.
    if(($sess['role']??'')==='manager'){
        $first=$db->prepare("SELECT MIN(created_at) FROM evaluations WHERE eval_from=? AND period=?"); $first->execute([$me,$period]);
        $firstTs=strtotime(((string)$first->fetchColumn()).' UTC');
        if($firstTs===false || time() > $firstTs+$win*60){ echo json_encode(['error'=>'edit_window_closed']); break; }
    } else {
        $dl=codeClaimDeadline($db,$me,$period,$win);
        if($dl===null || time()>=$dl){ echo json_encode(['error'=>'code_expired']); break; }
    }
    // audit s9: mirror submit's voting-rights guard — editor ($me) is a code employee (non-head); block editing a
    // vote ON a head when allowEmpRateHead is off (e.g. policy tightened after the original submission).
    $tgh=$db->prepare("SELECT is_head FROM employees WHERE id=?"); $tgh->execute([$to]);
    if((int)($tgh->fetchColumn()?:0)===1 && getSetting($db,'allowEmpRateHead','0')!=='1'){ echo tamperJson('rating_not_allowed'); break; }
    // s17 §2 backstop: mirror submit_evaluation — editing a vote on a rate_block target is refused too (policy can be
    // tightened after the original submission, and the edit window must not be an end-run around the block).
    $rbE=$db->prepare("SELECT rate_block FROM employees WHERE id=?"); $rbE->execute([$me]);
    if(in_array((string)$to,jdecodeArr($rbE->fetchColumn()),true)){ echo tamperJson('rating_not_allowed'); break; }
    $rules=getRules($db);
    // HRD R2: mirror submit_evaluation's globalNoSkip backstop in the edit window too (server-derived projects).
    $gNoSkip=getSetting($db,'globalNoSkip','0')==='1'; $gRatedAll=getSetting($db,'globalRatedByAll','0')==='1'; $myG=false; $toG=false;
    if($gNoSkip||$gRatedAll){ $pq=$db->prepare("SELECT id,project,projects FROM employees WHERE id IN (?,?)"); $pq->execute([$me,$to]);
        foreach($pq->fetchAll(PDO::FETCH_ASSOC) as $pr){ $g=rowInGlobal($pr); if($pr['id']===$me)$myG=$g; if($pr['id']===$to)$toG=$g; }
        // s11: an anonymous dept-pool holder (ac_*) isn't in the roster — derive its Global membership from the
        // code's bound project so the edit window enforces globalNoSkip exactly like submit_evaluation does.
        if(strpos((string)$me,'ac_')===0){ $acp=$db->prepare("SELECT project FROM access_codes WHERE employee_id=? AND active=1 AND period=?"); $acp->execute([$me,$period]); $myG=((string)$acp->fetchColumn()==='Global'); } }
    $existScores=json_decode((string)$er['scores'],true); if(!is_array($existScores))$existScores=[];
    // Audit s12 (#4 BYPASS fix — verification): MERGE the validated edit over the stored scores; NEVER full-replace.
    // (1) Omitting a key no longer drops it — it stays from $existScores (closes the "launder a rejection by leaving
    //     the key out" hole, and any partial-edit data loss). (2) A moderator's REJECTION is FULLY FROZEN: the author
    //     cannot re-score, skip, OR drop a rejected key — every client edit to it is ignored, the stored value stands.
    $incoming=array_intersect_key(is_array($input['scores'])?$input['scores']:[],['commitment'=>1,'communication'=>1,'expertise'=>1,'personality'=>1]);
    $scores=$existScores;
    foreach($incoming as $k=>$sv){
        if(!is_array($sv))continue;
        if(($existScores[$k]['status']??'')==='rejected') continue;                                   // frozen — keep stored rejected value
        $sk=!empty($sv['skipped']); $sc=isset($sv['score'])?(int)$sv['score']:0;
        if(!$sk && ($sc<1||$sc>10)){ echo tamperJson('bad_score',['key'=>$k]); break 2; }   // s13 #7/#8: non-skipped must be real 1–10 (no covert score:0)
        if($sk && (($gNoSkip && $myG && $toG) || ($gRatedAll && $myG && $toG))){ echo tamperJson('skip_not_allowed',['key'=>$k]); break 2; }
        $status=(!$sk && $sc>=$rules['moderationThreshold'])?'pending':'approved';
        // Same server-side comment-required guard as submit_evaluation — the edit window can't erase a mandatory justification.
        if(!$sk && $sc>0){
            $needTxt=($rules['caseRequiredHigh'] && $sc>=$rules['caseHighReq']) || ($rules['caseRequiredLow'] && $sc<=$rules['caseLow']);
            if($needTxt && mb_strlen(trim((string)($sv['text']??'')))<=5){ echo json_encode(['error'=>'comment_required','key'=>$k]); break 2; }
        }
        $scores[$k]=['score'=>$sk?0:$sc,'text'=>$sk?'':(string)($sv['text']??''),'skipped'=>$sk,'status'=>$status];
    }
    $db->prepare("UPDATE evaluations SET scores=? WHERE id=?")->execute([json_encode($scores,JSON_UNESCAPED_UNICODE),$er['id']]);
    echo json_encode(['success'=>true]);break;

case 'regenerate_codes':
    // R2-CODES: DEPRECATED — admin no longer mass-generates codes (that implied admin knows name→code).
    // Codes are self-generated by employees (generate_my_code). Kept as a no-op for old clients.
    requireRole($db,['superadmin']);
    echo json_encode(['error'=>'deprecated','reason'=>'codes are now self-generated by employees']);break;

case 'rotate_code':
    // R2-CODES: HR recovery / override — super-admin issues a fresh code for ONE employee (e.g. they lost theirs,
    // or an impersonated claim was reported). Reveals that employee's new code to the admin → logged in reveal_log.
    $rs=requireRole($db,['superadmin']);
    $eid=$input['employeeId']??''; if(!$eid){ echo json_encode(['error'=>'no id']); break; }
    $period=getSetting($db,'currentPeriod',date('Y-m')); $cfg=codeCfg($db);
    $c=genCode($db,$cfg,$period); if(!$c){ echo json_encode(['error'=>'gen_failed']); break; }
    // Audit fix: atomic deactivate-old + insert-new so a failure can't leave the employee with NO active code.
    try{
        $db->beginTransaction();
        $db->prepare("UPDATE access_codes SET active=0 WHERE employee_id=? AND period=? AND active=1")->execute([$eid,$period]);
        // AUDIT-ROTATE: also kill any LIVE auth session of the old code-holder. Rotating only deactivated the code,
        // so an impersonator who already logged in kept a valid 12h token and could keep voting. Force re-login.
        $db->prepare("DELETE FROM auth_sessions WHERE employee_id=?")->execute([$eid]);
        // Plain INSERT (not OR REPLACE): a 1-in-31^6 code collision throws → rollback → admin retries, instead of
        // REPLACE silently clobbering another employee's row on the (code,period) primary key (audit R2-CODES).
        $db->prepare("INSERT INTO access_codes(code,employee_id,period,active,edited_by_admin,created_at) VALUES(?,?,?,1,0,CURRENT_TIMESTAMP)")->execute([$c,$eid,$period]);
        $db->prepare("INSERT INTO reveal_log(action,eval_from,period,by_role,by_ip) VALUES('rotate_code',?,?,?,?)")->execute([$eid,$period,$rs['role']??'superadmin',clientIp()]);
        $db->commit();
        echo json_encode(['success'=>true,'code'=>$c]);
    }catch(Exception $e){ if($db->inTransaction())$db->rollBack(); echo json_encode(['error'=>'rotate_failed']); }
    break;

case 'edit_code':
    // R2-CODES: DEPRECATED — manual code-setting by admin isn't part of the self-generated model.
    // Use rotate_code for recovery (random code, logged).
    requireRole($db,['superadmin']);
    echo json_encode(['error'=>'deprecated','reason'=>'use rotate_code for recovery']);break;

case 'get_codes':
    // R2-CODES: NO name↔code map (confidential). Super-admin sees only PROGRESS per project+dept —
    // how many self-claimed a code vs the headcount — never which code belongs to whom.
    requireRole($db,['superadmin']);
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    // s11 (anonymous pool): codes are bound to a project+dept, not to a person — so "claimed" is simply how many
    // active codes were issued for that project+dept this period (≤ headcount by the pool cap). Never reveals WHO.
    $rows=$db->query("SELECT e.project,e.dept,
        COUNT(DISTINCT CASE WHEN e.is_head=0 THEN e.id END) AS headcount,
        (SELECT COUNT(*) FROM access_codes ac WHERE ac.active=1 AND ac.period=".$db->quote($period)."
            AND ac.project=e.project AND ac.dept=e.dept) AS claimed
        FROM employees e
        WHERE e.active=1 GROUP BY e.project,e.dept ORDER BY e.project,e.dept")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$rows,'period'=>$period]);break;

case 'find_submissions':
    // HR RECOVERY (anonymous model, HRD s13): the old "rotate code, keep data" is gone. Instead, an employee who lost
    // their code / needs a redo tells HR WHO they rated; HR enters those targets here and the server returns the
    // anonymous holder(s) in that project+dept whose ballot COVERS all named targets. Returns each candidate's full
    // list of rated people (ids only — the client maps to names) so HR can confirm the right one before deleting.
    // Super-admin only + logged: this is a deliberate, accountable de-anonymization aid, not open browsing.
    $rs=requireRole($db,['superadmin']);
    $proj=(string)($input['project']??''); $dept=(string)($input['dept']??'');
    $targets=is_array($input['targets']??null)?array_values(array_filter(array_map('strval',$input['targets']))):[];
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    if($proj===''||$dept===''||!$targets){ echo json_encode(['error'=>'bad_input']); break; }
    // Candidate holders = PEER code submitters (role 'employee') from this project+dept this period. Heads ('manager')
    // are never anonymous-pool holders and are excluded.
    $cand=$db->prepare("SELECT DISTINCT eval_from FROM evaluations WHERE period=? AND from_project=? AND from_dept=? AND evaluator_role='employee'");
    $cand->execute([$period,$proj,$dept]);
    $holders=$cand->fetchAll(PDO::FETCH_COLUMN);
    $matched=[];
    foreach($holders as $h){
        $r=$db->prepare("SELECT eval_to FROM evaluations WHERE eval_from=? AND period=?"); $r->execute([$h,$period]);
        $rated=$r->fetchAll(PDO::FETCH_COLUMN);
        $rset=array_flip($rated);
        $covers=true; foreach($targets as $tg){ if(!isset($rset[$tg])){ $covers=false; break; } }   // must cover ALL named targets
        if($covers){ $matched[]=['holderId'=>$h,'count'=>count($rated)]; }   // s13 review #1: COUNT only, never the ballot
    }
    // ANTI-CLUSTERING (s13 adversarial review #1): never hand back holders' full ballots. A single weak/common target
    // would otherwise dump many peers' fingerprintable target-sets — the exact cross-row clustering s12 per-row
    // pseudonymization killed. So a DELETABLE id is returned ONLY when the named targets narrow to EXACTLY ONE holder;
    // if several still match we return just the count and ask HR to name MORE people (the UI hint nudges cross-dept
    // names, which disambiguate). A holder's non-named targets are never revealed.
    $unique=(count($matched)===1);
    // Reconstructable audit trail (review #2): record the scope (project|dept|targets) AND the result in reveal_log,
    // so the most powerful de-anonymizing read is as auditable as reveal_author / delete_submission.
    $logScope='find:'.$proj.'|'.$dept.'|'.implode(',',$targets);
    $logRes=$unique?$matched[0]['holderId']:('matches='.count($matched));
    $db->prepare("INSERT INTO reveal_log(action,eval_id,eval_from,period,by_role,by_ip) VALUES('find_submission',?,?,?,?,?)")
       ->execute([$logScope,$logRes,$period,$rs['role']??'superadmin',clientIp()]);
    echo json_encode(['success'=>true,'match'=>$unique?$matched[0]:null,'ambiguous'=>(count($matched)>1),'matchCount'=>count($matched)]);break;

case 'delete_submission':
    // HR RECOVERY delete (HRD s13: "при удалении данные должны удаляться"). Removes ONE anonymous holder's whole
    // submission for the period: (1) DELETE its evaluations (data gone → also frees the dept participant-limit slot,
    // which counts DISTINCT eval_from in evaluations); (2) deactivate its code (frees the dept POOL slot AND releases
    // the per-device gen-lock, which keys on the code staying active → same device can re-generate); (3) kill any live
    // session. The employee then self-generates a fresh code and redoes the survey. Atomic + logged.
    $rs=requireRole($db,['superadmin']);
    $holder=(string)($input['holderId']??''); if($holder===''){ echo json_encode(['error'=>'no_holder']); break; }
    $period=getSetting($db,'currentPeriod',date('Y-m'));
    try{
        $db->beginTransaction();
        $meta=$db->prepare("SELECT from_project,from_dept,COUNT(*) AS n FROM evaluations WHERE eval_from=? AND period=? GROUP BY from_project,from_dept");
        $meta->execute([$holder,$period]); $groups=$meta->fetchAll(PDO::FETCH_ASSOC);
        $n=0; foreach($groups as $g){ $n+=(int)$g['n']; }
        $db->prepare("DELETE FROM evaluations WHERE eval_from=? AND period=?")->execute([$holder,$period]);
        $db->prepare("UPDATE access_codes SET active=0 WHERE employee_id=? AND period=?")->execute([$holder,$period]);
        $db->prepare("DELETE FROM auth_sessions WHERE employee_id=?")->execute([$holder]);
        $db->prepare("DELETE FROM survey_progress WHERE holder=? AND period=?")->execute([$holder,$period]);   // s14 audit #6: drop monitoring row so the freed slot isn't still "completed"
        // s13 review #3: decrement EVERY (project,dept) group the holder's rows spanned (a legacy code-holder moved
        // mid-period can span two) so the displayed dept counter stays honest. (The limit itself self-corrects — it
        // reads DISTINCT eval_from from evaluations, which we just deleted.)
        foreach($groups as $g){ if((int)$g['n']>0 && !empty($g['from_project'])){
            $db->prepare("UPDATE dept_submissions SET count=max(0,count-?) WHERE project=? AND dept=? AND period=?")
               ->execute([(int)$g['n'],$g['from_project'],$g['from_dept'],$period]); } }
        $db->prepare("INSERT INTO reveal_log(action,eval_from,period,by_role,by_ip) VALUES('delete_submission',?,?,?,?)")
           ->execute([$holder,$period,$rs['role']??'superadmin',clientIp()]);
        $db->commit();
        echo json_encode(['success'=>true,'deleted'=>$n]);
    }catch(Exception $e){ if($db->inTransaction())$db->rollBack(); echo json_encode(['error'=>'delete_failed']); }
    break;

case 'export_codes_csv':
    // R2-CODES: DEPRECATED — exporting "name→code" is exactly what broke anonymity. Removed.
    requireRole($db,['superadmin']);
    echo json_encode(['error'=>'deprecated','reason'=>'name→code export removed (confidentiality). Codes are self-generated.']);break;

case 'save_config':
    // Single superadmin-gated writer for config-as-data (M2/M3 + forward-compat M1). One key at a time from
    // an allow-list; the server re-validates & re-serializes, so the DB can never hold an out-of-range config.
    requireRole($db,['superadmin']);
    $key=$input['key']??'';
    $val=$input['value']??null;
    $allowed=['rulesConfig','weightsConfig','hierarchyConfig','codeLength','codeAlphabet','codeLoginEnabled','codeCaseSensitive','codeEditWindowMin'];
    if(!in_array($key,$allowed,true)){ echo json_encode(['error'=>'bad_key']); break; }
    if($key==='rulesConfig'){
        $norm=parseRulesArr(is_array($val)?$val:json_decode((string)$val,true));
        $norm['_updatedAt']=time();
        setSetting($db,$key,json_encode($norm,JSON_UNESCAPED_UNICODE));
    } else if($key==='weightsConfig'){
        $norm=parseWeightsArr(is_array($val)?$val:json_decode((string)$val,true));
        $norm['_updatedAt']=time();
        setSetting($db,$key,json_encode($norm,JSON_UNESCAPED_UNICODE));
    } else if($key==='hierarchyConfig'){
        // M1 not yet wired into the routing engine — store validated JSON for forward-compat only.
        $arr=is_array($val)?$val:json_decode((string)$val,true);
        if(!is_array($arr)){ echo json_encode(['error'=>'bad_json']); break; }
        $arr['_updatedAt']=time();
        setSetting($db,$key,json_encode($arr,JSON_UNESCAPED_UNICODE));
    } else if($key==='codeEditWindowMin'){
        setSetting($db,$key,(string)(int)jclampNum($val,0,1440,120)); // 0..24h
    } else if($key==='codeLength'){
        setSetting($db,$key,(string)(int)jclampNum($val,6,12,6));   // server minimum 6 (brute-force floor)
    } else if($key==='codeAlphabet'){
        $a=preg_replace('/[^A-Za-z0-9]/','',(string)$val);
        if(strlen($a)<10){ echo json_encode(['error'=>'alphabet_too_small']); break; }
        setSetting($db,$key,$a);
    } else { // codeLoginEnabled / codeCaseSensitive → '0' | '1'
        setSetting($db,$key,((string)$val==='1'||$val===true||$val===1)?'1':'0');
    }
    echo json_encode(['success'=>true]);break;

default:
    echo json_encode(['error'=>'unknown: '.$action]);
}
