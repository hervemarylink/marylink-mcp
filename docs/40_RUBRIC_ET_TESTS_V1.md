# 📊 Rubric Qualité AO + Jeu de Tests V1

## PARTIE 1 : RUBRIC "RÉPONSE APPEL D'OFFRES"

### Grille d'évaluation (1-5 par critère)

| Critère | Poids | 1 (Insuffisant) | 3 (Acceptable) | 5 (Excellent) |
|---------|-------|-----------------|----------------|---------------|
| **Compréhension du besoin** | 20% | Hors sujet, ne répond pas à l'AO | Comprend le besoin principal | Identifie besoins explicites ET implicites |
| **Utilisation des sources** | 25% | Ignore les sources injectées | Utilise 1-2 sources | Exploite toutes les sources pertinentes |
| **Factualité** | 20% | Invente des chiffres/références | Quelques approximations | 100% vérifiable dans les sources |
| **Structure & clarté** | 15% | Texte brut, pas de structure | Sections basiques | Structure pro (exec summary, détails, annexes) |
| **Persuasion & différenciation** | 10% | Générique, interchangeable | Arguments corrects | Mise en valeur unique, call-to-action fort |
| **Conformité style** | 10% | Ignore le ton demandé | Respecte partiellement | Ton parfaitement aligné (vouvoiement, etc.) |

### Score final
```
Quality Score = Σ (note × poids) / 5
```
- **≥ 4.0** : Production-ready (envoyable avec relecture légère)
- **3.0-3.9** : Acceptable (nécessite édition modérée)
- **< 3.0** : Insuffisant (refaire ou édition lourde)

---

### Checklist rapide (Yes/No)

| Check | Poids |
|-------|-------|
| ☐ Mentionne au moins 1 référence client pertinente | +0.3 |
| ☐ Inclut un budget/chiffrage cohérent avec la grille tarifaire | +0.3 |
| ☐ Propose un planning réaliste | +0.2 |
| ☐ Pas de "lorem ipsum" ou placeholder visible | +0.1 |
| ☐ Pas d'hallucination détectable (nom inventé, chiffre faux) | +0.5 |
| ☐ Longueur appropriée (ni trop court, ni verbeux) | +0.1 |

**Bonus Score** = Σ checks validés → ajouter au Quality Score (max +1.5)

---

## PARTIE 2 : JEU DE TESTS V1 (20 scénarios)

### Structure d'un test

```yaml
test_id: T001
category: ao_response | follow_up | proposal
input:
  problems: "texte admin"
  space_contents:  # publications simulées dans l'espace
    - id: 1, title: "...", type: data, keywords: [...]
    - id: 2, title: "...", type: style
expected:
  detected_tools: [ao_response]
  required_data: [catalog, pricing, references]
  selected_components:
    catalog: 1  # ID attendu
    pricing: 2
  placeholders: [references]  # si manquant
quality_rubric:
  min_score: 3.5
  must_include: ["référence client", "tarif"]
  must_not_include: ["lorem", "exemple fictif"]
```

---

### TESTS CATÉGORIE A : Détection des besoins (analyze)

#### T001 - Détection AO simple
```yaml
test_id: T001
input:
  problems: "Je veux répondre aux appels d'offres"
expected:
  detected_tools: [ao_response]
  required_data: [catalog, pricing, references]
  confidence: ">= 0.8"
```

#### T002 - Détection AO + relance (multi-outils)
```yaml
test_id: T002
input:
  problems: "Mes commerciaux doivent répondre aux AO et relancer les prospects"
expected:
  detected_tools: [ao_response, follow_up]
  required_data: [catalog, pricing, references]
```

#### T003 - Détection proposition commerciale
```yaml
test_id: T003
input:
  problems: "On a besoin de faire des devis et propositions commerciales rapidement"
expected:
  detected_tools: [proposal]
  required_data: [catalog, pricing, company_info]
```

