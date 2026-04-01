<?php
/**
 * SAIL Project — DeepL Vertaalscript
 * Gebruik: php translate.php [pad/naar/pagina.nl.md] of php translate.php (alle pagina's)
 */

// Gebruik Symfony YAML uit Grav vendor
require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

// Laad .env
$env = parse_ini_file(__DIR__ . '/.env');
$api_key = $env['DEEPL_API_KEY'];
$api_url  = $env['DEEPL_API_URL'];

// Velden in frontmatter die wél vertaald moeten worden
$translate_fields = [
    'title', 'subtitle', 'intro', 'description', 'description1', 'description2',
    'inactive_message', 'label', 'text', 'status', 'footer_text',
    'cta_text', 'second_cta_text', 'name', 'role', 'period',
];

// ── Vertaalfunctie via DeepL ──────────────────────────────────────────────────
function deepl_translate(string $text, string $api_key, string $api_url): string {
    if (trim($text) === '') return $text;

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Authorization: DeepL-Auth-Key $api_key"],
        CURLOPT_POSTFIELDS     => http_build_query([
            'text'        => $text,
            'source_lang' => 'NL',
            'target_lang' => 'EN-GB',
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['translations'][0]['text'] ?? $text;
}

// ── Frontmatter recursief vertalen ───────────────────────────────────────────
function translate_frontmatter(array $data, array $fields, string $api_key, string $api_url): array {
    foreach ($data as $key => &$value) {
        if (is_array($value)) {
            $value = translate_frontmatter($value, $fields, $api_key, $api_url);
        } elseif (is_string($value) && in_array($key, $fields) && strlen($value) > 1) {
            echo "  → Vertaal '$key': " . substr($value, 0, 50) . "...\n";
            $value = deepl_translate($value, $api_key, $api_url);
        }
    }
    return $data;
}

// ── YAML serialiseren (simpele versie) ───────────────────────────────────────
function yaml_dump_simple(array $data, int $indent = 0): string {
    $out = '';
    $pad = str_repeat('  ', $indent);
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $is_list = array_keys($value) === range(0, count($value) - 1);
            if ($is_list) {
                $out .= "$pad$key:\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $out .= "$pad  -\n" . yaml_dump_simple($item, $indent + 2);
                    } else {
                        $out .= "$pad  - " . yaml_quote($item) . "\n";
                    }
                }
            } else {
                $out .= "$pad$key:\n" . yaml_dump_simple($value, $indent + 1);
            }
        } else {
            $out .= "$pad$key: " . yaml_quote($value) . "\n";
        }
    }
    return $out;
}

function yaml_quote($val): string {
    if (is_bool($val)) return $val ? 'true' : 'false';
    if (is_null($val))  return 'null';
    if (is_numeric($val)) return (string)$val;
    if (is_string($val) && preg_match('/[:#\[\]{},&*?|<>=!%@`\'"\n]/', $val)) {
        return "'" . str_replace("'", "''", $val) . "'";
    }
    return (string)$val;
}

// ── Eén bestand verwerken ─────────────────────────────────────────────────────
function process_file(string $nl_file, string $api_key, string $api_url, array $fields): void {
    $en_file = preg_replace('/\.nl\.md$/', '.en.md', $nl_file);
    echo "\nBestand: $nl_file\n";

    $content  = file_get_contents($nl_file);

    // Splits frontmatter en body
    if (preg_match('/^---\n(.*?)\n---\n?(.*)/s', $content, $m)) {
        $raw_yaml = $m[1];
        $body     = $m[2];
    } else {
        $raw_yaml = '';
        $body     = $content;
    }

    // Parse YAML frontmatter
    $frontmatter = Yaml::parse($raw_yaml) ?: [];

    // Vertaal frontmatter
    if (!empty($frontmatter)) {
        $frontmatter = translate_frontmatter($frontmatter, $fields, $api_key, $api_url);
    }

    // Vertaal body (Markdown)
    $translated_body = '';
    if (trim($body) !== '') {
        echo "  → Vertaal body...\n";
        $translated_body = deepl_translate($body, $api_key, $api_url);
    }

    // Schrijf .en.md bestand
    $yaml_out = Yaml::dump($frontmatter, 4, 2);
    $output   = "---\n{$yaml_out}---\n{$translated_body}";
    file_put_contents($en_file, $output);
    echo "  ✓ Geschreven: $en_file\n";
}

// ── Hoofdlogica ───────────────────────────────────────────────────────────────
$pages_dir = __DIR__ . '/user/pages';

if (isset($argv[1])) {
    // Eén specifiek bestand
    process_file($argv[1], $api_key, $api_url, $translate_fields);
} else {
    // Alle .nl.md pagina's
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages_dir));
    foreach ($files as $file) {
        if (str_ends_with($file->getFilename(), '.nl.md')) {
            process_file($file->getPathname(), $api_key, $api_url, $translate_fields);
        }
    }
}

echo "\n✅ Vertaling voltooid!\n";
