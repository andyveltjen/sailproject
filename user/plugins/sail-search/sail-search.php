<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;

class SailSearchPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        // Niet uitvoeren in het admin panel
        if ($this->isAdmin()) {
            return;
        }
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    public function onPageInitialized(): void
    {
        // Enkel het /wiki-search endpoint onderscheppen
        if ($this->grav['uri']->route() !== '/wiki-search') {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        // Enkel POST toestaan
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        // Controleer of zoekfunctie actief is
        $search_active = $this->grav['config']->get('site.search.active', true);
        if (!$search_active) {
            echo json_encode(['error' => 'Search is disabled']);
            exit();
        }

        // Verzoek inlezen
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $question = trim($body['question'] ?? '');
        $lang     = in_array($body['lang'] ?? '', ['nl', 'en']) ? $body['lang'] : 'nl';

        if (!$question) {
            echo json_encode(['error' => 'No question provided']);
            exit();
        }

        // API-sleutel laden uit .env
        $env_file = GRAV_ROOT . '/.env';
        if (!file_exists($env_file)) {
            echo json_encode(['error' => 'Server configuration error']);
            exit();
        }

        $env     = parse_ini_file($env_file);
        $api_key = $env['ANTHROPIC_API_KEY'] ?? '';

        if (!$api_key) {
            echo json_encode(['error' => 'API key not configured']);
            exit();
        }

        // Wiki-inhoud samenstellen en Claude aanroepen
        [$context, $page_map] = $this->buildWikiContext($lang);
        $result = $this->askClaude($question, $context, $page_map, $lang, $api_key);

        echo json_encode($result);
        exit();
    }

    // ── Wiki-pagina's als context samenstellen ─────────────────────────────────
    // Geeft zowel de context-string als een page_map terug:
    // page_map = [ '/wiki/inleiding' => 'Introduction', ... ]

    private function buildWikiContext(string $lang): array
    {
        $pages_dir = GRAV_ROOT . '/user/pages/02.wiki';
        if (!is_dir($pages_dir)) return ['', []];

        $context  = '';
        $page_map = [];
        $suffix   = '.' . $lang . '.md';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pages_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!str_ends_with($file->getFilename(), $suffix)) continue;

            $raw = file_get_contents($file->getPathname());
            if (!preg_match('/^---\n(.*?)\n---\n?(.*)/s', $raw, $m)) continue;

            $yaml = $m[1];
            $body = trim($m[2]);
            if (!$body) continue;

            // Titel extraheren
            preg_match('/^title:\s*[\'"]?(.+?)[\'"]?\s*$/m', $yaml, $t);
            $title = $t[1] ?? $file->getFilename();

            // Bestandspad omzetten naar Grav-URL (strip ordering-prefixen)
            $dir      = dirname($file->getPathname());
            $relative = str_replace(GRAV_ROOT . '/user/pages/', '', $dir);
            $segments = explode('/', $relative);
            $clean    = array_map(fn($s) => preg_replace('/^\d+\./', '', $s), $segments);
            $url_path = '/' . implode('/', $clean);

            $page_map[$url_path] = $title;
            $context .= "## {$title} [ID:{$url_path}]\n\n{$body}\n\n---\n\n";
        }

        return [$context, $page_map];
    }

    // ── Claude Haiku aanroepen ─────────────────────────────────────────────────
    // Geeft ['answer' => string, 'refs' => [['path'=>..., 'title'=>...]]] terug

    private function askClaude(
        string $question,
        string $context,
        array  $page_map,
        string $lang,
        string $api_key
    ): array {
        $is_nl = $lang === 'nl';

        $ids_list = implode(', ', array_keys($page_map));

        $system = $is_nl
            ? "Je bent een onderzoeksassistent voor het SAIL-project (Scenario's AI en Leren, UCLL). "
            . "Beantwoord vragen uitsluitend op basis van de aangeleverde wiki-inhoud (de ID's zijn de URL-paden). "
            . "Wees beknopt: maximaal 4 zinnen. "
            . "Sluit je antwoord altijd af met een nieuwe regel die begint met 'REFS:' gevolgd door de ID's van de gebruikte secties, gescheiden door komma's. "
            . "Gebruik enkel ID's uit deze lijst: {$ids_list}. "
            . "Als het antwoord niet in de wiki staat, schrijf dan 'REFS:' zonder ID's."
            : "You are a research assistant for the SAIL project (Scenarios AI and Learning, UCLL). "
            . "Answer questions solely based on the provided wiki content (IDs are URL paths). "
            . "Be concise: maximum 4 sentences. "
            . "Always end your answer with a new line starting with 'REFS:' followed by the IDs of sections you used, comma-separated. "
            . "Only use IDs from this list: {$ids_list}. "
            . "If the answer is not in the wiki, write 'REFS:' with no IDs.";

        $user_msg = $is_nl
            ? "Wiki-inhoud:\n\n{$context}\n\nVraag: {$question}"
            : "Wiki content:\n\n{$context}\n\nQuestion: {$question}";

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 512,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user_msg]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "x-api-key: {$api_key}",
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['answer' => $is_nl
                ? 'Verbindingsfout met de AI-service. Probeer opnieuw.'
                : 'Connection error with AI service. Please try again.',
                'refs' => []];
        }

        $data = json_decode($response, true);

        if (!isset($data['content'][0]['text'])) {
            $msg = $data['error']['message'] ?? substr($response, 0, 200);
            return ['answer' => ($is_nl ? '⚠ API-fout: ' : '⚠ API error: ') . $msg, 'refs' => []];
        }

        $full_text = $data['content'][0]['text'];

        // REFS-regel extraheren en uit de antwoordtekst verwijderen
        $refs = [];
        if (preg_match('/\nREFS:\s*(.*)$/s', $full_text, $rm)) {
            $full_text = trim(preg_replace('/\nREFS:.*$/s', '', $full_text));
            $ref_ids   = array_filter(array_map('trim', explode(',', $rm[1])));
            foreach ($ref_ids as $id) {
                if (isset($page_map[$id])) {
                    $refs[] = ['path' => $id, 'title' => $page_map[$id]];
                }
            }
        }

        return ['answer' => $full_text, 'refs' => $refs];
    }
}