#### T004 - Formulation vague (robustesse)
```yaml
test_id: T004
input:
  problems: "aider mes équipes à vendre mieux"
expected:
  detected_tools: ["length >= 1"]  # Au moins 1 outil détecté
  # Accepte ao_response, follow_up, email_commercial, proposal
```

#### T005 - Aucun match (edge case)
```yaml
test_id: T005
input:
  problems: "Je veux un chatbot pour répondre aux questions RH"
expected:
  detected_tools: []
  error: "no_tools_detected"
```

---

### TESTS CATÉGORIE B : Auto-sélection (propose)

#### T006 - Sélection parfaite (tout trouvé)
```yaml
test_id: T006
input:
  problems: "Répondre aux AO"
  space_contents:
    - id: 101, title: "Catalogue Services 2024", type: data, slug: "catalogue-2024"
    - id: 102, title: "Grille Tarifaire", type: data, slug: "tarifs"
    - id: 103, title: "Nos Références", type: data, slug: "references"
    - id: 104, title: "Charte Éditoriale", type: style, slug: "charte"
expected:
  components:
    catalog: {found: true, id: 101}
    pricing: {found: true, id: 102}
    references: {found: true, id: 103}
    brand_guide: {found: true, id: 104}
  placeholder_count: 0
```

#### T007 - Sélection avec placeholders
```yaml
test_id: T007
input:
  problems: "Faire des propositions commerciales"
  space_contents:
    - id: 201, title: "Nos Services", type: data
    # Pas de pricing, pas de company_info
expected:
  components:
    catalog: {found: true, id: 201}
    pricing: {found: false}
    company_info: {found: false}
  placeholder_count: 2
```

#### T008 - Ambiguïté de sélection (2 catalogues)
```yaml
test_id: T008
input:
  problems: "AO"
  space_contents:
    - id: 301, title: "Catalogue 2023", type: data, modified: "2023-06-01"
    - id: 302, title: "Catalogue 2024", type: data, modified: "2024-11-01"
expected:
  components:
    catalog: {found: true, id: 302}  # Le plus récent
  reason: "recency_wins"
```

#### T009 - Meta exacte prioritaire
```yaml
test_id: T009
input:
  problems: "AO"
  space_contents:
    - id: 401, title: "Offres et Services", type: data, meta: {_ml_bootstrap_data_id: "catalog"}
    - id: 402, title: "Catalogue Complet 2024", type: data
expected:
  components:
    catalog: {found: true, id: 401}  # Meta exacte gagne même si titre moins bon
```

#### T010 - Espace vide
```yaml
test_id: T010
input:
  problems: "Répondre aux AO"
  space_contents: []
expected:
  components:
    catalog: {found: false}
    pricing: {found: false}
    references: {found: false}
  placeholder_count: 3
```

---

### TESTS CATÉGORIE C : URL Resolver

#### T011 - Résolution simple
```yaml
test_id: T011
input:
  instruction: |
    Tu es un expert.
    https://test.marylink.io/publication/catalogue-2024/
    Rédige une réponse.
  publications:
    - slug: "catalogue-2024", content: "## Nos Services\n- Conseil\n- Formation"
expected:
  resolved_count: 1
  output_contains: "=== BEGIN REFERENCE"
  output_contains: "Nos Services"
```

#### T012 - URLs multiples
```yaml
test_id: T012
input:
  instruction: |
    Voici les sources:
    https://test.marylink.io/publication/catalogue/
    https://test.marylink.io/publication/tarifs/
    https://test.marylink.io/publication/references/
expected:
  resolved_count: 3
  stats:
    urls_found: 3
    errors: 0
```

#### T013 - URL non trouvée (graceful failure)
```yaml
test_id: T013
input:
  instruction: |
    Source: https://test.marylink.io/publication/inexistant/
expected:
  errors: [{url: "*/inexistant/*", error: "*non trouvée*"}]
  # L'URL reste dans le contenu, pas de crash
```

#### T014 - Permission refusée
```yaml
test_id: T014
input:
  user_id: 99  # N'a pas accès
  instruction: "https://test.marylink.io/publication/confidentiel/"
  publications:
    - slug: "confidentiel", access: [1, 2]  # Seulement users 1 et 2
expected:
  errors: [{error: "*Accès refusé*"}]
```

