<?php
// ==========================================
// BACKEND PHP (API & LOGICA DI SCRAPING)
// ==========================================
session_start();

// Configurazione DB SQLite
$dbFile = __DIR__ . '/racconti.sqlite';
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Creazione tabella se non esiste
$pdo->exec("CREATE TABLE IF NOT EXISTS stories (
    hash TEXT PRIMARY KEY,
    title TEXT,
    author TEXT,
    pub_date TEXT,
    content TEXT,
    url TEXT,
    rating INTEGER DEFAULT 0
)");

// Funzione helper per le richieste cURL
function fetchHtml($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

// Router API
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    libxml_use_internal_errors(true);

    try {
        switch ($_GET['api']) {
            case 'get_max_pages':
                $html = fetchHtml("https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/");
                $dom = new DOMDocument();
                @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                $xpath = new DOMXPath($dom);

                $pages = $xpath->query('//nav[@id="pagination"]//a[contains(@class, "page-numbers")]');
                $max = 1;
                foreach ($pages as $node) {
                    $val = intval(trim($node->nodeValue));
                    if ($val > $max) $max = $val;
                }
                echo json_encode(['max_pages' => $max]);
                break;

            case 'fetch_page':
                $page = intval($_GET['p']);
                $url = "https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/" . ($page > 1 ? "page/$page/" : "");
                $html = fetchHtml($url);
                $dom = new DOMDocument();
                @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                $xpath = new DOMXPath($dom);

                $nodes = $xpath->query('//article//div[contains(@class, "post-header")]//h3[contains(@class, "title")]/a | //article//h3/a | //article//h2/a');
                $urls = [];

                // Seleziona i link non ancora presenti o con contenuto fallito
                $stmt = $pdo->prepare("SELECT content FROM stories WHERE hash = ?");

                foreach ($nodes as $node) {
                    $storyUrl = $node->getAttribute('href');
                    if (!empty($storyUrl)) {
                        $hash = md5($storyUrl);
                        $stmt->execute([$hash]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$row || $row['content'] === 'Contenuto non trovato.' || empty($row['content'])) {
                            $urls[] = $storyUrl;
                        }
                    }
                }
                echo json_encode(['urls' => array_values(array_unique($urls))]);
                break;

            case 'fetch_story':
                $url = $_GET['url'];
                $hash = md5($url);

                $html = fetchHtml($url);
                if (!$html) {
                    echo json_encode(['success' => false, 'error' => 'Impossibile scaricare la pagina']);
                    break;
                }

                $dom = new DOMDocument();
                @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                $xpath = new DOMXPath($dom);

                // Estrazione Titolo
                $titleNode = $xpath->query('//h1[contains(@class, "title") or contains(@class, "entry-title") or contains(@class, "post-title")] | //h1')->item(0);
                $title = $titleNode ? trim($titleNode->nodeValue) : 'Titolo Sconosciuto';

                // Estrazione Autore
                $authorNode = $xpath->query('//a[@rel="author"] | //span[contains(@class, "author")] | //div[contains(@class, "author")]')->item(0);
                $author = $authorNode ? trim($authorNode->nodeValue) : 'Anonimo';

                // Estrazione Data
                $dateNode = $xpath->query('//time | //span[contains(@class, "date")] | //span[contains(@class, "posted-on")]')->item(0);
                $date = $dateNode ? trim($dateNode->nodeValue) : '';

                // Estrazione Contenuto
                $contentNode = $xpath->query(
                        '//div[contains(@class, "entry-content")] | ' .
                        '//div[contains(@class, "post-content")] | ' .
                        '//div[contains(@class, "post-entry")] | ' .
                        '//div[contains(@class, "pf-content")] | ' .
                        '//div[contains(@class, "story-content")] | ' .
                        '//article//div[contains(@class, "content")]'
                )->item(0);

                $rawHtml = '';
                if ($contentNode) {
                    $rawHtml = $dom->saveHTML($contentNode);
                } else {
                    $paragraphs = $xpath->query('//article//p | //main//p');
                    if ($paragraphs->length > 0) {
                        foreach ($paragraphs as $p) {
                            $rawHtml .= $dom->saveHTML($p);
                        }
                    }
                }

                if (!empty(trim($rawHtml))) {
                    $cleanContent = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $rawHtml);
                } else {
                    $cleanContent = "Contenuto non trovato.";
                }

                $stmt = $pdo->prepare("
                    INSERT INTO stories (hash, title, author, pub_date, content, url) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(hash) DO UPDATE SET 
                        title = excluded.title,
                        author = excluded.author,
                        pub_date = excluded.pub_date,
                        content = excluded.content,
                        url = excluded.url
                    WHERE content = 'Contenuto non trovato.' OR content = ''
                ");
                $stmt->execute([$hash, $title, $author, $date, $cleanContent, $url]);

                echo json_encode(['success' => true, 'hash' => $hash]);
                break;

            case 'get_stories':
                $limit = 16;
                $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
                $sort = $_GET['sort'] ?? 'date_desc';

                $keywords = isset($_GET['keywords']) ? json_decode($_GET['keywords'], true) : [];
                $whereClauses = [];
                $params = [];

                if (is_array($keywords)) {
                    foreach ($keywords as $i => $kw) {
                        $whereClauses[] = "(title LIKE :kw$i OR content LIKE :kw$i)";
                        $params[":kw$i"] = "%" . $kw . "%";
                    }
                }

                $whereSql = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

                switch ($sort) {
                    case 'date_asc':
                        $orderSql = "ORDER BY pub_date ASC";
                        break;
                    case 'title_asc':
                    case 'title':
                        $orderSql = "ORDER BY title ASC";
                        break;
                    case 'title_desc':
                        $orderSql = "ORDER BY title DESC";
                        break;
                    case 'rating_desc':
                    case 'rating':
                        $orderSql = "ORDER BY rating DESC";
                        break;
                    case 'date_desc':
                    case 'date':
                    default:
                        $orderSql = "ORDER BY pub_date DESC";
                        break;
                }

                $sql = "SELECT hash, title, author, pub_date, rating, content FROM stories $whereSql $orderSql LIMIT $limit OFFSET $offset";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($stories as &$story) {
                    $story['excerpt'] = mb_substr(trim(strip_tags($story['content'])), 0, 200);
                }

                echo json_encode($stories);
                break;

            case 'rate':
                $hash = $_GET['hash'];
                $rating = max(0, min(5, intval($_GET['rating']))); // Garantisce un valore tra 0 e 5
                $stmt = $pdo->prepare("UPDATE stories SET rating = ? WHERE hash = ?");
                $stmt->execute([$rating, $hash]);
                echo json_encode(['success' => true, 'rating' => $rating]);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Racconti Erotici - Dominazione</title>
    <style>
        :root {
            --bg-color: #f4f4f9;
            --text-color: #333;
            --card-bg: #fff;
            --primary: #d63384;
            --secondary: #6c757d;
            --border: #ddd;
            --star-empty: #ccc;
            --star-fill: #f39c12;
            --modal-bg: rgba(0,0,0,0.8);
        }
        [data-theme="dark"] {
            --bg-color: #121212;
            --text-color: #e0e0e0;
            --card-bg: #1e1e1e;
            --primary: #ff66a3;
            --secondary: #a0a0a0;
            --border: #333;
            --star-empty: #444;
            --star-fill: #f39c12;
            --modal-bg: rgba(0,0,0,0.9);
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; transition: all 0.3s ease; }

        header { background: var(--card-bg); padding: 15px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--border); }
        .header-controls { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between; }
        .control-group { display: flex; align-items: center; gap: 10px; }

        button, select, input { padding: 8px 12px; border: 1px solid var(--border); border-radius: 5px; background: var(--bg-color); color: var(--text-color); cursor: pointer; }
        button.primary { background: var(--primary); color: white; border: none; font-weight: bold; }
        button.danger { background: #dc3545; color: white; border: none; font-weight: bold; }

        .search-container { display: flex; align-items: center; background: var(--bg-color); border: 1px solid var(--border); padding: 5px; border-radius: 5px; flex-grow: 1; max-width: 500px; flex-wrap: wrap; gap: 5px; }
        .search-container input { border: none; outline: none; flex-grow: 1; background: transparent; color: var(--text-color); padding: 5px; }
        .tag { background: var(--primary); color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; display: flex; align-items: center; gap: 5px; }
        .tag span { cursor: pointer; font-weight: bold; }

        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; padding: 20px; max-width: 1400px; margin: 0 auto; }
        @media(max-width: 1024px) { .grid { grid-template-columns: repeat(3, 1fr); } }
        @media(max-width: 768px) { .grid { grid-template-columns: repeat(2, 1fr); } }
        @media(max-width: 480px) { .grid { grid-template-columns: 1fr; } }

        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 15px; display: flex; flex-direction: column; cursor: pointer; transition: transform 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .card:hover { transform: translateY(-5px); }
        .card-title { font-size: 16px; font-weight: bold; margin-bottom: 5px; color: var(--primary); }
        .card-meta { font-size: 12px; color: var(--secondary); margin-bottom: 10px; }
        .card-excerpt { font-size: 14px; flex-grow: 1; line-height: 1.4; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; }

        /* Sistema Stelle con Hover Dinamico */
        .rating-container { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .rating { display: inline-flex; flex-direction: row-reverse; justify-content: flex-end; gap: 3px; font-size: 20px; user-select: none; }
        .rating span { cursor: pointer; color: var(--star-empty); transition: color 0.15s ease, transform 0.1s ease; }
        .rating span:hover { transform: scale(1.2); }

        .btn-clear-rating { background: none; border: none; color: var(--secondary); cursor: pointer; font-size: 16px; padding: 0 4px; line-height: 1; opacity: 0.6; transition: opacity 0.2s, color 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn-clear-rating:hover { opacity: 1; color: #dc3545; }

        /* Selezione fissa (Active) */
        .rating span.active,
        .rating span.active ~ span { color: var(--star-fill); }

        /* Anteprima Hover (sovrascrive lo stato attivo) */
        .rating:hover span { color: var(--star-empty); }
        .rating span:hover,
        .rating span:hover ~ span { color: var(--star-fill) !important; }

        .progress-container { width: 100%; margin-top: 10px; display: none; }
        .progress-bar-wrapper { width: 100%; background: var(--border); border-radius: 4px; height: 10px; margin-top: 5px; overflow: hidden; }
        .progress-bar { height: 100%; background: var(--primary); width: 0%; transition: width 0.2s; }
        .progress-text { font-size: 12px; margin-top: 3px; color: var(--secondary); display: flex; justify-content: space-between; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--modal-bg); z-index: 1000; overflow-y: auto; }
        .modal-content { background: var(--card-bg); max-width: 800px; margin: 40px auto; padding: 40px; border-radius: 8px; position: relative; font-size: 18px; line-height: 1.6; }
        .modal-header-info { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; }
        .modal-close { position: absolute; top: 15px; right: 20px; font-size: 28px; cursor: pointer; font-weight: bold; color: var(--secondary); }

        .loader { text-align: center; padding: 30px; color: var(--secondary); font-style: italic; min-height: 50px; }
    </style>
</head>
<body>

<header>
    <div class="header-controls">
        <div class="control-group">
            <h2 style="margin: 0; font-size: 20px;">Racconti Dominazione</h2>
            <button id="themeToggle" title="Cambia Tema">🌓</button>
        </div>

        <div class="search-container" id="searchContainer">
            <input type="text" id="searchInput" placeholder="Digita e premi invio per filtrare (AND)...">
        </div>

        <div class="control-group">
            <select id="sortSelect">
                <option value="date_desc">⏳ Più recenti</option>
                <option value="date_asc">⏳ Meno recenti</option>
                <option value="title_asc">🔼️ Titolo (A - Z)</option>
                <option value="title_desc">🔽 Titolo (Z - A)</option>
                <option value="rating_desc">⭐ Voto più alto</option>
            </select>
            <button id="btnSync" class="primary">Avvia Download</button>
        </div>
    </div>

    <div class="progress-container" id="progressContainer">
        <div class="progress-text">
            <span id="pageProgressText">Pagine: 0 / 0</span>
            <span id="storyProgressText">Racconti scaricati: 0</span>
        </div>
        <div class="progress-bar-wrapper">
            <div class="progress-bar" id="progressBar"></div>
        </div>
    </div>
</header>

<div class="grid" id="storyGrid"></div>
<div id="scrollSentinel" class="loader">Scorri per caricare altri racconti...</div>

<div class="modal" id="readerModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <h1 id="modalTitle" style="color: var(--primary); margin-top: 10px;"></h1>
        <div class="modal-header-info">
            <span style="color: var(--secondary); font-size: 14px;" id="modalMeta"></span>
            <div id="modalRating"></div>
        </div>
        <hr style="border: 0; border-top: 1px solid var(--border); margin: 15px 0 25px 0;">
        <div id="modalBody"></div>
    </div>
</div>

<script>
    const themeToggle = document.getElementById('themeToggle');
    let currentTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', currentTheme);

    themeToggle.addEventListener('click', () => {
        currentTheme = currentTheme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);
        localStorage.setItem('theme', currentTheme);
    });

    let offset = 0;
    let isLoading = false;
    let hasMore = true;
    let keywords = [];

    const searchInput = document.getElementById('searchInput');
    const searchContainer = document.getElementById('searchContainer');

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && searchInput.value.trim() !== '') {
            addKeyword(searchInput.value.trim());
            searchInput.value = '';
            reloadGrid();
        }
    });

    function addKeyword(word) {
        if (keywords.includes(word)) return;
        keywords.push(word);
        renderKeywords();
    }

    function removeKeyword(word) {
        keywords = keywords.filter(k => k !== word);
        renderKeywords();
        reloadGrid();
    }

    function renderKeywords() {
        document.querySelectorAll('.tag').forEach(el => el.remove());
        keywords.slice().reverse().forEach(word => {
            const span = document.createElement('div');
            span.className = 'tag';
            span.innerHTML = `${escapeHtml(word)} <span onclick="removeKeyword('${escapeHtml(word)}')">&times;</span>`;
            searchContainer.insertBefore(span, searchInput);
        });
    }

    document.getElementById('sortSelect').addEventListener('change', reloadGrid);

    function reloadGrid() {
        document.getElementById('storyGrid').innerHTML = '';
        offset = 0;
        hasMore = true;
        loadStories();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // Helper per generare l'HTML delle stelle e del tasto per annullare il voto
    function renderRatingHtml(hash, currentRating) {
        const showClear = currentRating > 0 ? 'inline-flex' : 'none';
        let html = `<div class="rating-container" data-hash="${hash}">`;
        html += `<div class="rating" data-hash="${hash}">`;
        for (let i = 5; i >= 1; i--) {
            const isActive = (i === currentRating) ? 'active' : '';
            html += `<span data-val="${i}" class="${isActive}" onclick="rateStory(event, '${hash}', ${i})" title="Vota ${i} stelle">★</span>`;
        }
        html += '</div>';
        html += `<button type="button" class="btn-clear-rating" onclick="rateStory(event, '${hash}', 0)" title="Annulla voto" style="display: ${showClear};">&times;</button>`;
        html += '</div>';
        return html;
    }

    async function loadStories() {
        if (isLoading || !hasMore) return;
        isLoading = true;

        const sort = document.getElementById('sortSelect').value;
        const kwJson = encodeURIComponent(JSON.stringify(keywords));
        const sentinel = document.getElementById('scrollSentinel');

        try {
            const res = await fetch(`?api=get_stories&offset=${offset}&sort=${sort}&keywords=${kwJson}`);
            if (!res.ok) throw new Error(`HTTP Error ${res.status}`);

            const stories = await res.json();
            if (!Array.isArray(stories)) throw new Error("Risposta API non valida");

            if (stories.length < 16) {
                hasMore = false;
            }

            const grid = document.getElementById('storyGrid');
            stories.forEach(story => {
                const card = document.createElement('div');
                card.className = 'card';
                card._storyData = story; // Salva la storia sull'elemento DOM
                card.onclick = (e) => {
                    if (!e.target.closest('.rating-container')) openModal(card._storyData);
                };

                card.innerHTML = `
                    <div class="card-title">${escapeHtml(story.title)}</div>
                    <div class="card-meta">Di ${escapeHtml(story.author)} - ${escapeHtml(story.pub_date || 'Data sconosciuta')}</div>
                    <div class="card-excerpt">${escapeHtml(story.excerpt)}...</div>
                    ${renderRatingHtml(story.hash, parseInt(story.rating) || 0)}
                `;
                grid.appendChild(card);
            });

            offset += stories.length;

            if (!hasMore) {
                sentinel.innerText = grid.children.length === 0
                    ? 'Nessun racconto presente nel database. Clicca su "Avvia Download".'
                    : 'Nessun altro racconto.';
            } else {
                sentinel.innerText = 'Scorri per caricare altri racconti...';
            }
        } catch(e) {
            console.error("Errore nel caricamento:", e);
        } finally {
            isLoading = false;
            checkSentinel();
        }
    }

    function checkSentinel() {
        if (!hasMore || isLoading) return;
        const sentinel = document.getElementById('scrollSentinel');
        const rect = sentinel.getBoundingClientRect();
        if (rect.top <= window.innerHeight + 300) {
            loadStories();
        }
    }

    window.addEventListener('scroll', checkSentinel, { passive: true });
    window.addEventListener('resize', checkSentinel, { passive: true });

    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
            loadStories();
        }
    }, { rootMargin: '300px' });

    observer.observe(document.getElementById('scrollSentinel'));

    function openModal(story) {
        document.getElementById('modalTitle').innerText = story.title;
        document.getElementById('modalMeta').innerText = `Autore: ${story.author} | Data: ${story.pub_date || 'Sconosciuta'}`;
        document.getElementById('modalRating').innerHTML = renderRatingHtml(story.hash, parseInt(story.rating) || 0);
        document.getElementById('modalBody').innerHTML = story.content;
        document.getElementById('readerModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('readerModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    document.getElementById('readerModal').addEventListener('click', (e) => {
        if (e.target.id === 'readerModal') closeModal();
    });

    // Funzione di votazione (valore da 0 a 5, con 0 che annulla il voto)
    async function rateStory(event, hash, rating) {
        if (event) event.stopPropagation();

        // Aggiorna istantaneamente l'interfaccia sia nella scheda che nel Modal
        document.querySelectorAll(`.rating-container[data-hash="${hash}"]`).forEach(container => {
            const stars = container.querySelectorAll('.rating span');
            stars.forEach(star => {
                if (parseInt(star.getAttribute('data-val')) === rating) {
                    star.classList.add('active');
                } else {
                    star.classList.remove('active');
                }
            });

            const clearBtn = container.querySelector('.btn-clear-rating');
            if (clearBtn) {
                clearBtn.style.display = rating > 0 ? 'inline-flex' : 'none';
            }

            // Aggiorna anche il dato salvato nell'oggetto card
            const card = container.closest('.card');
            if (card && card._storyData) {
                card._storyData.rating = rating;
            }
        });

        // Invio al backend
        try {
            await fetch(`?api=rate&hash=${hash}&rating=${rating}`);
        } catch(e) {
            console.error("Errore durante il salvataggio del voto:", e);
        }
    }

    let isSyncing = false;
    let abortController = null;
    let pageQueue = [];
    let storyQueue = [];

    let maxPages = 0;
    let pagesProcessed = 0;
    let storiesDownloaded = 0;

    const btnSync = document.getElementById('btnSync');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');

    btnSync.addEventListener('click', () => {
        if (isSyncing) {
            stopSync();
        } else {
            startSync();
        }
    });

    window.addEventListener('beforeunload', () => { if(isSyncing) stopSync(); });

    function updateProgressUI() {
        document.getElementById('pageProgressText').innerText = `Pagine analizzate: ${pagesProcessed} / ${maxPages}`;
        document.getElementById('storyProgressText').innerText = `Racconti scaricati (nuovi/aggiornati): ${storiesDownloaded}`;
        const pct = maxPages > 0 ? Math.min(100, (pagesProcessed / maxPages) * 100) : 0;
        progressBar.style.width = pct + '%';
    }

    function stopSync() {
        isSyncing = false;
        if (abortController) abortController.abort();
        btnSync.innerText = 'Avvia Download';
        btnSync.classList.remove('danger');
        btnSync.classList.add('primary');
    }

    async function startSync() {
        isSyncing = true;
        abortController = new AbortController();
        pageQueue = [];
        storyQueue = [];
        pagesProcessed = 0;
        storiesDownloaded = 0;

        btnSync.innerText = 'Interrompi Download';
        btnSync.classList.remove('primary');
        btnSync.classList.add('danger');
        progressContainer.style.display = 'block';

        try {
            const resPage = await fetch('?api=get_max_pages', { signal: abortController.signal });
            const dataPage = await resPage.json();
            maxPages = dataPage.max_pages;

            for (let i = 1; i <= maxPages; i++) {
                pageQueue.push(i);
            }
            updateProgressUI();

            const workers = [];
            for(let i=0; i<6; i++) {
                workers.push(syncWorker());
            }

            await Promise.all(workers);

            if (isSyncing) {
                alert("Download e aggiornamento completati!");
                reloadGrid();
                stopSync();
            }

        } catch (e) {
            if (e.name === 'AbortError') console.log("Download interrotto dall'utente.");
            else console.error("Errore durante il sync:", e);
        }
    }

    async function syncWorker() {
        if (!isSyncing) return;

        try {
            if (storyQueue.length > 0) {
                const url = storyQueue.shift();
                const res = await fetch(`?api=fetch_story&url=${encodeURIComponent(url)}`, { signal: abortController.signal });
                const json = await res.json();
                if (json.success) storiesDownloaded++;
                updateProgressUI();
            }
            else if (pageQueue.length > 0) {
                const p = pageQueue.shift();
                const res = await fetch(`?api=fetch_page&p=${p}`, { signal: abortController.signal });
                const json = await res.json();
                if (json.urls) {
                    storyQueue.push(...json.urls);
                }
                pagesProcessed++;
                updateProgressUI();
            }
            else if (pagesProcessed < maxPages) {
                await new Promise(r => setTimeout(r, 500));
            }
            else {
                return;
            }
        } catch(e) {
            if (e.name !== 'AbortError') console.error("Worker error:", e);
        }

        if (isSyncing && (pageQueue.length > 0 || storyQueue.length > 0 || pagesProcessed < maxPages)) {
            await syncWorker();
        }
    }

    // Caricamento iniziale
    loadStories();
</script>
</body>
</html>