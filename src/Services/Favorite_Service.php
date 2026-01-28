<?php
/**
 * Favorite Service - Business logic for user favorites (bookmarks)
 *
 * Handles:
 * - Listing user's favorites
 * - Adding/removing favorites (toggle)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;

class Favorite_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    /**
     * User meta key for favorites
     */
    private const META_KEY = '_ml_favorites';

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * List user's favorites with pagination
     *
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [favorites, has_more, total_count]
     */
    public function list_favorites(int $offset = 0, int $limit = 20): array {
        $all_favorites = $this->get_user_favorites();

        // Filter to only accessible publications
        $accessible_favorites = [];
        foreach ($all_favorites as $publication_id) {
            if ($this->permissions->can_see_publication($publication_id)) {
                $accessible_favorites[] = $publication_id;
            }
        }

        $total = count($accessible_favorites);
        $paginated = array_slice($accessible_favorites, $offset, $limit);

        // Format each favorite with publication info
        $formatted = [];
        foreach ($paginated as $publication_id) {
            $post = get_post($publication_id);
            if ($post) {
                $formatted[] = [
                    'id' => $publication_id,
                    'title' => $post->post_title,
                    'excerpt' => Render_Service::excerpt_from_html($post->post_content, 120),
                    'url' => get_permalink($publication_id),
                    'date_added' => $this->get_favorite_date($publication_id),
                ];
            }
        }

        return [
            'favorites' => $formatted,
            'has_more' => ($offset + count($paginated)) < $total,
            'total_count' => $total,
        ];
    }

    /**
     * Set favorite status (add or remove)
     *
     * @param int $publication_id Publication ID
     * @param bool $favorited True to add, false to remove
     * @return array Result with new status
     */
    public function set_favorite(int $publication_id, bool $favorited): array {
        if (!$this->permissions->can_see_publication($publication_id)) {
            return [
                'ok' => false,
                'error' => 'publication_not_accessible',
            ];
        }

        $favorites = $this->get_user_favorites();
        $was_favorited = in_array($publication_id, $favorites, true);
        $delta = 0;

        if ($favorited && !$was_favorited) {
            // Add favorite
            $favorites[] = $publication_id;
            $this->set_user_favorites($favorites);
            $this->record_favorite_date($publication_id);
            $delta = 1;
        } elseif (!$favorited && $was_favorited) {
            // Remove favorite
            $favorites = array_values(array_diff($favorites, [$publication_id]));
            $this->set_user_favorites($favorites);
            $this->remove_favorite_date($publication_id);
            $delta = -1;
        }

// Keep publication stats in sync (favorites count + quality/trending score)
if ($delta !== 0) {
    try {
        $scoring = new Scoring_Service();
        $scoring->update_metric($publication_id, 'favorites', $delta, true);
    } catch (\Throwable $e) {
        // Non-blocking: do not fail favorite action if scoring update fails
    }
}

        return [
            'ok' => true,
            'publication_id' => $publication_id,
            'is_favorited' => $favorited,
            'was_favorited' => $was_favorited,
        ];
    }

    /**
     * Toggle favorite status
     *
     * @param int $publication_id Publication ID
     * @return array Result with new status
     */
    public function toggle_favorite(int $publication_id): array {
        $favorites = $this->get_user_favorites();
        $is_favorited = in_array($publication_id, $favorites, true);

        return $this->set_favorite($publication_id, !$is_favorited);
    }

    /**
     * Check if publication is favorited
     *
     * @param int $publication_id Publication ID
     * @return bool
     */
    public function is_favorited(int $publication_id): bool {
        $favorites = $this->get_user_favorites();
        return in_array($publication_id, $favorites, true);
    }

    /**
     * Get all user favorites
     */
    private function get_user_favorites(): array {
        $favorites = get_user_meta($this->user_id, self::META_KEY, true);
        return is_array($favorites) ? array_map('intval', $favorites) : [];
    }

    /**
     * Set user favorites
     */
    private function set_user_favorites(array $favorites): void {
        update_user_meta($this->user_id, self::META_KEY, array_values(array_unique($favorites)));
    }

    /**
     * Record when a favorite was added
     */
    private function record_favorite_date(int $publication_id): void {
        $dates = get_user_meta($this->user_id, self::META_KEY . '_dates', true);
        if (!is_array($dates)) {
            $dates = [];
        }
        $dates[$publication_id] = current_time('mysql');
        update_user_meta($this->user_id, self::META_KEY . '_dates', $dates);
    }

    /**
     * Remove favorite date record
     */
    private function remove_favorite_date(int $publication_id): void {
        $dates = get_user_meta($this->user_id, self::META_KEY . '_dates', true);
        if (is_array($dates) && isset($dates[$publication_id])) {
            unset($dates[$publication_id]);
            update_user_meta($this->user_id, self::META_KEY . '_dates', $dates);
        }
    }

    /**
     * Get when a publication was favorited
     */
    private function get_favorite_date(int $publication_id): ?string {
        $dates = get_user_meta($this->user_id, self::META_KEY . '_dates', true);
        if (is_array($dates) && isset($dates[$publication_id])) {
            return Render_Service::format_date($dates[$publication_id]);
        }
        return null;
    }

    /**
     * Check if favorites feature is available
     */
    public static function is_available(): bool {
        return true; // Always available when user is logged in
    }
}
