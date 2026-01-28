# Spécification Complète MCP Marylink v3

**Version:** 3.1  
**Date:** 2025-12-19  
**Audience:** Claude Code / Développeur  
**Plugin:** `marylink-mcp-tools`

## Changelog v3.1 (Strict Picasso)

- Search/Best-of: **ne filtre plus uniquement par `get_user_publication_spaces()`** ; utilise un scan paginé + **filtrage item-by-item** via `can_see_publication()`.
- Toutes les requêtes WP utilisent `suppress_filters => true` (contexte MCP).
- `ml_pin`: vérifie l'accès à la publication (réponse neutre si interdit).
- `ml_apply_tool(commit)`: correction du `cleanup_session()` (utilise le vrai `session_id`).
- `save_as_comment`: écrit le vrai champ `comment_type` (`comment`/`private`) conforme Picasso (meta `_comment_type` optionnel).
- `rating.visible`: dépend des permissions Picasso (moyennes user/expert) — possible de **ranker** sans afficher les chiffres.


---

## Table des Matières

1. [Vue d'Ensemble](#1-vue-densemble)
2. [Architecture](#2-architecture)
3. [Phases d'Implémentation](#3-phases-dimplémentation)
4. [Phase 1 : Core Tools](#4-phase-1--core-tools)
5. [Phase 2 : Apply Tool](#5-phase-2--apply-tool)
6. [Phase 3 : Polish & Expert](#6-phase-3--polish--expert)
7. [Permissions Picasso](#7-permissions-picasso)
8. [Schémas JSON Complets](#8-schémas-json-complets)
9. [Tests & Validation](#9-tests--validation)
10. [Fichiers à Créer/Modifier](#10-fichiers-à-créermodifier)

---

## 1. Vue d'Ensemble

### 1.1 Objectif

Permettre aux utilisateurs d'accéder à Marylink via ChatGPT/Claude avec :
- Un **menu simple** (1/2/3/4/5)
- Des **suggestions contextuelles** basées sur le chat
- Des **recommandations personnalisées** (favoris, raccourcis)
- Un **Best-of** (top par qualité)
- L'**application de prompts/outils** à du texte

### 1.2 Principes

| Principe | Description |
|----------|-------------|
| **1 Tool Pivot** | `ml_help` couvre 90% des usages |
| **Anti-fuite** | Jamais de données inaccessibles |
| **Strict Picasso** | Permissions `space` ≠ `publications` + step-dependent |
| **Multi-client** | Compatible ChatGPT + Claude Desktop + Claude Web |
| **Simple UX** | Menu 1/2/3/4/5, pas de syntaxe complexe |

### 1.3 Tools Finaux (4 au lieu de 13+)

| Tool | Type | Rôle |
|------|------|------|
| `ml_help` | **Pivot** | Menu, search, reco, best, settings |
| `ml_get_publication` | Utilitaire | Détail d'une publication |
| `ml_apply_tool` | **Critique** | Appliquer un prompt à du texte |
| `ml_pin` | Utilitaire | Gérer favoris |

---

## 2. Architecture

### 2.1 Structure Plugin

```
marylink-mcp-tools/
├── marylink-mcp-tools.php          # Bootstrap
├── src/
│   ├── MCP/
│   │   ├── Tools_Registry.php      # Enregistrement tools AI Engine
│   │   ├── Help_Tool.php           # ← NOUVEAU: ml_help
│   │   ├── Apply_Tool.php          # ← NOUVEAU: ml_apply_tool
│   │   ├── Pin_Tool.php            # ← NOUVEAU: ml_pin
│   │   ├── Publication_Tool.php    # ml_get_publication (existant)
│   │   └── Permission_Checker.php  # Vérification permissions (existant)
│   ├── Services/
│   │   ├── Search_Service.php      # ← NOUVEAU: Recherche publications
│   │   ├── Recommendation_Service.php # ← NOUVEAU: Reco + Best-of
│   │   └── Favorites_Service.php   # ← NOUVEAU: Gestion favoris
│   ├── Integration/
│   │   └── AI_Engine_Bridge.php    # Hook AI Engine (existant)
│   └── User/
│       ├── Token_Manager.php       # Auth tokens (existant)
│       └── Profile_Tab.php         # UI profil (existant)
└── templates/
    └── profile-mcp-settings.php
```

### 2.2 Flow Global

```
┌─────────────────────────────────────────────────────────────┐
│  USER tape "menu" ou "marylink"                             │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  IA (ChatGPT/Claude) appelle ml_help(mode: "menu")          │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  MARYLINK retourne le menu :                                │
│                                                             │
│  1. Aide (dans ce chat)                                     │
│  2. Recommandé pour moi                                     │
│  3. Best-of (Top)                                           │
│  4. Trouver                                                 │
│  5. Réglages                                                │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  USER répond "2"                                            │
│  IA appelle ml_help(mode: "for_me")                         │
│  MARYLINK retourne liste personnalisée                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Phases d'Implémentation

### Vue d'Ensemble

| Phase | Contenu | Effort | Priorité |
|-------|---------|--------|----------|
| **Phase 1** | Core Tools (menu, search, for_me, best) | 4-5h | 🔴 P1 |
| **Phase 2** | Apply Tool (prepare/commit) | 3-4h | 🔴 P1 |
| **Phase 3** | Polish (Expert mode, scoring avancé) | 3-4h | 🟡 P2 |

### Dépendances

```
Phase 1 ──────────────────────────┐
   │                              │
   ▼                              ▼
Phase 2 (Apply)              Phase 3 (Polish)
   │                              │
   └──────────────────────────────┘
                 │
                 ▼
            PRODUCTION
```

---

## 4. Phase 1 : Core Tools

### 4.1 Fichier: `src/MCP/Help_Tool.php`

```php
<?php
/**
 * Help Tool - Tool pivot pour Marylink MCP
 * 
 * Modes supportés:
 * - menu: Affiche le menu principal
 * - search: Recherche par texte
 * - for_me: Recommandations personnalisées (favoris + raccourcis)
 * - best: Top par qualité
 * - reco: Suggestions contextuelles (Phase 3)
 * - settings_get: Récupérer réglages
 * - settings_set: Modifier réglages
 */

namespace MaryLink_MCP\MCP;

class Help_Tool {

    private Permission_Checker $permissions;
    private int $user_id;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Point d'entrée principal
     */
    public function execute(array $args): array {
        $mode = $args['mode'] ?? 'menu';

        switch ($mode) {
            case 'menu':
                return $this->mode_menu();
            
            case 'search':
                return $this->mode_search($args);
            
            case 'for_me':
                return $this->mode_for_me($args);
            
            case 'best':
                return $this->mode_best($args);
            
            case 'reco':
                return $this->mode_reco($args);
            
            case 'settings_get':
                return $this->mode_settings_get();
            
            case 'settings_set':
                return $this->mode_settings_set($args);
            
            default:
                return $this->error("Mode inconnu: {$mode}");
        }
    }

    // ... (voir détails ci-dessous)
}
```

### 4.2 Mode: `menu`

**Objectif:** Afficher le menu principal

**Input:**
```json
{
  "mode": "menu"
}
```

**Output:**
```json
{
  "view": "menu",
  "menu": {
    "title": "🔮 Marylink",
    "subtitle": "Que puis-je faire pour vous ?",
    "options": [
      { "key": "1", "label": "Aide (dans ce chat)", "action": "reco" },
      { "key": "2", "label": "Recommandé pour moi", "action": "for_me" },
      { "key": "3", "label": "Best-of (Top)", "action": "best" },
      { "key": "4", "label": "Trouver", "action": "search" },
      { "key": "5", "label": "Réglages", "action": "settings_get" }
    ],
    "hint": "Répondez 1, 2, 3, 4 ou 5"
  },
  "user_context": {
    "name": "Claude Dupont",
    "accessible_spaces": 12,
    "favorites_count": 5
  }
}
```

**Implémentation:**
```php
private function mode_menu(): array {
    $user = get_userdata($this->user_id);
    $spaces = $this->permissions->get_user_spaces();
    $favorites = $this->get_favorites_count();

    return [
        'view' => 'menu',
        'menu' => [
            'title' => '🔮 Marylink',
            'subtitle' => 'Que puis-je faire pour vous ?',
            'options' => [
                ['key' => '1', 'label' => 'Aide (dans ce chat)', 'action' => 'reco'],
                ['key' => '2', 'label' => 'Recommandé pour moi', 'action' => 'for_me'],
                ['key' => '3', 'label' => 'Best-of (Top)', 'action' => 'best'],
                ['key' => '4', 'label' => 'Trouver', 'action' => 'search'],
                ['key' => '5', 'label' => 'Réglages', 'action' => 'settings_get'],
            ],
            'hint' => 'Répondez 1, 2, 3, 4 ou 5',
        ],
        'user_context' => [
            'name' => $user->display_name ?? 'Utilisateur',
            'accessible_spaces' => count($spaces),
            'favorites_count' => $favorites,
        ],
    ];
}
```

### 4.3 Mode: `search`

**Objectif:** Rechercher des publications par texte/filtres

**Input:**
```json
{
  "mode": "search",
  "query": "prompt relance client",
  "filters": {
    "type": "prompt",
    "tags": ["--Rédiger"],
    "space_id": null,
    "step": "actif",
    "limit": 10,
    "page": 1
  }
}
```

**Output:**
```json
{
  "view": "list",
  "query": "prompt relance client",
  "filters_applied": { "type": "prompt", "step": "actif" },
  "count": 3,
  "items": [
    {
      "index": 1,
      "id": 25303,
      "title": "Prompt Relance Client Premium",
      "type": "prompt",
      "tags": ["--Rédiger", "**COMMERCIAL"],
      "rating": { "value": 4.7, "count": 23, "visible": true },
      "space": { "id": 15973, "title": "Outils Commerciaux" },
      "excerpt": "Génère un email de relance personnalisé...",
      "url": "https://..."
    },
    // ...
  ],
  "next_actions": [
    "ouvre 1",
    "applique 1",
    "filtre type:outil",
    "plus"
  ]
}
```

**Implémentation:**
```php
private function mode_search(array $args): array {
    $query   = sanitize_text_field($args['query'] ?? '');
    $filters = $this->parse_filters($args['filters'] ?? []);
    $limit   = min((int)($filters['limit'] ?? 10), 50);
    $page    = max(1, (int)($filters['page'] ?? ($args['page'] ?? 1)));

    // IMPORTANT (MCP): ne pas dépendre uniquement de get_user_publication_spaces()
    // car Picasso autorise aussi via auteur / co-auteur / team / expert / groupes.
    // On requête "large" puis on filtre item-by-item via can_see_publication().
    $query_base = [
        'post_type'        => 'publication',
        'post_status'      => ['publish', 'draft', 'pending'],
        'orderby'          => 'date',
        'order'            => 'DESC',
        's'                => $query,
        'suppress_filters' => true,
        // Sur-échantillonnage: on filtre ensuite
        'posts_per_page'   => max(1, $limit) * 5,
        'paged'            => $page,
        // Anti-fuite / perf
        'no_found_rows'    => true,
    ];

    // Filtre par space (optionnel)
    if (!empty($filters['space_id'])) {
        $query_base['post_parent'] = (int) $filters['space_id'];
    }

    // Filtres additionnels
    if (!empty($filters['type'])) {
        // Labels (publication_label taxonomy) pour type prompt/outil/style
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_label',
            'field'    => 'slug',
            'terms'    => $filters['type'],
        ];
    }

    if (!empty($filters['tags'])) {
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_tag',
            'field'    => 'name',
            'terms'    => $filters['tags'],
        ];
    }

    // Step filter
    if (!empty($filters['step'])) {
        $step = $filters['step'] === 'actif' ? 'submit' : sanitize_text_field($filters['step']);
        $query_base['meta_query'][] = [
            'key'   => '_publication_step',
            'value' => $step,
        ];
    }

    $items = [];
    $seen  = [];
    $index = 1;

    // Scan paginé pour remplir $limit après filtrage permissions
    $max_pages_to_scan = 5;

    for ($i = 0; $i < $max_pages_to_scan && count($items) < $limit; $i++) {
        $q = $query_base;
        $q['paged'] = $page + $i;

        $wp_query = new \WP_Query($q);

        foreach ($wp_query->posts as $post) {
            if (isset($seen[$post->ID])) {
                continue;
            }
            $seen[$post->ID] = true;

            // Source de vérité (strict Picasso)
            if (!$this->permissions->can_see_publication($post->ID)) {
                continue;
            }

            $items[] = $this->format_item($post, $index++);
            if (count($items) >= $limit) {
                break;
            }
        }

        // Stop si cette page n'était pas pleine (fin probable)
        if (count($wp_query->posts) < (int) $q['posts_per_page']) {
            break;
        }
    }

    if (empty($items)) {
        return $this->empty_result('Aucun résultat accessible');
    }

    return [
        'view'         => 'list',
        'mode'         => 'search',
        'query'        => $query,
        'count'        => count($items),
        'items'        => $items,
        'next_actions' => $this->build_actions($items),
    ];
}

private function mode_best(array $args): array {
    $filters = $this->parse_filters($args['filters'] ?? []);
    $limit   = min((int)($filters['limit'] ?? 10), 50);
    $page    = max(1, (int)($filters['page'] ?? ($args['page'] ?? 1)));

    // IMPORTANT (MCP / strict Picasso):
    // on ne peut pas se limiter aux espaces "publications" (auteur/co-auteur/team/etc.).
    // On trie large (quality_score) puis on filtre item-by-item.
    $query_base = [
        'post_type'        => 'publication',
        'post_status'      => ['publish', 'draft', 'pending'],
        'posts_per_page'   => max(1, $limit) * 5,
        'paged'            => $page,
        'meta_key'         => '_ml_quality_score',
        'orderby'          => 'meta_value_num',
        'order'            => 'DESC',
        'suppress_filters' => true,
        'no_found_rows'    => true,
    ];

    // Filtre par space (optionnel)
    if (!empty($filters['space_id'])) {
        $query_base['post_parent'] = (int) $filters['space_id'];
    }

    // Filtre tags
    if (!empty($filters['tags'])) {
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_tag',
            'field'    => 'name',
            'terms'    => $filters['tags'],
        ];
    }

    // Filtre type (labels)
    if (!empty($filters['type'])) {
        $query_base['tax_query'][] = [
            'taxonomy' => 'publication_label',
            'field'    => 'slug',
            'terms'    => $filters['type'],
        ];
    }

    // Filtre step (optionnel)
    if (!empty($filters['step'])) {
        $step = $filters['step'] === 'actif' ? 'submit' : sanitize_text_field($filters['step']);
        $query_base['meta_query'][] = [
            'key'   => '_publication_step',
            'value' => $step,
        ];
    }

    $items = [];
    $seen  = [];
    $index = 1;

    $max_pages_to_scan = 8; // best-of a souvent besoin de scanner un peu plus

    for ($i = 0; $i < $max_pages_to_scan && count($items) < $limit; $i++) {
        $q = $query_base;
        $q['paged'] = $page + $i;

        $wp_query = new \WP_Query($q);

        foreach ($wp_query->posts as $post) {
            if (isset($seen[$post->ID])) {
                continue;
            }
            $seen[$post->ID] = true;

            if (!$this->permissions->can_see_publication($post->ID)) {
                continue;
            }

            $items[] = $this->format_item($post, $index++);
            if (count($items) >= $limit) {
                break;
            }
        }

        if (count($wp_query->posts) < (int) $q['posts_per_page']) {
            break;
        }
    }

    if (empty($items)) {
        return $this->empty_result('Aucun contenu Best-of accessible');
    }

    return [
        'view'         => 'list',
        'mode'         => 'best',
        'count'        => count($items),
        'items'        => $items,
        'next_actions' => $this->build_actions($items),
    ];
}

private function format_item(\WP_Post $post, int $index): array {
    $space_id = (int) $post->post_parent;
    
    // Récupérer les taxonomies
    $labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
    $tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'names']);
    
    // Déterminer le type depuis les labels
    $type = 'contenu';
    $type_map = ['tool' => 'outil', 'prompt' => 'prompt', 'style' => 'style'];
    foreach ($labels as $label) {
        if (isset($type_map[$label])) {
            $type = $type_map[$label];
            break;
        }
    }

    // Rating (si calculé)
    $avg_rating = (float) get_post_meta($post->ID, '_ml_avg_rating', true);
    $rating_count = (int) get_post_meta($post->ID, '_ml_rating_count', true);

    // Vérifier si user peut voir le rating
    $can_see_rating = $this->permissions->can_see_rating_average($post->ID);

    return [
        'index' => $index,
        'id' => $post->ID,
        'title' => $post->post_title,
        'type' => $type,
        'tags' => array_slice($tags, 0, 5), // Max 5 tags
        'rating' => [
            'value' => $can_see_rating ? round($avg_rating, 1) : null,
            'count' => $can_see_rating ? $rating_count : null,
            'visible' => $can_see_rating,
        ],
        'space' => [
            'id' => $space_id,
            'title' => get_the_title($space_id),
        ],
        'excerpt' => wp_trim_words($post->post_content, 30),
        'url' => get_permalink($post->ID),
        'step' => get_post_meta($post->ID, '_publication_step', true) ?: 'submit',
    ];
}

/**
 * Suggère les prochaines actions basées sur les items
 */
private function suggest_actions(array $items): array {
    $actions = [];

    if (!empty($items)) {
        $actions[] = 'ouvre 1';
        
        // Si c'est un prompt/outil/style, suggérer applique
        $first_type = $items[0]['type'] ?? '';
        if (in_array($first_type, ['prompt', 'outil', 'style'])) {
            $actions[] = 'applique 1';
        }
    }

    $actions[] = 'filtre type:prompt';
    $actions[] = 'plus';

    return $actions;
}

/**
 * Parse les filtres depuis les arguments
 */
private function parse_filters(array $filters): array {
    return [
        'type' => isset($filters['type']) ? sanitize_text_field($filters['type']) : null,
        'tags' => isset($filters['tags']) ? array_map('sanitize_text_field', (array) $filters['tags']) : [],
        'space_id' => isset($filters['space_id']) ? (int) $filters['space_id'] : null,
        'step' => isset($filters['step']) ? sanitize_text_field($filters['step']) : null,
        'limit' => isset($filters['limit']) ? (int) $filters['limit'] : 10,
        'page'  => isset($filters['page']) ? max(1, (int) $filters['page']) : 1,
    ];
}

/**
 * Retourne un résultat vide
 */
private function empty_result(string $message): array {
    return [
        'view' => 'list',
        'count' => 0,
        'items' => [],
        'message' => $message,
        'next_actions' => ['menu'],
    ];
}

/**
 * Retourne une erreur
 */
private function error(string $message): array {
    return [
        'view' => 'error',
        'error' => $message,
        'next_actions' => ['menu'],
    ];
}
```

### 4.7 Enregistrement du Tool

**Fichier:** `src/MCP/Tools_Registry.php` (modifier)

```php
private function get_tool_definitions(): array {
    return [
        // TOOL PIVOT
        [
            'name' => 'ml_help',
            'description' => 'Menu principal Marylink. Modes: menu (affiche menu), search (recherche), for_me (recommandations), best (top), settings_get/settings_set (réglages)',
            'category' => 'Marylink',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['menu', 'search', 'for_me', 'best', 'reco', 'settings_get', 'settings_set'],
                        'description' => 'Mode: menu, search, for_me, best, reco, settings_get, settings_set',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Texte de recherche (mode search)',
                    ],
                    'context_text' => [
                        'type' => 'string',
                        'description' => 'Contexte du chat pour suggestions (mode reco)',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Filtres optionnels',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['outil', 'prompt', 'style', 'contenu'],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'space_id' => ['type' => 'integer'],
                            'step' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'required' => ['mode'],
            ],
            'annotations' => ['readOnlyHint' => true],
        ],

        // UTILITAIRE: Get Publication (existant, garder)
        [
            'name' => 'ml_get_publication',
            'description' => 'Récupère les détails complets d\'une publication par son ID',
            'category' => 'Marylink',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'publication_id' => [
                        'type' => 'integer',
                        'description' => 'ID de la publication',
                    ],
                ],
                'required' => ['publication_id'],
            ],
            'annotations' => ['readOnlyHint' => true],
        ],

        // ... (ml_apply_tool et ml_pin ajoutés en Phase 2)
    ];
}
```

---

## 5. Phase 2 : Apply Tool

### 5.1 Fichier: `src/MCP/Apply_Tool.php`

```php
<?php
/**
 * Apply Tool - Applique un prompt/outil Marylink à du texte
 * 
 * Flow en 2 étapes:
 * 1. prepare: Retourne le prompt préparé pour exécution par l'IA cliente
 * 2. commit: Sauvegarde le résultat (optionnel)
 */

namespace MaryLink_MCP\MCP;

class Apply_Tool {

    private Permission_Checker $permissions;
    private int $user_id;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Point d'entrée principal
     */
    public function execute(array $args): array {
        $stage = $args['stage'] ?? 'prepare';

        switch ($stage) {
            case 'prepare':
                return $this->stage_prepare($args);
            
            case 'commit':
                return $this->stage_commit($args);
            
            default:
                return $this->error("Stage inconnu: {$stage}");
        }
    }

    /**
     * Stage 1: Prépare le prompt pour exécution
     */
    private function stage_prepare(array $args): array {
        $tool_id = (int) ($args['tool_id'] ?? 0);
        $input_text = $args['input_text'] ?? '';
        $options = $args['options'] ?? [];

        // Valider l'accès au tool
        if (!$this->permissions->can_see_publication($tool_id)) {
            return $this->error('Accès refusé à cet outil');
        }

        // Récupérer le tool (publication avec label 'tool' ou 'prompt' ou 'style')
        $tool_post = get_post($tool_id);
        if (!$tool_post || $tool_post->post_type !== 'publication') {
            return $this->error('Outil non trouvé');
        }

        // Vérifier que c'est bien un tool/prompt/style
        $labels = wp_get_post_terms($tool_id, 'publication_label', ['fields' => 'slugs']);
        $valid_types = ['tool', 'prompt', 'style'];
        if (empty(array_intersect($labels, $valid_types))) {
            return $this->error('Cette publication n\'est pas un outil applicable');
        }

        // Récupérer le prompt depuis le contenu ou meta
        $prompt_template = get_post_meta($tool_id, '_tool_prompt', true);
        if (empty($prompt_template)) {
            $prompt_template = $tool_post->post_content;
        }

        // Construire le prompt final
        $prepared_prompt = $this->build_prompt($prompt_template, $input_text, $options);

        // Générer session_id pour le commit
        $session_id = $this->create_session($tool_id, $input_text);

        return [
            'stage' => 'prepared',
            'session_id' => $session_id,
            'tool' => [
                'id' => $tool_id,
                'title' => $tool_post->post_title,
                'type' => $labels[0] ?? 'tool',
            ],
            'prepared_prompt' => $prepared_prompt,
            'input_preview' => wp_trim_words($input_text, 50),
            'output_hint' => 'Texte transformé selon les consignes',
            'expires_in' => 300, // 5 minutes
            'next_actions' => [
                'Exécutez ce prompt avec le texte fourni',
                'Puis appelez ml_apply_tool avec stage=commit pour sauvegarder (optionnel)',
            ],
        ];
    }

    /**
     * Stage 2: Sauvegarde le résultat
     */
    private function stage_commit(array $args): array {
        $session_id = $args['session_id'] ?? '';
        $final_text = $args['final_text'] ?? '';
        $save_as = $args['save_as'] ?? 'none';
        $target = $args['target'] ?? [];

        // Valider la session
        $session = $this->validate_session($session_id);
        if (!$session) {
            return $this->error('Session expirée ou invalide. Refaites prepare.');
        }

        // Si pas de sauvegarde demandée
        if ($save_as === 'none') {
            $this->cleanup_session($session_id);
            return [
                'stage' => 'completed',
                'saved' => false,
                'message' => 'Résultat non sauvegardé (à votre demande)',
            ];
        }

        // Sauvegarder selon le type
        switch ($save_as) {
            case 'publication':
                return $this->save_as_publication($final_text, $target, $session, $session_id);

            case 'comment':
                return $this->save_as_comment($final_text, $target, $session, $session_id);

            default:
                return $this->error("Type de sauvegarde inconnu: {$save_as}");
        }
    }

    /**
     * Construit le prompt final avec variables
     */
    private function build_prompt(string $template, string $input_text, array $options): string {
        // Variables disponibles
        $vars = [
            '{{INPUT}}' => $input_text,
            '{{INPUT_TEXT}}' => $input_text,
            '{{TEXTE}}' => $input_text,
            '{{LANGUAGE}}' => $options['language'] ?? 'fr',
            '{{TONE}}' => $options['tone'] ?? 'professionnel',
            '{{FORMAT}}' => $options['output_format'] ?? 'text',
        ];

        // Remplacer les variables
        $prompt = str_replace(array_keys($vars), array_values($vars), $template);

        // Si pas de variable INPUT trouvée, ajouter le texte à la fin
        if (strpos($template, '{{INPUT') === false && strpos($template, '{{TEXTE') === false) {
            $prompt .= "\n\n---\nTexte à traiter:\n" . $input_text;
        }

        return $prompt;
    }

    /**
     * Crée une session temporaire
     */
    private function create_session(int $tool_id, string $input_text): string {
        $session_id = wp_generate_uuid4();
        $session_data = [
            'tool_id' => $tool_id,
            'input_hash' => md5($input_text),
            'user_id' => $this->user_id,
            'created' => time(),
        ];
        
        set_transient('ml_apply_session_' . $session_id, $session_data, 300);
        
        return $session_id;
    }

    /**
     * Valide une session
     */
    private function validate_session(string $session_id): ?array {
        $session = get_transient('ml_apply_session_' . $session_id);
        
        if (!$session || $session['user_id'] !== $this->user_id) {
            return null;
        }
        
        return $session;
    }

    /**
     * Nettoie une session
     */
    private function cleanup_session(string $session_id): void {
        delete_transient('ml_apply_session_' . $session_id);
    }

    /**
     * Sauvegarde comme publication
     */
    private function save_as_publication(string $content, array $target, array $session, string $session_id): array {
        $space_id = (int) ($target['space_id'] ?? 0);
        $title = $target['title'] ?? 'Résultat généré par IA';

        // Vérifier permissions d'écriture
        if (!$this->permissions->can_create_publication($space_id)) {
            return $this->error('Vous n\'avez pas le droit de publier dans cet espace');
        }

        // Créer la publication
        $post_id = wp_insert_post([
            'post_type' => 'publication',
            'post_title' => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
            'post_status' => 'publish',
            'post_author' => $this->user_id,
            'post_parent' => $space_id,
        ], true);

        if (is_wp_error($post_id)) {
            return $this->error($post_id->get_error_message());
        }

        // Ajouter meta: source tool
        update_post_meta($post_id, '_generated_by_tool', $session['tool_id']);
        update_post_meta($post_id, '_publication_step', 'submit');

        // Trigger hooks Picasso
        do_action('pb_post_saved', $post_id, true);

        $this->cleanup_session($session_id);

        return [
            'stage' => 'completed',
            'saved' => true,
            'save_type' => 'publication',
            'publication_id' => $post_id,
            'url' => get_permalink($post_id),
            'message' => 'Publication créée avec succès',
        ];
    }

    /**
     * Sauvegarde comme commentaire
     */
    private function save_as_comment(string $content, array $target, array $session, string $session_id): array {
        $publication_id = (int) ($target['publication_id'] ?? 0);
        $comment_type = $target['comment_type'] ?? 'public';

        // Vérifier permissions
        $permission_key = $comment_type === 'private' ? 'private_comments' : 'public_comments';
        if (!$this->permissions->can_post_comment($publication_id, $comment_type)) {
            return $this->error("Vous n'avez pas le droit de poster un commentaire {$comment_type}");
        }

        $user = get_userdata($this->user_id);

        $comment_id = wp_insert_comment([
            'comment_post_ID' => $publication_id,
            'comment_content' => wp_kses_post($content),
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $this->user_id,
            'comment_approved' => 1,
            // STRICT PICASSO: la visibilité dépend de wp_comments.comment_type
            'comment_type' => $comment_type === 'private' ? 'private' : 'comment',
            // Optionnel: réponse à un commentaire existant
            'comment_parent' => (int) ($target['parent_comment_id'] ?? 0),
        ]);

        if (!$comment_id) {
            return $this->error('Erreur lors de la création du commentaire');
        }

        // STRICT PICASSO: pour les réponses, Picasso utilise _comment_parent_author
        $parent_id = (int) ($target['parent_comment_id'] ?? 0);
        if ($parent_id > 0) {
            $parent = get_comment($parent_id);
            if ($parent && (int) $parent->comment_post_ID === $publication_id) {
                update_comment_meta($comment_id, '_comment_parent_author', (int) $parent->user_id);
            }
        }

        // Meta: source tool
        update_comment_meta($comment_id, '_generated_by_tool', $session['tool_id']);

        $this->cleanup_session($session_id);

        return [
            'stage' => 'completed',
            'saved' => true,
            'save_type' => 'comment',
            'comment_id' => $comment_id,
            'publication_id' => $publication_id,
            'message' => "Commentaire {$comment_type} ajouté avec succès",
        ];
    }

    /**
     * Retourne une erreur
     */
    private function error(string $message): array {
        return [
            'stage' => 'error',
            'error' => $message,
        ];
    }
}
```

### 5.2 Schéma JSON du Tool

```php
// Dans Tools_Registry.php, ajouter:
[
    'name' => 'ml_apply_tool',
    'description' => 'Applique un prompt/outil Marylink à du texte. Stage "prepare" retourne le prompt prêt. Stage "commit" sauvegarde le résultat.',
    'category' => 'Marylink',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'stage' => [
                'type' => 'string',
                'enum' => ['prepare', 'commit'],
                'description' => 'prepare: obtenir le prompt. commit: sauvegarder le résultat.',
            ],
            // Stage prepare
            'tool_id' => [
                'type' => 'integer',
                'description' => '(prepare) ID du tool/prompt/style à appliquer',
            ],
            'input_text' => [
                'type' => 'string',
                'description' => '(prepare) Texte à transformer',
            ],
            'options' => [
                'type' => 'object',
                'description' => '(prepare) Options: language, tone, output_format',
                'properties' => [
                    'language' => ['type' => 'string'],
                    'tone' => ['type' => 'string'],
                    'output_format' => ['type' => 'string'],
                ],
            ],
            // Stage commit
            'session_id' => [
                'type' => 'string',
                'description' => '(commit) Session ID retourné par prepare',
            ],
            'final_text' => [
                'type' => 'string',
                'description' => '(commit) Texte final généré par l\'IA',
            ],
            'save_as' => [
                'type' => 'string',
                'enum' => ['none', 'publication', 'comment'],
                'description' => '(commit) Type de sauvegarde',
            ],
            'target' => [
                'type' => 'object',
                'description' => '(commit) Cible de sauvegarde',
                'properties' => [
                    'space_id' => ['type' => 'integer'],
                    'publication_id' => ['type' => 'integer'],
                    'parent_comment_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'comment_type' => ['type' => 'string', 'enum' => ['public', 'private']],
                ],
            ],
        ],
        'required' => ['stage'],
    ],
    'annotations' => ['destructiveHint' => true],
],
```

### 5.3 Fichier: `src/MCP/Pin_Tool.php`

```php
<?php
/**
 * Pin Tool - Gestion des favoris
 */

