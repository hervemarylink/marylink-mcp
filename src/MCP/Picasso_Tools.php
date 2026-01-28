<?php
/**
 * Picasso Tools v4.1 - Executes MaryLink MCP tool actions
 *
 * CORRECTIONS v4 (basées sur review ChatGPT):
 * - list_publications() scanne plusieurs pages et filtre via can_execute() (pas juste par espaces)
 * - get_publication() utilise can_execute() comme source unique de vérité
 * - Support meta keys singulier ET pluriel (_co_author + _co_authors)
 * - Méthode get_publication_people_meta() pour normaliser
 *
 * CORRECTIONS v4.1:
 * - suppress_filters dans WP_Query pour bypasser le filtre Picasso sur WP user connecté
 *   (le MCP utilise son propre user_id, pas celui de la session WordPress)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Picasso\Meta_Keys;

use MCP_No_Headless\Schema\Publication_Schema;

class Picasso_Tools {

    /**
     * Permission checker instance
     */
    private ?Permission_Checker $permission_checker = null;

    /**
     * Current user ID
     */
    private int $user_id = 0;

    /**
     * Execute a tool action
     */
    public function execute(string $tool, array $args, int $user_id) {
        $this->user_id = $user_id;
        $this->permission_checker = new Permission_Checker($user_id);

        switch ($tool) {
            case 'ml_list_publications':
                return $this->list_publications($args);

            case 'ml_get_publication':
                return $this->get_publication($args['publication_id']);

            case 'ml_list_spaces':
                return $this->list_spaces($args);

            case 'ml_get_space':
                return $this->get_space($args['space_id']);

            case 'ml_get_my_context':
                return $this->get_user_context($user_id);

            case 'ml_create_publication':
            case 'ml_create_publication_from_text':
                return $this->create_publication($args, $user_id);

            case 'ml_edit_publication':
                return $this->edit_publication($args);

            case 'ml_append_to_publication':
                return $this->append_to_publication($args);

            case 'ml_add_comment':
            case 'ml_import_as_comment':
                return $this->add_comment($args, $user_id);

            case 'ml_create_review':
                return $this->create_review($args, $user_id);

            case 'ml_move_to_step':
                return $this->move_to_step($args);

            default:
                throw new \Exception("Unknown tool: {$tool}");
        }
    }

    // ========================================
    // Read operations (CORRIGÉES v4)
    // ========================================

    /**
     * List publications with filters
     * CORRIGÉ v4: Scanne plusieurs pages et filtre via can_execute() pour chaque publication
     * Cela gère tous les cas: co-auteur, team, expert, invité, groupe, public, etc.
     * CORRIGÉ v4.1: suppress_filters pour bypasser le filtre Picasso sur WP user connecté
     */
    private function list_publications(array $args): array {
        $limit = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 10;
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;

        $query_base = [
            'post_type' => 'publication',
            // Inclure aussi brouillons/en attente (le filtre final = Permission_Checker)
            'post_status' => ['publish', 'draft', 'pending'],
            // Sur-échantillonnage: on filtre ensuite via can_execute
            'posts_per_page' => $limit * 5,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true, // IMPORTANT: bypass Picasso query filter
        ];

        // Filter by space
        if (!empty($args['space_id'])) {
            $query_base['post_parent'] = (int) $args['space_id'];
        }

        // Filter by author
        if (!empty($args['author_id'])) {
            $query_base['author'] = (int) $args['author_id'];
        }

        // Search
        if (!empty($args['search'])) {
            $query_base['s'] = sanitize_text_field($args['search']);
        }

        // Filter by step
        if (!empty($args['step'])) {
            $query_base['meta_query'] = $query_base['meta_query'] ?? [];
            $query_base['meta_query'][] = [
                'key' => '_publication_step',
                'value' => sanitize_text_field($args['step']),
                'compare' => '=',
            ];
        }


// Filter by label/type (taxonomy: publication_label)
if (!empty($args['type'])) {
    $type_terms = $this->resolve_term_ids($args['type'], 'publication_label');
    if (!empty($type_terms)) {
        $query_base['tax_query'] = $query_base['tax_query'] ?? [];
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_label',
            'field' => 'term_id',
            'terms' => $type_terms,
        ];
    }
}

