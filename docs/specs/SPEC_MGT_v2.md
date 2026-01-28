# SPEC FONCTIONNELLE : Moteur de Génération Transparente (MGT)

**Version** : 2.0  
**Date** : 26 janvier 2026  
**Auteur** : Spécification technique Marylink  
**Statut** : Draft pour validation

---

## 1. VISION PRODUIT

### 1.1 Le problème

Les plateformes IA (ChatGPT, Copilot) sont des **boîtes noires** :
- L'utilisateur ne sait pas pourquoi l'IA a produit ce résultat
- Impossible d'auditer, reproduire ou améliorer
- Pas de capitalisation sur les bonnes pratiques

### 1.2 La promesse Marylink

> **"Vous savez toujours d'où vient le résultat."**

Mais cette transparence doit être **discrète par défaut, complète sur demande**.

### 1.3 Principes UX fondamentaux

| Principe | Application |
|----------|-------------|
| **Simplicité d'abord** | Le user voit le résultat, pas la mécanique |
| **Transparence opt-in** | Détails accessibles mais pas imposés |
| **Capitalisation fluide** | Sauvegarder un outil = 2 clics |
| **Zéro jargon** | Étoiles au lieu de scores, badges au lieu de labels |

---

## 2. EXPÉRIENCE UTILISATEUR

### 2.1 Les 3 moments du user

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│   MOMENT 1          MOMENT 2              MOMENT 3              │
│   ─────────         ─────────             ─────────             │
│                                                                 │
│   DEMANDER    →     VOIR LE RÉSULTAT  →   CAPITALISER          │
│                                           (optionnel)           │
│                                                                 │
│   "Rédige une       Résultat affiché      "Créer un outil"     │
│   lettre pour       + sources discrètes   pour réutiliser      │
│   Durand"                                                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 MOMENT 1 : Demander

**Interface minimaliste** :

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  Que voulez-vous créer ?                                        │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Rédige une lettre de relance pour Durand                    ││
│  │                                                     [Générer]││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  💡 Astuce : mentionnez un client ou projet pour un résultat   │
│     personnalisé                                                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Ce qui se passe en coulisses** (invisible pour le user) :
1. Détection d'intention → "create"
2. Recherche d'un outil existant → trouvé ou pas
3. Détection de "Durand" → Client Durand SA
4. Sélection du style approprié
5. Assemblage et exécution

### 2.3 MOMENT 2 : Voir le résultat

**Vue par défaut (épurée)** :

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  Cher M. Durand,                                                │
│                                                                 │
│  Suite à notre dernier échange concernant le projet de          │
│  migration ERP, je me permets de revenir vers vous au sujet     │
│  de notre proposition commerciale du 15 janvier dernier.        │
│                                                                 │
│  Nous restons convaincus que notre solution répond              │
│  parfaitement à vos enjeux de digitalisation et serions         │
│  ravis d'organiser une nouvelle rencontre pour finaliser        │
│  les modalités de notre collaboration.                          │
│                                                                 │
│  Dans l'attente de votre retour, je reste à votre entière      │
│  disposition.                                                   │
│                                                                 │
│  Cordialement,                                                  │
│  Hervé                                                          │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│                                                                 │
│  ✨ Basé sur : Fiche Durand SA • Projet ERP           [Détails] │
│                                                                 │
│  [📋 Copier]     [💾 Sauvegarder]     [🛠️ Créer un outil]      │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Éléments clés** :
- Le résultat occupe 90% de l'écran
- Une seule ligne de "sourcing" discrète en bas
- Bouton "Détails" pour les curieux
- Actions claires : Copier / Sauvegarder / Créer outil

**Vue étendue (clic sur "Détails")** :

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  [Résultat ci-dessus...]                                        │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│                                                                 │
│  ✨ Basé sur : Fiche Durand SA • Projet ERP       [▲ Masquer]  │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                                                             ││
│  │  📂 CONTEXTE UTILISÉ                                        ││
│  │                                                             ││
│  │  • Fiche Client Durand SA                              ↗   ││
│  │    Fabricant pièces automobiles • PME • Île-de-France      ││
│  │                                                             ││
│  │  • Projet Migration ERP                                ↗   ││
│  │    En cours • Deadline 15 mars 2026                        ││
│  │                                                             ││
│  │  ─────────────────────────────────────────────────────     ││
│  │                                                             ││
│  │  🎨 STYLE APPLIQUÉ                                          ││
│  │                                                             ││
│  │  Commercial Engageant                                  ↗   ││
│  │  ⭐⭐⭐⭐⭐ (142 avis) • Style officiel                       ││
│  │                                                             ││
│  │  ─────────────────────────────────────────────────────     ││
│  │                                                             ││
│  │  ⚙️ TECHNIQUE                                               ││
│  │                                                             ││
│  │  Modèle : GPT-4o • Temps : 2.7s • 1730 tokens              ││
│  │                                                             ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  [📋 Copier]     [💾 Sauvegarder]     [🛠️ Créer un outil]      │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.4 MOMENT 3 : Capitaliser (créer un outil)