namespace MaryLink_MCP\MCP;

class Pin_Tool {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    public function execute(array $args): array {
        $action = $args['action'] ?? 'pin';
        $publication_id = (int) ($args['publication_id'] ?? 0);

        if ($publication_id <= 0) {
            return ['success' => false, 'error' => 'ID publication invalide'];
        }

        // Strict Picasso: ne jamais permettre de "pinner" une publication inaccessible
        // (sinon on peut sonder l'existence d'IDs)
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication' || !$this->permissions->can_see_publication($publication_id)) {
            // Message neutre (anti-fuite)
            return ['success' => false, 'error' => 'Publication non trouvée'];
        }

        $favorites = get_user_meta($this->user_id, '_ml_favorite_publications', true) ?: [];

        if ($action === 'pin') {
            if (!in_array($publication_id, $favorites, true)) {
                $favorites[] = $publication_id;
                update_user_meta($this->user_id, '_ml_favorite_publications', $favorites);
            }
            return [
                'success' => true,
                'action' => 'pinned',
                'publication_id' => $publication_id,
                'message' => 'Ajouté aux favoris',
            ];
        }

        if ($action === 'unpin') {
            $favorites = array_values(array_diff($favorites, [$publication_id]));
            update_user_meta($this->user_id, '_ml_favorite_publications', $favorites);
            return [
                'success' => true,
                'action' => 'unpinned',
                'publication_id' => $publication_id,
                'message' => 'Retiré des favoris',
            ];
        }