// Filter by tags (taxonomy: publication_tag)
if (!empty($args['tags'])) {
    $tag_terms = $this->resolve_term_ids($args['tags'], 'publication_tag');
    if (!empty($tag_terms)) {
        $query_base['tax_query'] = $query_base['tax_query'] ?? [];
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_tag',
            'field' => 'term_id',
            'terms' => $tag_terms,
        ];
    }
}

// Period filter (7d, 30d, 90d, 1y, all)
if (!empty($args['period']) && $args['period'] !== 'all') {
    $date_query = $this->build_date_query($args['period']);
    if ($date_query) {
        $query_base['date_query'] = $date_query;
    }
}

// Sorting
$sort = $args['sort'] ?? 'newest';
switch ($sort) {
    case 'oldest':
        $query_base['orderby'] = 'date';
        $query_base['order'] = 'ASC';
        break;

    case 'best_rated':
        $query_base['meta_key'] = '_ml_average_rating';
        $query_base['orderby'] = 'meta_value_num';
        $query_base['order'] = 'DESC';
        // Only publications with at least 1 rating
        $query_base['meta_query'] = $query_base['meta_query'] ?? [];
        $query_base['meta_query'][] = [
            'key' => Meta_Keys::RATING_COUNT,
            'value' => 0,
            'compare' => '>',
            'type' => 'NUMERIC',
        ];
        break;

    case 'worst_rated':
        $query_base['meta_key'] = '_ml_average_rating';
        $query_base['orderby'] = 'meta_value_num';
        $query_base['order'] = 'ASC';
        $query_base['meta_query'] = $query_base['meta_query'] ?? [];
        $query_base['meta_query'][] = [
            'key' => Meta_Keys::RATING_COUNT,
            'value' => 0,
            'compare' => '>',
            'type' => 'NUMERIC',
        ];
        break;

    case 'most_rated':
        $query_base['meta_key'] = '_ml_rating_count';
        $query_base['orderby'] = 'meta_value_num';
        $query_base['order'] = 'DESC';
        break;

    case 'most_liked':
        $query_base['meta_key'] = '_ml_favorites_count';
        $query_base['orderby'] = 'meta_value_num';
        $query_base['order'] = 'DESC';
        break;

    case 'most_commented':
        $query_base['orderby'] = 'comment_count';
        $query_base['order'] = 'DESC';
        break;

    case 'trending':
                $query_base['orderby'] = 'modified';
                $query_base['order'] = 'DESC';
        break;

    case 'newest':
    default:
        $query_base['orderby'] = 'date';
        $query_base['order'] = 'DESC';
        break;
}

        $publications = [];
        $seen_ids = [];
        $space_ids = [];
        $raw_total = 0;

        // Scanner plusieurs pages pour remplir $limit après filtrage permissions
        $max_pages_to_scan = 5;

        for ($i = 0; $i < $max_pages_to_scan && count($publications) < $limit; $i++) {
            $query_args = $query_base;
            $query_args['paged'] = $page + $i;

            $query = new \WP_Query($query_args);

            if ($i === 0) {
                $raw_total = (int) $query->found_posts;
            }

            foreach ($query->posts as $post) {
                if (isset($seen_ids[$post->ID])) {
                    continue;
                }
                $seen_ids[$post->ID] = true;

                // Source de vérité: Permission_Checker (qui délègue à Picasso quand dispo)
                if (!$this->permission_checker->can_execute('ml_get_publication', ['publication_id' => $post->ID])) {
                    continue;
                }

                $publications[] = $this->format_publication_summary($post);

                $sid = (int) ($post->post_parent ?: get_post_meta($post->ID, '_publication_space', true));
                if ($sid > 0) {
                    $space_ids[$sid] = true;
                }

                if (count($publications) >= $limit) {
                    break;
                }
            }

            if ((int) $query->max_num_pages <= ($page + $i)) {
                break;
            }
        }

        if (empty($publications)) {
            return [
                'count' => 0,
                'total' => 0,
                'total_accessible' => 0,
                'accessible_spaces' => 0,
                'publications' => [],
                'message' => 'Aucune publication accessible pour cet utilisateur',
            ];
        }

        return [
            'count' => count($publications),
            'total' => $raw_total,
            'total_accessible' => count($publications),
            'accessible_spaces' => count($space_ids),
            'publications' => $publications,
        ];
    }

    /**
     * Get single publication details
     * CORRIGÉ v4: Utilise can_execute() comme source unique de vérité
     */
    private function get_publication(int $publication_id): array {
        $post = get_post($publication_id);

        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found: {$publication_id}");
        }

        // Source de vérité = Permission_Checker / Picasso
        if (!$this->permission_checker->can_execute('ml_get_publication', ['publication_id' => $publication_id])) {
            throw new \Exception("Accès refusé à cette publication");
        }

        return $this->format_publication_full($post);
    }

    /**
     * List spaces
     * CORRIGÉ v4.1: Utilise get_posts avec suppress_filters pour bypasser le filtre Picasso
     * qui sinon filtre sur l'utilisateur WordPress connecté (pas celui du MCP)
     */
    private function list_spaces(array $args): array {
        $user_spaces = $this->permission_checker->get_user_spaces();

        if (empty($user_spaces)) {
            return [
                'count' => 0,
                'spaces' => [],
                'message' => 'Aucun espace accessible pour cet utilisateur',
            ];
        }

        $query_args = [
            'post_type' => 'space',
            'post_status' => 'publish',
            'numberposts' => $args['limit'] ?? 20,
            'orderby' => 'title',
            'order' => 'ASC',
            'post__in' => $user_spaces,
            'suppress_filters' => true, // IMPORTANT: bypass Picasso query filter
        ];

        if (!empty($args['search'])) {
            $query_args['s'] = $args['search'];
        }

        $posts = get_posts($query_args);
        $spaces = [];

        $user_pub_spaces = $this->permission_checker->get_user_publication_spaces();

        foreach ($posts as $post) {
            $spaces[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'excerpt' => wp_trim_words($post->post_content, 30),
                'url' => get_permalink($post->ID),
                'can_see_publications' => in_array($post->ID, $user_pub_spaces, true),
            ];
        }

        return [
            'count' => count($spaces),
            'total_accessible' => count($user_spaces),
            'spaces' => $spaces,
        ];
    }

    /**
     * Get single space details
     */
    private function get_space(int $space_id): array {
        $post = get_post($space_id);

        if (!$post || $post->post_type !== 'space') {
            throw new \Exception("Space not found: {$space_id}");
        }

        if (!$this->permission_checker->can_see_space($space_id)) {
            throw new \Exception("Accès refusé à cet espace");
        }

        $user_pub_spaces = $this->permission_checker->get_user_publication_spaces();

$labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
if (is_wp_error($labels)) {
    $labels = [];
}

$tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
if (is_wp_error($tags)) {
    $tags = [];
}

$avg_rating = Meta_Keys::get_rating_avg($post->ID);
$rating_count = Meta_Keys::get_rating_count($post->ID);
$favorites_count = Meta_Keys::get_favorites_count($post->ID);

$quality_score = Meta_Keys::get_quality_score($post->ID);

$engagement_score = Meta_Keys::get_engagement_score($post->ID);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'url' => get_permalink($post->ID),
            'steps' => $this->get_space_steps($space_id),
            'can_see_publications' => in_array($space_id, $user_pub_spaces, true),
        ];
    }

    /**
     * Get user AI context
     */
    private function get_user_context(int $user_id): array {
        $user = get_userdata($user_id);

        if (!$user) {
            throw new \Exception("User not found");
        }

        $ai_context = '';
        if (function_exists('bp_get_profile_field_data')) {
            $ai_context = bp_get_profile_field_data([
                'field' => 186,
                'user_id' => $user_id,
            ]);
        }

        if (empty($ai_context)) {
            $ai_context = get_user_meta($user_id, '_mlai_user_context', true);
        }

        $user_spaces = $this->permission_checker->get_user_spaces();
        $user_pub_spaces = $this->permission_checker->get_user_publication_spaces();

        return [
            'user_id' => $user_id,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'roles' => $user->roles,
            'ai_context' => $ai_context ?: 'No AI context defined.',
            'member_since' => $user->user_registered,
            'accessible_spaces' => count($user_spaces),
            'spaces_with_publications' => count($user_pub_spaces),
        ];
    }

    // ========================================
    // Write operations
    // ========================================

    private function create_publication(array $args, int $user_id): array {
        $title = sanitize_text_field($args['title']);
        $content = wp_kses_post($args['text'] ?? $args['content'] ?? '');
        $space_id = absint($args['space_id']);
        $status = ($args['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';

        if ($space_id > 0 && !$this->permission_checker->can_see_space($space_id)) {
            throw new \Exception("Vous n'avez pas accès à cet espace");
        }

        if (!empty($args['source'])) {
            $content .= "\n\n---\n*Importé depuis: " . sanitize_text_field($args['source']) . "*";
        }

        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $status,
            'post_author' => $user_id,
            'post_type' => 'publication',
            'post_parent' => $space_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            throw new \Exception($post_id->get_error_message());
        }

        update_post_meta($post_id, '_publication_space', $space_id);

        // Dual-write: sync legacy _ml_space_id based on schema mode
        Publication_Schema::set_space_id($post_id, $space_id);

        // Set step - use provided step or default based on status
        $step = isset($args["step"]) ? sanitize_text_field($args["step"]) : ($status === "publish" ? "published" : "draft");
        Publication_Schema::set_step($post_id, $step);

        // Set excerpt if provided
        if (!empty($args["excerpt"])) {
            wp_update_post([
                "ID" => $post_id,
                "post_excerpt" => sanitize_textarea_field($args["excerpt"]),
            ]);
        }

        // Set type (publication_label taxonomy) if provided
        if (!empty($args["type"])) {
            $type_term = $this->ensure_term_exists($args["type"], "publication_label");
            if ($type_term) {
                wp_set_object_terms($post_id, [$type_term], "publication_label", false);
            }
        }

        // Set labels (additional publication_label terms) if provided
        if (!empty($args["labels"]) && is_array($args["labels"])) {
            $label_terms = [];
            foreach ($args["labels"] as $label) {
                $term = $this->ensure_term_exists($label, "publication_label");
                if ($term) {
                    $label_terms[] = $term;
                }
            }
            if (!empty($label_terms)) {
                wp_set_object_terms($post_id, $label_terms, "publication_label", true);
            }
        }

        // Set tags (publication_tag taxonomy) if provided
        if (!empty($args["tags"]) && is_array($args["tags"])) {
            $tag_terms = [];
            foreach ($args["tags"] as $tag) {
                $term = $this->ensure_term_exists($tag, "publication_tag");
                if ($term) {
                    $tag_terms[] = $term;
                }
            }
            if (!empty($tag_terms)) {
                wp_set_object_terms($post_id, $tag_terms, "publication_tag", false);
            }
        }

        do_action("pb_post_saved", $post_id, false);
        return [
            'success' => true,
            'publication_id' => $post_id,
            'title' => $title,
            'status' => $status,
            'space_id' => $space_id,
            'url' => get_permalink($post_id),
            'message' => "Publication '{$title}' créée avec succès (ID: {$post_id})",
        ];
    }

    private function edit_publication(array $args): array {
        $post_id = absint($args['publication_id']);
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found");
        }

        $update_data = ['ID' => $post_id];

        if (!empty($args['title'])) {
            $update_data['post_title'] = sanitize_text_field($args['title']);
        }

        if (!empty($args['content'])) {
            $update_data['post_content'] = wp_kses_post($args['content']);
        }

        // Handle excerpt
        if (isset($args["excerpt"])) {
            $update_data["post_excerpt"] = sanitize_textarea_field($args["excerpt"]);
        }


        // Handle space_id change
        if (isset($args["space_id"])) {
            $new_space_id = absint($args["space_id"]);
            if ($new_space_id > 0 && !$this->permission_checker->can_see_space($new_space_id)) {
                throw new \Exception("Vous n avez pas acces a cet espace");
            }
            $update_data["post_parent"] = $new_space_id;
            update_post_meta($post_id, "_publication_space", $new_space_id);
            Publication_Schema::set_space_id($post_id, $new_space_id);
        }
        $result = wp_update_post($update_data, true);

        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

// VERSION: Auto-increment on edit
        $current_version = get_post_meta($post_id, '_ml_version', true) ?: '1.0';
        $parts = explode('.', $current_version);
        $minor = isset($parts[1]) ? (int)$parts[1] + 1 : 1;
        $new_version = $parts[0] . '.' . $minor;
        update_post_meta($post_id, '_ml_version', $new_version);
        update_post_meta($post_id, '_ml_version_date', current_time('mysql'));


        // Set step if explicitly provided
        if (!empty($args["step"])) {
            Publication_Schema::set_step($post_id, sanitize_text_field($args["step"]));
        }

        // Set type (publication_label taxonomy) if provided
        if (!empty($args["type"])) {
            $type_term = $this->ensure_term_exists($args["type"], "publication_label");
            if ($type_term) {
                wp_set_object_terms($post_id, [$type_term], "publication_label", false);
            }
        }

        // Set labels (additional publication_label terms) if provided
        if (!empty($args["labels"]) && is_array($args["labels"])) {
            $label_terms = [];
            foreach ($args["labels"] as $label) {
                $term = $this->ensure_term_exists($label, "publication_label");
                if ($term) {
                    $label_terms[] = $term;
                }
            }
            if (!empty($label_terms)) {
                wp_set_object_terms($post_id, $label_terms, "publication_label", true);
            }
        }

        // Set tags (publication_tag taxonomy) if provided
        if (!empty($args["tags"]) && is_array($args["tags"])) {
            $tag_terms = [];
            foreach ($args["tags"] as $tag) {
                $term = $this->ensure_term_exists($tag, "publication_tag");
                if ($term) {
                    $tag_terms[] = $term;
                }
            }
            if (!empty($tag_terms)) {
                wp_set_object_terms($post_id, $tag_terms, "publication_tag", false);
            }
        }

        // Sync step if status changed (only if step not explicitly set above)
        if (empty($args["step"])) {
            $updated_post = get_post($post_id);
            if ($updated_post && $updated_post->post_status === "publish") {
                Publication_Schema::set_step($post_id, "published");
            }
        }
        return [
            'success' => true,
            'publication_id' => $post_id,
            'version' => $new_version,
            'message' => "Publication mise à jour avec succès",
        ];
    }

    private function append_to_publication(array $args): array {
        $post_id = absint($args['publication_id']);
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found");
        }

        $separator = $args['separator'] ?? "\n\n---\n\n";
        $new_content = $post->post_content . $separator . wp_kses_post($args['content']);

        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        return [
            'success' => true,
            'publication_id' => $post_id,
            'message' => "Contenu ajouté à la publication",
        ];
    }

    private function add_comment(array $args, int $user_id): array {
        $publication_id = absint($args['publication_id']);
        $content = sanitize_textarea_field($args['content']);
        $type = $args['type'] ?? 'public';
        $parent_id = $args['parent_id'] ?? 0;

        if (!empty($args['source'])) {
            $content = "[Importé depuis: " . sanitize_text_field($args['source']) . "]\n\n" . $content;
        }

        $user = get_userdata($user_id);

        $comment_data = [
            'comment_post_ID' => $publication_id,
            'comment_content' => $content,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $user_id,
            'comment_parent' => $parent_id,
            'comment_approved' => 1,
        ];

        $comment_id = wp_insert_comment($comment_data);

        if (!$comment_id) {
            throw new \Exception("Failed to create comment");
        }

        if ($type === 'private') {
            update_comment_meta($comment_id, '_comment_type', 'private');
        }

        return [
            'success' => true,
            'comment_id' => $comment_id,
            'type' => $type,
            'message' => "Commentaire ajouté avec succès",
        ];
    }

    private function create_review(array $args, int $user_id): array {
        $publication_id = absint($args['publication_id']);
        $rating = max(1, min(5, absint($args['rating'])));
        $content = sanitize_textarea_field($args['content'] ?? '');
        $type = $args['type'] ?? 'user';

        $review_data = [
            'publication_id' => $publication_id,
            'user_id' => $user_id,
            'rating' => $rating,
            'content' => $content,
            'type' => $type,
            'date' => current_time('mysql'),
        ];

        if (class_exists('Picasso_Backend\\Submission\\Review')) {
            $review_key = "review_{$publication_id}_{$user_id}_" . time();
            update_post_meta($publication_id, $review_key, $review_data);
        } else {
            $existing_reviews = get_post_meta($publication_id, '_publication_reviews', true) ?: [];
            $existing_reviews[] = $review_data;
            update_post_meta($publication_id, '_publication_reviews', $existing_reviews);
        }

        return [
            'success' => true,
            'publication_id' => $publication_id,
            'rating' => $rating,
            'type' => $type,
            'message' => "Review ({$rating}/5) ajoutée avec succès",
        ];
    }

    private function move_to_step(array $args): array {
        $publication_id = absint($args['publication_id']);
        $step_name = sanitize_text_field($args['step_name']);

        $post = get_post($publication_id);

        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found");
        }

        $old_step = get_post_meta($publication_id, '_publication_step', true);
        update_post_meta($publication_id, '_publication_step', $step_name);

        do_action('publication_step_changed', $publication_id, $step_name, $old_step);

        return [
            'success' => true,
            'publication_id' => $publication_id,
            'old_step' => $old_step,
            'new_step' => $step_name,
            'message' => "Publication déplacée vers le step '{$step_name}'",
        ];
    }

    // ========================================
    // Helper methods (CORRIGÉES v4)
    // ========================================

    /**
     * Read people meta for a publication.
     * Supports both singular and plural meta keys used by Picasso.
     * NOUVEAU v4
     */
    private function get_publication_people_meta(int $post_id, array $meta_keys): array {
        $values = [];

        foreach ($meta_keys as $key) {
            // Some keys are stored as multiple meta rows
            $multi = get_post_meta($post_id, $key, false);
            if (!empty($multi)) {
                $values = array_merge($values, $multi);
                continue;
            }

            // Other installs store a single row containing array or scalar
            $single = get_post_meta($post_id, $key, true);
            if (is_array($single)) {
                $values = array_merge($values, $single);
            } elseif ($single !== '' && $single !== null) {
                $values[] = $single;
            }
        }

        // Normalize: array of unique positive ints
        $values = array_map('intval', $values);
        $values = array_values(array_unique(array_filter($values, static fn($v) => $v > 0)));

        return $values;
    }

    /**
     * Format publication summary
     */

