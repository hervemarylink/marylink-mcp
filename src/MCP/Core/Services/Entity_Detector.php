<?php
/**
 * Entity Detector - Business entity detection service
 *
 * Automatically detects business entities (clients, projects, products,
 * mentions, tags, dates, amounts) in user text.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

class Entity_Detector {

    const VERSION = '3.0.0';

    // Entity types
    const TYPE_CLIENT = 'client';
    const TYPE_PROJECT = 'project';
    const TYPE_PRODUCT = 'product';
    const TYPE_USER = 'user';
    const TYPE_SPACE = 'space';
    const TYPE_TAG = 'tag';
    const TYPE_DATE = 'date';
    const TYPE_AMOUNT = 'amount';
    const TYPE_URL = 'url';
    const TYPE_EMAIL = 'email';

    // Detection confidence levels
    const CONFIDENCE_HIGH = 0.9;
    const CONFIDENCE_MEDIUM = 0.7;
    const CONFIDENCE_LOW = 0.5;

    /**
     * Detect all entities in text
     *
     * @param string $text Text to analyze
     * @param int $user_id User ID for context
     * @param array $options Detection options
     * @return array Detected entities
     */
    public static function detect(string $text, int $user_id, array $options = []): array {
        $entities = [
            'clients' => [],
            'projects' => [],
            'products' => [],
            'users' => [],
            'spaces' => [],
            'tags' => [],
            'dates' => [],
            'amounts' => [],
            'urls' => [],
            'emails' => [],
        ];

        // Skip detection for very short texts
        if (strlen($text) < 3) {
            return self::format_result($entities);
        }

        // Detect patterns first (mentions, tags, dates, etc.)
        $entities['users'] = self::detect_mentions($text);
        $entities['tags'] = self::detect_tags($text);
        $entities['dates'] = self::detect_dates($text);
        $entities['amounts'] = self::detect_amounts($text);
        $entities['urls'] = self::detect_urls($text);
        $entities['emails'] = self::detect_emails($text);

        // Detect business entities (require DB lookup)
        if (!isset($options['skip_business']) || !$options['skip_business']) {
            $entities['clients'] = self::detect_clients($text, $user_id);
            $entities['projects'] = self::detect_projects($text, $user_id);
            $entities['products'] = self::detect_products($text, $user_id);
            $entities['spaces'] = self::detect_spaces($text, $user_id);
        }

        return self::format_result($entities);
    }

    /**
     * Detect @mentions
     */
    public static function detect_mentions(string $text): array {
        $mentions = [];

        preg_match_all('/@([a-zA-Z0-9_]+)/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $username = $match[0];
            $position = $match[1];

            $user = get_user_by('login', $username);

            if ($user) {
                $mentions[] = [
                    'type' => self::TYPE_USER,
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'username' => $username,
                    'match' => '@' . $username,
                    'position' => $position - 1, // Adjust for @
                    'confidence' => self::CONFIDENCE_HIGH,
                ];
            } else {
                // Store as unresolved mention
                $mentions[] = [
                    'type' => self::TYPE_USER,
                    'id' => null,
                    'name' => $username,
                    'username' => $username,
                    'match' => '@' . $username,
                    'position' => $position - 1,
                    'confidence' => self::CONFIDENCE_LOW,
                    'unresolved' => true,
                ];
            }
        }

        return $mentions;
    }

    /**
     * Detect #tags
     */
    public static function detect_tags(string $text): array {
        $tags = [];

        preg_match_all('/#([a-zA-Z0-9_\-]+)/u', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $tag = $match[0];
            $position = $match[1];

            $tags[] = [
                'type' => self::TYPE_TAG,
                'value' => $tag,
                'match' => '#' . $tag,
                'position' => $position - 1,
                'confidence' => self::CONFIDENCE_HIGH,
            ];
        }

        return $tags;
    }

    /**
     * Detect dates in various formats
     */
    public static function detect_dates(string $text): array {
        $dates = [];

        // Common date patterns
        $patterns = [
            // ISO format: 2024-01-15
            '/\b(\d{4}-\d{2}-\d{2})\b/' => 'Y-m-d',
            // French format: 15/01/2024 or 15-01-2024
            '/\b(\d{2}[\/\-]\d{2}[\/\-]\d{4})\b/' => 'd/m/Y',
            // US format: 01/15/2024
            '/\b(\d{2}\/\d{2}\/\d{4})\b/' => 'm/d/Y',
            // Relative: aujourd'hui, demain, hier
            '/\b(aujourd\'?hui|demain|hier)\b/i' => 'relative',
            // French: 15 janvier 2024
            '/\b(\d{1,2}\s+(?:janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+\d{4})\b/i' => 'french',
            // English: January 15, 2024
            '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i' => 'english',
        ];

        foreach ($patterns as $pattern => $format) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                $date_str = $match[0];
                $position = $match[1];

                $parsed = self::parse_date($date_str, $format);

                $dates[] = [
                    'type' => self::TYPE_DATE,
                    'value' => $parsed['date'],
                    'original' => $date_str,
                    'format' => $format,
                    'match' => $date_str,
                    'position' => $position,
                    'confidence' => $parsed['confidence'],
                ];
            }
        }

        return $dates;
    }

    /**
     * Parse date string to standard format
     */
    private static function parse_date(string $date_str, string $format): array {
        $date = null;
        $confidence = self::CONFIDENCE_HIGH;

        switch ($format) {
            case 'relative':
                $lower = mb_strtolower($date_str);
                if (str_contains($lower, 'aujourd')) {
                    $date = date('Y-m-d');
                } elseif (str_contains($lower, 'demain')) {
                    $date = date('Y-m-d', strtotime('+1 day'));
                } elseif (str_contains($lower, 'hier')) {
                    $date = date('Y-m-d', strtotime('-1 day'));
                }
                break;

            case 'Y-m-d':
                $date = $date_str;
                break;

            case 'd/m/Y':
                $parts = preg_split('/[\/\-]/', $date_str);
                if (count($parts) === 3) {
                    $date = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
                break;

            case 'french':
            case 'english':
                $timestamp = strtotime($date_str);
                if ($timestamp) {
                    $date = date('Y-m-d', $timestamp);
                    $confidence = self::CONFIDENCE_MEDIUM;
                }
                break;

            default:
                $timestamp = strtotime($date_str);
                if ($timestamp) {
                    $date = date('Y-m-d', $timestamp);
                    $confidence = self::CONFIDENCE_LOW;
                }
        }

        return [
            'date' => $date,
            'confidence' => $confidence,
        ];
    }

    /**
     * Detect monetary amounts
     */
    public static function detect_amounts(string $text): array {
        $amounts = [];

        // Currency patterns
        $patterns = [
            // Euro: 1234€, 1 234 €, 1234.56€
            '/(\d[\d\s]*(?:[,\.]\d{1,2})?\s*€)/u' => 'EUR',
            // Dollar: $1234, $1,234.56
            '/(\$[\d,\s]+(?:\.\d{1,2})?)/u' => 'USD',
            // With currency name: 1234 euros, 1234 EUR
            '/(\d[\d\s]*(?:[,\.]\d{1,2})?\s*(?:euros?|EUR))\b/i' => 'EUR',
            '/(\d[\d\s]*(?:[,\.]\d{1,2})?\s*(?:dollars?|USD))\b/i' => 'USD',
            // Generic number with k/K/M suffix: 15k€, 1.5M€
            '/([\d\.]+\s*[kKmM]\s*€)/' => 'EUR',
        ];

        foreach ($patterns as $pattern => $currency) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                $amount_str = $match[0];
                $position = $match[1];

                $parsed = self::parse_amount($amount_str);

                $amounts[] = [
                    'type' => self::TYPE_AMOUNT,
                    'value' => $parsed['value'],
                    'currency' => $currency,
                    'original' => $amount_str,
                    'match' => $amount_str,
                    'position' => $position,
                    'confidence' => self::CONFIDENCE_HIGH,
                ];
            }
        }

        return $amounts;
    }

    /**
     * Parse amount string to numeric value
     */
    private static function parse_amount(string $amount_str): array {
        // Remove currency symbols and spaces
        $clean = preg_replace('/[€$\s]/', '', $amount_str);

        // Handle k/K/M suffixes
        $multiplier = 1;
        if (preg_match('/([kKmM])$/i', $clean, $suffix)) {
            $multiplier = strtolower($suffix[1]) === 'k' ? 1000 : 1000000;
            $clean = preg_replace('/[kKmM]$/i', '', $clean);
        }

        // Convert comma to dot for decimals
        $clean = str_replace(',', '.', $clean);

        $value = (float) $clean * $multiplier;

        return ['value' => $value];
    }

    /**
     * Detect URLs
     */
    public static function detect_urls(string $text): array {
        $urls = [];

        $pattern = '/\b(https?:\/\/[^\s<>\[\]"\']+)/i';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $url = $match[0];
            $position = $match[1];

            // Clean trailing punctuation
            $url = rtrim($url, '.,;:!?)');

            $urls[] = [
                'type' => self::TYPE_URL,
                'value' => $url,
                'match' => $url,
                'position' => $position,
                'confidence' => self::CONFIDENCE_HIGH,
            ];
        }

        return $urls;
    }

    /**
     * Detect email addresses
     */
    public static function detect_emails(string $text): array {
        $emails = [];

        $pattern = '/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $email = $match[0];
            $position = $match[1];

            $emails[] = [
                'type' => self::TYPE_EMAIL,
                'value' => $email,
                'match' => $email,
                'position' => $position,
                'confidence' => self::CONFIDENCE_HIGH,
            ];
        }

        return $emails;
    }

    /**
     * Detect clients from database
     */
    public static function detect_clients(string $text, int $user_id): array {
        return self::detect_business_entity($text, $user_id, 'ml_client', self::TYPE_CLIENT);
    }

    /**
     * Detect projects from database
     */
    public static function detect_projects(string $text, int $user_id): array {
        return self::detect_business_entity($text, $user_id, 'ml_project', self::TYPE_PROJECT);
    }

    /**
     * Detect products from database
     */
    public static function detect_products(string $text, int $user_id): array {
        return self::detect_business_entity($text, $user_id, 'ml_product', self::TYPE_PRODUCT);
    }

    /**
     * Detect spaces/groups from database
     */
    public static function detect_spaces(string $text, int $user_id): array {
        $found = [];

        // Canonical: CPT 'space'
        $space_ids = [];
        if (class_exists(\MCP_No_Headless\MCP\Core\Services\Permission_Service::class)) {
            $space_ids = \MCP_No_Headless\MCP\Core\Services\Permission_Service::get_user_space_ids($user_id);
        }

        if (!empty($space_ids)) {
            $posts = get_posts([
                'post_type' => 'space',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'post__in' => $space_ids,
            ]);

            foreach ($posts as $post) {
                $name = $post->post_title;
                if (!$name) { continue; }
                if (stripos($text, $name) !== false || stripos($text, $post->post_name) !== false) {
                    $found[] = [
                        'type' => 'space',
                        'id' => (int) $post->ID,
                        'name' => $name,
                        'confidence' => 0.85,
                        '_id_ns' => 'wp_post',
                    ];
                }
            }
        }

        if (!empty($found)) {
            return $found;
        }

        // Backward compat: BuddyBoss groups
        if (!function_exists('groups_get_groups')) {
            return [];
        }

        $groups = groups_get_groups([
            'per_page' => 50,
            'show_hidden' => true,
        ]);

        foreach ($groups['groups'] ?? [] as $group) {
            $name = $group->name ?? '';
            if ($name && stripos($text, $name) !== false) {
                $found[] = [
                    'type' => 'group',
                    'id' => (int) $group->id,
                    'name' => $name,
                    'confidence' => 0.6,
                    '_id_ns' => 'bb_group',
                    '_compat' => 'legacy_space_as_group',
                ];
            }
        }

        return $found;
    }

    /**
     * Generic business entity detection
     */
    private static function detect_business_entity(string $text, int $user_id, string $post_type, string $entity_type): array {
        global $wpdb;

        $entities = [];
        $text_lower = mb_strtolower($text);

        // Get entities accessible to user
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_name FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish'
             AND (post_author = %d OR ID IN (
                 SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ml_accessible_users'
                 AND meta_value LIKE %s
             ))
             LIMIT 200",
            $post_type, $user_id, '%"' . $user_id . '"%'
        ));

        foreach ($posts as $post) {
            $name_lower = mb_strtolower($post->post_title);

            // Exact match
            if (str_contains($text_lower, $name_lower)) {
                $pos = mb_stripos($text, $post->post_title);
                $entities[] = [
                    'type' => $entity_type,
                    'id' => (int) $post->ID,
                    'name' => $post->post_title,
                    'slug' => $post->post_name,
                    'match' => $post->post_title,
                    'position' => $pos,
                    'confidence' => self::CONFIDENCE_HIGH,
                ];
            }
            // Fuzzy match (for longer names)
            elseif (strlen($post->post_title) > 5) {
                $similarity = similar_text($text_lower, $name_lower, $percent);
                if ($percent > 70) {
                    $entities[] = [
                        'type' => $entity_type,
                        'id' => (int) $post->ID,
                        'name' => $post->post_title,
                        'slug' => $post->post_name,
                        'match' => null,
                        'position' => null,
                        'confidence' => self::CONFIDENCE_LOW,
                        'similarity' => round($percent, 1),
                    ];
                }
            }
        }

        // Sort by confidence
        usort($entities, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $entities;
    }

    /**
     * Format detection result
     */
    private static function format_result(array $entities): array {
        // Count total entities
        $total = 0;
        $by_type = [];

        foreach ($entities as $type => $items) {
            $count = count($items);
            $total += $count;
            if ($count > 0) {
                $by_type[$type] = $count;
            }
        }

        // Remove empty categories
        $entities = array_filter($entities, fn($items) => !empty($items));

        return [
            'success' => true,
            'total' => $total,
            'by_type' => $by_type,
            'entities' => $entities,
        ];
    }

    /**
     * Get entity context for prompt injection
     *
     * @param array $detected_entities Output from detect()
     * @return array Context data for prompts
     */
    public static function get_context_for_prompt(array $detected_entities): array {
        $context = [];

        // Primary client
        if (!empty($detected_entities['entities']['clients'])) {
            $client = $detected_entities['entities']['clients'][0];
            if ($client['confidence'] >= self::CONFIDENCE_MEDIUM) {
                $context['client'] = self::load_entity_details('ml_client', $client['id']);
            }
        }

        // Primary project
        if (!empty($detected_entities['entities']['projects'])) {
            $project = $detected_entities['entities']['projects'][0];
            if ($project['confidence'] >= self::CONFIDENCE_MEDIUM) {
                $context['project'] = self::load_entity_details('ml_project', $project['id']);
            }
        }

        // Primary space
        if (!empty($detected_entities['entities']['spaces'])) {
            $space = $detected_entities['entities']['spaces'][0];
            if ($space['confidence'] >= self::CONFIDENCE_MEDIUM) {
                $context['space'] = [
                    'id' => $space['id'],
                    'name' => $space['name'],
                ];
            }
        }

        // Mentioned users
        if (!empty($detected_entities['entities']['users'])) {
            $context['mentioned_users'] = array_filter(
                array_map(fn($u) => $u['unresolved'] ?? false ? null : $u['name'], $detected_entities['entities']['users'])
            );
        }

        // Tags
        if (!empty($detected_entities['entities']['tags'])) {
            $context['tags'] = array_column($detected_entities['entities']['tags'], 'value');
        }

        // Dates
        if (!empty($detected_entities['entities']['dates'])) {
            $context['dates'] = array_column($detected_entities['entities']['dates'], 'value');
        }

        return $context;
    }

    /**
     * Load full entity details for context
     */
    private static function load_entity_details(string $post_type, int $id): array {
        $post = get_post($id);

        if (!$post) {
            return [];
        }

        $details = [
            'id' => $id,
            'name' => $post->post_title,
            'description' => wp_trim_words($post->post_content, 30),
        ];

        // Load custom fields based on type
        switch ($post_type) {
            case 'ml_client':
                $details['industry'] = get_post_meta($id, '_ml_client_industry', true);
                $details['size'] = get_post_meta($id, '_ml_client_size', true);
                $details['website'] = get_post_meta($id, '_ml_client_website', true);
                break;

            case 'ml_project':
                $details['status'] = get_post_meta($id, '_ml_project_status', true);
                $details['client_id'] = get_post_meta($id, '_ml_project_client_id', true);
                $details['deadline'] = get_post_meta($id, '_ml_project_deadline', true);
                break;

            case 'ml_product':
                $details['sku'] = get_post_meta($id, '_ml_product_sku', true);
                $details['price'] = get_post_meta($id, '_ml_product_price', true);
                break;
        }

        // Remove empty values
        return array_filter($details);
    }
}
