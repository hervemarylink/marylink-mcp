# Marylink MCP — Plan de non‑régression (MGT v2 / ml_find ranking + reviews)

Date: 2026-01-26  
Scope: stabiliser le contrat `ml_find`, exposer métriques SSoT, ajouter `sort`, explainability optionnelle, reviews sample (sans IA), et préparer le flywheel.

---

## 0) Principes (à ne pas violer)

1. **SSoT** (single source of truth)
   - Lecture: `post_meta` existantes: `_ml_average_rating`, `_ml_rating_count`, `_ml_rating_distribution`, `_ml_quality_score`, `_ml_engagement_score`, `_ml_favorites_count`, `_ml_likes_count` (si présent).
   - Événements: avis/notes unitaires = table ratings (si existante) via `Rating_Service`.
   - **ml_find ne recalcule pas**: pas de recompute de moyennes côté outil.

2. **Backward compatible**
   - Si `sort` absent: **mêmes résultats et même ordre** qu’avant (date desc).
   - Ajouts uniquement: champs supplémentaires derrière `include`.

3. **Perf**
   - Pas de N+1 reviews: `include=reviews` ne s’applique qu’aux **N premiers** résultats (N=5).
   - Ranking non SQL fragile: préférer sur-récupération + tri PHP.

---

## 1) Matrice des PR (ordre recommandé)

- PR1: Contract-first (stop mélange users/publications)
- PR2: Exposition métriques SSoT dans `ml_find` (include=metadata)
- PR3: `sort` dans `ml_find` (best/best_rated/trending/most_*)
- PR4: Explainability (include=ranking_reason)
- PR5: Reviews sample (include=reviews)
- PR6: Flywheel (capture rating/feedback dans le flux)

Chaque PR inclut ses tests + baseline.

---

## 2) Baseline à figer AVANT PR (snapshot)

### 2.1 Baseline response shape
Sur une instance de staging:
- lancer `ml_find` sans `include` et sans `sort`
- capturer:
  - structure des items (id, title, type, status, dates, etc.)
  - ordre des 10 premiers IDs

**But:** détecter toute régression non voulue (champ supprimé / ordre différent).

---

## 3) Jeux de données de test (fixtures)

On utilise 6 publications de test, toutes visibles par l’utilisateur test.

### 3.1 Publications
Créer via WP-CLI (ou UI). Slugs/titres importants pour tests.
- P1: title="Alpha - low score"
- P2: title="Bravo - mid score"
- P3: title="Charlie - high score"
- P4: title="Delta - rating tie low count"
- P5: title="Echo - rating tie high count"
- P6: title="Foxtrot - no rating metas"

### 3.2 Metas SSoT
Définir des metas explicitement (ne pas recalculer).

**Quality / engagement / favorites / likes**
- P1: `_ml_quality_score=10`, `_ml_engagement_score=5`, `_ml_favorites_count=1`, `_ml_likes_count=2`
- P2: `_ml_quality_score=50`, `_ml_engagement_score=40`, `_ml_favorites_count=5`, `_ml_likes_count=7`
- P3: `_ml_quality_score=30`, `_ml_engagement_score=80`, `_ml_favorites_count=2`, `_ml_likes_count=10`
- P4: `_ml_quality_score=20`, `_ml_engagement_score=10`, `_ml_favorites_count=0`, `_ml_likes_count=0`
- P5: `_ml_quality_score=20`, `_ml_engagement_score=10`, `_ml_favorites_count=0`, `_ml_likes_count=0`
- P6: pas de metas (toutes absentes) → doit produire null/0.

**Rating metas**
- P4: `_ml_average_rating=4.9`, `_ml_rating_count=1`
- P5: `_ml_average_rating=4.9`, `_ml_rating_count=50`
- P1/P2/P3: `_ml_average_rating` absent (préférer absent) + `_ml_rating_count` absent/0
- P6: absent

Distribution (optionnel)
- P5: `_ml_rating_distribution='{"5":45,"4":5}'` (JSON)

### 3.3 Reviews unitaires (si table ratings existe)
Optionnel (PR5):
- Ajouter 2 avis à P5:
  - 5★ "Très utile pour cadrer un client."
  - 4★ "Bon mais manque un exemple."
- Ajouter 1 avis à P4:
  - 5★ "Top, concis."

Si la table n’existe pas, les tests PR5 vérifient un fallback `[]` sans crash.

---

## 4) Cas de test / Assertions (par PR)

### PR1 — Contract-first