/**
 * Resolve a term (or list of terms) into term IDs for a taxonomy.
 * Accepts: term_id, slug, or name.
 */

    /**
     * Ensure a taxonomy term exists, creating it if necessary.
     * Returns the term ID or null if failed.
     */
    private function ensure_term_exists($term_input, string $taxonomy): ?int {
        if (empty($term_input)) {
            return null;
        }
        if (is_int($term_input) || (is_string($term_input) && ctype_digit($term_input))) {
            $term = get_term((int) $term_input, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }
        $term_input = trim((string) $term_input);
        if ($term_input === "") {
            return null;
        }
        $term = get_term_by("slug", sanitize_title($term_input), $taxonomy);
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
        $term = get_term_by("name", $term_input, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
        $result = wp_insert_term($term_input, $taxonomy, ["slug" => sanitize_title($term_input)]);
        if (!is_wp_error($result) && isset($result["term_id"])) {
            return (int) $result["term_id"];
        }
        return null;
    }

private function resolve_term_ids($input, string $taxonomy): array {
    $terms = is_array($input) ? $input : [$input];
    $ids = [];

    foreach ($terms as $t) {
        if ($t === null) {
            continue;
        }

        if (is_int($t) || (is_string($t) && ctype_digit($t))) {
            $ids[] = (int) $t;
            continue;
        }

        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }

        $term = get_term_by('slug', $t, $taxonomy);
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($t), $taxonomy);
        }
        if (!$term) {
            $term = get_term_by('name', $t, $taxonomy);
        }

        if ($term && !is_wp_error($term)) {
            $ids[] = (int) $term->term_id;
        }
    }

    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}