#### T015 - Truncation (contenu trop long)
```yaml
test_id: T015
input:
  instruction: "https://test.marylink.io/publication/gros-doc/"
  publications:
    - slug: "gros-doc", content: "[100000 caractères]"
expected:
  stats:
    truncated: true
  output_contains: "[... contenu tronqué ...]"
```

---

### TESTS CATÉGORIE D : Qualité output (end-to-end)

#### T016 - AO simple avec sources complètes
```yaml
test_id: T016
category: quality
input:
  tool: ao_response
  user_input: |
    APPEL D'OFFRES - Mairie de Lyon
    Objet: Accompagnement transformation digitale
    Budget: 50-80k€
    Critères: expérience secteur public, méthodologie agile
  injected_sources:
    catalog: "## Nos Offres\n- Transformation digitale\n- Conseil stratégique"
    pricing: "## Tarifs\nConsultant Senior: 1200€/jour"
    references: "## Références\n- Métropole de Bordeaux (2023)\n- Région Occitanie (2022)"
expected:
  quality_rubric:
    min_score: 3.5
    must_include:
      - "transformation digitale"
      - "Bordeaux" OR "Occitanie"  # Au moins 1 référence
      - pattern: "[0-9]+.?[0-9]*€"  # Un montant
    must_not_include:
      - "lorem"
      - "exemple"
      - "[à compléter]"
```

#### T017 - AO avec placeholder (sources partielles)
```yaml
test_id: T017
category: quality
input:
  tool: ao_response
  user_input: "AO Conseil RH - Budget 30k€"
  injected_sources:
    catalog: "[Contenu placeholder - à compléter]"
    pricing: "## Tarifs\nConsultant: 1000€/jour"
expected:
  quality_rubric:
    min_score: 2.5  # Acceptable mais dégradé
    notes: "Output générique faute de catalogue"
```

#### T018 - Relance client
```yaml
test_id: T018
category: quality
input:
  tool: follow_up
  user_input: |
    Client: Acme Corp
    Contexte: Proposition envoyée il y a 2 semaines, pas de réponse
    Contact: Marie Dupont, DSI
expected:
  quality_rubric:
    min_score: 3.5
    must_include:
      - "Marie" OR "Dupont"  # Personnalisation
      - "proposition"
    max_length: 200  # Email court
```

#### T019 - Proposition commerciale
```yaml
test_id: T019
category: quality
input:
  tool: proposal
  user_input: "Proposition pour audit SI - Client: TechnoPlus - Budget: 25k€"
  injected_sources:
    catalog: "## Audit SI\nDurée: 10-15 jours\nLivrables: rapport, recommandations"
    pricing: "Expert: 1500€/jour"
expected:
  quality_rubric:
    must_include:
      - "audit"
      - "TechnoPlus"
      - pattern: "planning|délai|durée"
```

#### T020 - Style compliance
```yaml
test_id: T020
category: quality
input:
  tool: ao_response
  user_input: "AO formation management"
  injected_sources:
    style: |
      ## Charte
      - Vouvoiement obligatoire
      - Ton professionnel mais chaleureux
      - Éviter le jargon technique
expected:
  quality_rubric:
    style_checks:
      - no_tutoyement: true
      - no_jargon: ["ROI", "scalable", "disruptif"]  # Mots interdits
```

---

## PARTIE 3 : SCRIPT DE TEST AUTOMATISÉ

