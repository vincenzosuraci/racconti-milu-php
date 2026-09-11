<?php
declare(strict_types=1);

/*
 * Racconti Milù - downloader + reader, single-file PHP application.
 * Requirements: PHP 8.1+, cURL, DOM, SQLite3, mbstring recommended.
 *
 * Put this file on a PHP-enabled web server. The PHP process must be allowed
 * to run CLI children (proc_open/proc_get_status) for true background mode.
 */

const BASE_URL = 'https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/';
const PAGE_SIZE = 16;
const PAGE_CONCURRENCY = 8;
const STORY_CONCURRENCY = 8;
const HTTP_TIMEOUT = 30;
const CONNECT_TIMEOUT = 10;
const USER_AGENT = 'Mozilla/5.0 (compatible; RaccontiMilù-Reader/1.0; +https://raccontimilu.com/)';

$dbFile = __DIR__ . '/racconti.sqlite';
$lockFile = __DIR__ . '/racconti.worker.lock';

function db(): SQLite3 {
    static $db = null;
    if ($db instanceof SQLite3) return $db;
    $db = new SQLite3($GLOBALS['dbFile']);
    $db->busyTimeout(10000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA foreign_keys=ON');
    initDb($db);
    return $db;
}

function initDb(SQLite3 $db): void {
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS pages (
    page_no INTEGER PRIMARY KEY,
    url TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    http_code INTEGER,
    error TEXT,
    discovered_at TEXT,
    completed_at TEXT
);
CREATE TABLE IF NOT EXISTS stories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL UNIQUE,
    url_hash TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT '',
    published_at TEXT,
    rating INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    http_code INTEGER,
    error TEXT,
    source_page INTEGER,
    discovered_at TEXT,
    downloaded_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_stories_status ON stories(status);
CREATE INDEX IF NOT EXISTS idx_stories_title ON stories(title COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_stories_date ON stories(published_at);
CREATE INDEX IF NOT EXISTS idx_stories_rating ON stories(rating);
CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(status);
SQL);
}

function setting(string $key, ?string $default = null): ?string {
    $st = db()->prepare('SELECT value FROM settings WHERE key=:k');
    $st->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? (string)$r['value'] : $default;
}

function setSetting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO settings(key,value) VALUES(:k,:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $st->bindValue(':k', $key, SQLITE3_TEXT);
    $st->bindValue(':v', $value, SQLITE3_TEXT);
    $st->execute();
}

function jsonResponse(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanUrl(string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return $url;
    $scheme = strtolower($p['scheme'] ?? 'https');
    $host = strtolower($p['host']);
    $path = $p['path'] ?? '/';
    $path = preg_replace('~/+~', '/', $path);
    if ($path !== '/') $path = rtrim($path, '/') . '/';
    return $scheme . '://' . $host . $path;
}

function urlHash(string $url): string { return hash('sha256', cleanUrl($url)); }

function httpMulti(array $urls, int $concurrency=8): array {
    $urls = array_values(array_unique($urls));
    $out = [];
    $mh = curl_multi_init();
    $queue = $urls;
    $handles = [];

    $add = function(string $url) use (&$mh, &$handles): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => HTTP_TIMEOUT,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch'=>$ch, 'url'=>$url];
    };

    while ($queue || $handles) {
        while ($queue && count($handles) < $concurrency) $add(array_shift($queue));
        do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        if ($mrc !== CURLM_OK) break;
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $meta = $handles[$key] ?? ['url'=>''];
            $body = curl_multi_getcontent($ch);
            $out[$meta['url']] = [
                'body' => $body ?: '',
                'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error' => curl_error($ch),
                'effective_url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);
        }
        if ($running) {
            $n = curl_multi_select($mh, 1.0);
            if ($n === -1) usleep(10000);
        }
    }
    curl_multi_close($mh);
    return $out;
}

function dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    libxml_use_internal_errors(true);
    $d = new DOMDocument('1.0', 'UTF-8');
    $ok = $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    return $ok ? $d : null;
}

function xpath(DOMDocument $d): DOMXPath { return new DOMXPath($d); }

