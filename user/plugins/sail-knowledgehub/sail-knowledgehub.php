<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;

class SailKnowledgehubPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'];
        if ($page->template() !== 'knowledge-hub') {
            return;
        }

        $data = $this->fetchKnowledgeHubData();
        $this->grav['twig']->twig_vars['raindrop_collections'] = $data['collections'];
        $this->grav['twig']->twig_vars['raindrop_all_tags']    = $data['all_tags'];
        $this->grav['twig']->twig_vars['raindrop_error']       = $data['error'] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data ophalen (met cache)
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchKnowledgeHubData(): array
    {
        $token        = $this->getToken();
        $parentId     = $this->config->get('plugins.sail-knowledgehub.collection_id', '');
        $cacheTtl     = (int) $this->config->get('plugins.sail-knowledgehub.cache_ttl', 3600);
        $perPage      = (int) $this->config->get('plugins.sail-knowledgehub.per_page', 50);

        if (empty($token)) {
            return [
                'collections' => [],
                'all_tags'    => [],
                'error'       => 'Raindrop API-token ontbreekt. Configureer de sail-knowledgehub plugin.',
            ];
        }

        $cache   = $this->grav['cache'];
        $cacheId = md5('sail_raindrop_' . $parentId . '_' . $perPage);
        $cached  = $cache->fetch($cacheId);

        if ($cached !== false) {
            return $cached;
        }

        // Haal alle root-collecties op
        $allCollections = $this->apiGet('https://api.raindrop.io/rest/v1/collections', $token);

        if ($allCollections === null) {
            return [
                'collections' => [],
                'all_tags'    => [],
                'error'       => 'Kon geen verbinding maken met de Raindrop API. Controleer het netwerk of de API-token.',
            ];
        }

        $targetCollections = [];

        if (!empty($allCollections['items'])) {
            foreach ($allCollections['items'] as $col) {
                $colParentId = ($col['parent'] ?? null) ? ($col['parent']['$id'] ?? null) : null;

                if (!empty($parentId)) {
                    // Modus A: toon kinderen van een specifieke collectie
                    if ((string) $colParentId === (string) $parentId) {
                        $targetCollections[] = $col;
                    }
                } else {
                    // Modus B: geen parent opgegeven → toon alle root-collecties
                    if ($colParentId === null) {
                        $targetCollections[] = $col;
                    }
                }
            }
        }

        // Fallback: als nog steeds leeg, gebruik de opgegeven collectie zelf
        if (empty($targetCollections) && !empty($parentId)) {
            $targetCollections = [
                ['_id' => $parentId, 'title' => 'Bronnen', 'count' => 0]
            ];
        }

        // Haal per sub-collectie de bookmarks op
        $collections = [];
        $allTags     = [];

        foreach ($targetCollections as $col) {
            $colId    = $col['_id'];
            $response = $this->apiGet(
                "https://api.raindrop.io/rest/v1/raindrops/{$colId}?perpage={$perPage}&sort=-created",
                $token
            );

            $items = [];
            if (!empty($response['items'])) {
                foreach ($response['items'] as $item) {
                    $tags  = $item['tags'] ?? [];
                    $items[] = [
                        'id'          => $item['_id'],
                        'title'       => $item['title'] ?? '',
                        'excerpt'     => $item['excerpt'] ?? '',
                        'link'        => $item['link'] ?? '#',
                        'domain'      => $item['domain'] ?? '',
                        'tags'        => $tags,
                        'cover'       => $item['cover'] ?? '',
                        'created'     => $item['created'] ?? '',
                    ];
                    foreach ($tags as $tag) {
                        $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
                    }
                }
            }

            $collections[] = [
                'id'    => $colId,
                'title' => $col['title'] ?? 'Collectie',
                'count' => count($items),
                'items' => $items,
            ];
        }

        // Sorteer tags op frequentie (meest voorkomende eerst)
        arsort($allTags);
        $sortedTags = array_keys($allTags);

        $result = [
            'collections' => $collections,
            'all_tags'    => $sortedTags,
            'error'       => null,
        ];

        $cache->save($cacheId, $result, $cacheTtl);
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getToken(): string
    {
        // Token kan uit .env komen als RAINDROP_TOKEN, of uit plugin-config
        $envToken = getenv('RAINDROP_TOKEN');
        if ($envToken) {
            return $envToken;
        }
        return $this->config->get('plugins.sail-knowledgehub.api_token', '');
    }

    private function apiGet(string $url, string $token): ?array
    {
        if (!function_exists('curl_init')) {
            // Fallback naar file_get_contents
            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'header'  => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
                    'timeout' => 10,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return null;
            }
        }

        if (!$response) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