/**
 * Build a WP date_query array from a compact period string.
 */
private function build_date_query(string $period): ?array {
    $map = [
        '7d' => '-7 days',
        '30d' => '-30 days',
        '90d' => '-90 days',
        '1y' => '-1 year',
    ];

    if (!isset($map[$period])) {
        return null;
    }

    return [
        [
            'after' => $map[$period],
            'inclusive' => true,
        ],
    ];
}

    private function format_publication_summary(\WP_Post $post): array {
        $space_id = (int) $post->post_parent;
        if ($space_id <= 0) {
            $space_id = (int) get_post_meta($post->ID, '_publication_space', true);
        }

        $labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
        if (is_wp_error($labels)) {
            $labels = [];
        }

        $tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
        if (is_wp_error($tags)) {
            $tags = [];
        }

        $avg_rating = Meta_Keys::get_rating_avg($post->ID);
        $rating_count = Meta_Keys::get_rating_count($post->ID);
        $favorites_count = Meta_Keys::get_favorites_count($post->ID);

        $quality_score = Meta_Keys::get_quality_score($post->ID);

        $engagement_score = Meta_Keys::get_engagement_score($post->ID);

        $excerpt_source = !empty($post->post_excerpt) ? $post->post_excerpt : $post->post_content;
        $excerpt_text = wp_strip_all_tags(apply_filters('the_content', $excerpt_source));
        $excerpt = wp_trim_words($excerpt_text, 50);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => $excerpt,
            'author_id' => $post->post_author,
            'author_name' => get_the_author_meta('display_name', $post->post_author),
            'date' => $post->post_date,
            'status' => $post->post_status,
            'space_id' => $space_id,
            'space_title' => $space_id ? get_the_title($space_id) : null,
            'step' => get_post_meta($post->ID, '_publication_step', true) ?: 'submit',
            'labels' => $labels,
            'type' => !empty($labels) ? $labels[0] : null,
            'tags' => $tags,
            'rating' => [
                'average' => round($avg_rating, 2),
                'count' => $rating_count,
            ],
            'favorites_count' => $favorites_count,
            'comment_count' => (int) $post->comment_count,
            'quality_score' => $quality_score,
            'engagement_score' => $engagement_score,
            'url' => get_permalink($post->ID),
        ];
    }


    /**
     * Format full publication details
     * CORRIGÉ v4: Support singulier + pluriel pour meta keys
     */
    private function format_publication_full(\WP_Post $post): array {
        $space_id = (int) $post->post_parent;
        if ($space_id <= 0) {
            $space_id = (int) get_post_meta($post->ID, '_publication_space', true);
        }

        // Support singulier ET pluriel (compatibilité Picasso)
        $co_author_ids = $this->get_publication_people_meta($post->ID, ['_publication_co_author', '_publication_co_authors']);
        $expert_ids = $this->get_publication_people_meta($post->ID, ['_publication_expert', '_publication_experts']);
        $team_ids = $this->get_publication_people_meta($post->ID, ['_in_publication_team']);

        // Formatter les users
        $co_authors = [];
        foreach ($co_author_ids as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $co_authors[] = ['id' => $uid, 'name' => $u->display_name];
            }
        }

        $experts = [];
        foreach ($expert_ids as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $experts[] = ['id' => $uid, 'name' => $u->display_name];
            }
        }

        $team = [];
        foreach ($team_ids as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $team[] = ['id' => $uid, 'name' => $u->display_name];
            }
        }

        // Get labels and tags
        $labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
        if (is_wp_error($labels)) {
            $labels = [];
        }

        $tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
        if (is_wp_error($tags)) {
            $tags = [];
        }

        // Get ratings and scores
        $avg_rating = Meta_Keys::get_rating_avg($post->ID);
        $rating_count = Meta_Keys::get_rating_count($post->ID);
        $favorites_count = Meta_Keys::get_favorites_count($post->ID);

        $quality_score = Meta_Keys::get_quality_score($post->ID);

        $engagement_score = Meta_Keys::get_engagement_score($post->ID);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'content_html' => apply_filters('the_content', $post->post_content),
            'author_id' => $post->post_author,
            'author_name' => get_the_author_meta('display_name', $post->post_author),
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'status' => $post->post_status,
            'space_id' => $space_id,
            'space_title' => $space_id ? get_the_title($space_id) : null,
            'step' => get_post_meta($post->ID, '_publication_step', true) ?: 'submit',
            'co_authors' => $co_authors,
            'experts' => $experts,
'team' => $team,
'labels' => $labels,
'type' => !empty($labels) ? $labels[0] : null,
'tags' => $tags,
'rating' => [
    'average' => round($avg_rating, 2),
    'count' => $rating_count,
],
'favorites_count' => $favorites_count,
'comment_count' => (int) $post->comment_count,
'quality_score' => $quality_score,
'engagement_score' => $engagement_score,
'url' => get_permalink($post->ID),

        ];
    }

    /**
     * Get space workflow steps
     */
    private function get_space_steps(int $space_id): array {
        $steps = get_post_meta($space_id, '_space_steps', true);

        if (empty($steps) || !is_array($steps)) {
            return [];
        }

        return array_map(function ($step) {
            return [
                'name' => $step['name'] ?? '',
                'label' => $step['label'] ?? $step['name'] ?? '',
            ];
        }, $steps);
    }
}
