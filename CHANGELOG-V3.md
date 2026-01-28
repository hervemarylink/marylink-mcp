# MaryLink MCP - Changelog V3.0.0

## Version 3.2.9 (2026-01-26)

### Added
- `ml_find sort=`: classement in-memory (`best`, `best_rated`, `trending`, `most_commented`, `most_liked`, `most_favorited`) sans impacter les requêtes existantes.
- `ml_find include=["reviews"]`: ajoute `metadata.reviews_sample` (limité au top 5 résultats).
- `ml_find include=["metadata"]`: enrichi avec `metadata.rating`, `favorites_count`, `quality_score`, `engagement_score` (champs déjà présents côté Picasso).

### Fixed
- Validation `limit>=1` (évite `limit=-5` silencieux).
- Validation `space_id` (évite un filtre vide silencieux).

### Notes
- Le tri n'est appliqué **que** si `sort` est fourni (pas de régression sur les listes existantes).

---

## Version 3.0.0 (2025-01-23)

### Commits

---

#### `b0212cc` - fix: Add suppress_filters to bypass URE Pro restrictions

**Problème observé:**
- `ml_find type:publication` retournait 0 résultats
- La base de données contenait 144 publications avec `post_type='publication'` et `post_status='publish'`
- `WP_Query` standard retournait 0, mais requête SQL directe retournait 144

**Cause identifiée:**
- Le plugin **User Role Editor Pro** ajoute un filtre `hide_prohibited_posts` (priorité 999) sur `pre_get_posts`
- Ce filtre bloque les publications pour les utilisateurs sans contexte de session WordPress
- MCP s'exécute via API REST sans session WordPress standard

**Diagnostic effectué:**
```php
// Test avec suppress_filters=false : 0 résultats
$query = new WP_Query(['post_type' => 'publication', 'post_status' => 'publish']);

// Test avec suppress_filters=true : 144 résultats
$query = new WP_Query(['post_type' => 'publication', 'post_status' => 'publish', 'suppress_filters' => true]);
```

**Solution appliquée:**
- Ajout de `'suppress_filters' => true` dans `Find.php` pour les requêtes publications (ligne 256)
- Ajout de `'suppress_filters' => true` dans `Find.php` pour les requêtes tools (ligne 517)
- Cette option bypasse les filtres `pre_get_posts` et `posts_where` de URE Pro

**Fichiers modifiés:**
- `src/MCP/Core/Tools/Find.php`
- `version.json` (build 20250123-002)

---

#### `1283ce7` - chore: bump version to 3.0.0

**Changement:**
- Mise à jour du header WordPress plugin de 2.2.15 vers 3.0.0
- Mise à jour de version.json avec changelog V3 complet

**Fichiers modifiés:**
- `marylink-mcp.php` (Version: 3.0.0)
- `version.json`

---

#### `9e77ff8` - V3 ONLY mode - expose only 6 Core tools

**Problème observé:**
- Après passage à V3, 60+ outils étaient encore exposés
- AI Engine Pro ajoutait 36 outils via le même filtre `mwai_mcp_tools`
- Les outils V2 legacy (54 outils) étaient toujours présents

**Cause identifiée:**
- La fonction `register_tools()` recevait un array `$tools` pré-rempli par d'autres plugins
- Les outils V3 étaient ajoutés mais ne remplaçaient pas les existants

**Solution appliquée:**
```php
public function register_tools(array $tools): array {
    // V3 ONLY: Clear all previous tools and use only 6 Core tools
    $tools = [];  // <-- Reset complet
    
    $v3_tools = Tool_Catalog_V3::build();
    foreach ($v3_tools as $tool) {
        $tools[] = $tool;
    }
    return $tools;
}
```

**Fichiers modifiés:**
- `src/MCP/Tools_Registry.php`

---

#### `b6f6d24` - Fix Tool_Catalog_V3::build() call - no argument needed

**Problème observé:**
- Erreur PHP: argument passé à `Tool_Catalog_V3::build()` ne correspondait pas à la signature

**Cause identifiée:**
- Appel initial: `Tool_Catalog_V3::build($user_id)` passant un `int`
- Signature réelle: `Tool_Catalog_V3::build(array $filters = [])`

**Solution appliquée:**
- Changé `Tool_Catalog_V3::build($user_id)` en `Tool_Catalog_V3::build()`

**Fichiers modifiés:**
- `src/MCP/Tools_Registry.php`

---

#### `9bbe833` - Switch to V3 Tool Catalog - 6 Core tools

**Problème observé:**
- Le système utilisait encore Tool_Catalog V2 (26+ outils)
- Les outils V3 n'étaient pas routés vers Router_V3

**Solution appliquée:**
1. Ajout des use statements:
```php
use MCP_No_Headless\MCP\Core\Tool_Catalog_V3;
use MCP_No_Headless\MCP\Core\Router_V3;
```