        return ['success' => false, 'error' => 'Action invalide'];
    }
}
```

---

## 6. Phase 3 : Polish & Expert

### 6.1 Mode Expert (Commandes Directes)

**Implémentation:** Côté IA (prompt système) + parsing serveur

**Commandes supportées:**
```
aide                     → ml_help(mode: reco, context_text: ...)
reco                     → ml_help(mode: for_me)
best                     → ml_help(mode: best)
trouve <texte>           → ml_help(mode: search, query: <texte>)
ouvre <N>                → ml_get_publication(publication_id: <ID du N>)
applique <N>             → ml_apply_tool(stage: prepare, tool_id: <ID>)
filtre type:prompt       → ml_help(mode: search, filters: {type: prompt})
pin <N>                  → ml_pin(action: pin, publication_id: <ID>)
```

### 6.2 Mode Reco (Contextuel)

```php
private function mode_reco(array $args): array {
    $context_text = $args['context_text'] ?? '';
    $filters = $this->parse_filters($args['filters'] ?? []);

    if (empty($context_text)) {
        return $this->mode_for_me($args); // Fallback
    }

    // Extraire mots-clés du contexte
    $keywords = $this->extract_keywords($context_text);

    // Rechercher avec ces mots-clés
    $args['query'] = implode(' ', $keywords);
    $args['filters']['limit'] = $args['filters']['limit'] ?? 5;

    $results = $this->mode_search($args);
    $results['title'] = 'Suggestions pour ce chat';
    $results['context_analyzed'] = true;
    $results['keywords_detected'] = $keywords;

    return $results;
}

