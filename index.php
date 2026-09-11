<?php
declare(strict_types=1);

/*
 * ============================================================
 *  ARCHIVIO RACCONTI - SINGLE FILE PHP
 * ============================================================
 *
 *  Tutto in un unico file:
 *    - PHP backend
 *    - SQLite database
 *    - sincronizzazione remota
 *    - API AJAX
 *    - HTML
 *    - CSS
 *    - JavaScript
 *
 *  File creati nella stessa directory:
 *
 *    racconti.sqlite
 *    sync.lock
 *
 *  Il vecchio racconti.json viene mantenuto e, alla prima
 *  esecuzione, viene importato automaticamente in SQLite.
 *
 * ============================================================
 */

define(
        'BASE_URL',
        'https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/'
);

define('DB_FILE', __DIR__ . '/racconti.sqlite');
define('LEGACY_JSON', __DIR__ . '/racconti.json');
define('SYNC_LOCK_FILE', __DIR__ . '/sync.lock');

define('STORIES_PER_PAGE', 24);

/*
 * Numero massimo di richieste HTTP contemporanee.
 *
 * 6 è un buon compromesso per un normale hosting condiviso.
 * Se il server remoto tollera bene il traffico puoi portarlo
 * a 8 o 10.
 */
define('DOWNLOAD_CONCURRENCY', 6);

/*
 * Timeout delle richieste HTTP.
 */
define('HTTP_CONNECT_TIMEOUT', 10);
define('HTTP_TIMEOUT', 30);

/*
 * Dopo quanto tempo permettere nuovamente una sincronizzazione
 * automatica.
 *
 * 0 = ad ogni apertura pagina viene sempre controllata la
 *     prima pagina remota.
 *
 * Questo è volutamente 0: puoi aggiornare la pagina quando vuoi.
 * Il controllo è comunque molto leggero perché vengono controllati
 * solo i nuovi URL.
 */
define('SYNC_MIN_INTERVAL', 0);


/* ============================================================
 * UTILITY
 * ============================================================ */

function json_response(array $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}


/* ============================================================
 * DATABASE
 * ============================================================ */

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        die(
        'Errore: l\'estensione PHP PDO SQLite non è disponibile sul server.'
        );
    }

    $pdo = new PDO('sqlite:' . DB_FILE);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    /*
     * Migliora sensibilmente le prestazioni SQLite.
     */
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    initialize_database($pdo);

    return $pdo;
}


