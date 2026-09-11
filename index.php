<?php
// Configurazione file di storage locale
define('DATA_FILE', __DIR__ . '/racconti.json');

// --- GESTIONE API BACKEND (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 1. Scansiona l'indice per trovare il totale delle pagine
    if ($_GET['action'] === 'get_sync_index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $baseUrl = 'https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/';
        $html = @file_get_contents($baseUrl);
        if (!$html) {
            echo json_encode(['status' => 'error', 'message' => 'Impossibile leggere il sito']);
            exit;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $totalPages = 1;
        $pageNodes = $xpath->query("//nav[@id='pagination']//a[contains(@class, 'page-numbers')]");
        foreach ($pageNodes as $node) {
            $text = trim($node->textContent);
            if (is_numeric($text) && intval($text) > $totalPages) {
                $totalPages = intval($text);
            }
        }

        echo json_encode(['status' => 'success', 'total_pages' => $totalPages]);
        exit;
    }

    // 1.1 Analizza una specifica pagina
    if ($_GET['action'] === 'get_page_urls' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $baseUrl = 'https://raccontimilu.com/racconti-erotici/racconti-erotici-sulla-dominazione/';
        $targetPage = intval($input['p'] ?? 1);

        $currentUrl = ($targetPage === 1) ? $baseUrl : $baseUrl . 'page/' . $targetPage . '/';
        $html = @file_get_contents($currentUrl);

        if (!$html) {
            echo json_encode(['status' => 'success', 'urls' => []]);
            exit;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $storyUrls = [];
        $nodes = $xpath->query("//a[contains(@class, 'entry-title-link') or contains(@class, 'post-title') or @rel='bookmark']");
        if ($nodes->length === 0) {
            $nodes = $xpath->query("//article//h2/a | //div[contains(@class, 'post')]//a");
        }

        foreach ($nodes as $node) {
            $link = $node->getAttribute('href');
            if ($link && filter_var($link, FILTER_VALIDATE_URL) && !in_array($link, $storyUrls)) {
                $storyUrls[] = $link;
            }
        }

        echo json_encode(['status' => 'success', 'urls' => $storyUrls]);
        exit;
    }

    // 2. Scarica e salva un singolo racconto (con filtro rigido anti "Senza titolo")
    if ($_GET['action'] === 'save_story' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $url = $input['url'] ?? '';

        if (!$url) {
            echo json_encode(['status' => 'error']);
            exit;
        }

        $pageHtml = @file_get_contents($url);
        if (!$pageHtml) {
            echo json_encode(['status' => 'error']);
            exit;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($pageHtml);
        $xpath = new DOMXPath($dom);

        $titleNode = $xpath->query("//h1[contains(@class, 'entry-title') or contains(@class, 'post-title')]");
        $title = $titleNode->length > 0 ? trim($titleNode->item(0)->textContent) : '';
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // SCARTA SUBITO SE IL TITOLO È VUOTO O "Senza titolo"
        if (empty($title) || mb_strtolower($title) === 'senza titolo') {
            echo json_encode(['status' => 'skipped']);
            exit;
        }

        $contentNode = $xpath->query("//div[contains(@class, 'entry-content') or contains(@class, 'post-content')]");
        $text = '';
        if ($contentNode->length > 0) {
            $contentContainer = $contentNode->item(0);
            $paragraphs = $xpath->query('.//p', $contentContainer);
            $cleanParagraphs = [];

            foreach ($paragraphs as $p) {
                $pText = trim($p->textContent);
                if (!empty($pText) && mb_strlen($pText) > 2) {
                    if (stripos($pText, 'Condividi questo') === false && stripos($pText, 'Mi piace:') === false) {
                        $cleanParagraphs[] = $pText;
                    }
                }
            }

            if (!empty($cleanParagraphs)) {
                $text = implode("\n\n", $cleanParagraphs);
            } else {
                $text = strip_tags($dom->saveHTML($contentContainer));
            }

            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/[ \t]+/', ' ', $text));
        }

        if (!empty($text)) {
            // Operazione atomica sicura con blocco file
            $fp = fopen(DATA_FILE, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $filesize = filesize(DATA_FILE);
                $content = $filesize > 0 ? fread($fp, $filesize) : '';
                $existingStories = json_decode($content, true);
                if (!is_array($existingStories)) $existingStories = [];

                $exists = false;
                foreach ($existingStories as $story) {
                    if ($story['url'] === $url) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $newStory = [
                            'id' => md5($url),
                            'url' => $url,
                            'title' => $title,
                            'content' => $text,
                            'rating' => 0,
                            'timestamp' => time()
                    ];
                    $existingStories[] = $newStory;
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($existingStories, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    echo json_encode(['status' => 'saved']);
                    exit;
                }
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        echo json_encode(['status' => 'exists']);
        exit;
    }

    // 2.1 Gestione Voti
    if ($_GET['action'] === 'rate_story' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $rating = intval($input['rating'] ?? 0);

        $fp = fopen(DATA_FILE, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $filesize = filesize(DATA_FILE);
            $content = $filesize > 0 ? fread($fp, $filesize) : '';
            $existingStories = json_decode($content, true);
            if (is_array($existingStories)) {
                foreach ($existingStories as &$story) {
                    if ($story['id'] === $id) {
                        $story['rating'] = max(1, min(5, $rating));
                        break;
                    }
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($existingStories, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
                echo json_encode(['status' => 'success']);
                exit;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        echo json_encode(['status' => 'error']);
        exit;
    }

    // 3. Fornisce i racconti salvati
    if ($_GET['action'] === 'get_stories') {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = 24;
        $keywords = isset($_GET['keywords']) ? json_decode($_GET['keywords'], true) : [];
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

        $stories = file_exists(DATA_FILE) ? json_decode(file_get_contents(DATA_FILE), true) : [];
        if (!is_array($stories)) $stories = [];

        $stories = array_filter($stories, function($story) {
            $t = trim($story['title'] ?? '');
            return !empty($t) && mb_strtolower($t) !== 'senza titolo';
        });
        $stories = array_values($stories);

        if (!empty($keywords) && is_array($keywords)) {
            $stories = array_filter($stories, function($story) use ($keywords) {
                $haystack = mb_strtolower($story['title'] . ' ' . $story['content']);
                foreach ($keywords as $kw) {
                    $kw = mb_strtolower(trim($kw));
                    if ($kw !== '' && mb_strpos($haystack, $kw) === false) return false;
                }
                return true;
            });
            $stories = array_values($stories);
        }

        usort($stories, function($a, $b) use ($sort) {
            $rA = $a['rating'] ?? 0;
            $rB = $b['rating'] ?? 0;
            if (($rA > 0) !== ($rB > 0)) return ($rA > 0) ? -1 : 1;
            if ($sort === 'rating' && $rA !== $rB) return $rB - $rA;
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

        $total = count($stories);
        $offset = ($page - 1) * $limit;
        $pagedStories = array_slice($stories, $offset, $limit);

        echo json_encode([
                'total' => $total,
                'page' => $page,
                'has_more' => ($offset + $limit) < $total,
                'stories' => $pagedStories
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Racconti Erotici - Sulla Dominazione</title>
    <style>
        :root {
            --bg-color: #f8f9fa; --card-bg: #ffffff; --text-color: #333333;
            --text-muted: #6c757d; --primary: #007bff; --border-color: #ced4da;
            --shadow: rgba(0,0,0,0.05); --star-color: #e4e5e9; --star-active: #ffc107;
        }
        [data-theme="dark"] {
            --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0;
            --text-muted: #a0a0a0; --primary: #bb86fc; --border-color: #333333;
            --shadow: rgba(0,0,0,0.5); --star-color: #444444; --star-active: #ffbb00;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 20px; transition: background 0.3s, color 0.3s; }
        .container { max-width: 1400px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        h1 { font-size: 24px; margin: 0; }
        .header-controls { display: flex; gap: 10px; align-items: center; }
        .theme-toggle, .sort-select { background: var(--card-bg); border: 1px solid var(--border-color); color: var(--text-color); padding: 8px 15px; border-radius: 20px; cursor: pointer; font-size: 14px; font-weight: 600; }
        #status-bar { text-align: center; font-size: 13px; color: var(--text-muted); margin-bottom: 20px; background: var(--card-bg); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); }
        #search-container { background: var(--card-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 25px; }
        #tag-input { width: 100%; padding: 10px; font-size: 15px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-color); border-radius: 4px; box-sizing: border-box; }
        #tags-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .tag { background: var(--primary); color: white; padding: 6px 12px; border-radius: 20px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .tag .remove-tag { cursor: pointer; font-weight: bold; background: rgba(255,255,255,0.2); border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; }
        #stories-container { display: grid; grid-template-columns: repeat(1, 1fr); gap: 20px; }
        @media (min-width: 600px) { #stories-container { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 992px) { #stories-container { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1300px) { #stories-container { grid-template-columns: repeat(4, 1fr); } }
        .story-card { background: var(--card-bg); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; }
        .story-card h3 { margin-top: 0; color: var(--primary); font-size: 17px; cursor: pointer; }
        .story-content-preview { line-height: 1.5; max-height: 90px; overflow: hidden; color: var(--text-muted); font-size: 13px; margin-bottom: 15px; cursor: pointer; }
        .rating-stars { display: flex; gap: 4px; align-items: center; margin-top: 10px; border-top: 1px solid var(--border-color); padding-top: 10px; }
        .star-container { display: inline-flex; flex-direction: row-reverse; justify-content: flex-end; }
        .star-container input { display: none; }
        .star-container label { font-size: 22px; color: var(--star-color); cursor: pointer; }
        .star-container label:hover, .star-container label:hover ~ label, .star-container input:checked ~ label { color: var(--star-active); }
        #modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); display: none; justify-content: center; align-items: center; z-index: 1000; padding: 20px; box-sizing: border-box; }
        #modal-content { background: var(--card-bg); color: var(--text-color); width: 100%; max-width: 900px; height: 85vh; border-radius: 12px; padding: 30px; display: flex; flex-direction: column; border: 1px solid var(--border-color); position: relative; }
        #modal-body { overflow-y: auto; line-height: 1.8; font-size: 16px; flex-grow: 1; white-space: pre-wrap; }
        .close-modal { position: absolute; top: 20px; right: 25px; background: none; border: none; font-size: 28px; color: var(--text-color); cursor: pointer; }
        #loading { text-align: center; padding: 30px; font-weight: bold; color: var(--text-muted); grid-column: 1 / -1; display: none; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Racconti di Dominazione</h1>
        <div class="header-controls">
            <select id="sort-select" class="sort-select" onchange="changeSort()">
                <option value="newest">🕒 Più recenti</option>
                <option value="rating">⭐ Voto più alto</option>
            </select>
            <button class="theme-toggle" onclick="toggleTheme()">🌓 Tema</button>
        </div>
    </header>

    <div id="status-bar">Caricamento archivio locale...</div>

    <div id="search-container">
        <input type="text" id="tag-input" placeholder="Scrivi una parola chiave e premi Invio per aggiungere un filtro (AND)...">
        <div id="tags-list"></div>
    </div>

    <div id="stories-container"></div>
    <div id="loading">Caricamento altri racconti...</div>
</div>

<div id="modal-overlay" onclick="closeModalOnOutside(event)">
    <div id="modal-content">
        <button class="close-modal" onclick="closeModal()">&times;</button>
        <div style="margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
            <h2 id="modal-title" style="margin: 0 0 10px 0; color: var(--primary);"></h2>
            <div id="modal-stars" class="rating-stars"></div>
        </div>
        <div id="modal-body"></div>
    </div>
</div>

<script>
    let keywords = [];
    let currentPage = 1;
    let isLoading = false;
    let hasMore = true;
    let currentSort = 'newest';
    let globalStoriesMap = {};

    function toggleTheme() {
        const html = document.documentElement;
        const newTheme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
    }
    if (localStorage.getItem('theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark');

    function changeSort() {
        currentSort = document.getElementById('sort-select').value;
        resetAndLoad();
    }

    function initSync() {
        const statusBar = document.getElementById('status-bar');

        // Carica subito i racconti presenti nel JSON locale
        loadStories(false);

        // Avvia la scansione in background per gli aggiornamenti
        fetch('?action=get_sync_index', { method: 'POST' })
            .then(res => res.json())
            .then(indexData => {
                if (indexData.status !== 'success') return;

                let totalPages = indexData.total_pages;
                let pageToScan = 1;
                let newStoriesFound = 0;
                let foundExisting = false;

                function scanNextPageForward() {
                    if (/*foundExisting ||*/ pageToScan > totalPages) {
                        statusBar.innerText = `Sincronizzazione completata! Trovati ${newStoriesFound} nuovi racconti.`;
                        if (newStoriesFound > 0) loadStories(false);
                        return;
                    }

                    statusBar.innerText = `Controllo aggiornamenti pagina ${pageToScan} di ${totalPages} (Nuovi trovati: ${newStoriesFound})...`;

                    fetch('?action=get_page_urls', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ p: pageToScan })
                    })
                        .then(res => res.json())
                        .then(pageData => {
                            const urls = pageData.urls || [];
                            if (urls.length === 0) {
                                pageToScan++;
                                scanNextPageForward();
                                return;
                            }

                            const promises = urls.map(url => {
                                return fetch('?action=save_story', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ url: url })
                                })
                                    .then(res => res.json())
                                    .catch(() => ({ status: 'error' }));
                            });

                            Promise.all(promises).then(results => {
                                let pageAddedCount = 0;

                                for (let resData of results) {
                                    if (resData.status === 'saved') {
                                        pageAddedCount++;
                                    } else if (resData.status === 'exists') {
                                        foundExisting = true;
                                    }
                                }

                                newStoriesFound += pageAddedCount;
                                pageToScan++;
                                scanNextPageForward();
                            });
                        })
                        .catch(() => {
                            pageToScan++;
                            scanNextPageForward();
                        });
                }

                scanNextPageForward();
            });
    }

    const tagInput = document.getElementById('tag-input');
    tagInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = this.value.trim();
            if (val && !keywords.includes(val)) {
                keywords.push(val);
                this.value = '';
                renderTags();
                resetAndLoad();
            }
        }
    });

    function removeKeyword(index) {
        keywords.splice(index, 1);
        renderTags();
        resetAndLoad();
    }

    function renderTags() {
        const container = document.getElementById('tags-list');
        container.innerHTML = '';
        keywords.forEach((kw, index) => {
            const tag = document.createElement('div');
            tag.className = 'tag';
            tag.innerHTML = `${escapeHtml(kw)} <span class="remove-tag" onclick="removeKeyword(${index})">&times;</span>`;
            container.appendChild(tag);
        });
    }

    function renderStarRating(storyId, currentRating) {
        let html = '<div class="star-container">';
        for (let i = 5; i >= 1; i--) {
            const checked = i === currentRating ? 'checked' : '';
            html += `<input type="radio" id="star-${storyId}-${i}" name="rating-${storyId}" value="${i}" ${checked} onclick="rateStory('${storyId}', ${i})"><label for="star-${storyId}-${i}">&#9733;</label>`;
        }
        return html + '</div>';
    }

    function loadStories(append = false) {
        if (isLoading) return;
        isLoading = true;
        document.getElementById('loading').style.display = 'block';

        const kwParam = encodeURIComponent(JSON.stringify(keywords));
        fetch(`?action=get_stories&page=${currentPage}&keywords=${kwParam}&sort=${currentSort}`)
            .then(res => res.json())
            .then(data => {
                hasMore = data.has_more;
                const container = document.getElementById('stories-container');

                if (!append) {
                    container.innerHTML = '';
                    globalStoriesMap = {};
                }

                if (data.stories.length === 0 && !append) {
                    container.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:40px; grid-column:1/-1;">Nessun racconto trovato nell\'archivio locale.</div>';
                } else {
                    document.getElementById('status-bar').innerText = `Racconti presenti in archivio: ${data.total}`;
                }

                data.stories.forEach(story => {
                    globalStoriesMap[story.id] = story;
                    const card = document.createElement('div');
                    card.className = 'story-card';
                    card.innerHTML = `
                        <div>
                            <h3 onclick="openModal('${story.id}')">${escapeHtml(story.title)}</h3>
                            <div class="story-content-preview" onclick="openModal('${story.id}')">${escapeHtml(story.content)}</div>
                        </div>
                        <div class="rating-stars">${renderStarRating(story.id, story.rating)}</div>
                    `;
                    container.appendChild(card);
                });

                isLoading = false;
                document.getElementById('loading').style.display = 'none';
            })
            .catch(() => {
                isLoading = false;
                document.getElementById('loading').style.display = 'none';
            });
    }

    function resetAndLoad() {
        currentPage = 1;
        hasMore = true;
        loadStories(false);
    }

    function rateStory(id, rating) {
        const cleanId = id.toString().replace('modal-', '');
        fetch('?action=rate_story', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: cleanId, rating: rating })
        }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (globalStoriesMap[cleanId]) globalStoriesMap[cleanId].rating = rating;
                loadStories(false);
            }
        });
    }

    function openModal(storyId) {
        const story = globalStoriesMap[storyId];
        if (!story) return;
        document.getElementById('modal-title').innerText = story.title;
        document.getElementById('modal-body').innerText = story.content;
        document.getElementById('modal-stars').innerHTML = renderStarRating('modal-' + story.id, story.rating);
        document.getElementById('modal-overlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('modal-overlay').style.display = 'none';
        document.body.style.overflow = 'auto';
        loadStories(false);
    }

    function closeModalOnOutside(event) {
        if (event.target.id === 'modal-overlay') closeModal();
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    window.addEventListener('scroll', () => {
        if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 500) {
            if (hasMore && !isLoading) {
                currentPage++;
                loadStories(true);
            }
        }
    });

    // Avvio: carica l'archivio locale e avvia i controlli di aggiornamento
    initSync();
</script>

</body>
</html>