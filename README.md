# MCP No Headless

Plugin WordPress MCP Server standalone - sans dependances BuddyBoss/Picasso.

## Description

Fork de `marylink-mcp-tools` pour exposer un serveur MCP (Model Context Protocol) directement via REST API SSE sur n'importe quel site WordPress.

## Fonctionnalites

- **SSE Endpoint**: `/wp-json/mcp/v1/sse`
- **Messages Endpoint**: `/wp-json/mcp/v1/messages`
- **Token Management**: Generation de tokens API par utilisateur
- **Outils MCP**: Publications, commentaires, recherche
- **Integration AI Engine**: Optionnelle (si AI Engine Pro installe)
- **OpenAI Connectors**: Compatible ChatGPT Actions (search/fetch)

## Installation

1. Telecharger le plugin
2. Uploader dans `/wp-content/plugins/`
3. Activer via l'admin WordPress
4. Configurer les tokens dans le profil utilisateur

## Configuration Claude Desktop

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "sse",
      "url": "https://votre-site.com/wp-json/mcp/v1/sse",
      "headers": {
        "X-API-Key": "VOTRE_TOKEN"
      }
    }
  }
}
```

## Outils MCP Exposes

### OpenAI Connectors (ChatGPT Compatible)

| Outil | Description |
|-------|-------------|
| `search` | Recherche publications et espaces. Retourne des resultats citables avec IDs |
| `fetch` | Recupere le contenu complet par ID (pub:<id> ou space:<id>) |

### Menu Pivot (Phase 3)

| Outil | Description |
|-------|-------------|
| `ml_help` | Assistant interactif avec navigation menu, recherche, recommandations |

**Modes disponibles:**
- `menu` - Navigation principale avec commandes expert
- `search` - Recherche publications/espaces
- `for_me` - Publications de l'utilisateur
- `best` - Publications populaires (tri par quality score)
- `settings` - Profil IA et parametres utilisateur
- `reco` - Recommandations contextuelles avec extraction keywords

**Mode Reco (Recommandations):**
```json
{
  "mode": "reco",
  "context_text": "Je cherche a ameliorer la redaction de mes emails professionnels",
  "limit": 5,
  "filters": {"space_id": 123}
}
```
Retourne des suggestions basees sur l'extraction de keywords du contexte.

**Mode Expert:**
Activez via `ml_help(mode:"settings", action:"set", setting:"expert_mode", value:true)`

Commandes expert disponibles:
- `trouve <texte>` - Recherche rapide
- `ouvre N` - Ouvrir le resultat N
- `applique N` - Appliquer l'outil N
- `best` - Publications populaires
- `mes pubs` - Mes publications
- `reco` - Suggestions contextuelles

**Resultats indexes:**
Tous les resultats de liste incluent un champ `index` (1, 2, 3...) pour faciliter la navigation via commandes expert.

### Publications

| Outil | Description |
|-------|-------------|
| `ml_list_publications` | Liste les publications avec filtres |
| `ml_get_publication` | Details d'une publication |
| `ml_create_publication` | Creer une publication |
| `ml_create_publication_from_text` | Importer du contenu externe |
| `ml_edit_publication` | Modifier une publication |
| `ml_append_to_publication` | Ajouter du contenu |

### Espaces

| Outil | Description |
|-------|-------------|
| `ml_list_spaces` | Liste les espaces accessibles |
| `ml_get_space` | Details d'un espace |

### Commentaires & Reviews

| Outil | Description |
|-------|-------------|
| `ml_add_comment` | Ajouter un commentaire |
| `ml_import_as_comment` | Importer contenu externe |
| `ml_create_review` | Creer une review |

### Workflow

| Outil | Description |
|-------|-------------|
| `ml_move_to_step` | Changer le step workflow |
| `ml_get_my_context` | Contexte IA utilisateur |

### BuddyBoss Groups (Phase 4A)

| Outil | Description |
|-------|-------------|
| `ml_groups_search` | Recherche groupes avec filtres (my_only, status) |
| `ml_group_fetch` | Details d'un groupe |
| `ml_group_members` | Liste membres d'un groupe |

**Exemple:**
```json
{
  "query": "marketing",
  "filters": {"my_only": true, "status": "public"},
  "limit": 10
}
```

### BuddyBoss Activity (Phase 4A)

| Outil | Description |
|-------|-------------|
| `ml_activity_list` | Flux d'activite avec scope (all, group, friends, my, public) |
| `ml_activity_fetch` | Details d'une activite |
| `ml_activity_comments` | Commentaires d'une activite |

**Exemple:**
```json
{
  "group_id": 123,
  "scope": "group",
  "per_page": 10
}
```

### BuddyBoss Members (Phase 4A)

| Outil | Description |
|-------|-------------|
| `ml_members_search` | Recherche membres avec filtres (friends_only, group_id) |
| `ml_member_fetch` | Profil minimal d'un membre (data minimization) |

**Securite Phase 4A:**
- Groupes hidden/private: invisibles hors membres (meme erreur "not found")
- Activity de groupe prive: filtree automatiquement
- Profils: uniquement infos publiques (data minimization)
- Cache: 30-60s par requete pour performance

### Apply Tool (Phase 2)

| Outil | Description |
|-------|-------------|
| `ml_apply_tool` | Appliquer un outil/prompt/style a un texte avec flow prepare/commit |

**Flow prepare/commit:**

1. **prepare**: Construit le prompt a partir du template + input
   - Input: `tool_id`, `input_text`, `options` (language, tone, output_format)
   - Output: `prepared_prompt`, `session_id` (valide 5 min)

2. **commit**: Sauvegarde le resultat (optionnel)
   - Input: `session_id`, `final_text`, `save_as` (none/publication/comment), `target`
   - save_as=publication: `{space_id, title, status}`
   - save_as=comment: `{publication_id, comment_type, parent_comment_id}`

**Exemple:**
```json
// Etape 1: prepare
{
  "stage": "prepare",
  "tool_id": 123,
  "input_text": "Mon texte a transformer",
  "options": {"language": "fr", "tone": "professional"}
}