function initialize_database(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $initialized = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stories (
            id TEXT PRIMARY KEY,
            url TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            rating INTEGER NOT NULL DEFAULT 0,
            timestamp INTEGER NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS processed_urls (
            url TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            timestamp INTEGER NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS metadata (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ");

    /*
     * Indici normali.
     */
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_stories_timestamp
        ON stories(timestamp DESC)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_stories_rating
        ON stories(rating DESC)
    ");

    /*
     * Proviamo ad attivare FTS5.
     *
     * Se la versione SQLite dell'hosting non lo supporta,
     * l'applicazione continua comunque a funzionare usando
     * LIKE come fallback.
     */
    try {
        $pdo->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS stories_fts
            USING fts5(
                title,
                content,
                content='stories',
                content_rowid='rowid'
            )
        ");

        $pdo->exec("
            CREATE TRIGGER IF NOT EXISTS stories_ai
            AFTER INSERT ON stories
            BEGIN
                INSERT INTO stories_fts(rowid, title, content)
                VALUES (new.rowid, new.title, new.content);
            END
        ");

        $pdo->exec("
            CREATE TRIGGER IF NOT EXISTS stories_ad
            AFTER DELETE ON stories
            BEGIN
                INSERT INTO stories_fts(
                    stories_fts,
                    rowid,
                    title,
                    content
                )
                VALUES (
                    'delete',
                    old.rowid,
                    old.title,
                    old.content
                );
            END
        ");

        $pdo->exec("
            CREATE TRIGGER IF NOT EXISTS stories_au
            AFTER UPDATE ON stories
            BEGIN
                INSERT INTO stories_fts(
                    stories_fts,
                    rowid,
                    title,
                    content
                )
                VALUES (
                    'delete',
                    old.rowid,
                    old.title,
                    old.content
                );

                INSERT INTO stories_fts(rowid, title, content)
                VALUES (new.rowid, new.title, new.content);
            END
        ");

        set_metadata($pdo, 'fts_enabled', '1');

    } catch (Throwable $e) {
        set_metadata($pdo, 'fts_enabled', '0');
    }

    /*
     * Importazione automatica del vecchio JSON.
     */
    migrate_legacy_json($pdo);
}


function get_metadata(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("
        SELECT value
        FROM metadata
        WHERE key = ?
        LIMIT 1
    ");

    $stmt->execute([$key]);

    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}


function set_metadata(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO metadata(key, value)
        VALUES (?, ?)
        ON CONFLICT(key)
        DO UPDATE SET value = excluded.value
    ");

    $stmt->execute([$key, $value]);
}


/* ============================================================
 * MIGRAZIONE DAL VECCHIO racconti.json
 * ============================================================ */

function migrate_legacy_json(PDO $pdo): void
{
    /*
     * Se abbiamo già effettuato la migrazione, non rileggiamo
     * mai più il JSON.
     */
    if (get_metadata($pdo, 'legacy_migration_done') === '1') {
        return;
    }

    if (!file_exists(LEGACY_JSON)) {
        set_metadata($pdo, 'legacy_migration_done', '1');
        return;
    }

    $json = @file_get_contents(LEGACY_JSON);

    if ($json === false || trim($json) === '') {
        set_metadata($pdo, 'legacy_migration_done', '1');
        return;
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        set_metadata($pdo, 'legacy_migration_done', '1');
        return;
    }

    /*
     * Supportiamo entrambi i formati:
     *
     * vecchio:
     * [
     *   {...},
     *   {...}
     * ]
     *
     * nuovo:
     * {
     *   "urls": {...},
     *   "stories": [...]
     * }
     */

    if (isset($data['stories']) && is_array($data['stories'])) {
        $stories = $data['stories'];
    } else {
        $stories = $data;
    }

    $pdo->beginTransaction();

    try {

        $insertStory = $pdo->prepare("
            INSERT OR IGNORE INTO stories
            (
                id,
                url,
                title,
                content,
                rating,
                timestamp
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $insertUrl = $pdo->prepare("
            INSERT OR IGNORE INTO processed_urls
            (
                url,
                status,
                timestamp
            )
            VALUES (?, ?, ?)
        ");

        foreach ($stories as $story) {

            if (!is_array($story)) {
                continue;
            }

            $url = trim((string)($story['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $title = trim((string)($story['title'] ?? ''));

            $content = (string)($story['content'] ?? '');

            $rating = max(
                    0,
                    min(
                            5,
                            (int)($story['rating'] ?? 0)
                    )
            );

            $timestamp = (int)($story['timestamp'] ?? time());

            if ($timestamp <= 0) {
                $timestamp = time();
            }

            $id = (string)($story['id'] ?? md5($url));

            /*
             * Se il vecchio racconto non ha titolo valido,
             * non lo mostriamo ma lo consideriamo comunque
             * già processato.
             */
            if (
                    $title === '' ||
                    mb_strtolower(trim($title)) === 'senza titolo'
            ) {

                $insertUrl->execute([
                        $url,
                        'skipped',
                        $timestamp
                ]);

                continue;
            }

            $insertStory->execute([
                    $id,
                    $url,
                    $title,
                    $content,
                    $rating,
                    $timestamp
            ]);

            $insertUrl->execute([
                    $url,
                    'saved',
                    $timestamp
            ]);
        }

        /*
         * Se il JSON contiene anche URL già scartati ma che non
         * sono presenti nell'array stories, li importiamo.
         */
        if (
                isset($data['urls']) &&
                is_array($data['urls'])
        ) {

            foreach ($data['urls'] as $url => $status) {

                if (!is_string($url) || $url === '') {
                    continue;
                }

                $status = ($status === 'skipped')
                        ? 'skipped'
                        : 'saved';

                $insertUrl->execute([
                        $url,
                        $status,
                        time()
                ]);
            }
        }

        $pdo->commit();

        set_metadata(
                $pdo,
                'legacy_migration_done',
                '1'
        );

        set_metadata(
                $pdo,
                'legacy_migration_time',
                (string)time()
        );

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
         * Non marchiamo la migrazione come completata:
         * al successivo accesso potrà essere ritentata.
         */
    }
}


/* ============================================================
 * HTTP
 * ============================================================ */

function http_get(string $url): ?string
{
    /*
     * Preferiamo cURL.
     */
    if (function_exists('curl_init')) {

        $ch = curl_init($url);

        curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => HTTP_TIMEOUT,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT =>
                        'Mozilla/5.0 (compatible; RaccontiArchive/2.0)',
                CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml',
                        'Accept-Language: it-IT,it;q=0.9'
                ]
        ]);

        $body = curl_exec($ch);

        $httpCode = (int)curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if (
                $body === false ||
                $httpCode < 200 ||
                $httpCode >= 400
        ) {
            return null;
        }

        return (string)$body;
    }

    /*
     * Fallback per server senza cURL.
     */
    $context = stream_context_create([
            'http' => [
                    'method' => 'GET',
                    'timeout' => HTTP_TIMEOUT,
                    'header' =>
                            "User-Agent: Mozilla/5.0 (compatible; RaccontiArchive/2.0)\r\n" .
                            "Accept: text/html,application/xhtml+xml\r\n"
            ]
    ]);

    $body = @file_get_contents(
            $url,
            false,
            $context
    );

    return $body === false ? null : $body;
}


/* ============================================================
 * PARSING HTML
 * ============================================================ */

function parse_story_urls(string $html): array
{
    $dom = new DOMDocument();

    @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html
    );

    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query(
            "//a[
            contains(concat(' ', normalize-space(@class), ' '), ' entry-title-link ')
            or
            contains(concat(' ', normalize-space(@class), ' '), ' post-title ')
            or
            @rel='bookmark'
        ]"
    );

    /*
     * Fallback.
     */
    if (!$nodes || $nodes->length === 0) {

        $nodes = $xpath->query(
                "//article//h2/a
            |
            //div[contains(@class, 'post')]//a"
        );
    }

    $urls = [];

    if (!$nodes) {
        return [];
    }

    foreach ($nodes as $node) {

        $href = trim(
                (string)$node->getAttribute('href')
        );

        if ($href === '') {
            continue;
        }

        /*
         * Convertiamo eventuali URL relativi.
         */
        if (
                !filter_var(
                        $href,
                        FILTER_VALIDATE_URL
                )
        ) {

            $base = rtrim(BASE_URL, '/');

            if (str_starts_with($href, '/')) {
                $href = 'https://raccontimilu.com' . $href;
            } else {
                $href = $base . '/' . ltrim($href, '/');
            }
        }

        if (
                filter_var(
                        $href,
                        FILTER_VALIDATE_URL
                ) &&
                !in_array($href, $urls, true)
        ) {
            $urls[] = $href;
        }
    }

    return $urls;
}


function parse_total_pages(string $html): int
{
    $dom = new DOMDocument();

    @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html
    );

    $xpath = new DOMXPath($dom);

    $totalPages = 1;

    $nodes = $xpath->query(
            "//nav[@id='pagination']//a[contains(@class, 'page-numbers')]
        |
        //a[contains(@class, 'page-numbers')]"
    );

    if (!$nodes) {
        return 1;
    }

    foreach ($nodes as $node) {

        $text = trim(
                $node->textContent
        );

        if (
                is_numeric($text) &&
                (int)$text > $totalPages
        ) {
            $totalPages = (int)$text;
        }
    }

    return max(1, $totalPages);
}


function parse_story(string $url, string $html): ?array
{
    $dom = new DOMDocument();

    @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html
    );

    $xpath = new DOMXPath($dom);

    /*
     * Titolo.
     */
    $titleNode = $xpath->query(
            "//h1[
            contains(concat(' ', normalize-space(@class), ' '), ' entry-title ')
            or
            contains(concat(' ', normalize-space(@class), ' '), ' post-title ')
        ]"
    );

    $title = '';

    if (
            $titleNode &&
            $titleNode->length > 0
    ) {
        $title = trim(
                $titleNode->item(0)->textContent
        );
    }

    $title = html_entity_decode(
            $title,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
    );

    /*
     * Racconti senza titolo:
     * vengono considerati processati ma non salvati.
     */
    if (
            $title === '' ||
            mb_strtolower(trim($title)) === 'senza titolo'
    ) {

        return [
                'status' => 'skipped',
                'url' => $url
        ];
    }

    /*
     * Contenuto.
     */
    $contentNode = $xpath->query(
            "//div[
            contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')
            or
            contains(concat(' ', normalize-space(@class), ' '), ' post-content ')
        ]"
    );

    $text = '';

    if (
            $contentNode &&
            $contentNode->length > 0
    ) {

        $container = $contentNode->item(0);

        $paragraphs = $xpath->query(
                './/p',
                $container
        );

        $cleanParagraphs = [];

        if ($paragraphs) {

            foreach ($paragraphs as $p) {

                $pText = trim(
                        $p->textContent
                );

                if (
                        $pText !== '' &&
                        mb_strlen($pText) > 2
                ) {

                    if (
                            stripos(
                                    $pText,
                                    'Condividi questo'
                            ) !== false
                    ) {
                        continue;
                    }

                    if (
                            stripos(
                                    $pText,
                                    'Mi piace:'
                            ) !== false
                    ) {
                        continue;
                    }

                    $cleanParagraphs[] = $pText;
                }
            }
        }

        if (!empty($cleanParagraphs)) {

            $text = implode(
                    "\n\n",
                    $cleanParagraphs
            );

        } else {

            $text = strip_tags(
                    $dom->saveHTML($container)
            );
        }
    }

    $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
    );

    /*
     * Normalizzazione spazi.
     */
    $text = preg_replace(
            '/[ \t]+/',
            ' ',
            $text
    );

    $text = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $text
    );

    $text = trim((string)$text);

    if ($text === '') {
        return [
                'status' => 'skipped',
                'url' => $url
        ];
    }

    return [
            'status' => 'saved',
            'id' => md5($url),
            'url' => $url,
            'title' => $title,
            'content' => $text,
            'rating' => 0,
            'timestamp' => time()
    ];
}


/* ============================================================
 * DATABASE - URL GIÀ PROCESSATI
 * ============================================================ */

function get_known_urls(PDO $pdo, array $urls): array
{
    if (empty($urls)) {
        return [];
    }

    /*
     * SQLite ha un limite sul numero di parametri.
     * Le pagine normalmente contengono pochi URL, ma dividiamo
     * comunque in blocchi.
     */
    $known = [];

    foreach (array_chunk($urls, 500) as $chunk) {

        $placeholders = implode(
                ',',
                array_fill(0, count($chunk), '?')
        );

        $stmt = $pdo->prepare("
            SELECT url
            FROM processed_urls
            WHERE url IN ($placeholders)
        ");

        $stmt->execute($chunk);

        while ($row = $stmt->fetch()) {
            $known[$row['url']] = true;
        }
    }

    return $known;
}


/* ============================================================
 * DOWNLOAD PARALLELO
 * ============================================================ */

function download_stories_parallel(array $urls): array
{
    if (empty($urls)) {
        return [];
    }

    /*
     * Se cURL non esiste, fallback sequenziale.
     */
    if (!function_exists('curl_multi_init')) {

        $results = [];

        foreach ($urls as $url) {

            $html = http_get($url);

            if ($html !== null) {

                $story = parse_story(
                        $url,
                        $html
                );

                if ($story !== null) {
                    $results[] = $story;
                }
            }
        }

        return $results;
    }

    $results = [];

    /*
     * Processiamo in piccoli batch.
     */
    foreach (
            array_chunk(
                    $urls,
                    DOWNLOAD_CONCURRENCY
            ) as $batch
    ) {

        $multi = curl_multi_init();

        $handles = [];

        foreach ($batch as $url) {

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
                    CURLOPT_TIMEOUT => HTTP_TIMEOUT,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT =>
                            'Mozilla/5.0 (compatible; RaccontiArchive/2.0)',
                    CURLOPT_HTTPHEADER => [
                            'Accept: text/html,application/xhtml+xml',
                            'Accept-Language: it-IT,it;q=0.9'
                    ]
            ]);

            curl_multi_add_handle(
                    $multi,
                    $ch
            );

            $handles[(int)$ch] = [
                    'handle' => $ch,
                    'url' => $url
            ];
        }

        /*
         * Esecuzione multi-cURL.
         */
        $running = null;

        do {

            $status = curl_multi_exec(
                    $multi,
                    $running
            );

            if ($running) {
                curl_multi_select(
                        $multi,
                        1.0
                );
            }

        } while (
                $running &&
                $status === CURLM_OK
        );

        /*
         * Recuperiamo i risultati.
         */
        foreach ($handles as $item) {

            $ch = $item['handle'];
            $url = $item['url'];

            $html = curl_multi_getcontent($ch);

            $httpCode = (int)curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
            );

            if (
                    $html !== false &&
                    $html !== '' &&
                    $httpCode >= 200 &&
                    $httpCode < 400
            ) {

                $story = parse_story(
                        $url,
                        $html
                );

                if ($story !== null) {
                    $results[] = $story;
                }
            }

            curl_multi_remove_handle(
                    $multi,
                    $ch
            );

            curl_close($ch);
        }

        curl_multi_close($multi);
    }

    return $results;
}


