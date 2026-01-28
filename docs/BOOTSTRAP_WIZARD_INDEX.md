# 📦 PACK COMPLET : Bootstrap Wizard + Auto-Sélection + URLs

**Version 2.1** — Prod-ready avec métriques + sécurité anti-SSRF

---

## Architecture validée

```
Admin décrit problème
        ↓
   [analyze] détecte les outils nécessaires
        ↓
   [propose] auto-sélectionne les composants (scoring)
        ↓
   [collect] correction manuelle (optionnel)
        ↓
   [execute] crée Outil avec URLs dans _ml_instruction
        ↓
   [runtime] resolve URLs → inject contenu → LLM répond
```

---

## Index des fichiers

### 📋 DOCUMENTATION (4 fichiers)

| Fichier | Description |
|---------|-------------|
| `01_DESCRIPTION_FONCTIONNELLE.md` | Parcours UX complet (admin + consultant) |
| `02_SPEC_CLAUDE_READY.md` | Spec technique + 8 Acceptance Criteria |
| `03_SPEC_BOOTSTRAP_WIZARD.md` | Templates, Data Types, Patterns détection |
| `40_RUBRIC_ET_TESTS_V1.md` | Rubric AO + 20 tests + Dashboard métriques |

### 💻 CODE PHP (6 fichiers, ~1500 lignes)

| Fichier | Lignes | Description |
|---------|--------|-------------|
| `10_Bootstrap_Wizard_Tool.php` | ~550 | Wizard 5 stages + métriques |
| `11_URL_Resolver.php` | ~350 | **v2 prod-ready** : allowlist, API JSON, singulier/pluriel |
| `12_Tool_Service.php` | ~90 | Orchestre la résolution |
| `13_Component_Picker.php` | ~180 | Auto-sélection avec scoring |
| `14_Instruction_Builder.php` | ~100 | Construit instruction avec URLs |
| `15_Metrics_Collector.php` | ~280 | Stockage BDD + dashboard queries |

### 🔧 PATCHES (2 fichiers)

| Fichier | Description |
|---------|-------------|
| `20_PATCH_Tools_Registry.diff` | Enregistrer ml_bootstrap_wizard |
| `21_PATCH_Permission_Checker.diff` | Ajouter can_use_tool(), can_create_in_space() |

### ✅ TESTS (1 fichier)

| Fichier | Description |
|---------|-------------|
| `30_TEST_PLAN.md` | 20 tests E2E + checklist validation |

---

## Améliorations v2.1 (prod-ready)

### 🔒 Sécurité anti-SSRF

```php
// Seuls ces domaines sont autorisés
ALLOWED_DOMAINS = ['marylink.net', 'marylink.io']

// Pas d'IP literals, pas de redirects
// Timeout strict (5 secondes)
```

### 📡 Normalisation API JSON

```php
// Avant (page HTML avec menus/footer)
https://cabinet.marylink.net/publication/catalog

// Après (données propres)
https://cabinet.marylink.net/wp-json/marylink/v1/publications/catalog
```

### 🔄 Support singulier/pluriel

```php
// Tous ces formats sont supportés :
/publication/catalog     ✓
/publications/catalog    ✓ (legacy)
/style/formal-b2b       ✓
/styles/formal-b2b      ✓ (legacy)
```

### 🛡️ Wrapper anti prompt-injection

```
=== BEGIN REFERENCE: Catalogue 2024 ===
[contenu injecté]
=== END REFERENCE ===

=== BEGIN STYLE GUIDE: Charte Éditoriale ===
[style injecté]
=== END STYLE GUIDE ===
```

### ⚡ Fallback graceful

```php
// Si une URL échoue, on n'interrompt pas tout :
[Source 'catalog' indisponible: permission_denied]
```

---

## Métriques émises

| Event | Stage | Métriques clés |
|-------|-------|----------------|
| `bootstrap_analyze` | analyze | `confidence`, `detected_tools_count` |
| `bootstrap_select` | propose | `coverage_rate`, `placeholder_rate`, `avg_score` |
| `bootstrap_override` | collect | `is_replacement` (pour replacement_rate) |
| `tool_created` | execute | `url_count`, `placeholder_count` |
| `bootstrap_complete` | execute | `total_latency_ms`, `success` |
| `url_resolve` | runtime | `latency_ms`, `injected_tokens`, `local_count`, `remote_count` |

---

## Installation

```bash
# 1. Dézipper
unzip pack_bootstrap_wizard.zip

# 2. Copier les fichiers PHP
cp pack/10_Bootstrap_Wizard_Tool.php  src/MCP/Bootstrap_Wizard_Tool.php
cp pack/11_URL_Resolver.php           src/Services/URL_Resolver.php
cp pack/12_Tool_Service.php           src/Services/Tool_Service.php
cp pack/13_Component_Picker.php       src/Services/Component_Picker.php
cp pack/14_Instruction_Builder.php    src/Services/Instruction_Builder.php
cp pack/15_Metrics_Collector.php      src/Services/Metrics_Collector.php

# 3. Appliquer les patches
# (voir fichiers .diff)

# 4. Initialiser les métriques
Metrics_Collector::init();
```

---

## Dashboard minimum viable

```php
$metrics = Metrics_Collector::get_dashboard_metrics('30d');
```

| Métrique | Objectif | Alerte |
|----------|----------|--------|
| Coverage rate | ≥ 70% | < 50% |
| Placeholder rate | ≤ 30% | > 50% |
| Replacement rate | ≤ 25% | > 40% |
| Fetch success rate | ≥ 95% | < 90% |
| P95 resolve latency | < 500ms | > 1000ms |
| Avg injected tokens | < 15K | > 25K |

---

## Phase 1 vs Phase 2

### Phase 1 (actuel, hybride)

L'outil stocke :
- `_ml_instruction` avec URLs (pour runtime)
- `_ml_tool_contents` et `_ml_linked_styles` (compat Marylink)

### Phase 2 (futur, URL-only)

Une fois validé que tout passe par le resolver :
- Supprimer `_ml_tool_contents` et `_ml_linked_styles`
- Garder uniquement `_ml_instruction` avec URLs