function textOf(?DOMNode $node): string {
    if (!$node) return '';
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function firstNode(DOMXPath $xp, array $queries): ?DOMNode {
    foreach ($queries as $q) {
        $n = $xp->query($q);
        if ($n && $n->length) return $n->item(0);
    }
    return null;
}

function firstAttr(DOMXPath $xp, array $queries, string $attr): string {
    $n = firstNode($xp, $queries);
    return $n instanceof DOMElement ? trim($n->getAttribute($attr)) : '';
}

function parseMaxPage(string $html): int {
    $d = dom($html); if (!$d) return 1;
    $xp = xpath($d);
    $max = 1;
    $nodes = $xp->query("//*[@id='pagination']//a[contains(concat(' ', normalize-space(@class), ' '), ' page-numbers ')]");
    foreach ($nodes as $n) {
        $label = trim($n->textContent);
        if (ctype_digit($label)) $max = max($max, (int)$label);
        $href = $n->getAttribute('href');
        if (preg_match('~/page/(\d+)/?~', $href, $m)) $max = max($max, (int)$m[1]);
    }
    return $max;
}

function pageUrl(int $n): string { return $n <= 1 ? BASE_URL : BASE_URL . 'page/' . $n . '/'; }

function parseListing(string $html, int $pageNo): array {
    $d = dom($html); if (!$d) return [];
    $xp = xpath($d); $rows = [];
    $articles = $xp->query('//article');
    foreach ($articles as $article) {
        $a = null;
        foreach ([
                     ".//h3[contains(concat(' ',normalize-space(@class),' '),' title ')]//a",
                     ".//h2[contains(concat(' ',normalize-space(@class),' '),' title ')]//a",
                     ".//a[contains(@href,'raccontimilu.com')][.//text()]"
                 ] as $q) {
            $nn = $xp->query($q, $article);
            if ($nn && $nn->length) { $a = $nn->item(0); break; }
        }
        if (!$a instanceof DOMElement) continue;
        $href = cleanUrl($a->getAttribute('href'));
        if (!str_starts_with($href, 'https://raccontimilu.com/')) continue;
        $title = textOf($a);
        if ($title === '') continue;
        $rows[$href] = ['url'=>$href, 'title'=>$title, 'source_page'=>$pageNo];
    }
    // Fallback for themes that don't wrap posts in <article>.
    if (!$rows) {
        $nodes = $xp->query("//h3[contains(concat(' ',normalize-space(@class),' '),' title ')]//a | //h2[contains(concat(' ',normalize-space(@class),' '),' title ')]//a");
        foreach ($nodes as $a) {
            if (!$a instanceof DOMElement) continue;
            $href = cleanUrl($a->getAttribute('href'));
            if (!str_starts_with($href, 'https://raccontimilu.com/')) continue;
            if ($href === cleanUrl(BASE_URL) || preg_match('~/page/\d+/?$~', $href)) continue;
            $rows[$href] = ['url'=>$href, 'title'=>textOf($a), 'source_page'=>$pageNo];
        }
    }
    return array_values($rows);
}

function removeLinksAndNoise(DOMNode $root): string {
    $doc = $root->ownerDocument;
    if (!$doc) return '';
    $xp = new DOMXPath($doc);
    foreach ($xp->query('.//script|.//style|.//noscript|.//iframe|.//form', $root) as $n) $n->parentNode?->removeChild($n);
    // Links are not part of the readable text. Keep their text, remove the <a> itself.
    foreach ($xp->query('.//a', $root) as $a) {
        $parent = $a->parentNode; if (!$parent) continue;
        while ($a->firstChild) $parent->insertBefore($a->firstChild, $a);
        $parent->removeChild($a);
    }
    $html = $doc->saveHTML($root) ?: '';
    $html = preg_replace('~<\/?(?:img|figure|picture|svg|video|audio|source|button|input|textarea|select|option)[^>]*>.*?<\/(?:figure|picture|video|audio|select)>~is', ' ', $html) ?? $html;
    $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
    $html = preg_replace('~</p\s*>~i', "\n\n", $html) ?? $html;
    $html = preg_replace('~</div\s*>~i', "\n", $html) ?? $html;
    $html = preg_replace('~<[^>]+>~', ' ', $html) ?? $html;
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\xC2\xA0", "\r"], [' ', ''], $text);
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n[ \t]+/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function jsonLdObjects(DOMXPath $xp): array {
    $out=[];
    foreach ($xp->query("//script[@type='application/ld+json']") as $s) {
        $raw=trim($s->textContent);
        $j=json_decode($raw,true);
        if (is_array($j)) {
            if (isset($j['@graph']) && is_array($j['@graph'])) $out=array_merge($out,$j['@graph']);
            else $out[]=$j;
        }
    }
    return $out;
}

function parseStory(string $html, string $url): ?array {
    $d = dom($html); if (!$d) return null;
    $xp = xpath($d);
    $title = textOf(firstNode($xp, [
        '//article//h1', '//main//h1', '//h1'
    ]));
    $author = '';
    $date = '';

    $author = textOf(firstNode($xp, [
        "//article//*[contains(concat(' ',normalize-space(@class),' '),' author ')]//a",
        "//article//*[contains(concat(' ',normalize-space(@class),' '),' author ')]",
        "//main//*[contains(concat(' ',normalize-space(@class),' '),' author ')]//a",
        "//main//*[contains(concat(' ',normalize-space(@class),' '),' author ')]"
    ]));
    if (preg_match('/^By\s+/iu', $author)) $author = trim(preg_replace('/^By\s+/iu','',$author));

    $date = firstAttr($xp, ["//meta[@property='article:published_time']", "//meta[@property='og:article:published_time']"], 'content');
    if ($date === '') $date = firstAttr($xp, ["//meta[@name='date']", "//meta[@name='pubdate']"], 'content');
    if ($date === '') $date = textOf(firstNode($xp, [
        "//article//time[@datetime]", "//main//time[@datetime]", "//time[@datetime]"
    ]));
    if ($date === '') $date = firstAttr($xp, ["//article//time[@datetime]", "//main//time[@datetime]", "//time[@datetime]"], 'datetime');

    foreach (jsonLdObjects($xp) as $obj) {
        $type = is_string($obj['@type'] ?? null) ? strtolower($obj['@type']) : '';
        if ($title === '' && in_array($type,['article','blogposting','newsarticle'],true)) $title = trim((string)($obj['headline'] ?? $obj['name'] ?? ''));
        if ($author === '' && isset($obj['author'])) {
            $aa=$obj['author'];
            if (is_array($aa) && isset($aa['name'])) $author=trim((string)$aa['name']);
            elseif (is_array($aa) && isset($aa[0]['name'])) $author=trim((string)$aa[0]['name']);
        }
        if ($date === '') $date=trim((string)($obj['datePublished'] ?? ''));
    }

    $contentNode = firstNode($xp, [
        "//article//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
        "//article//*[contains(concat(' ',normalize-space(@class),' '),' post-content ')]",
        "//article//*[contains(concat(' ',normalize-space(@class),' '),' content ')]",
        "//main//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
        "//main//*[contains(concat(' ',normalize-space(@class),' '),' post-content ')]",
        '//article', '//main'
    ]);
    if (!$contentNode) return null;

    // Clone before modifying, so title/metadata remain intact.
    $content = $contentNode->cloneNode(true);
    // Remove common non-story descendants.
    $cx = new DOMXPath($content->ownerDocument);
    foreach ($cx->query('.//h1|.//header|.//footer|.//*[contains(concat(" ",normalize-space(@class)," ")," comments ")]|.//*[contains(concat(" ",normalize-space(@class)," ")," author ")]|.//*[contains(concat(" ",normalize-space(@class)," ")," post-meta ")]', $content) as $n) {
        if ($n !== $content) $n->parentNode?->removeChild($n);
    }
    $readable = removeLinksAndNoise($content);

    if ($title === '') $title = textOf(firstNode($xp, ['//h1']));
    return [
        'url'=>cleanUrl($url),
        'url_hash'=>urlHash($url),
        'title'=>$title,
        'content'=>$readable,
        'author'=>$author,
        'published_at'=>normalizeDate($date),
    ];
}

function normalizeDate(string $date): ?string {
    $date=trim($date); if ($date==='') return null;
    $ts=strtotime($date); return $ts ? date('Y-m-d H:i:s',$ts) : $date;
}

function ensurePages(int $n): void {
    $st=db()->prepare('INSERT OR IGNORE INTO pages(page_no,url,status) VALUES(:n,:u,"pending")');
    for($i=1;$i<=$n;$i++) { $st->bindValue(':n',$i,SQLITE3_INTEGER); $st->bindValue(':u',pageUrl($i),SQLITE3_TEXT); $st->execute(); }
}

function spawnWorker(): bool {
    global $lockFile;
    $fp=@fopen($lockFile,'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX|LOCK_NB)) { fclose($fp); return true; }
    // Keep lock only during spawn; worker obtains its own lock.
    flock($fp, LOCK_UN); fclose($fp);
    if (!function_exists('proc_open')) return false;
    $php=PHP_BINARY;
    $script=$_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $cmd=escapeshellarg($php).' '.escapeshellarg($script).' --worker';
    $spec=[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']];
    $p=@proc_open($cmd,$spec,$pipes,__DIR__);
    if (is_resource($p)) { proc_close($p); return true; }
    return false;
}

function workerRunning(): bool {
    global $lockFile;
    $fp=@fopen($lockFile,'c'); if(!$fp) return false;
    $ok=!flock($fp,LOCK_EX|LOCK_NB);
    if(!$ok) { flock($fp,LOCK_UN); }
    fclose($fp); return $ok;
}

function worker(): never {
    global $lockFile;
    $fp=@fopen($lockFile,'c');
    if(!$fp || !flock($fp,LOCK_EX|LOCK_NB)) exit(0);
    set_time_limit(0);
    setSetting('worker','running');
    setSetting('stop_requested','0');

    try {
        // Discover N from page 1 when needed.
        $n=(int)(setting('total_pages','0') ?? '0');
        if ($n<1) {
            $r=httpMulti([BASE_URL],1)[BASE_URL] ?? null;
            if(!$r || $r['code']<200 || $r['code']>=400) throw new RuntimeException('Impossibile scaricare la pagina iniziale.');
            $n=parseMaxPage($r['body']);
            setSetting('total_pages',(string)$n);
            ensurePages($n);
        }
        ensurePages($n);

        // Page discovery stage.
        while ((int)db()->querySingle("SELECT COUNT(*) FROM pages WHERE status IN ('pending','error')") > 0) {
            if (setting('stop_requested','0')==='1') { setSetting('worker','stopped'); exit(0); }
            $rows=[]; $res=db()->query("SELECT page_no,url,attempts FROM pages WHERE status IN ('pending','error') ORDER BY page_no LIMIT ".PAGE_CONCURRENCY);
            while($x=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$x;
            if(!$rows) break;
            $urls=array_column($rows,'url');
            $results=httpMulti($urls,PAGE_CONCURRENCY);
            db()->exec('BEGIN');
            try {
                foreach($rows as $row) {
                    $url=$row['url']; $r=$results[$url]??null;
                    $upd=db()->prepare('UPDATE pages SET attempts=attempts+1,http_code=:c,error=:e,status=:s,completed_at=:d WHERE page_no=:n');
                    $upd->bindValue(':c',$r['code']??0,SQLITE3_INTEGER);
                    $upd->bindValue(':e',($r && !$r['error'] ? null : ($r['error']??'HTTP error')),SQLITE3_TEXT);
                    $ok=$r && $r['code']>=200 && $r['code']<400 && $r['body']!=='';
                    $upd->bindValue(':s',$ok?'done':'error',SQLITE3_TEXT);
                    $upd->bindValue(':d',$ok?date('Y-m-d H:i:s'):null,SQLITE3_TEXT);
                    $upd->bindValue(':n',(int)$row['page_no'],SQLITE3_INTEGER); $upd->execute();
                    if($ok) {
                        $stories=parseListing($r['body'],(int)$row['page_no']);
                        $ins=db()->prepare('INSERT OR IGNORE INTO stories(url,url_hash,title,status,source_page,discovered_at) VALUES(:u,:h,:t,"pending",:p,:d)');
                        foreach($stories as $s){
                            $ins->bindValue(':u',$s['url'],SQLITE3_TEXT); $ins->bindValue(':h',urlHash($s['url']),SQLITE3_TEXT); $ins->bindValue(':t',$s['title'],SQLITE3_TEXT); $ins->bindValue(':p',(int)$row['page_no'],SQLITE3_INTEGER); $ins->bindValue(':d',date('Y-m-d H:i:s'),SQLITE3_TEXT); $ins->execute();
                        }
                    }
                }
                db()->exec('COMMIT');
            } catch(Throwable $e){ db()->exec('ROLLBACK'); throw $e; }
        }

        // Story download stage.
        while ((int)db()->querySingle("SELECT COUNT(*) FROM stories WHERE status IN ('pending','error')") > 0) {
            if (setting('stop_requested','0')==='1') { setSetting('worker','stopped'); exit(0); }
            $rows=[]; $res=db()->query("SELECT id,url,attempts FROM stories WHERE status IN ('pending','error') ORDER BY id LIMIT ".STORY_CONCURRENCY);
            while($x=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$x;
            if(!$rows) break;
            $results=httpMulti(array_column($rows,'url'),STORY_CONCURRENCY);
            db()->exec('BEGIN');
            try {
                foreach($rows as $row){
                    $r=$results[$row['url']]??null;
                    $parsed=null;
                    if($r && $r['code']>=200 && $r['code']<400 && $r['body']!=='') $parsed=parseStory($r['body'],$row['url']);
                    $ok=is_array($parsed) && $parsed['content']!=='';
                    $st=$ok ? db()->prepare('UPDATE stories SET title=:t,content=:c,author=:a,published_at=:d,status="done",attempts=attempts+1,http_code=:h,error=NULL,downloaded_at=:now WHERE id=:id') : db()->prepare('UPDATE stories SET status="error",attempts=attempts+1,http_code=:h,error=:e WHERE id=:id');
                    if($ok){$st->bindValue(':t',$parsed['title'],SQLITE3_TEXT);$st->bindValue(':c',$parsed['content'],SQLITE3_TEXT);$st->bindValue(':a',$parsed['author'],SQLITE3_TEXT);$st->bindValue(':d',$parsed['published_at'],SQLITE3_TEXT);$st->bindValue(':now',date('Y-m-d H:i:s'),SQLITE3_TEXT);} else {$st->bindValue(':e',$r['error']??'Parsing del racconto fallito',SQLITE3_TEXT);}
                    $st->bindValue(':h',$r['code']??0,SQLITE3_INTEGER);$st->bindValue(':id',(int)$row['id'],SQLITE3_INTEGER);$st->execute();
                }
                db()->exec('COMMIT');
            } catch(Throwable $e){ db()->exec('ROLLBACK'); throw $e; }
        }
        setSetting('worker','done');
        setSetting('last_run',date('Y-m-d H:i:s'));
    } catch(Throwable $e) {
        setSetting('worker','error'); setSetting('last_error',$e->getMessage());
    }
    flock($fp,LOCK_UN); fclose($fp); exit(0);
}

function progress(): array {
    $d=db();
    $totalPages=(int)(setting('total_pages','0')??0);
    $pagesDone=(int)$d->querySingle("SELECT COUNT(*) FROM pages WHERE status='done'");
    $pagesPending=(int)$d->querySingle("SELECT COUNT(*) FROM pages WHERE status IN ('pending','error')");
    $storiesTotal=(int)$d->querySingle('SELECT COUNT(*) FROM stories');
    $storiesDone=(int)$d->querySingle("SELECT COUNT(*) FROM stories WHERE status='done'");
    $storiesPending=(int)$d->querySingle("SELECT COUNT(*) FROM stories WHERE status IN ('pending','error')");
    return [
        'worker'=>setting('worker','idle'), 'stop_requested'=>setting('stop_requested','0'),
        'total_pages'=>$totalPages,'pages_done'=>$pagesDone,'pages_pending'=>$pagesPending,
        'stories_total'=>$storiesTotal,'stories_done'=>$storiesDone,'stories_pending'=>$storiesPending,
        'last_error'=>setting('last_error',''),'last_run'=>setting('last_run','')
    ];
}

function likeEscape(string $s): string { return strtr($s,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']); }

// CLI worker.
if (PHP_SAPI === 'cli' && in_array('--worker',$argv??[],true)) { db(); worker(); }

db();
$action=$_GET['action']??'';
if($action==='start'){
    setSetting('stop_requested','0'); setSetting('last_error','');
    if ((int)(setting('total_pages','0')??0) < 1) {
        $r=httpMulti([BASE_URL],1)[BASE_URL]??null;
        if(!$r || $r['code']<200 || $r['code']>=400) jsonResponse(['ok'=>false,'error'=>'Non riesco a scaricare la pagina iniziale.'],502);
        $n=parseMaxPage($r['body']); setSetting('total_pages',(string)$n); ensurePages($n);
        // Immediately parse page 1, so the UI has content even if CLI spawning is disabled.
        $stories=parseListing($r['body'],1);
        $st=db()->prepare('INSERT OR IGNORE INTO stories(url,url_hash,title,status,source_page,discovered_at) VALUES(:u,:h,:t,"pending",1,:d)');
        foreach($stories as $s){$st->bindValue(':u',$s['url'],SQLITE3_TEXT);$st->bindValue(':h',urlHash($s['url']),SQLITE3_TEXT);$st->bindValue(':t',$s['title'],SQLITE3_TEXT);$st->bindValue(':d',date('Y-m-d H:i:s'),SQLITE3_TEXT);$st->execute();}
        db()->exec("UPDATE pages SET status='done',http_code=".(int)$r['code'].",completed_at='".SQLite3::escapeString(date('Y-m-d H:i:s'))."' WHERE page_no=1");
    }
    $spawn=spawnWorker();
    jsonResponse(['ok'=>true,'spawned'=>$spawn,'progress'=>progress()]);
}
if($action==='stop'){setSetting('stop_requested','1');jsonResponse(['ok'=>true,'progress'=>progress()]);}
if($action==='reset'){
    setSetting('stop_requested','1');
    db()->exec('DELETE FROM stories'); db()->exec('DELETE FROM pages');
    setSetting('total_pages','0'); setSetting('worker','idle'); setSetting('last_error','');
    jsonResponse(['ok'=>true]);
}
if($action==='progress'){jsonResponse(['ok'=>true,'progress'=>progress()]);}
if($action==='rate' && $_SERVER['REQUEST_METHOD']==='POST'){
    $id=(int)($_POST['id']??0);$rating=max(0,min(5,(int)($_POST['rating']??0)));
    $st=db()->prepare('UPDATE stories SET rating=:r WHERE id=:id');$st->bindValue(':r',$rating,SQLITE3_INTEGER);$st->bindValue(':id',$id,SQLITE3_INTEGER);$st->execute();jsonResponse(['ok'=>true]);
}
if($action==='story'){
    $id=(int)($_GET['id']??0);$st=db()->prepare('SELECT id,url,title,content,author,published_at,rating FROM stories WHERE id=:id AND status="done"');$st->bindValue(':id',$id,SQLITE3_INTEGER);$r=$st->execute()->fetchArray(SQLITE3_ASSOC);if(!$r)jsonResponse(['ok'=>false],404);jsonResponse(['ok'=>true,'story'=>$r]);
}
if($action==='stories'){
    $limit=max(1,min(100,(int)($_GET['limit']??PAGE_SIZE)));$offset=max(0,(int)($_GET['offset']??0));$sort=$_GET['sort']??'date';$dir=strtolower($_GET['dir']??'desc')==='asc'?'ASC':'DESC';
    $allowed=['title'=>'title COLLATE NOCASE','date'=>'published_at','rating'=>'rating'];$order=$allowed[$sort]??$allowed['date'];
    $q=trim((string)($_GET['q']??''));$words=preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $where=["status='done'"];$params=[];
    foreach($words as $i=>$w){$p=':q'.$i;$where[]="(title LIKE $p ESCAPE '\\' OR content LIKE $p ESCAPE '\\')";$params[$p]='%'.likeEscape($w).'%';}
    $sql='SELECT id,url,title,substr(content,1,420) preview,author,published_at,rating FROM stories WHERE '.implode(' AND ',$where).' ORDER BY '.$order.' '.$dir.', id DESC LIMIT :lim OFFSET :off';
    $st=db()->prepare($sql);foreach($params as $p=>$v)$st->bindValue($p,$v,SQLITE3_TEXT);$st->bindValue(':lim',$limit,SQLITE3_INTEGER);$st->bindValue(':off',$offset,SQLITE3_INTEGER);$rs=[];$res=$st->execute();while($x=$res->fetchArray(SQLITE3_ASSOC))$rs[]=$x;
    $countSql='SELECT COUNT(*) FROM stories WHERE '.implode(' AND ',$where);$ct=db()->prepare($countSql);foreach($params as $p=>$v)$ct->bindValue($p,$v,SQLITE3_TEXT);$total=(int)$ct->execute()->fetchArray(SQLITE3_NUM)[0];
    jsonResponse(['ok'=>true,'items'=>$rs,'total'=>$total,'progress'=>progress()]);
}

// HTML UI.
?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Racconti di Dominazione</title>
    <style>
        :root{--bg:#f4f5f7;--fg:#20242a;--muted:#69717d;--card:#fff;--border:#dfe3e8;--accent:#6c4cff;--star:#e2a800;--shadow:0 5px 22px rgba(0,0,0,.07)}
        [data-theme=dark]{--bg:#111318;--fg:#edf0f5;--muted:#9da6b2;--card:#1a1e25;--border:#303641;--accent:#9b87ff;--shadow:0 7px 28px rgba(0,0,0,.28)}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.wrap{max-width:1500px;margin:auto;padding:22px}.top{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--bg) 92%,transparent);backdrop-filter:blur(10px);padding-bottom:15px}.bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.bar h1{margin:0 20px 0 0;font-size:24px}.search{display:flex;flex:1;min-width:250px;gap:8px}.search input{flex:1}.input,select,button{border:1px solid var(--border);background:var(--card);color:var(--fg);border-radius:9px;padding:9px 11px;font:inherit}button{cursor:pointer}.primary{background:var(--accent);color:white;border-color:var(--accent)}.progress{margin-top:12px;background:var(--card);border:1px solid var(--border);padding:10px 12px;border-radius:10px}.track{height:8px;background:var(--border);border-radius:99px;overflow:hidden}.fill{height:100%;width:0;background:var(--accent);transition:width .25s}.status{display:flex;gap:18px;flex-wrap:wrap;color:var(--muted);margin-top:7px;font-size:13px}.chips{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0}.chip{border:1px solid var(--border);border-radius:99px;padding:4px 8px;background:var(--card)}.chip button{border:0;padding:0 0 0 5px;background:none;color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:18px}.card{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:17px;box-shadow:var(--shadow);min-height:220px;display:flex;flex-direction:column}.card h2{font-size:18px;line-height:1.25;margin:0 0 8px}.meta{font-size:13px;color:var(--muted);margin-bottom:11px}.preview{white-space:pre-line;color:var(--fg);opacity:.88;display:-webkit-box;-webkit-line-clamp:7;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer}.card .bottom{margin-top:auto;display:flex;justify-content:space-between;align-items:center;padding-top:12px}.stars{display:inline-flex;gap:1px}.star{font-size:22px;border:0;padding:0;background:none;color:#aeb5be;line-height:1}.star.on{color:var(--star)}.modal{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:20;display:none;padding:20px}.modal.show{display:block}.reader{max-width:1000px;height:100%;margin:auto;background:var(--card);color:var(--fg);border-radius:12px;overflow:auto;position:relative}.reader-head{position:sticky;top:0;background:var(--card);border-bottom:1px solid var(--border);padding:18px 24px;z-index:1}.reader-head h2{margin:0 35px 5px 0}.close{position:absolute;right:12px;top:10px;font-size:28px;border:0;background:none}.reader-body{padding:24px;font-size:18px;line-height:1.75;white-space:pre-wrap}.reader-body a{color:inherit}.loader{text-align:center;color:var(--muted);padding:30px}.hidden{display:none}@media(max-width:1050px){.grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:760px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.grid{grid-template-columns:1fr}.wrap{padding:12px}.bar h1{width:100%}}
    </style></head><body>
<div class="wrap"><div class="top"><div class="bar"><h1>Racconti</h1><div class="search"><input id="search" class="input" placeholder="Aggiungi una parola chiave e premi Invio"><button id="clearSearch" title="Cancella ricerca">×</button></div><select id="sort"><option value="date:desc">Data ↓</option><option value="date:asc">Data ↑</option><option value="title:asc">Alfabetico A→Z</option><option value="title:desc">Alfabetico Z→A</option><option value="rating:desc">Voto ↓</option><option value="rating:asc">Voto ↑</option></select><button id="theme">☾</button><button id="start" class="primary">Avvia / riprendi</button><button id="stop">Ferma</button></div><div id="chips" class="chips"></div><div class="progress"><div class="track"><div id="fill" class="fill"></div></div><div id="status" class="status"></div></div></div>
    <div id="grid" class="grid"></div><div id="loader" class="loader">Caricamento…</div></div>
<div id="modal" class="modal"><article class="reader"><div class="reader-head"><button class="close" id="close">×</button><h2 id="rt"></h2><div id="rm" class="meta"></div><div id="rs"></div></div><div id="rb" class="reader-body"></div></article></div>
<script>
    const S={offset:0,loading:false,more:true,words:[],sort:'date',dir:'desc',theme:localStorage.getItem('theme')||'light'};
    const $=id=>document.getElementById(id); document.documentElement.dataset.theme=S.theme;
    function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
    function stars(r,id){let h='<span class="stars">';for(let i=1;i<=5;i++)h+=`<button class="star ${i<=r?'on':''}" data-id="${id}" data-rating="${i}">★</button>`;return h+'</span>';}
    function chips(){ $('chips').innerHTML=S.words.map((w,i)=>`<span class="chip">${esc(w)} <button data-word="${i}">×</button></span>`).join('');}
    function query(){return S.words.join(' ')}
    async function api(u,opt){const r=await fetch(u,opt);return r.json();}
    async function load(reset=false){if(S.loading||(!S.more&&!reset))return;S.loading=true;if(reset){S.offset=0;S.more=true;$('grid').innerHTML='';} $('loader').textContent='Caricamento…';let [sort,dir]=$('sort').value.split(':');S.sort=sort;S.dir=dir;let p=new URLSearchParams({action:'stories',offset:S.offset,limit:16,sort,dir,q:query()});let d=await api('?'+p);for(const x of d.items){const el=document.createElement('article');el.className='card';el.innerHTML=`<h2>${esc(x.title)}</h2><div class="meta">${esc(x.author||'Autore non indicato')} · ${esc(x.published_at||'Data non indicata')}</div><div class="preview" data-id="${x.id}">${esc(x.preview)}</div><div class="bottom">${stars(x.rating,x.id)}<button class="read" data-id="${x.id}">Leggi</button></div>`;$('grid').appendChild(el);}S.offset+=d.items.length;S.more=S.offset<d.total;$('loader').textContent=S.more?'Scorri per caricare altri racconti':'Fine dei risultati';S.loading=false;updateProgress(d.progress);}
    function updateProgress(p){let pagePct=p.total_pages?Math.round(p.pages_done*100/p.total_pages):0;let storyPct=p.stories_total?Math.round(p.stories_done*100/p.stories_total):0;$('fill').style.width=Math.max(pagePct,storyPct)+'%';$('status').innerHTML=`Pagine: <b>${p.pages_done}/${p.total_pages}</b> · Racconti: <b>${p.stories_done}/${p.stories_total}</b> · In coda: ${p.stories_pending} · Stato: <b>${esc(p.worker)}</b>${p.last_error?' · '+esc(p.last_error):''}`;}
    async function progress(){try{let d=await api('?action=progress');updateProgress(d.progress);if(d.progress.worker==='running'&&S.timer==null)S.timer=setInterval(progress,1500);if(d.progress.worker!=='running'&&S.timer){clearInterval(S.timer);S.timer=null;load(true);}}catch(e){}}
    $('start').onclick=async()=>{let d=await api('?action=start');updateProgress(d.progress);if(d.spawned){if(!S.timer)S.timer=setInterval(progress,1500);}else alert('Il server non ha potuto avviare il worker CLI. Abilita proc_open/CLI PHP oppure configura un cron che esegua questo file con --worker.');load(true);};
    $('stop').onclick=async()=>{await api('?action=stop');progress();};
    $('theme').onclick=()=>{S.theme=S.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=S.theme;localStorage.setItem('theme',S.theme);};
    $('sort').onchange=()=>load(true);
    $('search').onkeydown=e=>{if(e.key==='Enter'){let v=e.target.value.trim();if(v&&!S.words.includes(v))S.words.push(v);e.target.value='';chips();load(true);}};
    $('clearSearch').onclick=()=>{S.words=[];chips();load(true);};
    $('chips').onclick=e=>{let i=e.target.dataset.word;if(i!==undefined){S.words.splice(+i,1);chips();load(true);}};
    $('grid').onclick=async e=>{let r=e.target.closest('.read,.preview');if(r){let d=await api('?action=story&id='+r.dataset.id);if(d.ok){$('rt').textContent=d.story.title;$('rm').textContent=(d.story.author||'Autore non indicato')+' · '+(d.story.published_at||'');$('rs').innerHTML=stars(d.story.rating,d.story.id);$('rb').textContent=d.story.content;$('modal').classList.add('show');}}
        let st=e.target.closest('.star');if(st){e.stopPropagation();await api('?action=rate',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id:st.dataset.id,rating:st.dataset.rating})});load(true);}}
    $('close').onclick=()=>$('modal').classList.remove('show');$('modal').onclick=e=>{if(e.target===$('modal'))$('modal').classList.remove('show');};document.addEventListener('keydown',e=>{if(e.key==='Escape')$('modal').classList.remove('show');});
    window.addEventListener('scroll',()=>{if(innerHeight+scrollY>document.documentElement.scrollHeight-700)load(false);});
    chips();load(true);progress();
</script></body></html>
