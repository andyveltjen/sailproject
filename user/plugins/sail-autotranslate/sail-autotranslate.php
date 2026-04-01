<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class SailAutotranslatePlugin extends Plugin
{
    /** Velden in frontmatter die vertaald worden */
    private array $translate_fields = [
        'title', 'subtitle', 'intro', 'description', 'description1', 'description2',
        'inactive_message', 'label', 'text', 'status', 'footer_text',
        'cta_text', 'second_cta_text', 'name', 'role', 'period',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
        ];
    }

    public function onAdminAfterSave(Event $event): void
    {
        $obj = $event['object'];

        // Enkel Page-objecten verwerken
        if (!($obj instanceof Page)) {
            return;
        }

        $nl_file = $obj->filePath();

        // Enkel .nl.md bestanden vertalen
        if (!str_ends_with($nl_file, '.nl.md')) {
            return;
        }

        // API-gegevens laden uit .env
        $env_file = GRAV_ROOT . '/.env';
        if (!file_exists($env_file)) {
            $this->grav['debugger']?->addMessage('[sail-autotranslate] .env niet gevonden');
            return;
        }

        $env     = parse_ini_file($env_file);
        $api_key = $env['DEEPL_API_KEY'] ?? '';
        $api_url = $env['DEEPL_API_URL'] ?? '';

        if (!$api_key || !$api_url) {
            return;
        }

        $this->translatePage($nl_file, $api_key, $api_url);
    }

    // ── Vertaal één pagina ──────────────────────────────────────────────────────

    private function translatePage(string $nl_file, string $api_key, string $api_url): void
    {
        $en_file = preg_replace('/\.nl\.md$/', '.en.md', $nl_file);
        $content = file_get_contents($nl_file);

        // Splits frontmatter en body
        if (preg_match('/^---\n(.*?)\n---\n?(.*)/s', $content, $m)) {
            $raw_yaml = $m[1];
            $body     = $m[2];
        } else {
            $raw_yaml = '';
            $body     = $content;
        }

        // Parse en vertaal frontmatter
        $frontmatter = Yaml::parse($raw_yaml) ?: [];
        if (!empty($frontmatter)) {
            $frontmatter = $this->translateFrontmatter($frontmatter, $api_key, $api_url);
        }

        // Vertaal body (Markdown)
        $translated_body = '';
        if (trim($body) !== '') {
            $translated_body = $this->deepLTranslate($body, $api_key, $api_url);
        }

        // Schrijf .en.md
        $yaml_out = Yaml::dump($frontmatter, 4, 2);
        $output   = "---\n{$yaml_out}---\n{$translated_body}";
        file_put_contents($en_file, $output);
    }

    // ── Recursief frontmatter vertalen ─────────────────────────────────────────

    private function translateFrontmatter(array $data, string $api_key, string $api_url): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->translateFrontmatter($value, $api_key, $api_url);
            } elseif (is_string($value) && in_array($key, $this->translate_fields) && strlen($value) > 1) {
                $value = $this->deepLTranslate($value, $api_key, $api_url);
            }
        }
        return $data;
    }

    // ── Bescherm markdown-URLs voor vertaling ─────────────────────────────────
    // Vervangt URLs in [tekst](url) en ![alt](url) door tijdelijke placeholders,
    // zodat DeepL de paden niet vertaalt.

    private function protectUrls(string $text): array
    {
        $urls    = [];
        $counter = 0;
        $protected = preg_replace_callback(
            '/(!?\[[^\]]*\])\(([^)]+)\)/',
            function ($m) use (&$urls, &$counter) {
                $placeholder        = 'SAILURL' . $counter++;
                $urls[$placeholder] = $m[2];
                return $m[1] . '(' . $placeholder . ')';
            },
            $text
        );
        return [$protected, $urls];
    }

    private function restoreUrls(string $text, array $urls): string
    {
        foreach ($urls as $placeholder => $url) {
            $text = str_replace($placeholder, $url, $text);
            $text = str_replace(strtolower($placeholder), $url, $text);
        }
        return $text;
    }

    // ── DeepL API-aanroep ──────────────────────────────────────────────────────

    private function deepLTranslate(string $text, string $api_key, string $api_url): string
    {
        if (trim($text) === '') return $text;

        // URLs beschermen voor DeepL ze kan vertalen
        [$protected, $urls] = $this->protectUrls($text);

        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Authorization: DeepL-Auth-Key $api_key"],
            CURLOPT_POSTFIELDS     => http_build_query([
                'text'        => $protected,
                'source_lang' => 'NL',
                'target_lang' => 'EN-GB',
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data       = json_decode($response, true);
        $translated = $data['translations'][0]['text'] ?? $protected;

        // Originele URLs terugzetten
        return $this->restoreUrls($translated, $urls);
    }
}