**Modale simplifiée** (2 champs seulement) :

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  🛠️ Créer un outil réutilisable                                │
│                                                        [✕]     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  La prochaine fois que quelqu'un demandera une "lettre de      │
│  relance", cet outil sera automatiquement proposé.              │
│                                                                 │
│  ─────────────────────────────────────────────────────────────  │
│                                                                 │
│  Nom de l'outil :                                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Lettre de relance commerciale                               ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  Partager avec :                                                │
│  ○ Moi uniquement                                               │
│  ● Mon équipe (Espace Commercial)                               │
│  ○ Toute l'organisation                                         │
│                                                                 │
│  ─────────────────────────────────────────────────────────────  │
│                                                                 │
│  ✓ Inclut automatiquement le style "Commercial Engageant"      │
│                                                                 │
│                              [Annuler]     [Créer l'outil]     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Ce qui n'est PAS montré** (géré automatiquement) :
- Les URLs des contenus liés
- Les variables {{client}}, {{projet}}
- Le quality_score, engagement_score
- Les options draft/publish
- Le format du prompt

**Confirmation succincte** :

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  ✅ Outil créé !                                                │
│                                                                 │
│  "Lettre de relance commerciale" est maintenant disponible     │
│  pour votre équipe.                                             │
│                                                                 │
│  [Voir l'outil]                                      [Fermer]  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. TRADUCTION UX → TECHNIQUE

### 3.1 Correspondance termes

| Ce que voit le USER | Ce qui est en BACKEND |
|---------------------|----------------------|
| "Basé sur : Fiche Durand SA" | Entity_Detector → client_id=456 |
| ⭐⭐⭐⭐⭐ (142 avis) | rating_avg=4.7, rating_count=142 |
| "Style officiel" | quality_score ≥ 3.5, espace=Official |
| "Mon équipe" | space_id du groupe BuddyPress |
| "Créer un outil" | ml_save(type=tool, label=tool) |

### 3.2 Mapping des actions

| Action USER | Appels MCP |
|-------------|------------|
| Clic "Générer" | ml_assist(action=suggest) → ml_run |
| Clic "Détails" | Affiche données déjà en mémoire |
| Clic "Sauvegarder" | ml_save(type=content) |
| Clic "Créer un outil" | ml_save(type=tool) avec composition |
| Clic sur source "↗" | Ouvre publication dans nouvel onglet |

---

## 4. ARCHITECTURE BACKEND

### 4.1 Pipeline invisible (5 étapes)

Le user ne voit qu'un bouton "Générer", mais en backend :

```
┌─────────────────────────────────────────────────────────────────┐
│                     PIPELINE MGT (invisible)                    │
│                                                                 │
│  [Input user]                                                   │
│       │                                                         │
│       ▼                                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 0 : Analyse d'intention                               ││
│  │   ml_assist → intent, keywords, entities                    ││
│  └─────────────────────────────────────────────────────────────┘│
│       │                                                         │
│       ▼                                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 1 : Recherche outil existant                          ││
│  │   ml_find(type=tool, sort=best)                             ││
│  │   Si trouvé (score ≥ seuil) → exécution directe             ││
│  └─────────────────────────────────────────────────────────────┘│
│       │                                                         │
│       ▼                                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 2 : Chargement contexte                               ││
│  │   Entity_Detector.detect() → Business_Context_Service       ││
│  └─────────────────────────────────────────────────────────────┘│
│       │                                                         │
│       ▼                                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 3 : Sélection style                                   ││
│  │   ml_find(type=style, sort=best_rated)                      ││
│  └─────────────────────────────────────────────────────────────┘│
│       │                                                         │
│       ▼                                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 4 : Résolution prompt + Exécution                     ││
│  │   ml_run(tool_id ou prompt, with_context=true)              ││
│  └─────────────────────────────────────────────────────────────┘│
│       │                                                         │
│       ▼                                                         │
│  [Output + metadata pour affichage]                             │
│       │                                                         │
│       ▼ (si user clique "Créer outil")                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ ÉTAPE 5 : Capitalisation                                    ││
│  │   ml_save(type=tool, content=prompt+urls)                   ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Outils MCP utilisés

| Outil | Version | Rôle |
|-------|---------|------|
| `ml_assist` | 3.0.0 | Orchestrateur, détection d'intention |
| `ml_find` | 3.2.9 | Recherche publications, scoring |
| `ml_run` | 3.0.0 | Exécution avec contexte auto-injecté |
| `ml_save` | 3.2.7 | Sauvegarde publication/outil |

### 4.3 Services internes

| Service | Rôle |
|---------|------|
| `Scoring_Service` | Calcul quality_score (0-5) |
| `Find_Ranking` | Tri best, best_rated, trending |
| `Entity_Detector` | Détection clients, projets, tags |
| `Business_Context_Service` | Injection contexte dans prompt |

---

## 5. ALGORITHMES DE SCORING

### 5.1 Quality Score (0-5)

```php
$quality_score = (
    0.35 * $rating_score +      // Moyenne bayésienne
    0.25 * $favorites_score +   // Échelle log
    0.20 * $engagement_score +  // Vues + commentaires
    0.20 * $freshness_score     // Décroissance 30j
);
```

**Affichage USER** :

| Quality Score | Affichage |
|---------------|-----------|
| 4.5 - 5.0 | ⭐⭐⭐⭐⭐ |
| 3.5 - 4.4 | ⭐⭐⭐⭐ |
| 2.5 - 3.4 | ⭐⭐⭐ |
| 1.5 - 2.4 | ⭐⭐ |
| 0 - 1.4 | ⭐ |

### 5.2 Seuils de décision (invisibles)

| Seuil | Valeur | Usage interne |
|-------|--------|---------------|
| Outil réutilisable | quality ≥ 2.0, ratings ≥ 3 | Proposé automatiquement |
| Style officiel | quality ≥ 3.5, ratings ≥ 20 | Badge "Style officiel" |
| Promotion espace officiel | quality ≥ 3.5, rating_avg ≥ 4.0, usages ≥ 50 | Éligible à promotion |

### 5.3 Détection d'entités

| Entité | Pattern détecté | Exemple |
|--------|-----------------|---------|
| Client | Nom dans base clients | "Durand" → Durand SA |
| Projet | Nom dans base projets | "migration ERP" → Projet #789 |
| Date | Formats FR/EN/ISO | "15 janvier" → 2026-01-15 |
| Mention | @username | @jean → User Jean Dupont |
| Tag | #hashtag | #relance |

---

## 6. STRUCTURE D'UN OUTIL

### 6.1 Définition

Un **Outil** est une publication avec :
- **Label** : `tool`
- **Corps** : prompt (instructions IA) + URLs vers contenus et styles

### 6.2 Format du corps

```markdown
Rédige une lettre de relance commerciale professionnelle.

La lettre doit :
- Rappeler le contexte de la relation commerciale
- Mentionner la proposition en attente
- Proposer une action concrète (réunion, appel)
- Rester courtoise mais ferme

Utilise les informations du client et du projet fournis en contexte.

---

## Ressources liées

### Contenus
- [Fiche Client type](https://instance.marylink.io/publication/456)

### Style
- [Commercial Engageant](https://instance.marylink.io/publication/101)
```

### 6.3 Résolution à l'exécution

Quand un outil est exécuté via `ml_run` :

1. `Picasso_Adapter::get_tool_instruction()` extrait le prompt
2. `Picasso_Adapter::get_tool_linked_contents()` extrait les URLs contenus
3. `Picasso_Adapter::get_tool_linked_styles()` extrait les URLs styles
4. Les contenus sont chargés et injectés dans le contexte
5. Le style est appliqué

---

## 7. API

### 7.1 Endpoint principal

**POST** `/mcp/ml_generate`

```json
// Request simple (cas 90%)
{
  "query": "Rédige une lettre de relance pour Durand"
}

// Request avec options
{
  "query": "Rédige une lettre de relance pour Durand",
  "options": {
    "style_id": 101,        // Forcer un style (optionnel)
    "space_id": 17062,      // Contexte espace (optionnel)
    "save_result": false,   // Sauvegarder le résultat (optionnel)
    "create_tool": false    // Créer un outil (optionnel)
  }
}
```

```json
// Response
{
  "success": true,
  "output": "Cher M. Durand...",
  
  "sources": {
    "context": [
      {"id": 456, "title": "Fiche Client Durand SA", "type": "client"},
      {"id": 789, "title": "Projet Migration ERP", "type": "projet"}
    ],
    "style": {
      "id": 101,
      "title": "Commercial Engageant",
      "rating": 4.7,
      "rating_count": 142,
      "official": true
    },
    "tool_used": null
  },
  
  "meta": {
    "model": "gpt-4o",
    "duration_ms": 2700,
    "tokens": 1730
  },
  
  "can_create_tool": true
}
```

### 7.2 Créer un outil

**POST** `/mcp/ml_generate/create_tool`

```json
// Request
{
  "generation_id": "mgt_20260126_123000_abc123",
  "title": "Lettre de relance commerciale",
  "share_with": "team",  // "me" | "team" | "organization"
  "space_id": 17062      // Si share_with = "team"
}

// Response
{
  "success": true,
  "tool": {
    "id": 3456,
    "title": "Lettre de relance commerciale",
    "url": "https://instance.marylink.io/publication/3456"
  }
}
```

### 7.3 Compatibilité avec outils existants

L'endpoint `ml_generate` peut aussi être appelé via `ml_assist` :

```json
{
  "action": "generate",
  "context": "Rédige une lettre de relance pour Durand"
}
```

---

## 8. TRAÇABILITÉ

### 8.1 Niveaux de détail

| Niveau | Visible par | Contenu |
|--------|-------------|---------|
| **Minimal** | User par défaut | "Basé sur : Fiche X, Projet Y" |
| **Standard** | User (clic Détails) | Sources cliquables + style + stats |
| **Complet** | Admin / Audit | JSON avec tous les scores et timings |

### 8.2 Format JSON complet (niveau Admin)

```json
{
  "mgt_version": "2.0",
  "request_id": "mgt_20260126_123000_abc123",
  "timestamp": "2026-01-26T12:30:00Z",
  
  "user": {
    "id": 123,
    "name": "Hervé"
  },
  
  "input": {
    "query": "Rédige une lettre de relance pour Durand",
    "space_id": null
  },
  
  "pipeline": {
    "step_0_analysis": {
      "intent": "create",
      "confidence": 0.8,
      "keywords": ["rédiger", "lettre", "relance"],
      "entities": {
        "clients": [{"id": 456, "name": "Durand SA", "confidence": 0.92}]
      },
      "latency_ms": 45
    },
    
    "step_1_tool_search": {
      "found": false,
      "candidates_searched": 15,
      "best_score": 0.42,
      "threshold": 0.75,
      "latency_ms": 120
    },
    
    "step_2_context": {
      "contents_loaded": [
        {"id": 456, "type": "client", "tokens": 340},
        {"id": 789, "type": "projet", "tokens": 180}
      ],
      "total_tokens": 520,
      "latency_ms": 85
    },
    
    "step_3_style": {
      "id": 101,
      "name": "Commercial Engageant",
      "quality_score": 4.2,
      "rating_avg": 4.7,
      "rating_count": 142,
      "match_method": "tag_match",
      "latency_ms": 35
    },
    
    "step_4_execution": {
      "model": "gpt-4o",
      "prompt_tokens": 1250,
      "completion_tokens": 480,
      "latency_ms": 2340
    },
    
    "step_5_tool_creation": null
  },
  
  "totals": {
    "latency_ms": 2625,
    "tokens": 1730,
    "reuse_ratio": 0.75
  },
  
  "reproducibility_hash": "sha256:a3f2c1d4e5f6..."
}
```

### 8.3 Stockage

- **Court terme** : en mémoire pendant la session (pour "Détails")
- **Moyen terme** : table `wp_mgt_traces` (30 jours)
- **Long terme** : export JSON sur demande (audit/compliance)

---

## 9. RÈGLES MÉTIER

### 9.1 Cycle de vie d'un outil

```
┌─────────┐     ┌─────────┐     ┌─────────┐     ┌─────────┐
│  CRÉÉ   │ ──▶ │ PARTAGÉ │ ──▶ │ POPULAIRE│ ──▶ │OFFICIEL │
│ (privé) │     │ (équipe)│     │ (org)   │     │ (promu) │
└─────────┘     └─────────┘     └─────────┘     └─────────┘
                                     │
                                     ▼
                              Critères promotion :
                              • rating_avg ≥ 4.0
                              • rating_count ≥ 20
                              • usages ≥ 50
                              • quality_score ≥ 3.5
```

### 9.2 Priorité de réutilisation

Quand MGT cherche un outil existant (étape 1) :

1. **Espace Officiel** en priorité (outils certifiés)
2. **Espace de l'utilisateur** ensuite
3. **Espaces dont l'utilisateur est membre**
4. **Création ad-hoc** si rien trouvé

### 9.3 Styles

Les styles ne sont jamais modifiés, ils sont **versionnés** :

```
Commercial Engageant v1.0 → v1.1 → v2.0 → v2.1 (current)
```

Le style utilisé est tracé avec sa version exacte.

---

## 10. KPIs

### 10.1 Métriques pipeline

| Métrique | Cible | Mesure |
|----------|-------|--------|
| Taux réutilisation outils | > 40% | Étape 1 success / total |
| Taux enrichissement contexte | > 80% | Étape 2 avec ≥1 contenu / total |
| Taux styles officiels | > 90% | Styles certifiés utilisés |
| Taux capitalisation | > 15% | Outils créés / générations |
| Latence P95 | < 5s | 95ème percentile |

### 10.2 Métriques satisfaction

| Métrique | Cible | Source |
|----------|-------|--------|
| Satisfaction globale | > 85% | Feedback 👍/👎 |
| Taux de clic "Détails" | 10-20% | Analytics |
| Taux de création d'outils | > 15% | Analytics |
| Outils promus Officiel | > 30% | Des outils créés |

---

## 11. IMPLÉMENTATION

### 11.1 Fichiers à créer

| Fichier | Description |
|---------|-------------|
| `src/MCP/Core/Tools/Generate.php` | Endpoint `ml_generate` |
| `src/MCP/Core/Services/MGT_Pipeline.php` | Orchestration 5 étapes |
| `src/MCP/Core/Services/MGT_Trace.php` | Construction traces |

### 11.2 Modifications mineures

| Fichier | Modification |
|---------|--------------|
| `Assist.php` | Ajouter `action: "generate"` |
| `Tool_Catalog_V3.php` | Enregistrer `ml_generate` |

### 11.3 Estimation

| Phase | Jours |
|-------|-------|
| Backend pipeline | 3-4 |
| API endpoint | 1 |
| Tests | 3 |
| Intégration frontend | 2 |
| **Total** | **9-10 jours** |

---

## 12. ANNEXES

### A. Messages utilisateur

| Situation | Message |
|-----------|---------|
| Aucun contexte détecté | "💡 Astuce : mentionnez un client ou projet pour un résultat personnalisé" |
| Outil existant utilisé | "✨ Généré avec l'outil [Nom de l'outil]" |
| Style officiel | Badge "Style officiel" + étoiles |
| Outil créé | "✅ Outil créé ! [Nom] est maintenant disponible pour [audience]" |
| Erreur génération | "Oups, quelque chose n'a pas fonctionné. Réessayez ou reformulez votre demande." |

### B. Glossaire simplifié (pour doc utilisateur)

| Terme | Définition simple |
|-------|-------------------|
| **Outil** | Un modèle de génération réutilisable en 1 clic |
| **Style** | La "personnalité" du texte (formel, engageant, technique...) |
| **Contexte** | Les infos sur vos clients/projets injectées automatiquement |
| **Espace** | Un groupe de travail avec ses propres outils et contenus |

### C. Constantes techniques

```php
// Seuils de scoring
const MGT_TOOL_MIN_QUALITY = 2.0;
const MGT_TOOL_MIN_RATINGS = 3;
const MGT_OFFICIAL_MIN_QUALITY = 3.5;
const MGT_OFFICIAL_MIN_RATING_AVG = 4.0;
const MGT_OFFICIAL_MIN_USAGES = 50;

// Poids scoring
const MGT_WEIGHT_RATING = 0.35;
const MGT_WEIGHT_FAVORITES = 0.25;
const MGT_WEIGHT_ENGAGEMENT = 0.20;
const MGT_WEIGHT_FRESHNESS = 0.20;

// Limites
const MGT_MAX_CONTEXT_TOKENS = 4000;
const MGT_TRACE_RETENTION_DAYS = 30;
```

---

**Fin de la spécification MGT v2.0**