private function extract_keywords(string $text): array {
    // Extraction simple: mots de 4+ lettres, fréquents
    $words = str_word_count(strtolower($text), 1);
    $words = array_filter($words, fn($w) => strlen($w) >= 4);
    $freq = array_count_values($words);
    arsort($freq);
    
    // Top 5 mots
    return array_slice(array_keys($freq), 0, 5);
}
```

### 6.3 Best-of avec Scores Calculés (Cron)

**Fichier:** `src/Services/Scoring_Service.php`

```php
<?php
namespace MaryLink_MCP\Services;

class Scoring_Service {

    /**
     * Recalcule les scores de qualité (appelé par cron)
     */
    public static function recalculate_scores(): void {
        $publications = get_posts([
            'post_type' => 'publication',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
        ]);

        foreach ($publications as $post_id) {
            $score = self::calculate_quality_score($post_id);
            update_post_meta($post_id, '_ml_quality_score', $score);
        }
    }

    /**
     * Calcule le score de qualité d'une publication
     */
    public static function calculate_quality_score(int $post_id): float {
        // Composants du score
        $avg_user = (float) get_post_meta($post_id, '_ml_avg_user_rating', true);
        $n_user = (int) get_post_meta($post_id, '_ml_user_rating_count', true);
        $avg_expert = (float) get_post_meta($post_id, '_ml_avg_expert_rating', true);
        $n_expert = (int) get_post_meta($post_id, '_ml_expert_rating_count', true);
        $favorites = (int) get_post_meta($post_id, '_ml_favorites_count', true);

        // Pondérations
        $w_user = 1.0;
        $w_expert = 2.0; // Experts comptent double
        $w_fav = 0.5;

        // Score avec confiance (bayésien simplifié)
        $prior = 3.0; // Note moyenne a priori
        $k = 5; // Poids du prior

        $user_score = ($n_user > 0) 
            ? (($k * $prior) + ($n_user * $avg_user)) / ($k + $n_user)
            : $prior;

        $expert_score = ($n_expert > 0)
            ? (($k * $prior) + ($n_expert * $avg_expert)) / ($k + $n_expert)
            : $prior;

        // Score final
        $score = ($w_user * $user_score) + ($w_expert * $expert_score) + ($w_fav * min($favorites, 10) / 10);
        $score = $score / ($w_user + $w_expert + $w_fav); // Normaliser sur 5

        return round($score, 2);
    }
}
```

**Cron job (dans marylink-mcp-tools.php):**
```php
// Programmer le cron
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('ml_recalculate_scores')) {
        wp_schedule_event(time(), 'daily', 'ml_recalculate_scores');
    }
});

