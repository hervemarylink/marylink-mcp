<?php
/**
 * Search & Fetch Tools - OpenAI Connectors compatible tools
 *
 * Implements the mandatory search and fetch tools for ChatGPT Connectors
 * and deep research compatibility.
 *
 * @package MCP_No_Headless
 * @see https://platform.openai.com/docs/actions/getting-started
 */

namespace MCP_No_Headless\MCP;

class Search_Fetch_Tools {

    private ?Permission_Checker $permission_checker = null;
    private int $user_id = 0;

    public function execute(string $tool, array $args, int $user_id): array {
        $this->user_id = $user_id;
        $this->permission_checker = new Permission_Checker($user_id);

        switch ($tool) {
            case 'search':
                return $this->search($args);
            case 'fetch':
                return $this->fetch($args);
            default:
                throw new \Exception("Unknown tool: {$tool}");
        }
    }

    private function search(array $args): array {
        $query = sanitize_text_field($args['query'] ?? '');
        $limit = isset($args['limit']) ? max(1, min(20, (int) $args['limit'])) : 10;
        $types = $args['types'] ?? ['publication', 'space'];
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $step = isset($args['step']) ? sanitize_text_field($args['step']) : null;

        if (empty($query)) {
            throw new \Exception("Query parameter is required");
        }

        $results = [];

        if (in_array('publication', $types, true)) {
            $results = array_merge($results, $this->search_publications($query, $limit, $space_id, $step));
        }

        if (in_array('space', $types, true)) {
            $results = array_merge($results, $this->search_spaces($query, $limit));
        }

        usort($results, function($a, $b) use ($query) {
            $a_match = stripos($a['title'], $query) !== false ? 0 : 1;
            $b_match = stripos($b['title'], $query) !== false ? 0 : 1;
            return $a_match - $b_match;
        });

        return ['results' => array_slice($results, 0, $limit)];
    }

    private function search_publications(string $query, int $limit, ?int $space_id, ?string $step): array {
        $query_args = [
            'post_type' => 'publication',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => $limit * 3,
            's' => $query,
            'orderby' => 'relevance',
            'order' => 'DESC',
            'suppress_filters' => true,
        ];

        if ($space_id) {
            $query_args['post_parent'] = $space_id;
        }

        if ($step) {
            $query_args['meta_query'] = [[
                'key' => '_publication_step',
                'value' => $step,
                'compare' => '=',
            ]];
        }

        $posts = get_posts($query_args);
        $results = [];

        foreach ($posts as $post) {
            if (!$this->permission_checker->can_execute('ml_get_publication', ['publication_id' => $post->ID])) {
                continue;
            }
            $results[] = [
                'id' => 'pub:' . $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
            ];
            if (count($results) >= $limit) break;
        }

        return $results;
    }

    private function search_spaces(string $query, int $limit): array {
        $user_spaces = $this->permission_checker->get_user_spaces();
        if (empty($user_spaces)) return [];

        $posts = get_posts([
            'post_type' => 'space',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            's' => $query,
            'post__in' => $user_spaces,
            'suppress_filters' => true,
        ]);

        $results = [];
        foreach ($posts as $post) {
            $results[] = [
                'id' => 'space:' . $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
            ];
        }
        return $results;
    }

    private function fetch(array $args): array {
        $id = $args['id'] ?? '';
        $format = $args['format'] ?? 'text';

        if (empty($id)) {
            throw new \Exception("ID parameter is required");
        }

        if (preg_match('/^pub:(\d+)$/', $id, $matches)) {
            return $this->fetch_publication((int) $matches[1], $format);
        } elseif (preg_match('/^space:(\d+)$/', $id, $matches)) {
            return $this->fetch_space((int) $matches[1], $format);
        }

        throw new \Exception("Invalid ID format. Expected pub:<id> or space:<id>");
    }

    private function fetch_publication(int $publication_id, string $format): array {
        $post = get_post($publication_id);

        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found: {$publication_id}");
        }

        if (!$this->permission_checker->can_execute('ml_get_publication', ['publication_id' => $publication_id])) {
            throw new \Exception("permission_denied");
        }

        $content = $post->post_content;
        if ($format === 'html') {
            $content = apply_filters('the_content', $content);
        } elseif ($format === 'text') {
            $content = wp_strip_all_tags($content);
        }

        $truncated = false;
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n\n[TRUNCATED]";
            $truncated = true;
        }

        return [
            'id' => 'pub:' . $publication_id,
            'title' => $post->post_title,
            'text' => $content,
            'url' => get_permalink($post->ID),
            'truncated' => $truncated,
        ];
    }

    private function fetch_space(int $space_id, string $format): array {
        $post = get_post($space_id);

        if (!$post || $post->post_type !== 'space') {
            throw new \Exception("Space not found: {$space_id}");
        }

        if (!$this->permission_checker->can_see_space($space_id)) {
            throw new \Exception("permission_denied");
        }

        $content = $post->post_content;
        if ($format === 'html') {
            $content = apply_filters('the_content', $content);
        } elseif ($format === 'text') {
            $content = wp_strip_all_tags($content);
        }

        $steps = get_post_meta($space_id, '_space_steps', true);
        if (!empty($steps) && is_array($steps)) {
            $content .= "\n\n## Workflow Steps\n";
            foreach ($steps as $step) {
                $content .= "- " . ($step['label'] ?? $step['name'] ?? '') . "\n";
            }
        }

        return [
            'id' => 'space:' . $space_id,
            'title' => $post->post_title,
            'text' => $content,
            'url' => get_permalink($post->ID),
        ];
    }

    public static function get_tool_definitions(): array {
        return [
            [
                'name' => 'search',
                'description' => 'Search publications and spaces. Returns citable results with IDs for use with fetch.',
                'category' => 'OpenAI Connectors',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'Search query'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10],
                        'types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['publication', 'space']], 'default' => ['publication', 'space']],
                        'space_id' => ['type' => 'integer', 'description' => 'Optional: restrict to this space'],
                        'step' => ['type' => 'string', 'description' => 'Optional: filter by workflow step'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
            [
                'name' => 'fetch',
                'description' => 'Fetch full content of a publication or space by ID (from search results).',
                'category' => 'OpenAI Connectors',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'ID from search: pub:<id> or space:<id>'],
                        'format' => ['type' => 'string', 'enum' => ['text', 'markdown', 'html'], 'default' => 'text'],
                    ],
                    'required' => ['id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],
        ];
    }
}