/* ============================================================
 * SALVATAGGIO BATCH
 * ============================================================ */

function save_stories_batch(PDO $pdo, array $stories): array
{
    if (empty($stories)) {
        return [
                'saved' => 0,
                'skipped' => 0
        ];
    }

    $saved = 0;
    $skipped = 0;

    $pdo->beginTransaction();

    try {

        $insertStory = $pdo->prepare("
            INSERT OR IGNORE INTO stories
            (
                id,
                url,
                title,
                content,
                rating,
                timestamp
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $insertUrl = $pdo->prepare("
            INSERT OR REPLACE INTO processed_urls
            (
                url,
                status,
                timestamp
            )
            VALUES (?, ?, ?)
        ");

        foreach ($stories as $story) {

            $url = $story['url'] ?? '';

            if ($url === '') {
                continue;
            }

            if (
                    ($story['status'] ?? '') === 'skipped'
            ) {

                $insertUrl->execute([
                        $url,
                        'skipped',
                        time()
                ]);

                $skipped++;

                continue;
            }

            $insertStory->execute([
                    $story['id'],
                    $url,
                    $story['title'],
                    $story['content'],
                    max(
                            0,
                            min(
                                    5,
                                    (int)$story['rating']
                            )
                    ),
                    (int)$story['timestamp']
            ]);

            $insertUrl->execute([
                    $url,
                    'saved',
                    (int)$story['timestamp']
            ]);

            if ($insertStory->rowCount() > 0) {
                $saved++;
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return [
            'saved' => $saved,
            'skipped' => $skipped
    ];
}


/* ============================================================
 * LOCK SINCRONIZZAZIONE
 * ============================================================ */

function acquire_sync_lock()
{
    $fp = @fopen(
            SYNC_LOCK_FILE,
            'c'
    );

    if (!$fp) {
        return false;
    }

    /*
     * LOCK_NB = non aspettare.
     *
     * Se un altro refresh sta sincronizzando, restituiamo
     * immediatamente "busy".
     */
    if (!flock($fp, LOCK_EX | LOCK_NB)) {

        fclose($fp);

        return false;
    }

    return $fp;
}


/* ============================================================
 * SINCRONIZZAZIONE
 * ============================================================ */

function synchronize(): array
{
    $pdo = db();

    /*
     * Evitiamo due sincronizzazioni contemporanee.
     */
    $lock = acquire_sync_lock();

    if ($lock === false) {

        return [
                'status' => 'busy',
                'message' =>
                        'Una sincronizzazione è già in corso.'
        ];
    }

    try {

        $storyCount = (int)$pdo->query("
            SELECT COUNT(*)
            FROM stories
        ")->fetchColumn();

        $processedCount = (int)$pdo->query("
            SELECT COUNT(*)
            FROM processed_urls
        ")->fetchColumn();

        /*
         * Archivio vuoto:
         *
         * dobbiamo fare la scansione completa.
         *
         * Archivio già popolato:
         *
         * partiamo dalla pagina 1 e continuiamo solo se troviamo
         * materiale nuovo.
         */
        $initialImport = (
                $storyCount === 0 &&
                $processedCount === 0
        );

        $totalPages = 1;
        $page = 1;

        $newStories = 0;
        $skipped = 0;
        $pagesChecked = 0;
        $errors = 0;

        /*
         * Prima pagina.
         */
        while (true) {

            $pageUrl = (
                    $page === 1
            )
                    ? BASE_URL
                    : BASE_URL . 'page/' . $page . '/';

            $html = http_get($pageUrl);

            if ($html === null) {

                $errors++;

                /*
                 * Se non riusciamo a leggere la pagina iniziale,
                 * interrompiamo.
                 */
                if ($page === 1) {

                    return [
                            'status' => 'error',
                            'message' =>
                                    'Impossibile leggere il sito remoto.',
                            'new_stories' => 0
                    ];
                }

                break;
            }

            $pagesChecked++;

            if ($page === 1) {
                $totalPages = parse_total_pages($html);
            }

            $urls = parse_story_urls($html);

            if (empty($urls)) {

                /*
                 * Pagina vuota.
                 */
                break;
            }

            $known = get_known_urls(
                    $pdo,
                    $urls
            );

            $newUrls = [];

            foreach ($urls as $url) {

                if (!isset($known[$url])) {
                    $newUrls[] = $url;
                }
            }

            /*
             * ----------------------------------------------------
             * CASO ARCHIVIO GIÀ POPOLATO
             * ----------------------------------------------------
             *
             * Se la pagina contiene solo URL già conosciuti,
             * possiamo fermarci immediatamente.
             *
             * Questo è il punto che elimina il problema originale:
             * normalmente una sincronizzazione richiede UNA SOLA
             * richiesta alla pagina 1.
             */
            if (!$initialImport && empty($newUrls)) {
                break;
            }

            /*
             * Scarichiamo SOLO gli URL nuovi.
             */
            if (!empty($newUrls)) {

                $stories = download_stories_parallel(
                        $newUrls
                );

                /*
                 * Gli URL che non hanno prodotto una risposta valida
                 * non vengono marcati come processed: potranno essere
                 * ritentati al prossimo refresh.
                 */
                if (!empty($stories)) {

                    $stats = save_stories_batch(
                            $pdo,
                            $stories
                    );

                    $newStories += $stats['saved'];
                    $skipped += $stats['skipped'];
                }

                /*
                 * Se una pagina contiene nuovi racconti, continuiamo
                 * con la pagina successiva.
                 *
                 * In questo modo, se durante un periodo sono stati
                 * pubblicati molti racconti, una singola sincronizzazione
                 * riesce a recuperarli tutti.
                 */
            }

            /*
             * Archivio iniziale:
             * dobbiamo arrivare fino all'ultima pagina.
             *
             * Archivio già popolato:
             * continuiamo solo perché questa pagina aveva nuovi URL.
             */
            if ($page >= $totalPages) {
                break;
            }

            $page++;

            /*
             * Sicurezza contro eventuali loop.
             */
            if ($page > 10000) {
                break;
            }
        }

        set_metadata(
                $pdo,
                'last_sync',
                (string)time()
        );

        set_metadata(
                $pdo,
                'last_sync_new_stories',
                (string)$newStories
        );

        return [
                'status' => 'success',
                'new_stories' => $newStories,
                'skipped' => $skipped,
                'pages_checked' => $pagesChecked,
                'initial_import' => $initialImport,
                'errors' => $errors
        ];

    } finally {

        flock(
                $lock,
                LOCK_UN
        );

        fclose($lock);
    }
}


/* ============================================================
 * API
 * ============================================================ */

if (isset($_GET['action'])) {

    $action = (string)$_GET['action'];

    /*
     * ----------------------------------------------------------
     * SINCRONIZZAZIONE
     * ----------------------------------------------------------
     */
    if (
            $action === 'sync' &&
            $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {

        try {

            json_response(
                    synchronize()
            );

        } catch (Throwable $e) {

            json_response([
                    'status' => 'error',
                    'message' =>
                            'Errore durante la sincronizzazione: ' .
                            $e->getMessage()
            ], 500);
        }
    }


    /*
     * ----------------------------------------------------------
     * GET STORIES
     * ----------------------------------------------------------
     */
    if ($action === 'get_stories') {

        try {

            $pdo = db();

            $page = max(
                    1,
                    (int)($_GET['page'] ?? 1)
            );

            $limit = STORIES_PER_PAGE;

            $offset = (
                            $page - 1
                    ) * $limit;

            $sort = (string)(
                    $_GET['sort'] ?? 'newest'
            );

            if (
                    $sort !== 'rating' &&
                    $sort !== 'newest'
            ) {
                $sort = 'newest';
            }

            $keywords = [];

            if (
                    isset($_GET['keywords'])
            ) {

                $decoded = json_decode(
                        (string)$_GET['keywords'],
                        true
                );

                if (
                        is_array($decoded)
                ) {

                    foreach ($decoded as $keyword) {

                        $keyword = trim(
                                (string)$keyword
                        );

                        if ($keyword !== '') {
                            $keywords[] = $keyword;
                        }
                    }
                }
            }

            $ftsEnabled =
                    get_metadata(
                            $pdo,
                            'fts_enabled',
                            '0'
                    ) === '1';

            $where = [];
            $params = [];

            /*
             * Ricerca.
             */
            if (
                    !empty($keywords)
            ) {

                if ($ftsEnabled) {

                    /*
                     * Ogni keyword viene quotata e collegata con AND.
                     */
                    $ftsTerms = [];

                    foreach ($keywords as $keyword) {

                        /*
                         * Escape delle virgolette per FTS5.
                         */
                        $safe = str_replace(
                                '"',
                                '""',
                                $keyword
                        );

                        $ftsTerms[] =
                                '"' . $safe . '"';
                    }

                    $match = implode(
                            ' AND ',
                            $ftsTerms
                    );

                    $where[] = "
                        stories.rowid IN (
                            SELECT rowid
                            FROM stories_fts
                            WHERE stories_fts MATCH :fts_match
                        )
                    ";

                    $params[':fts_match'] = $match;

                } else {

                    /*
                     * Fallback LIKE.
                     *
                     * Tutte le parole devono essere presenti.
                     */
                    foreach (
                            $keywords as $index => $keyword
                    ) {

                        $param = ':kw' . $index;

                        $where[] = "
                            (
                                title LIKE $param
                                OR
                                content LIKE $param
                            )
                        ";

                        $params[$param] =
                                '%' . $keyword . '%';
                    }
                }
            }

            $whereSql = '';

            if (!empty($where)) {

                $whereSql =
                        'WHERE ' .
                        implode(
                                ' AND ',
                                $where
                        );
            }

            /*
             * Ordinamento.
             *
             * Come nel vecchio codice:
             *
             * 1. prima i racconti votati
             * 2. poi, in base alla modalità:
             *      rating
             *      oppure data
             */
            if ($sort === 'rating') {

                $orderSql = "
                    CASE WHEN rating > 0 THEN 0 ELSE 1 END,
                    rating DESC,
                    timestamp DESC
                ";

            } else {

                $orderSql = "
                    CASE WHEN rating > 0 THEN 0 ELSE 1 END,
                    timestamp DESC
                ";
            }

            /*
             * Totale.
             */
            $countSql = "
                SELECT COUNT(*)
                FROM stories
                $whereSql
            ";

            $stmt = $pdo->prepare(
                    $countSql
            );

            foreach ($params as $key => $value) {
                $stmt->bindValue(
                        $key,
                        $value,
                        PDO::PARAM_STR
                );
            }

            $stmt->execute();

            $total = (int)$stmt->fetchColumn();

            /*
             * Racconti.
             */
            $sql = "
                SELECT
                    id,
                    url,
                    title,
                    content,
                    rating,
                    timestamp
                FROM stories
                $whereSql
                ORDER BY $orderSql
                LIMIT :limit
                OFFSET :offset
            ";

            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $stmt->bindValue(
                        $key,
                        $value,
                        PDO::PARAM_STR
                );
            }

            $stmt->bindValue(
                    ':limit',
                    $limit,
                    PDO::PARAM_INT
            );

            $stmt->bindValue(
                    ':offset',
                    $offset,
                    PDO::PARAM_INT
            );

            $stmt->execute();

            $stories = $stmt->fetchAll();

            json_response([
                    'status' => 'success',
                    'total' => $total,
                    'page' => $page,
                    'has_more' =>
                            ($offset + $limit) < $total,
                    'stories' => $stories
            ]);

        } catch (Throwable $e) {

            json_response([
                    'status' => 'error',
                    'message' => $e->getMessage()
            ], 500);
        }
    }


    /*
     * ----------------------------------------------------------
     * VOTO
     * ----------------------------------------------------------
     */
    if (
            $action === 'rate_story' &&
            $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {

        try {

            $input = json_decode(
                    file_get_contents('php://input'),
                    true
            );

            $id = trim(
                    (string)($input['id'] ?? '')
            );

            $rating = max(
                    1,
                    min(
                            5,
                            (int)($input['rating'] ?? 0)
                    )
            );

            if ($id === '') {

                json_response([
                        'status' => 'error'
                ], 400);
            }

            $pdo = db();

            $stmt = $pdo->prepare("
                UPDATE stories
                SET rating = ?
                WHERE id = ?
            ");

            $stmt->execute([
                    $rating,
                    $id
            ]);

            if ($stmt->rowCount() > 0) {

                json_response([
                        'status' => 'success'
                ]);
            }

            json_response([
                    'status' => 'error'
            ], 404);

        } catch (Throwable $e) {

            json_response([
                    'status' => 'error',
                    'message' => $e->getMessage()
            ], 500);
        }
    }


    /*
     * ----------------------------------------------------------
     * STATISTICHE / STATO
     * ----------------------------------------------------------
     */
    if ($action === 'status') {

        try {

            $pdo = db();

            $count = (int)$pdo->query("
                SELECT COUNT(*)
                FROM stories
            ")->fetchColumn();

            $lastSync = (int)(
            get_metadata(
                    $pdo,
                    'last_sync',
                    '0'
            )
            );

            json_response([
                    'status' => 'success',
                    'stories' => $count,
                    'last_sync' => $lastSync
            ]);

        } catch (Throwable $e) {

            json_response([
                    'status' => 'error'
            ], 500);
        }
    }


    json_response([
            'status' => 'error',
            'message' => 'Azione non riconosciuta.'
    ], 404);
}


/* ============================================================
 * HTML
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">

<head>

    <meta charset="UTF-8">

    <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
    >

    <title>Racconti Erotici - Sulla Dominazione</title>

    <style>

        :root {
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #333333;
            --text-muted: #6c757d;
            --primary: #007bff;
            --border-color: #ced4da;
            --shadow: rgba(0,0,0,0.05);
            --star-color: #e4e5e9;
            --star-active: #ffc107;
        }

        [data-theme="dark"] {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --text-muted: #a0a0a0;
            --primary: #bb86fc;
            --border-color: #333333;
            --shadow: rgba(0,0,0,0.5);
            --star-color: #444444;
            --star-active: #ffbb00;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    Roboto,
                    sans-serif;

            background: var(--bg-color);
            color: var(--text-color);

            margin: 0;
            padding: 20px;

            transition:
                    background 0.3s,
                    color 0.3s;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-bottom: 20px;

            flex-wrap: wrap;
            gap: 15px;
        }

        h1 {
            font-size: 24px;
            margin: 0;
        }

        .header-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .theme-toggle,
        .sort-select,
        .sync-button {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);

            padding: 8px 15px;

            border-radius: 20px;

            cursor: pointer;

            font-size: 14px;
            font-weight: 600;
        }

        .sync-button {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .sync-button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        #status-bar {
            text-align: center;

            font-size: 13px;
            color: var(--text-muted);

            margin-bottom: 20px;

            background: var(--card-bg);

            padding: 10px;

            border-radius: 6px;

            border: 1px solid var(--border-color);

            min-height: 18px;
        }

        #search-container {
            background: var(--card-bg);

            padding: 15px;

            border-radius: 8px;

            border: 1px solid var(--border-color);

            margin-bottom: 25px;
        }

        #tag-input {
            width: 100%;

            padding: 10px;

            font-size: 15px;

            border: 1px solid var(--border-color);

            background: var(--bg-color);

            color: var(--text-color);

            border-radius: 4px;
        }

        #tags-list {
            display: flex;
            flex-wrap: wrap;

            gap: 8px;

            margin-top: 10px;
        }

        .tag {
            background: var(--primary);

            color: white;

            padding: 6px 12px;

            border-radius: 20px;

            font-size: 13px;

            display: inline-flex;

            align-items: center;

            gap: 8px;
        }

        .tag .remove-tag {
            cursor: pointer;

            font-weight: bold;

            background: rgba(255,255,255,0.2);

            border-radius: 50%;

            width: 18px;
            height: 18px;

            display: inline-flex;

            align-items: center;
            justify-content: center;
        }

        #stories-container {
            display: grid;

            grid-template-columns: repeat(1, 1fr);

            gap: 20px;
        }

        @media (min-width: 600px) {
            #stories-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 992px) {
            #stories-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (min-width: 1300px) {
            #stories-container {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .story-card {
            background: var(--card-bg);

            padding: 20px;

            border-radius: 8px;

            border: 1px solid var(--border-color);

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            box-shadow:
                    0 2px 5px var(--shadow);
        }

        .story-card h3 {
            margin-top: 0;

            color: var(--primary);

            font-size: 17px;

            cursor: pointer;
        }

        .story-content-preview {
            line-height: 1.5;

            max-height: 90px;

            overflow: hidden;

            color: var(--text-muted);

            font-size: 13px;

            margin-bottom: 15px;

            cursor: pointer;

            white-space: pre-line;
        }

        .rating-stars {
            display: flex;

            gap: 4px;

            align-items: center;

            margin-top: 10px;

            border-top: 1px solid var(--border-color);

            padding-top: 10px;
        }

        .star-container {
            display: inline-flex;

            flex-direction: row-reverse;

            justify-content: flex-end;
        }

        .star-container input {
            display: none;
        }

        .star-container label {
            font-size: 22px;

            color: var(--star-color);

            cursor: pointer;
        }

        .star-container label:hover,
        .star-container label:hover ~ label,
        .star-container input:checked ~ label {
            color: var(--star-active);
        }

        #modal-overlay {
            position: fixed;

            top: 0;
            left: 0;

            width: 100%;
            height: 100%;

            background: rgba(0,0,0,0.75);

            display: none;

            justify-content: center;
            align-items: center;

            z-index: 1000;

            padding: 20px;
        }

        #modal-content {
            background: var(--card-bg);

            color: var(--text-color);

            width: 100%;

            max-width: 900px;

            height: 85vh;

            border-radius: 12px;

            padding: 30px;

            display: flex;

            flex-direction: column;

            border: 1px solid var(--border-color);

            position: relative;
        }

        #modal-body {
            overflow-y: auto;

            line-height: 1.8;

            font-size: 16px;

            flex-grow: 1;

            white-space: pre-wrap;
        }

        .close-modal {
            position: absolute;

            top: 20px;
            right: 25px;

            background: none;

            border: none;

            font-size: 28px;

            color: var(--text-color);

            cursor: pointer;
        }

        #loading {
            text-align: center;

            padding: 30px;

            font-weight: bold;

            color: var(--text-muted);

            grid-column: 1 / -1;

            display: none;
        }

        #sync-indicator {
            display: inline-block;

            width: 8px;
            height: 8px;

            border-radius: 50%;

            background: var(--text-muted);

            margin-right: 5px;
        }

    </style>

</head>

<body>

<div class="container">

    <header>

        <h1>Racconti di Dominazione</h1>

        <div class="header-controls">

            <select
                    id="sort-select"
                    class="sort-select"
                    onchange="changeSort()"
            >
                <option value="newest">
                    🕒 Più recenti
                </option>

                <option value="rating">
                    ⭐ Voto più alto
                </option>
            </select>

            <button
                    class="sync-button"
                    id="sync-button"
                    onclick="manualSync()"
            >
                🔄 Aggiorna
            </button>

            <button
                    class="theme-toggle"
                    onclick="toggleTheme()"
            >
                🌓 Tema
            </button>

        </div>

    </header>

    <div id="status-bar">
        <span id="sync-indicator"></span>
        Caricamento archivio locale...
    </div>

    <div id="search-container">

        <input
                type="text"
                id="tag-input"
                placeholder="Scrivi una parola chiave e premi Invio per aggiungere un filtro (AND)..."
        >

        <div id="tags-list"></div>

    </div>

    <div id="stories-container"></div>

    <div id="loading">
        Caricamento altri racconti...
    </div>

</div>


<div
        id="modal-overlay"
        onclick="closeModalOnOutside(event)"
>

    <div id="modal-content">

        <button
                class="close-modal"
                onclick="closeModal()"
        >
            &times;
        </button>

        <div
                style="
                margin-bottom:20px;
                border-bottom:1px solid var(--border-color);
                padding-bottom:15px;
            "
        >

            <h2
                    id="modal-title"
                    style="
                    margin:0 0 10px 0;
                    color:var(--primary);
                "
            ></h2>

            <div
                    id="modal-stars"
                    class="rating-stars"
            ></div>

        </div>

        <div id="modal-body"></div>

    </div>

</div>


<script>

    /* ============================================================
     * STATO
     * ============================================================ */

    let keywords = [];

    let currentPage = 1;

    let isLoading = false;

    let hasMore = true;

    let currentSort = 'newest';

    let globalStoriesMap = {};

    let syncRunning = false;


    /* ============================================================
     * TEMA
     * ============================================================ */

    function toggleTheme() {

        const html = document.documentElement;

        const newTheme =
            html.getAttribute('data-theme') === 'dark'
                ? 'light'
                : 'dark';

        html.setAttribute(
            'data-theme',
            newTheme
        );

        localStorage.setItem(
            'theme',
            newTheme
        );
    }


    if (
        localStorage.getItem('theme') === 'dark'
    ) {

        document.documentElement.setAttribute(
            'data-theme',
            'dark'
        );
    }


    /* ============================================================
     * ORDINAMENTO
     * ============================================================ */

    function changeSort() {

        currentSort =
            document.getElementById(
                'sort-select'
            ).value;

        resetAndLoad();
    }


    /* ============================================================
     * STATUS
     * ============================================================ */

    function setStatus(
        text,
        syncing = false
    ) {

        document.getElementById(
            'status-bar'
        ).innerHTML =
            '<span id="sync-indicator"></span>' +
            escapeHtml(text);

        const indicator =
            document.getElementById(
                'sync-indicator'
            );

        if (syncing) {

            indicator.style.background =
                'var(--primary)';

            indicator.style.animation =
                'pulse 1s infinite';

        } else {

            indicator.style.background =
                'var(--text-muted)';

            indicator.style.animation =
                'none';
        }
    }


    /* ============================================================
     * TAG
     * ============================================================ */

    const tagInput =
        document.getElementById(
            'tag-input'
        );


    tagInput.addEventListener(
        'keydown',
        function(e) {

            if (e.key !== 'Enter') {
                return;
            }

            e.preventDefault();

            const val =
                this.value.trim();

            if (
                val &&
                !keywords.includes(val)
            ) {

                keywords.push(val);

                this.value = '';

                renderTags();

                resetAndLoad();
            }
        }
    );


    function removeKeyword(index) {

        keywords.splice(
            index,
            1
        );

        renderTags();

        resetAndLoad();
    }


    function renderTags() {

        const container =
            document.getElementById(
                'tags-list'
            );

        container.innerHTML = '';

        keywords.forEach(
            (kw, index) => {

                const tag =
                    document.createElement(
                        'div'
                    );

                tag.className = 'tag';

                tag.innerHTML =
                    escapeHtml(kw) +
                    ' <span class="remove-tag" ' +
                    'onclick="removeKeyword(' +
                    index +
                    ')">&times;</span>';

                container.appendChild(tag);
            }
        );
    }


    /* ============================================================
     * STELLE
     * ============================================================ */

    function renderStarRating(
        storyId,
        currentRating
    ) {

        let html =
            '<div class="star-container">';

        for (
            let i = 5;
            i >= 1;
            i--
        ) {

            const checked =
                i === Number(currentRating)
                    ? 'checked'
                    : '';

            html +=
                '<input ' +
                'type="radio" ' +
                'id="star-' +
                storyId +
                '-' +
                i +
                '" ' +
                'name="rating-' +
                storyId +
                '" ' +
                'value="' +
                i +
                '" ' +
                checked +
                ' ' +
                'onclick="rateStory(\'' +
                storyId +
                '\', ' +
                i +
                ')">' +

                '<label for="star-' +
                storyId +
                '-' +
                i +
                '">' +
                '&#9733;' +
                '</label>';
        }

        return html +
            '</div>';
    }


    /* ============================================================
     * CARICAMENTO RACCONTI
     * ============================================================ */

    function loadStories(
        append = false
    ) {

        if (isLoading) {
            return;
        }

        isLoading = true;

        document.getElementById(
            'loading'
        ).style.display = 'block';

        const kwParam =
            encodeURIComponent(
                JSON.stringify(keywords)
            );

        fetch(
            '?action=get_stories' +
            '&page=' +
            currentPage +
            '&keywords=' +
            kwParam +
            '&sort=' +
            encodeURIComponent(
                currentSort
            ),
            {
                cache: 'no-store'
            }
        )

            .then(res => res.json())

            .then(data => {

                if (
                    data.status !== 'success'
                ) {
                    throw new Error(
                        'Errore caricamento'
                    );
                }

                hasMore =
                    Boolean(data.has_more);

                const container =
                    document.getElementById(
                        'stories-container'
                    );

                if (!append) {

                    container.innerHTML = '';

                    globalStoriesMap = {};
                }

                if (
                    data.stories.length === 0 &&
                    !append
                ) {

                    container.innerHTML =
                        '<div style="' +
                        'text-align:center;' +
                        'color:var(--text-muted);' +
                        'padding:40px;' +
                        'grid-column:1/-1;">' +

                        'Nessun racconto trovato ' +
                        'nell\'archivio locale.' +

                        '</div>';

                } else {

                    /*
                     * Non sovrascriviamo lo status di sincronizzazione
                     * se in quel momento sta lavorando.
                     */
                    if (!syncRunning) {

                        setStatus(
                            'Racconti presenti in archivio: ' +
                            data.total
                        );
                    }
                }

                data.stories.forEach(
                    story => {

                        globalStoriesMap[
                            story.id
                            ] = story;

                        const card =
                            document.createElement(
                                'div'
                            );

                        card.className =
                            'story-card';

                        card.innerHTML =
                            '<div>' +

                            '<h3 onclick="openModal(\'' +
                            escapeJs(story.id) +
                            '\')">' +

                            escapeHtml(
                                story.title
                            ) +

                            '</h3>' +

                            '<div ' +
                            'class="story-content-preview" ' +
                            'onclick="openModal(\'' +
                            escapeJs(story.id) +
                            '\')">' +

                            escapeHtml(
                                story.content
                            ) +

                            '</div>' +

                            '</div>' +

                            '<div class="rating-stars">' +

                            renderStarRating(
                                story.id,
                                story.rating
                            ) +

                            '</div>';

                        container.appendChild(
                            card
                        );
                    }
                );

                isLoading = false;

                document.getElementById(
                    'loading'
                ).style.display = 'none';

            })

            .catch(() => {

                isLoading = false;

                document.getElementById(
                    'loading'
                ).style.display = 'none';

                if (!syncRunning) {

                    setStatus(
                        'Errore durante il caricamento dell\'archivio.'
                    );
                }
            });
    }


    function resetAndLoad() {

        currentPage = 1;

        hasMore = true;

        loadStories(false);
    }


    /* ============================================================
     * VOTO
     * ============================================================ */

    function rateStory(
        id,
        rating
    ) {

        const cleanId =
            id.toString()
                .replace('modal-', '');

        fetch(
            '?action=rate_story',
            {
                method: 'POST',

                headers: {
                    'Content-Type':
                        'application/json'
                },

                body: JSON.stringify({
                    id: cleanId,
                    rating: rating
                })
            }
        )

            .then(res => res.json())

            .then(data => {

                if (
                    data.status === 'success'
                ) {

                    if (
                        globalStoriesMap[
                            cleanId
                            ]
                    ) {

                        globalStoriesMap[
                            cleanId
                            ].rating = rating;
                    }

                    /*
                     * Aggiorniamo la visualizzazione senza
                     * dover riscaricare i racconti dal server.
                     */
                    updateVisibleRatings(
                        cleanId,
                        rating
                    );
                }
            });
    }


    function updateVisibleRatings(
        id,
        rating
    ) {

        const story =
            globalStoriesMap[id];

        if (!story) {
            return;
        }

        /*
         * Se il modal è aperto, aggiorniamo anche quello.
         */
        const modalStars =
            document.getElementById(
                'modal-stars'
            );

        if (
            document.getElementById(
                'modal-overlay'
            ).style.display === 'flex'
        ) {

            modalStars.innerHTML =
                renderStarRating(
                    'modal-' + id,
                    rating
                );
        }

        /*
         * La pagina viene ricaricata localmente dal DB.
         * Nessun download remoto.
         */
        resetAndLoad();
    }


    /* ============================================================
     * MODAL
     * ============================================================ */

    function openModal(
        storyId
    ) {

        const story =
            globalStoriesMap[storyId];

        if (!story) {
            return;
        }

        document.getElementById(
            'modal-title'
        ).innerText =
            story.title;

        document.getElementById(
            'modal-body'
        ).innerText =
            story.content;

        document.getElementById(
            'modal-stars'
        ).innerHTML =
            renderStarRating(
                'modal-' + story.id,
                story.rating
            );

        document.getElementById(
            'modal-overlay'
        ).style.display = 'flex';

        document.body.style.overflow =
            'hidden';
    }


    function closeModal() {

        document.getElementById(
            'modal-overlay'
        ).style.display = 'none';

        document.body.style.overflow =
            'auto';
    }


    function closeModalOnOutside(
        event
    ) {

        if (
            event.target.id ===
            'modal-overlay'
        ) {
            closeModal();
        }
    }


    /* ============================================================
     * SINCRONIZZAZIONE
     * ============================================================ */

    function syncRemote(
        manual = false
    ) {

        if (syncRunning) {
            return;
        }

        syncRunning = true;

        const button =
            document.getElementById(
                'sync-button'
            );

        button.disabled = true;

        setStatus(
            manual
                ? 'Controllo del sito remoto...'
                : 'Controllo aggiornamenti...',
            true
        );

        fetch(
            '?action=sync',
            {
                method: 'POST',
                cache: 'no-store'
            }
        )

            .then(res => res.json())

            .then(data => {

                if (
                    data.status === 'busy'
                ) {

                    setStatus(
                        'Un\'altra sincronizzazione è già in corso.'
                    );

                    return;
                }

                if (
                    data.status !== 'success'
                ) {

                    throw new Error(
                        data.message ||
                        'Errore sincronizzazione'
                    );
                }

                const newStories =
                    Number(
                        data.new_stories || 0
                    );

                const pages =
                    Number(
                        data.pages_checked || 0
                    );

                if (newStories > 0) {

                    setStatus(
                        'Sincronizzazione completata: ' +
                        newStories +
                        ' nuovi racconti.'
                    );

                    /*
                     * Aggiorniamo l'elenco locale.
                     */
                    currentPage = 1;

                    loadStories(false);

                } else {

                    setStatus(
                        'Archivio aggiornato: nessun nuovo racconto.'
                    );
                }

            })

            .catch(() => {

                setStatus(
                    'Impossibile sincronizzare il sito remoto. ' +
                    'L\'archivio locale resta disponibile.'
                );

            })

            .finally(() => {

                syncRunning = false;

                button.disabled = false;
            });
    }


    function manualSync() {

        syncRemote(true);
    }


    /* ============================================================
     * SICUREZZA HTML / JS
     * ============================================================ */

    function escapeHtml(
        text
    ) {

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(text)
            .replace(
                /[&<>"']/g,
                m => map[m]
            );
    }


    function escapeJs(
        text
    ) {

        return String(text)
            .replace(
                /\\/g,
                '\\\\'
            )
            .replace(
                /'/g,
                "\\'"
            )
            .replace(
                /"/g,
                '\\"'
            )
            .replace(
                /\r/g,
                '\\r'
            )
            .replace(
                /\n/g,
                '\\n'
            );
    }


    /* ============================================================
     * SCROLL INFINITO
     * ============================================================ */

    window.addEventListener(
        'scroll',
        () => {

            if (
                window.innerHeight +
                window.scrollY >=
                document.body.offsetHeight - 500
            ) {

                if (
                    hasMore &&
                    !isLoading
                ) {

                    currentPage++;

                    loadStories(true);
                }
            }
        }
    );


    /* ============================================================
     * AVVIO
     * ============================================================ */

    /*
     * 1. Mostriamo IMMEDIATAMENTE l'archivio locale.
     *
     * 2. In parallelo avviamo il controllo remoto.
     *
     * Quindi l'utente non deve aspettare la sincronizzazione
     * per poter leggere i racconti già presenti.
     */
    loadStories(false);

    syncRemote(false);

</script>

</body>
</html>

