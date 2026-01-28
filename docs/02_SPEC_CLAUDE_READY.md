# SPEC TECHNIQUE : Bootstrap Wizard avec Auto-Sélection

## 1. Vue d'ensemble

L'admin décrit son besoin → le système analyse → sélectionne automatiquement les meilleurs contenus → génère des Outils prêts à l'emploi.

**Principe clé** : L'Outil créé contient des URLs Marylink dans son instruction. Au runtime, le resolver parse ces URLs, charge les publications locales, et injecte leur contenu.

---

## 2. Parcours Utilisateur (API)

### Étape 1 : Analyse (stage: analyze)

**Input** :
```json
{
  "stage": "analyze",
  "problems": "Je veux répondre aux AO et relancer les prospects",
  "target_space_id": 42
}
```

**Output** :
```json
{
  "session_id": "boot_abc123",
  "stage": "analyze",
  "detected_tools": ["ao_response", "follow_up"],
  "required_data": {
    "catalog": { "label": "Catalogue produits/services", "required": true },
    "pricing": { "label": "Grille tarifaire", "required": true },
    "references": { "label": "Références clients", "required": false }
  },
  "required_styles": ["formal_b2b"],
  "next_stage": "propose"
}
```

### Étape 2 : Proposition (stage: propose)

**Input** :
```json
{
  "stage": "propose",
  "session_id": "boot_abc123"
}
```

**Output** :
```json
{
  "session_id": "boot_abc123",
  "stage": "propose",
  "proposed_kit": {
    "name": "Kit Commercial",
    "tools": [
      {
        "id": "ao_response",
        "name": "Générateur de Réponse AO",
        "description": "Rédige des réponses structurées aux appels d'offres"
      },
      {
        "id": "follow_up",
        "name": "Assistant Relance Client",
        "description": "Rédige des emails de relance personnalisés"
      }
    ]
  },
  "components": {
    "catalog": { "found": true, "publication_id": 123, "title": "Catalogue 2024", "score": 0.95 },
    "pricing": { "found": true, "publication_id": 456, "title": "Grille Tarifaire", "score": 0.88 },
    "references": { "found": false, "publication_id": null, "title": null, "score": 0 }
  },
  "missing_count": 1,
  "next_stage": "collect"
}
```

### Étape 3 : Collecte/Correction (stage: collect) — OPTIONNEL

**Input** (pour corriger une sélection) :
```json
{
  "stage": "collect",
  "session_id": "boot_abc123",
  "data_id": "catalog",
  "publication_id": 789
}
```

**Output** :
```json
{
  "session_id": "boot_abc123",
  "stage": "collect",
  "updated": true,
  "components": {
    "catalog": { "found": true, "publication_id": 789, "title": "Nouveau Catalogue", "score": 1.0 }
  }
}
```

### Étape 4 : Validation (stage: validate)

**Input** :
```json
{
  "stage": "validate",
  "session_id": "boot_abc123"
}
```

**Output** :
```json
{
  "session_id": "boot_abc123",
  "stage": "validate",
  "ready": true,
  "summary": {
    "tools_count": 2,
    "components_found": 2,
    "components_missing": 1,
    "placeholders_to_create": ["references"]
  },
  "warnings": [
    "1 contenu manquant sera créé comme placeholder"
  ],
  "next_stage": "execute"
}
```

### Étape 5 : Exécution (stage: execute)

**Input** :
```json
{
  "stage": "execute",
  "session_id": "boot_abc123",
  "confirmed": true
}
```

**Output** :
```json
{
  "session_id": "boot_abc123",
  "stage": "execute",
  "status": "completed",
  "created": [
    { "id": 1001, "type": "placeholder", "title": "📝 Références à compléter" },
    { "id": 1002, "type": "tool", "title": "Générateur de Réponse AO" },
    { "id": 1003, "type": "tool", "title": "Assistant Relance Client" },
    { "id": 1004, "type": "landing", "title": "Guide - Kit Commercial" }
  ]
}
```

---

## 3. RÈGLES NON AMBIGUËS

### 3.1 Stockage des dépendances

**Mécanisme hybride (Phase 1)** :

L'Outil créé stocke ses dépendances de DEUX façons :

```php
// 1. Metas traditionnelles (compatibilité)
update_post_meta($tool_id, '_ml_tool_contents', [123, 456, 789]);
update_post_meta($tool_id, '_ml_linked_styles', [321]);

// 2. URLs dans l'instruction (nouveau système)
$instruction = "Tu es un expert...

https://instance.marylink.io/publication/catalogue-2024/
https://instance.marylink.io/publication/grille-tarifaire/
https://instance.marylink.io/publication/references/
https://instance.marylink.io/publication/style-formel/

Rédige une réponse structurée.";

update_post_meta($tool_id, '_ml_instruction', $instruction);
```

### 3.2 Algorithme d'auto-sélection

**Objectif** : Pour un `data_id` requis (ex: "catalog"), trouver la meilleure publication dans l'espace.

**Critères de ranking (par ordre de priorité)** :