add_action('ml_recalculate_scores', ['MaryLink_MCP\\Services\\Scoring_Service', 'recalculate_scores']);
```

---

## 7. Permissions Picasso

### 7.1 Mapping Tool → Permission (Strict)

| Tool | Permission SEE | Permission POST |
|------|----------------|-----------------|
| `ml_help(menu)` | - | - |
| `ml_help(search)` | **per-item:** `can_see_publication(publication_id)` | - |
| `ml_help(for_me)` | **per-item:** `can_see_publication(publication_id)` | - |
| `ml_help(best)` | **per-item:** `can_see_publication(publication_id)` | - |
| `ml_help(reco)` | **per-item:** `can_see_publication(publication_id)` | - |
| `ml_get_publication` | `can_see_publication(publication_id)` | - |
| `ml_apply_tool(prepare)` | `can_see_publication(tool_id)` | - |
| `ml_apply_tool(commit → publication)` | - | `can_create_publication(space_id)` (publish/edit) |
| `ml_apply_tool(commit → comment)` | - | `can_post_comment(publication_id, public/private)` *(step-dependent)* |
| `ml_pin` | `can_see_publication(publication_id)` | - |

**Affichage des notes (ratings):**  
- `rating.visible=true` uniquement si l'utilisateur a le droit de voir les moyennes (ex: `user_reviews_average` / `expert_reviews_average` au step courant).  
- Le tri Best-of peut s'appuyer sur `_ml_quality_score` même si `rating.visible=false` (on masque juste les chiffres).

### 7.2 Règles Critiques

```php
// TOUJOURS vérifier space ≠ publications
$spaces_visibles = $this->permissions->get_user_spaces(); // Pour lister espaces
$spaces_publications = $this->permissions->get_user_publication_spaces(); // (optionnel) optimisation de scope, mais insuffisant (auteur/co-auteur/team/groupe...)