**CT1.1** `ml_find query="*"`  
Attendu: ne renvoie pas de users.  
- Si vous choisissez "normaliser": `*` est traité comme `""` (liste).
- Sinon: `validation_error` clair.

**CT1.2** `ml_find query="john"` (user "john" existe en WP)  
Attendu: résultats = publications uniquement (pas d’objet user).

**CT1.3** `ml_find type=content query="" limit=10`  
Attendu: structure identique baseline (au moins champs existants), ordre baseline.

---

### PR2 — include=metadata (SSoT)

**CT2.1** `ml_find ... include=["metadata"]` renvoie pour P5:
- `metadata.rating.average == 4.9`
- `metadata.rating.count == 50`
- `metadata.rating.distribution` décodée en dict (si meta JSON fournie)
- `metadata.quality_score == 20`
- `metadata.engagement_score == 10`
- `metadata.favorites_count == 0`

**CT2.2** P6 sans metas:
- `metadata.rating.average == null`
- `metadata.rating.count == 0`
- `metadata.quality_score == null`
- `metadata.engagement_score == null`
- `metadata.favorites_count == 0`

**CT2.3** Sans `include`, pas de nouveaux champs forcés.

---

### PR3 — sort

**CT3.1** sort absent:
- ordre identique baseline (date desc)

**CT3.2** `sort=best` (quality_score desc, tie-break date desc):
- attendu (sur fixtures) : P2 (50) > P3 (30) > P5 (20) ~= P4 (20) > P1 (10) > P6 (0/null)

Note: si P4/P5 ont mêmes scores, l’ordre dépend du tie-break (date desc). Fixer des dates distinctes si nécessaire.

**CT3.3** `sort=best_rated`:
- P5 (avg 4.9, count 50) avant P4 (avg 4.9, count 1)

**CT3.4** `sort=trending` (engagement_score desc):
- P3 (80) > P2 (40) > P4/P5 (10) > P1 (5) > P6 (0/null)

**CT3.5** `sort=most_liked`:
- P3 (10) > P2 (7) > P1 (2) > autres

**CT3.6** `sort=most_favorited`:
- P2 (5) > P3 (2) > P1 (1) > autres

---

### PR4 — include=ranking_reason

**CT4.1** Sans include, rien ne change.

**CT4.2** Avec `include=["ranking_reason"]` sur `sort=best_rated`:
- si `rating.count == 0` sur un item, `fallback_applied == true` et `signal_used != "rating"`
- si `rating.count > 0`, `fallback_applied == false` et `signal_used == "rating"`

---

### PR5 — include=reviews (sample)

**CT5.1** Table ratings absente:
- `reviews_sample` présent et == `[]` (pas d’erreur)

**CT5.2** Table présente et avis insérés:
- P5 `reviews_sample` contient 2 items max 3, champs:
  - rating (int), comment (<= 200 chars), created_at, user_name (optionnel)
- P4 `reviews_sample` contient 1 item
- Seuls les **5 premiers résultats** doivent inclure `reviews_sample` (les suivants n’ont rien, ou un champ vide selon convention choisie).

---

## 5) Scripts de test (WP-CLI) — recommandés

### 5.1 Script de setup fixtures (staging)
Créer `tests/setup-fixtures.sh` (exécutable), qui:
- crée les posts P1..P6 (post_type: publication ou post WP utilisé par Marylink)
- set les metas via `wp post meta set`
- optionnel: crée un user "john" pour tests PR1
- optionnel: insère 3 ratings si table existe

### 5.2 Script de non-régression ml_find
Créer `tests/non-regression-ml_find.sh` qui:
- appelle `Find::execute()` via `wp eval` et compare:
  - ordre baseline vs actuel (sort absent)
  - ordre `sort=best`, `sort=best_rated`, etc.
  - présence clés metadata
  - `limit` validation (limit=-5 doit échouer)

---

## 6) Validation erreurs / sécurité

- `limit <= 0` doit renvoyer `validation_error` (400)  
- `space_id` inexistant: choisir la règle:
  - soit `validation_error` (recommandé)
  - soit retour vide + warning (moins strict)

---

## 7) Definition of Done (DoD)

- Tous les CT PR1..PR5 passent.
- Aucune régression baseline (structure + ordre sans sort).
- `sort` unique, documenté, visible dans le schema tool.
- `include=metadata` fournit les métriques SSoT.
- `include=reviews` ne crash jamais, est limité perf.
- Changelog mis à jour.
