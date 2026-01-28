# Plan de Tests E2E - Bootstrap Wizard

## 1. Setup de Test

### Prérequis
- Instance Marylink fonctionnelle
- Utilisateur admin (ID: 1)
- Utilisateur standard (ID: 2, membre d'un espace)
- Espace de test (ID: 42)

### Données de test à créer

```php
// Publications existantes dans l'espace 42
$test_publications = [
    [
        'title' => 'Catalogue Services 2024',
        'content' => '## Nos Offres\n\n### Conseil Stratégique\n- Audit organisationnel\n- Feuille de route digitale\n\n### Formation\n- Leadership\n- Management agile',
        'type' => 'data',
        'slug' => 'catalogue-2024',
    ],
    [
        'title' => 'Grille Tarifaire',
        'content' => '## Tarifs 2024\n\n| Prestation | TJM |\n|------------|-----|\n| Consultant Senior | 1200€ |\n| Expert | 1500€ |',
        'type' => 'data',
        'slug' => 'tarifs-2024',
    ],
    [
        'title' => 'Charte Éditoriale',
        'content' => '## Notre Style\n\n- Vouvoiement\n- Ton professionnel\n- Chiffres mis en valeur',
        'type' => 'style',
        'slug' => 'charte-editoriale',
    ],
];
```

---

## 2. Tests du Flow Complet

### TEST 1: Flow nominal complet

```
GIVEN admin connecté (user_id=1)
AND espace 42 contient "Catalogue Services 2024", "Grille Tarifaire", "Charte Éditoriale"

WHEN POST ml_bootstrap_wizard
  stage: "analyze"
  problems: "Je veux répondre aux appels d'offres"
  target_space_id: 42

THEN response.detected_tools contient "ao_response"
AND response.required_data contient "catalog", "pricing"
AND response.session_id exists

WHEN POST ml_bootstrap_wizard
  stage: "propose"
  session_id: {session_id}

THEN response.components.catalog.found = true
AND response.components.catalog.title = "Catalogue Services 2024"
AND response.components.pricing.found = true

WHEN POST ml_bootstrap_wizard
  stage: "validate"
  session_id: {session_id}

THEN response.ready = true
AND response.summary.placeholders_to_create = ["references"]

WHEN POST ml_bootstrap_wizard
  stage: "execute"
  session_id: {session_id}
  confirmed: true

THEN response.status = "completed"
AND response.created contient type="placeholder" (références)
AND response.created contient type="tool" (Générateur AO)
AND response.created contient type="landing"
```

### TEST 2: Flow avec espace vide (tous placeholders)

```
GIVEN admin connecté
AND espace 99 est vide (aucune publication)

WHEN POST ml_bootstrap_wizard
  stage: "analyze"
  problems: "Je veux un outil de proposition commerciale"
  target_space_id: 99

THEN response.detected_tools contient "proposal"

WHEN POST ml_bootstrap_wizard stage="propose"

THEN response.components.catalog.found = false
AND response.components.pricing.found = false

WHEN POST ml_bootstrap_wizard stage="execute" confirmed=true

THEN response.created contient 3+ placeholders
AND chaque placeholder a _ml_is_placeholder = true
```

### TEST 3: Flow avec correction manuelle

```
GIVEN espace contient "Catalogue 2023" (score bas) et "Catalogue 2024" (uploadé récemment)
AND système propose "Catalogue 2023" par défaut

WHEN POST ml_bootstrap_wizard
  stage: "collect"
  session_id: {session_id}
  data_id: "catalog"
  publication_id: {id_catalogue_2024}

THEN response.updated = true
AND response.components.catalog.publication_id = {id_catalogue_2024}

WHEN POST ml_bootstrap_wizard stage="execute" confirmed=true

THEN l'outil créé référence "Catalogue 2024" (pas 2023)
```

---

## 3. Tests de l'Auto-Sélection

### TEST 4: Scoring - Meta exacte prioritaire

```
GIVEN publication A avec _ml_bootstrap_data_id = "catalog" (score attendu: +100)
AND publication B avec titre "Catalogue Produits" (score attendu: +30)

WHEN Component_Picker::pick("catalog")

THEN publication A est sélectionnée (score > B)
```

### TEST 5: Scoring - Récence

```
GIVEN publication A modifiée il y a 60 jours
AND publication B modifiée il y a 7 jours
AND les deux ont le même titre

WHEN Component_Picker::pick("catalog")

THEN publication B est sélectionnée (+10 récence)
```

### TEST 6: Scoring - Score minimum

```
GIVEN publication "Notes de réunion" (aucun mot-clé catalog)

WHEN Component_Picker::pick("catalog")

THEN found = false (score < 20)
```

---

## 4. Tests du URL Resolver

### TEST 7: Résolution simple

```
GIVEN publication "test-doc" avec contenu "Hello World"

WHEN URL_Resolver::resolve(
  "Voici la source:\nhttps://test.marylink.io/publication/test-doc/\nFin."
)

THEN result.content contient "=== BEGIN REFERENCE: Test Doc ==="
AND result.content contient "Hello World"
AND result.content contient "=== END REFERENCE ==="
AND result.resolved[0].id = {id_test_doc}
```

### TEST 8: Publication non trouvée

```
WHEN URL_Resolver::resolve(
  "Source:\nhttps://test.marylink.io/publication/inexistant/"
)

THEN result.errors[0].error contient "non trouvée"
AND l'URL reste inchangée dans le contenu
```

### TEST 9: Permission refusée

```
GIVEN publication "confidentiel" accessible uniquement à user_id=1
AND résolution par user_id=2

WHEN URL_Resolver::resolve(url_vers_confidentiel)

THEN result.errors[0].error contient "Accès refusé"
```

### TEST 10: Limite de caractères

```
GIVEN publication avec 100,000 caractères

WHEN URL_Resolver::resolve(url)

THEN contenu injecté <= 50,000 caractères
AND contient "[... contenu tronqué ...]"
```

---

## 5. Tests de Permissions

### TEST 11: Accès refusé à l'espace

```
GIVEN user_id=99 n'a pas accès à espace 42

WHEN POST ml_bootstrap_wizard
  stage: "analyze"
  target_space_id: 42

THEN error = "access_denied"
```

### TEST 12: Accès refusé à une publication (collect)

```
GIVEN user_id=2 n'a pas accès à publication 999

WHEN POST ml_bootstrap_wizard
  stage: "collect"
  publication_id: 999

THEN error = "access_denied"
```

---

## 6. Tests de Session

### TEST 13: Session expirée

```
GIVEN session créée il y a 2 heures (TTL = 1h)

WHEN POST ml_bootstrap_wizard
  stage: "propose"
  session_id: {old_session}

THEN error = "session_expired"
```

### TEST 14: Session invalide

```
WHEN POST ml_bootstrap_wizard
  stage: "propose"
  session_id: "fake_session_id"

THEN error = "session_expired"
```

---

## 7. Tests de l'Instruction Builder

### TEST 15: Construction avec URLs

```
GIVEN content_ids = [123, 456]
AND style_ids = [789]

WHEN Instruction_Builder::build(
  base: "Tu es un expert",
  content_ids: [123, 456],
  style_ids: [789],
  final_task: "Rédige une réponse"
)

THEN result contient "Tu es un expert"
AND result contient "https://xxx.marylink.io/publication/slug-123/"
AND result contient "https://xxx.marylink.io/publication/slug-456/"
AND result contient "https://xxx.marylink.io/publication/slug-789/"
AND result contient "Rédige une réponse"
```

### TEST 16: Limite de 20 URLs

```
GIVEN 25 content_ids

WHEN Instruction_Builder::build(...)

THEN result contient exactement 20 URLs
```

---

## 8. Tests de Création

### TEST 17: Placeholder créé correctement

```
WHEN placeholder créé pour data_id="catalog"

THEN post_title = "📝 Catalogue produits/services à compléter"
AND _ml_is_placeholder = true
AND _ml_bootstrap_data_id = "catalog"
AND _ml_publication_type = "data"
AND post_status = "draft"
```

### TEST 18: Outil créé avec instruction correcte

```
WHEN outil "ao_response" créé avec content_ids=[123,456]

THEN _ml_instruction contient les URLs des publications
AND _ml_tool_contents = [123, 456]
AND _ml_publication_type = "tool"
AND post a le term "outil" dans publication_label
```

### TEST 19: Landing page créée

```
WHEN execute terminé avec 2 outils et 1 placeholder

THEN landing page créée
AND contenu liste les 2 outils avec liens
AND contenu liste le placeholder avec "À compléter"
```

---

## 9. Tests Live Update

### TEST 20: Modification propagée

```
GIVEN outil référençant publication "tarifs" contenant "TJM: 1000€"

WHEN admin modifie "tarifs" → "TJM: 1100€"

WHEN Tool_Service::resolve_tool(outil_id)

THEN instruction résolue contient "TJM: 1100€"
```

---

## 10. Checklist de Validation Manuelle

- [ ] Flow complet via interface MCP (Claude)
- [ ] Les outils créés sont utilisables via ml_apply_tool
- [ ] Les placeholders apparaissent dans l'espace avec icône "À compléter"
- [ ] La landing page est lisible et les liens fonctionnent
- [ ] Un consultant peut utiliser l'outil et obtenir une réponse personnalisée
- [ ] La modification d'une source se répercute sur l'outil
