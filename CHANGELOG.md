# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

## [2.0.0] - 2024-12-19

### Fixed
- Autoloader: backslash correctement échappés (`MaryLink_MCP\\` au lieu de `MaryLink_MCP\`)

### Current State
- Version stable avec tools legacy (13+ tools)
- MCP classes v4.1 (Permission_Checker, Picasso_Tools, Tools_Registry)

---

## [1.4.0] - 2024-12-08

### Added
- Tools MCP complets pour publications, commentaires, reviews
- Intégration AI Engine Pro MCP
- Permission_Checker basé sur Picasso Backend
- Rate limiting (100 calls/heure/user)

### Tools disponibles
- `ml_list_publications` - Liste publications avec filtres
- `ml_get_publication` - Détails publication
- `ml_list_spaces` - Liste espaces
- `ml_get_space` - Détails espace
- `ml_get_my_context` - Contexte IA user
- `ml_create_publication` - Création publication
- `ml_add_comment` - Ajout commentaire
- `ml_create_review` - Création review
- `ml_move_to_step` - Workflow step

---

## [En développement] Spec v3.1

### Objectif
Simplifier de 13+ tools à 4 tools :
- `ml_help` - Tool pivot (menu, search, for_me, best, reco, settings)
- `ml_get_publication` - Détails publication
- `ml_apply_tool` - Appliquer prompt à texte (prepare/commit)
- `ml_pin` - Gérer favoris

### Status
- Branche: `dev/spec-3.1`
- Issue: Backend lent/cassé - à investiguer