// TOUJOURS filtrer item-by-item
foreach ($items as $item) {
    if (!$this->permissions->can_see_publication($item['id'])) {
        continue; // Skip
    }
    $filtered[] = $item;
}

// JAMAIS retourner un total brut
return [
    'count' => count($filtered), // OK
    // 'total' => $raw_total,    // INTERDIT (fuite)
];
```


### 7.3 Visibilité des ratings (moyennes)

En **strict Picasso**, l'affichage des moyennes (ratings) doit respecter les permissions **step-dependent** (ex: `user_reviews_average`, `expert_reviews_average`) au niveau de l'espace.

Exemple (API attendue côté `Permission_Checker`) :

```php
public function can_see_rating_average(int $publication_id): bool {
    $post = get_post($publication_id);
    if (!$post || $post->post_type !== 'publication') {
        return false;
    }

    $space_id = (int) $post->post_parent;
    $step = (string) get_post_meta($publication_id, '_publication_step', true);
    if ($step === '') {
        $step = 'submit';
    }

    return $this->has_see_permission($space_id, 'user_reviews_average', $step)
        || $this->has_see_permission($space_id, 'expert_reviews_average', $step);
}
```

> Remarque : le **tri Best-of** peut utiliser `_ml_quality_score` même si `rating.visible=false` (on masque simplement `value/count`).

---

## 8. Schémas JSON Complets

### 8.1 ml_help - Input

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "mode": {
      "type": "string",
      "enum": ["menu", "search", "for_me", "best", "reco", "settings_get", "settings_set"],
      "description": "Mode d'opération"
    },
    "query": {
      "type": "string",
      "description": "Texte de recherche (mode search)"
    },
    "context_text": {
      "type": "string",
      "description": "Contexte du chat (mode reco)"
    },
    "filters": {
      "type": "object",
      "properties": {
        "type": {
          "type": "string",
          "enum": ["outil", "prompt", "style", "contenu"]
        },
        "tags": {
          "type": "array",
          "items": { "type": "string" }
        },
        "space_id": { "type": "integer" },
        "step": { "type": "string" },
        "limit": { "type": "integer", "minimum": 1, "maximum": 50 },
        "page": { "type": "integer", "minimum": 1 }
      }
    },
    "settings": {
      "type": "object",
      "description": "Pour settings_set",
      "properties": {
        "expert_mode": { "type": "boolean" },
        "default_limit": { "type": "integer" },
        "default_scope": { "type": "string" }
      }
    }
  },
  "required": ["mode"]
}
```