2. Remplacement du catalog:
```php
// Avant: $tools = Tool_Catalog::build();
// Après:
$v3_tools = Tool_Catalog_V3::build();
```

3. Ajout du routage V3 dans `handle_callback()`:
```php
$v3_tools = ['ml_ping', 'ml_find', 'ml_me', 'ml_save', 'ml_run', 'ml_assist'];
if (in_array($tool, $v3_tools)) {
    $result = Router_V3::route($tool, $args, $user_id);
    return $this->success_response($id, $result);
}
```

**Fichiers modifiés:**
- `src/MCP/Tools_Registry.php`

---


---

#### `2bf3184` - fix(ml_find): Tools use taxonomy publication_label=tool not post_type

**Probleme observe:**
- `ml_find type:tool` retournait 0 resultats
- La base de donnees contenait des outils (ex: ID 20477 "Analyse d'une nouvelle idee")
- Les outils ont `post_type='publication'`, pas `post_type='tool'`

**Cause identifiee:**
- Dans MaryLink, les outils/prompts sont des **publications** avec une taxonomie speciale
- Taxonomie: `publication_label` avec term slug `tool`
- Le code V3 cherchait un `post_type='tool'` qui n'existe pas

**Diagnostic effectue:**
```sql
-- Verification de la structure des outils
SELECT p.ID, p.post_title, t.name, t.slug
FROM wpnj_posts p
JOIN wpnj_term_relationships tr ON p.ID = tr.object_id
JOIN wpnj_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN wpnj_terms t ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'publication_label' AND t.slug = 'tool';
-- Resultat: 1 outil trouve (ID 20477)
```

**Solution appliquee:**
1. `get_tool()` - Change la verification:
   - Avant: `$post->post_type !== 'tool'`
   - Apres: `$post->post_type !== 'publication'` + `has_term('tool', 'publication_label', $post->ID)`

2. `search_tools()` - Ajout de tax_query:
```php
$args = [
    'post_type' => 'publication',  // Avant: 'tool'
    'tax_query' => [
        [
            'taxonomy' => 'publication_label',
            'field' => 'slug',
            'terms' => 'tool',
        ],
    ],
    // ...
];
```

**Fichiers modifies:**
- `src/MCP/Core/Tools/Find.php` (get_tool, search_tools)
- `version.json` (3.0.1, build 20250123-004)
- `marylink-mcp.php` (Version: 3.0.1)

**Test de validation:**
```json
{
    "success": true,
    "data": {
        "type": "tool",
        "total": 1,
        "items": [{"id": 20477, "name": "Analyse d'une nouvelle idee vs notre existant"}]
    }
}
```

### Autres corrections (non commitées séparément)

#### Post type incorrect

**Problème observé:**
- `ml_find` cherchait `post_type='ml_publication'`
- JAN26 utilise `post_type='publication'`

**Diagnostic:**
```bash
wp post-type list | grep publication
# Résultat: publication (pas ml_publication)
```

**Solution:**
- Remplacé `'ml_publication'` par `'publication'` dans Find.php (lignes 219, 250)
- Remplacé `'ml_tool'` par `'tool'` dans Find.php (lignes 500, 517)

---

### Problèmes de déploiement rencontrés

#### BOM UTF-8 dans Tools_Registry.php

**Problème observé:**
- Erreur PHP: `Namespace declaration statement has to be the very first statement`

**Cause:**
- PowerShell/Windows avait ajouté un BOM (EF BB BF) au début du fichier

**Solution:**
```bash
sed -i '1s/^\xEF\xBB\xBF//' src/MCP/Tools_Registry.php
```

#### Permissions fichiers après unzip

**Problème observé:**
- Plugin "actif" mais classes non chargées
- Erreur: `Class Permission_Checker not found`

**Cause:**
- Fichiers owned par `root:root` après `unzip`
- Serveur web (user `runcloud`) ne pouvait pas lire

**Solution:**
```bash
chown -R runcloud:runcloud /path/to/plugin/
chmod -R 755 /path/to/plugin/
```

---

## Architecture V3

### 6 Outils Core exposés

| Outil | Description |
|-------|-------------|
| `ml_ping` | Health check et version |
| `ml_find` | Recherche et lecture (publications, spaces, tools, users) |
| `ml_me` | Contexte utilisateur (profile, spaces, quotas) |
| `ml_save` | Création et modification de contenu |
| `ml_run` | Exécution d'outils IA |
| `ml_assist` | Assistant conversationnel |

### Flux de routage

```
MCP Request
    ↓
Tools_Registry::handle_callback()
    ↓
V3 tool? → Router_V3::route() → Tool::execute()
    ↓
Response
```
