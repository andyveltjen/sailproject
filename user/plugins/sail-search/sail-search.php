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
        $context = $this->buildWikiContext($lang);
        $answer  = $this->askClaude($question, $context, $lang, $api_key);

        echo json_encode(['answer' => $answer]);
        exit();
    }

    // ── Wiki-pagina's als context samenstellen ─────────────────────────────────

    private function buildWikiContext(string $lang): string
    {
        $pages_dir = GRAV_ROOT . '/user/pages/02.wiki';
        if (!is_dir($pages_dir)) return '';

        $context  = '';
        $suffix   = '.' . $lang . '.md';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pages_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!str_ends_with($file->getFilename(), $suffix)) continue;

            $raw = file_get_contents($file->getPathname());

            // Frontmatter en body splitsen
            if (!preg_match('/^---\n(.*?)\n---\n?(.*)/s', $raw, $m)) continue;

            $yaml = $m[1];
            $body = trim($m[2]);
            if (!$body) continue;

            // Titel extraheren
            preg_match('/^title:\s*[\'"]?(.+?)[\'"]?\s*$/m', $yaml, $t);
            $title = $t[1] ?? $file->getFilename();

            $context .= "## {$title}\n\n{$body}\n\n---\n\n";
        }

        return $context;
    }

    // ── Claude Haiku aanroepen ─────────────────────────────────────────────────

    private function askClaude(
        string $question,
        string $context,
        string $lang,
        string $api_key
    ): string {
        $is_nl = $lang === 'nl';

        $system = $is_nl
            ? "Je bent een onderzoeksassistent voor het SAIL-project (Scenario's AI en Leren, UCLL). "
            . "Beantwoord vragen uitsluitend op basis van de aangeleverde wiki-inhoud. "
            . "Wees beknopt: maximaal 4 zinnen. Verwijs bij naam naar de relevante wiki-sectie. "
            . "Als het antwoord niet in de wiki staat, zeg dat dan eerlijk."
            : "You are a research assistant for the SAIL project (Scenarios AI and Learning, UCLL). "
            . "Answer questions solely based on the provided wiki content. "
            . "Be concise: maximum 4 sentences. Reference the relevant wiki section by name. "
            . "If the answer is not in the wiki, say so honestly.";

        $user_msg = $is_nl
            ? "Wiki-inhoud:\n\n{$context}\n\nVraag: {$question}"
            : "Wiki content:\n\n{$context}\n\nQuestion: {$question}";

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 512,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user_msg],
            ],
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
            return $is_nl
                ? 'Verbindingsfout met de AI-service. Probeer opnieuw.'
                : 'Connection error with AI service. Please try again.';
        }

        $data = json_decode($response, true);

        // Succesvolle response
        if (isset($data['content'][0]['text'])) {
            return $data['content'][0]['text'];
        }

        // API-fout — geef de foutmelding terug voor diagnose
        if (isset($data['error']['message'])) {
            return ($is_nl ? '⚠ API-fout: ' : '⚠ API error: ') . $data['error']['message'];
        }

        // Onverwachte response
        return ($is_nl
            ? '⚠ Onverwacht antwoord van de AI. Response: '
            : '⚠ Unexpected response from AI. Response: ')
            . substr($response, 0, 200);
    }
}