### 8.2 ml_help - Output

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "view": {
      "type": "string",
      "enum": ["menu", "list", "settings", "error"]
    },
    "menu": {
      "type": "object",
      "properties": {
        "title": { "type": "string" },
        "subtitle": { "type": "string" },
        "options": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "key": { "type": "string" },
              "label": { "type": "string" },
              "action": { "type": "string" }
            }
          }
        },
        "hint": { "type": "string" }
      }
    },
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "index": { "type": "integer" },
          "id": { "type": "integer" },
          "title": { "type": "string" },
          "type": { "type": "string" },
          "tags": { "type": "array", "items": { "type": "string" } },
          "rating": {
            "type": "object",
            "properties": {
              "value": { "type": ["number", "null"] },
              "count": { "type": ["integer", "null"] },
              "visible": { "type": "boolean" }
            }
          },
          "space": {
            "type": "object",
            "properties": {
              "id": { "type": "integer" },
              "title": { "type": "string" }
            }
          },
          "excerpt": { "type": "string" },
          "url": { "type": "string" },
          "reason": { "type": "string" }
        }
      }
    },
    "count": { "type": "integer" },
    "next_actions": {
      "type": "array",
      "items": { "type": "string" }
    },
    "error": { "type": "string" }
  }
}
```

### 8.3 ml_apply_tool - Input

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "stage": {
      "type": "string",
      "enum": ["prepare", "commit"]
    },
    "tool_id": { "type": "integer" },
    "input_text": { "type": "string" },
    "options": {
      "type": "object",
      "properties": {
        "language": { "type": "string" },
        "tone": { "type": "string" },
        "output_format": { "type": "string" }
      }
    },
    "session_id": { "type": "string" },
    "final_text": { "type": "string" },
    "save_as": {
      "type": "string",
      "enum": ["none", "publication", "comment"]
    },
    "target": {
      "type": "object",
      "properties": {
        "space_id": { "type": "integer" },
        "publication_id": { "type": "integer" },
        "parent_comment_id": { "type": "integer" },
        "title": { "type": "string" },
        "comment_type": { "type": "string", "enum": ["public", "private"] }
      }
    }
  },
  "required": ["stage"]
}
```