```php
<?php
/**
 * Test Runner pour Bootstrap Wizard
 */

class Bootstrap_Test_Runner {
    
    private array $results = [];
    
    public function run_test(array $test): array {
        $result = [
            'test_id' => $test['test_id'],
            'passed' => true,
            'checks' => [],
        ];
        
        // Exécuter le wizard
        $response = $this->execute_wizard($test['input']);
        
        // Vérifier les attentes
        foreach ($test['expected'] as $key => $expected) {
            $check = $this->check($key, $expected, $response);
            $result['checks'][] = $check;
            if (!$check['passed']) {
                $result['passed'] = false;
            }
        }
        
        $this->results[] = $result;
        return $result;
    }
    
    private function check(string $key, $expected, array $response): array {
        $actual = $response[$key] ?? null;
        
        // Comparaison intelligente
        if (is_array($expected) && isset($expected['found'])) {
            // Check component
            $passed = ($actual['found'] ?? false) === $expected['found'];
            if ($passed && isset($expected['id'])) {
                $passed = ($actual['publication_id'] ?? null) === $expected['id'];
            }
        } elseif (is_string($expected) && str_starts_with($expected, '>=')) {
            // Check numérique
            $threshold = (float) trim(substr($expected, 2));
            $passed = ($actual ?? 0) >= $threshold;
        } else {
            $passed = $actual === $expected;
        }
        
        return [
            'key' => $key,
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $passed,
        ];
    }
    
    public function get_summary(): array {
        $passed = count(array_filter($this->results, fn($r) => $r['passed']));
        $total = count($this->results);
        
        return [
            'passed' => $passed,
            'failed' => $total - $passed,
            'total' => $total,
            'success_rate' => $total > 0 ? round($passed / $total * 100, 1) : 0,
        ];
    }
}
```

---

## PARTIE 4 : TABLEAU DE BORD MINIMUM VIABLE

### 8 métriques clés (comme demandé)

| # | Métrique | Source | Objectif V1 | Alerte si |
|---|----------|--------|-------------|-----------|
| 1 | **Coverage rate** | bootstrap_select | ≥ 70% | < 50% |
| 2 | **Placeholder rate** | bootstrap_select | ≤ 30% | > 50% |
| 3 | **Replacement rate** | UI collect stage | ≤ 25% | > 40% |
| 4 | **Fetch success rate** | url_resolve | ≥ 95% | < 90% |
| 5 | **P95 resolve latency** | url_resolve | < 500ms | > 1000ms |
| 6 | **Injected tokens** | url_resolve | < 15K | > 25K |
| 7 | **Quality score** | quality_feedback | ≥ 3.5/5 | < 3.0 |
| 8 | **Tool execution rate** | tool_execute | ≥ 3/tool/week | < 1 |

### Events à émettre

```php
// 1. Après analyze
do_action('ml_metrics', 'bootstrap_analyze', [
    'run_id' => $run_id,
    'space_id' => $space_id,
    'detected_tools' => $tools,
    'confidence' => $confidence,
]);

// 2. Après propose
do_action('ml_metrics', 'bootstrap_select', [
    'run_id' => $run_id,
    'coverage_rate' => $found / $required,
    'placeholder_rate' => $placeholders / $required,
    'components' => $components,
]);

// 3. Après execute
do_action('ml_metrics', 'tool_created', [
    'run_id' => $run_id,
    'tool_id' => $tool_id,
    'url_count' => count($urls),
    'placeholder_count' => $placeholders,
]);

// 4. À chaque résolution
do_action('ml_metrics', 'url_resolve', [
    'run_id' => $run_id,
    'url_count' => $count,
    'success_count' => $success,
    'latency_ms' => $latency,
    'injected_tokens' => $tokens,
    'cache_hit_rate' => $cache_hits / $count,
    'errors' => $errors,
]);

// 5. Feedback qualité (thumbs up/down + rubric si audit)
do_action('ml_metrics', 'quality_feedback', [
    'tool_id' => $tool_id,
    'rating' => $thumb, // 1 ou -1
    'rubric_score' => $score, // si audit manuel
    'edit_ratio' => $edit_ratio, // si mesurable
]);
```

---

## Prochaine étape

1. **Implémenter les events** dans Bootstrap_Wizard_Tool.php
2. **Créer 5 espaces de test** avec données variées
3. **Runner les 20 tests** automatiquement
4. **Dashboard Grafana/Metabase** sur les events

Tu veux que je t'ajoute les hooks d'events directement dans le code PHP du wizard ?
