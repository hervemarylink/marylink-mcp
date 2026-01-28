<?php
/**
 * Tool Service - Business logic for tool publications
 *
 * Handles:
 * - Tool resolution with context assembly
 * - Input validation against tool schemas
 * - Dependency tree resolution
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Tool_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);

        // Initialize URL resolver if available
        if (class_exists(URL_Resolver::class)) {
            $this->resolver = new URL_Resolver($user_id);
        }
    }

    /**
     * Resolve a tool with its full context tree
     *
     * @param int $tool_id Tool (publication) ID
     * @return array|null Resolved tool or null if not accessible
     */
    public function resolve_tool(int $tool_id): ?array {
        if (!$this->permissions->can_use_tool($tool_id)) {
            return null;
        }

        $post = get_post($tool_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        $tool = [
            'id' => $tool_id,
            'title' => $post->post_title,
            'type' => $this->get_tool_type($tool_id),
            'instruction' => Picasso_Adapter::get_tool_instruction($tool_id),
        ];

        // Get linked dependencies
        $tool['context'] = $this->assemble_context($tool_id);

        // Get input schema if defined
        $schema = get_post_meta($tool_id, '_ml_input_schema', true);
        if (!empty($schema)) {
            $tool['input_schema'] = is_string($schema) ? json_decode($schema, true) : $schema;
        }

        // Get output format
        $output_format = get_post_meta($tool_id, '_ml_output_format', true);
        if (!empty($output_format)) {
            $tool['output_format'] = $output_format;
        }

        return $tool;
    }

    /**
     * Validate input against a tool's schema
     *
     * @param int $tool_id Tool ID
     * @param array $input Input to validate
     * @return array Validation result with ok, errors
     */
    public function validate_input(int $tool_id, array $input): array {
        if (!$this->permissions->can_use_tool($tool_id)) {
            return [
                'ok' => false,
                'errors' => ['Tool not accessible.'],
            ];
        }

        $schema = get_post_meta($tool_id, '_ml_input_schema', true);
        if (empty($schema)) {
            // No schema = any input accepted
            return [
                'ok' => true,
                'errors' => [],
                'warnings' => ['No input schema defined for this tool.'],
            ];
        }

        if (is_string($schema)) {
            $schema = json_decode($schema, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'ok' => false,
                    'errors' => ['Invalid schema definition in tool.'],
                ];
            }
        }

        $errors = $this->validate_against_schema($input, $schema);

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Assemble context from linked dependencies
     */
    private function assemble_context(int $tool_id): array {
        $context = [
            'styles' => [],
            'data' => [],
            'docs' => [],
        ];

        // Get linked styles
        $style_ids = Picasso_Adapter::get_tool_linked_styles($tool_id);
        foreach ($style_ids as $style_id) {
            if ($this->permissions->can_see_publication($style_id)) {
                $style_post = get_post($style_id);
                if ($style_post) {
                    $context['styles'][] = [
                        'id' => $style_id,
                        'title' => $style_post->post_title,
                        'content' => Render_Service::html_to_text($style_post->post_content),
                    ];
                }
            }
        }

        // Get linked contents/data
        $content_ids = Picasso_Adapter::get_tool_linked_contents($tool_id);
        foreach ($content_ids as $content_id) {
            if ($this->permissions->can_see_publication($content_id)) {
                $content_post = get_post($content_id);
                if ($content_post) {
                    $type = $this->categorize_content_type($content_id);
                    $item = [
                        'id' => $content_id,
                        'title' => $content_post->post_title,
                        'content' => Render_Service::html_to_text($content_post->post_content),
                    ];

                    if ($type === 'data') {
                        $context['data'][] = $item;
                    } else {
                        $context['docs'][] = $item;
                    }
                }
            }
        }

        return $context;
    }

    /**
     * Categorize content type
     */
    private function categorize_content_type(int $publication_id): string {
        $type = get_post_meta($publication_id, '_ml_publication_type', true);

        switch ($type) {
            case 'data':
            case 'dataset':
                return 'data';
            case 'style':
            case 'template':
                return 'style';
            case 'doc':
            case 'documentation':
            default:
                return 'doc';
        }
    }

    /**
     * Get tool type
     */
    private function get_tool_type(int $tool_id): string {
        $type = get_post_meta($tool_id, '_ml_publication_type', true);
        return $type ?: 'tool';
    }

    /**
     * Validate input against JSON schema
     */
    private function validate_against_schema(array $input, array $schema): array {
        $errors = [];

        // Check required fields
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!isset($input[$field]) || $input[$field] === '') {
                    $errors[] = sprintf('Required field "%s" is missing.', $field);
                }
            }
        }

        // Check properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($input as $key => $value) {
                if (!isset($schema['properties'][$key])) {
                    // Unknown field, could warn
                    continue;
                }

                $prop_schema = $schema['properties'][$key];
                $field_errors = $this->validate_field($key, $value, $prop_schema);
                $errors = array_merge($errors, $field_errors);
            }
        }

        return $errors;
    }

    /**
     * Validate a single field
     */
    private function validate_field(string $field, $value, array $schema): array {
        $errors = [];

        // Type check
        if (isset($schema['type'])) {
            $type = $schema['type'];
            $actual_type = gettype($value);

            $type_map = [
                'string' => 'string',
                'integer' => 'integer',
                'number' => ['integer', 'double'],
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array', // PHP arrays for objects
            ];

            $expected = $type_map[$type] ?? null;
            if ($expected !== null) {
                $valid = is_array($expected)
                    ? in_array($actual_type, $expected, true)
                    : $actual_type === $expected;

                if (!$valid && !($type === 'number' && is_numeric($value))) {
                    $errors[] = sprintf('Field "%s" must be of type %s.', $field, $type);
                }
            }
        }

        // Enum check
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $errors[] = sprintf(
                    'Field "%s" must be one of: %s.',
                    $field,
                    implode(', ', $schema['enum'])
                );
            }
        }

        // String constraints
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                $errors[] = sprintf('Field "%s" must be at least %d characters.', $field, $schema['minLength']);
            }
            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                $errors[] = sprintf('Field "%s" must be at most %d characters.', $field, $schema['maxLength']);
            }
        }

        // Number constraints
        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = sprintf('Field "%s" must be at least %s.', $field, $schema['minimum']);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = sprintf('Field "%s" must be at most %s.', $field, $schema['maximum']);
            }
        }

        return $errors;
    }

    /**
     * Check if tool publications feature is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