---

## 9. Tests & Validation

### 9.1 Tests Unitaires

```php
// tests/test-help-tool.php

class Test_Help_Tool extends WP_UnitTestCase {

    public function test_mode_menu_returns_options() {
        $tool = new Help_Tool(1);
        $result = $tool->execute(['mode' => 'menu']);
        
        $this->assertEquals('menu', $result['view']);
        $this->assertCount(5, $result['menu']['options']);
    }

    public function test_search_respects_permissions() {
        // User sans accès à l'espace 123
        $tool = new Help_Tool(99);
        $result = $tool->execute([
            'mode' => 'search',
            'query' => 'test',
            'filters' => ['space_id' => 123]
        ]);
        
        $this->assertEquals(0, $result['count']);
    }

    public function test_best_never_leaks_inaccessible() {
        // Créer publication dans espace non accessible
        // ...
        
        $tool = new Help_Tool(99);
        $result = $tool->execute(['mode' => 'best']);
        
        foreach ($result['items'] as $item) {
            $this->assertTrue(
                $this->permissions->can_see_publication($item['id'])
            );
        }
    }
}
```

### 9.2 Tests d'Intégration

```bash
# Test via WP-CLI
wp eval '
$tool = new MaryLink_MCP\MCP\Help_Tool(93);

// Test menu
$r = $tool->execute(["mode" => "menu"]);
echo "Menu: " . count($r["menu"]["options"]) . " options\n";

// Test search
$r = $tool->execute(["mode" => "search", "query" => "prompt"]);
echo "Search: " . $r["count"] . " résultats\n";

// Test for_me
$r = $tool->execute(["mode" => "for_me"]);
echo "For me: " . $r["count"] . " recommandations\n";

// Test best
$r = $tool->execute(["mode" => "best"]);
echo "Best: " . $r["count"] . " top items\n";
'
```

### 9.3 Check-list Anti-Fuite

- [ ] `ml_help(search)` ne retourne jamais de publication inaccessible
- [ ] `ml_help(best)` filtre par `get_user_publication_spaces()`
- [ ] `ml_help(for_me)` vérifie chaque favori est accessible
- [ ] Aucun endpoint ne retourne `total_raw`
- [ ] `ml_apply_tool(commit)` vérifie permissions POST
- [ ] User avec `space` mais pas `publications` ne voit pas les publications

---

## 10. Fichiers à Créer/Modifier

### Phase 1

| Fichier | Action | Description |
|---------|--------|-------------|
| `src/MCP/Help_Tool.php` | CRÉER | Tool pivot ml_help |
| `src/MCP/Tools_Registry.php` | MODIFIER | Ajouter ml_help, simplifier |
| `src/Services/Search_Service.php` | CRÉER | Logique de recherche |
| `src/Services/Favorites_Service.php` | CRÉER | Gestion favoris |

### Phase 2

| Fichier | Action | Description |
|---------|--------|-------------|
| `src/MCP/Apply_Tool.php` | CRÉER | ml_apply_tool |
| `src/MCP/Pin_Tool.php` | CRÉER | ml_pin |
| `src/MCP/Tools_Registry.php` | MODIFIER | Ajouter ml_apply_tool, ml_pin |

### Phase 3

| Fichier | Action | Description |
|---------|--------|-------------|
| `src/Services/Scoring_Service.php` | CRÉER | Calcul scores qualité |
| `src/MCP/Help_Tool.php` | MODIFIER | Ajouter mode reco |
| `marylink-mcp-tools.php` | MODIFIER | Ajouter cron scoring |

---

## Résumé Exécutif

```
PHASE 1 (4-5h) - Core
├── ml_help(menu)       → Menu 1-5
├── ml_help(search)     → Recherche
├── ml_help(for_me)     → Favoris/raccourcis
├── ml_help(best)       → Top simple
└── ml_get_publication  → Détail (existant)

PHASE 2 (3-4h) - Apply
├── ml_apply_tool(prepare)  → Prépare prompt
├── ml_apply_tool(commit)   → Sauvegarde résultat
└── ml_pin                  → Favoris

PHASE 3 (3-4h) - Polish
├── ml_help(reco)           → Suggestions contextuelles
├── Mode Expert             → Commandes directes
└── Scoring avancé          → Cron + calcul qualité

TOTAL: ~12h de développement
TOOLS FINAUX: 4 (au lieu de 13+)
```

---

*Spec générée le 2024-12-19 - Marylink MCP v3*