// Etape 2: commit (apres execution du prompt)
{
  "stage": "commit",
  "session_id": "sess_abc123...",
  "final_text": "Resultat genere par l'IA",
  "save_as": "publication",
  "target": {"space_id": 456, "title": "Ma nouvelle publication"}
}
```

## Ops & Securite (Phase 5)

### REST API Monitoring

| Endpoint | Methode | Auth | Description |
|----------|---------|------|-------------|
| `/wp-json/marylink-mcp/v1/health` | GET | Public | Health check simple |
| `/wp-json/marylink-mcp/v1/health/full` | GET | Admin | Health check detaille |
| `/wp-json/marylink-mcp/v1/diagnostics` | GET | Admin | Diagnostics complets |
| `/wp-json/marylink-mcp/v1/audit` | GET | Admin | Logs d'audit |
| `/wp-json/marylink-mcp/v1/audit/stats` | GET | Admin | Statistiques audit |
| `/wp-json/marylink-mcp/v1/recalculate-scores` | POST | Admin | Recalculer scores |
| `/wp-json/marylink-mcp/v1/rate-limits` | GET | User | Limites utilisateur |
| `/wp-json/marylink-mcp/v1/rate-limits/reset` | POST | Admin | Reset limites |
| `/wp-json/marylink-mcp/v1/token` | GET | User | Info token |
| `/wp-json/marylink-mcp/v1/token/regenerate` | POST | User | Regenerer token |
| `/wp-json/marylink-mcp/v1/token/revoke` | POST | User | Revoquer token |
| `/wp-json/marylink-mcp/v1/token/scopes` | PUT | User | Modifier scopes |

### Token Scopes

| Scope | Permissions |
|-------|-------------|
| `read:content` | Lecture publications, espaces |
| `write:content` | Creation/edition publications |
| `read:social` | Lecture groupes, activites, membres |
| `write:social` | Post activites, commentaires |

**Par defaut:** `read:content` + `read:social` (ecriture desactivee)

### Rate Limiting

| Operation | Limite/min/user | Burst (5s) |
|-----------|-----------------|------------|
| Read | 120 | 15 |
| Write | 20 | 5 |
| Global | 2000/5min | - |

### Audit Logging

Toutes les operations MCP sont tracees avec:
- `debug_id` unique pour correlation support
- user_id, tool_name, target_type/id
- result (success/error/denied/rate_limited)
- latency_ms

Retention configurable (defaut: 30 jours)

### Admin Dashboard

Acces: **WP Admin -> MaryLink MCP -> Status**

- Health status en temps reel
- Stats 24h/7j (requetes, erreurs, latence)
- Top outils utilises
- Erreurs recentes avec debug_id
- Actions: recalcul scores, reset rate limits, diagnostics

### RUNBOOK

Voir `RUNBOOK.md` pour guide operationnel complet.

## Structure du Projet

```
mcp-no-headless/
├── mcp-no-headless.php      # Fichier principal
├── README.md
├── RUNBOOK.md               # Guide operationnel (Phase 5)
├── assets/                  # CSS/JS admin
│   ├── admin.css
│   └── admin.js
└── src/
    ├── MCP/
    │   ├── Tools_Registry.php      # Registre des outils MCP
    │   ├── Picasso_Tools.php       # Execution des outils
    │   ├── Permission_Checker.php  # Verification permissions
    │   ├── Search_Fetch_Tools.php  # OpenAI Connectors (search/fetch)
    │   ├── Help_Tool.php           # Menu pivot interactif (Phase 3)
    │   └── Apply_Tool.php          # Flow prepare/commit (Phase 2)
    ├── BuddyBoss/                   # Phase 4A - Social Layer
    │   ├── Group_Service.php       # Groupes BuddyBoss
    │   ├── Activity_Service.php    # Flux d'activite
    │   └── Member_Service.php      # Profils membres
    ├── Ops/                         # Phase 5 - Operations
    │   ├── Audit_Logger.php        # Logging structure
    │   ├── Error_Handler.php       # Erreurs normalisees
    │   ├── Health_Check.php        # Diagnostics systeme
    │   ├── Rate_Limiter.php        # Rate limiting unifie
    │   └── REST_Controller.php     # Endpoints REST ops
    ├── Admin/
    │   └── Admin_Page.php          # Dashboard admin (Phase 5)
    ├── Services/
    │   └── Scoring_Service.php     # Calcul scores qualite (Phase 3)
    ├── User/
    │   ├── Token_Manager.php       # Gestion tokens + scopes (Phase 5)
    │   └── Profile_Tab.php         # Onglet profil
    └── Integration/
        └── AI_Engine_Bridge.php    # Bridge AI Engine
```

## Systeme de Scoring (Phase 3)

Le scoring calcule un score de qualite (0-5) pour chaque publication base sur:
- **Rating utilisateur** (35%) - Moyenne bayesienne des notes
- **Favoris** (25%) - Nombre de bookmarks (log scale)
- **Engagement** (20%) - Vues + commentaires
- **Fraicheur** (20%) - Decroissance exponentielle (demi-vie 30 jours)

**Cron automatique:** Recalcul quotidien de tous les scores.

**Meta keys utilisees:**
- `_ml_quality_score` - Score final (0-5)
- `_ml_avg_user_rating` - Moyenne des notes
- `_ml_user_rating_count` - Nombre de votes
- `_ml_favorites_count` - Favoris
- `_ml_views_count` - Vues
- `_ml_comment_count` - Commentaires

## Prerequis

- WordPress 6.0+
- PHP 8.0+
- SSL (HTTPS recommande)

## Dependances Optionnelles

- **Picasso Backend**: Pour les permissions avancees (co-auteurs, teams, etc.)
- **AI Engine Pro**: Pour les fonctionnalites IA avancees
- **BuddyBoss**: Pour l'onglet profil MCP

## Licence

GPL v2 ou ulterieure

## Auteur

MaryLink - https://marylink.io
