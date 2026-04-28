<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Cache;
use Grav\Common\Scheduler\Scheduler;
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
            'onAdminAfterSave'       => ['onAdminAfterSave', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ];
    }

    // ── Admin save: vertaal meteen bij opslaan via dashboard ───────────────────

    public function onAdminAfterSave(Event $event): void
    {
        $obj = $event['object'];

        // Ondersteun zowel klassieke Page als Grav 1.7 Flex PageObject
        $is_page = ($obj instanceof PageInterface)
            || ($obj instanceof Page)
            || (is_object($obj) && method_exists($obj, 'filePath') && method_exists($obj, 'language'));

        if (!$is_page) {
            $this->grav['log']->debug('[sail-autotranslate] Skip: geen page object (' . get_class($obj) . ')');
            return;
        }

        $nl_file = $obj->filePath();
        $lang    = method_exists($obj, 'language') ? $obj->language() : null;

        $this->grav['log']->info('[sail-autotranslate] onAdminAfterSave: ' . $nl_file . ' (lang=' . $lang . ')');

        // Controleer of het een NL-pagina is via taalcode of bestandsextensie
        $is_nl = ($lang === 'nl') || str_ends_with((string)$nl_file, '.nl.md');

        if (!$is_nl) {
            $this->grav['log']->debug('[sail-autotranslate] Skip: niet een NL pagina');
            return;
        }

        // Zorg dat het pad eindigt op .nl.md (Flex geeft soms alleen .md terug)
        if (!str_ends_with((string)$nl_file, '.nl.md')) {
            $nl_file = preg_replace('/\.md$/', '.nl.md', $nl_file);
        }

        if (!file_exists($nl_file)) {
            $this->grav['log']->warning('[sail-autotranslate] NL-bestand niet gevonden: ' . $nl_file);
            return;
        }

        [$api_key, $api_url] = $this->loadApiCredentials();
        if (!$api_key || !$api_url) return;

        $this->translatePage($nl_file, $api_key, $api_url);

        // Volledige Grav-cache wissen zodat alle wijzigingen (ook Advanced settings)
        // meteen zichtbaar zijn op de frontend zonder manuele cache-clear
        Cache::clearCache('standard');
        $this->grav['log']->info('[sail-autotranslate] Cache gewist na save');
    }

    // ── Scheduler: elke minuut NL-bestanden controleren op wijzigingen ─────────
    // Pikt ook bewerkingen op die buiten het admin zijn gedaan (bv. direct in bestand).

    public function onSchedulerInitialized(Event $event): void
    {
        /** @var Scheduler $scheduler */
        $scheduler = $event['scheduler'];

        $job = $scheduler->addFunction(
            'Grav\Plugin\SailAutotranslatePlugin::runSyncJob',
            [],
            'sail-autotranslate-sync'
        );
        $job->at('* * * * *');          // elke minuut
        $job->output(GRAV_ROOT . '/logs/sail-autotranslate.log');
        $job->runInBackground();
    }

    /**
     * Statische methode die door de scheduler wordt aangeroepen.
     * Zoekt alle .nl.md bestanden die nieuwer zijn dan hun .en.md tegenhanger.
     */
    public static function runSyncJob(): void
    {
        $grav = \Grav\Common\Grav::instance();

        $env_file = GRAV_ROOT . '/.env';
        if (!file_exists($env_file)) return;

        $env     = parse_ini_file($env_file);
        $api_key = $env['DEEPL_API_KEY'] ?? '';
        $api_url = $env['DEEPL_API_URL'] ?? '';
        if (!$api_key || !$api_url) return;

        $pages_dir = GRAV_ROOT . '/user/pages';
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pages_dir, \FilesystemIterator::SKIP_DOTS)
        );

        $plugin = new self($grav, []);

        foreach ($iterator as $file) {
            if (!str_ends_with($file->getFilename(), '.nl.md')) continue;

            $nl_path = $file->getRealPath();
            $en_path = preg_replace('/\.nl\.md$/', '.en.md', $nl_path);

            // Vertaal als: EN-bestand ontbreekt OF NL is nieuwer dan EN
            $needs_translation = !file_exists($en_path)
                || filemtime($nl_path) > filemtime($en_path);

            if ($needs_translation) {
                $grav['log']->info('[sail-autotranslate] Scheduler vertaalt: ' . $nl_path);
                $plugin->translatePage($nl_path, $api_key, $api_url);
            }
        }
    }

    // ── Vertaal één pagina ──────────────────────────────────────────────────────

    public function translatePage(string $nl_file, string $api_key, string $api_url): void
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

        // Zet de mtime van EN gelijk aan NL, zodat de scheduler niet opnieuw triggert
        touch($en_file, filemtime($nl_file));

        $this->grav['log']->info('[sail-autotranslate] EN vertaling geschreven: ' . $en_file);
    }

    // ── Laad API-credentials uit .env ─────────────────────────────────────────

    private function loadApiCredentials(): array
    {
        $env_file = GRAV_ROOT . '/.env';
        if (!file_exists($env_file)) return ['', ''];

        $env = parse_ini_file($env_file);
        return [
            $env['DEEPL_API_KEY'] ?? '',
            $env['DEEPL_API_URL'] ?? '',
        ];
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

        return $this->restoreUrls($translated, $urls);
    }
}