| Priorité | Critère | Points |
|----------|---------|--------|
| 1 | Meta `_ml_bootstrap_data_id` exacte | +100 |
| 2 | Label/type publication correspond | +50 |
| 3 | Titre contient le mot-clé | +30 |
| 4 | Slug contient le mot-clé | +20 |
| 5 | Publication récente (< 30 jours) | +10 |
| 6 | Publication longue (> 500 chars) | +5 |

**Tie-breaker** : `post_modified DESC`

**Fallback** : Si score < 20 → `publication_id = null` → placeholder

**Mots-clés par data_id** :

```php
const DATA_KEYWORDS = [
    'catalog' => ['catalog', 'catalogue', 'offre', 'service', 'produit', 'prestation'],
    'pricing' => ['tarif', 'prix', 'pricing', 'grille', 'cout', 'devis'],
    'references' => ['reference', 'client', 'portfolio', 'cas', 'temoignage', 'projet'],
    'brand_guide' => ['charte', 'style', 'editorial', 'brand', 'marque', 'ton', 'voix'],
    'company_info' => ['entreprise', 'societe', 'cabinet', 'equipe', 'histoire', 'valeur'],
];
```

### 3.3 Placeholders

**Quand créer** : Si `publication_id = null` après auto-sélection ET l'admin n'a pas fourni de remplacement.

**Structure** :

```php
$placeholder_id = wp_insert_post([
    'post_type' => 'publication',
    'post_status' => 'draft',
    'post_parent' => $space_id,
    'post_author' => $user_id,
    'post_title' => "📝 {$data_label} à compléter",
    'post_content' => "## {$data_label}\n\n[Contenu à compléter par l'administrateur]\n\nCe document sera utilisé par vos outils IA pour générer des réponses personnalisées.",
]);

// Metas obligatoires
update_post_meta($placeholder_id, '_ml_publication_type', 'data');
update_post_meta($placeholder_id, '_ml_is_placeholder', true);
update_post_meta($placeholder_id, '_ml_bootstrap_data_id', $data_id);
update_post_meta($placeholder_id, '_ml_space_id', $space_id);

// Label taxonomy
wp_set_post_terms($placeholder_id, ['contenu'], 'publication_label');
```

### 3.4 Construction de l'instruction

**Fonction** :

```php
function build_instruction_with_urls(
    string $base_instruction,
    array $content_ids,
    array $style_ids,
    string $final_task
): string {
    $urls = [];
    
    foreach ($content_ids as $id) {
        $post = get_post($id);
        if ($post) {
            $urls[] = home_url("/publication/{$post->post_name}/");
        }
    }
    
    foreach ($style_ids as $id) {
        $post = get_post($id);
        if ($post) {
            $urls[] = home_url("/publication/{$post->post_name}/");
        }
    }
    
    $urls = array_values(array_unique($urls));
    
    // Limite
    if (count($urls) > 20) {
        $urls = array_slice($urls, 0, 20);
    }
    
    return trim($base_instruction)
        . "\n\n"
        . implode("\n", $urls)
        . "\n\n"
        . trim($final_task);
}
```

### 3.5 URL Resolver

**Pattern de détection** :
```php
const URL_PATTERN = '~https?://[a-zA-Z0-9.-]+\.marylink\.(io|net)/publication/([a-zA-Z0-9_-]+)/?~i';
```

**Processus** :
1. Extraire toutes les URLs matchant le pattern
2. Pour chaque URL, extraire le slug
3. `get_posts(['post_type' => 'publication', 'name' => $slug])`
4. Vérifier permissions avec `Permission_Checker::can_see_publication()`
5. Si OK → injecter le contenu
6. Si KO → loguer erreur, ne pas injecter

**Format d'injection** :
```
=== BEGIN REFERENCE: {titre} ===
{contenu}
=== END REFERENCE ===
```

### 3.6 Live Update

**Règle** : `duplicate_uploads = false` par défaut.

Les outils référencent par URL (slug), pas par copie. Modifier une source = effet immédiat sur tous les outils.

---

## 4. TEMPLATES DE DONNÉES

### 4.1 Data Types

```php
const DATA_TYPES = [
    'catalog' => [
        'id' => 'catalog',
        'label' => 'Catalogue produits/services',
        'description' => 'Liste de vos offres avec descriptions',
        'required_for' => ['ao_response', 'follow_up', 'proposal'],
    ],
    'pricing' => [
        'id' => 'pricing',
        'label' => 'Grille tarifaire',
        'description' => 'Vos tarifs et conditions',
        'required_for' => ['ao_response', 'proposal', 'quote'],
    ],
    'references' => [
        'id' => 'references',
        'label' => 'Références clients',
        'description' => 'Projets réalisés et témoignages',
        'required_for' => ['ao_response', 'proposal'],
    ],
    'company_info' => [
        'id' => 'company_info',
        'label' => 'Présentation entreprise',
        'description' => 'Histoire, équipe, valeurs',
        'required_for' => ['ao_response', 'proposal'],
    ],
    'brand_guide' => [
        'id' => 'brand_guide',
        'label' => 'Charte éditoriale',
        'description' => 'Ton, style, vocabulaire',
        'required_for' => ['*'],
    ],
];
```

### 4.2 Tool Templates

```php
const TOOL_TEMPLATES = [
    'ao_response' => [
        'id' => 'ao_response',
        'name' => 'Générateur de Réponse AO',
        'description' => 'Rédige des réponses structurées aux appels d\'offres',
        'instruction' => "Tu es un expert en réponse aux appels d'offres B2B avec 15 ans d'expérience.
Tu connais parfaitement les attentes des acheteurs publics et privés.
Tu structures tes réponses de manière claire et persuasive.",
        'final_task' => "Analyse l'appel d'offres fourni et rédige une réponse complète, structurée et persuasive.
Mets en valeur les points forts et les références pertinentes.
Adapte le style au contexte (public/privé, formel/moins formel).",
        'required_data' => ['catalog', 'pricing', 'references'],
        'optional_data' => ['company_info'],
        'style' => 'formal_b2b',
    ],
    'follow_up' => [
        'id' => 'follow_up',
        'name' => 'Assistant Relance Client',
        'description' => 'Rédige des emails de relance personnalisés',
        'instruction' => "Tu es un expert en relation client B2B.
Tu sais relancer avec tact, sans être insistant.
Tu personnalises chaque message en fonction du contexte.",
        'final_task' => "Rédige un email de relance professionnel et personnalisé.
Adapte le ton au stade de la relation (premier contact, relance, réactivation).
Inclus un appel à l'action clair.",
        'required_data' => ['catalog'],
        'optional_data' => ['references'],
        'style' => 'professional_friendly',
    ],
    // ... autres templates
];
```

---

## 5. ACCEPTANCE CRITERIA

### AC1 : Analyse détecte correctement les besoins

```gherkin
GIVEN un admin dans l'espace 42
WHEN il appelle ml_bootstrap_wizard avec stage="analyze" et problems="Je veux répondre aux AO"
THEN detected_tools contient "ao_response"
AND required_data contient "catalog", "pricing", "references"
```

### AC2 : Auto-sélection trouve les bonnes publications

```gherkin
GIVEN l'espace 42 contient "Catalogue Services 2024" (label=contenu, modifié hier)
AND l'espace 42 contient "Vieux Catalogue 2020" (label=contenu, modifié il y a 2 ans)
WHEN le système cherche data_id="catalog" dans l'espace 42
THEN il retourne publication_id de "Catalogue Services 2024"
AND score > score de "Vieux Catalogue 2020"
```

### AC3 : Placeholders créés si contenu manquant

```gherkin
GIVEN l'espace 42 ne contient aucune publication avec "references"
WHEN le système exécute le wizard avec confirmed=true
THEN une publication placeholder est créée avec titre "📝 Références à compléter"
AND meta _ml_is_placeholder = true
AND meta _ml_bootstrap_data_id = "references"
```

### AC4 : Admin peut corriger une sélection

```gherkin
GIVEN le système propose publication_id=123 pour catalog (score=0.85)
WHEN l'admin appelle stage="collect" avec data_id="catalog", publication_id=789
THEN le mapping catalog → 789 est mis à jour
AND l'outil final utilise publication 789
```

### AC5 : L'instruction contient les URLs

```gherkin
GIVEN un outil créé avec content_ids=[123, 456]
WHEN on lit _ml_instruction de l'outil
THEN il contient "https://xxx.marylink.io/publication/" suivi du slug de 123
AND il contient "https://xxx.marylink.io/publication/" suivi du slug de 456
```

### AC6 : Le resolver injecte le contenu

```gherkin
GIVEN un outil dont _ml_instruction contient "https://xxx.marylink.io/publication/catalogue-2024/"
AND la publication "catalogue-2024" existe avec post_content "## Nos offres..."
WHEN Tool_Service::resolve_tool() est appelé
THEN le résultat contient "=== BEGIN REFERENCE: Catalogue 2024 ==="
AND le résultat contient "## Nos offres..."
AND le résultat contient "=== END REFERENCE ==="
```

### AC7 : Live update fonctionne

```gherkin
GIVEN un outil référençant "grille-tarifaire" contenant "TJM: 1000€"
WHEN l'admin modifie "grille-tarifaire" pour mettre "TJM: 1100€"
AND un consultant utilise l'outil
THEN le prompt envoyé au LLM contient "TJM: 1100€"
```

### AC8 : Permissions respectées

```gherkin
GIVEN un outil référençant "document-prive"
AND user_A a le rôle "editor" dans l'espace
AND user_B a le rôle "subscriber" sans accès au document
WHEN user_A utilise l'outil → le contenu est injecté
WHEN user_B utilise l'outil → le contenu N'EST PAS injecté, erreur loguée
```

---

## 6. HORS SCOPE MVP

- Cross-instance (bibliothèque centrale)
- Versioning des outils
- Suggestions IA pour améliorer les contenus
- Import automatique depuis Google Drive / Dropbox
- Traduction automatique des contenus
