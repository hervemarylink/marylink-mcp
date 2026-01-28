# Description Fonctionnelle : Création Automatique d'Outils

## Vue d'ensemble

L'admin décrit son besoin en langage naturel. Le système analyse, sélectionne les meilleurs contenus disponibles, et génère un outil prêt à l'emploi. L'admin n'a rien à configurer, coller ou lier manuellement.

---

## Acteurs

| Acteur | Rôle |
|--------|------|
| **Admin** | Responsable d'un espace, veut équiper son équipe d'outils IA |
| **Consultant** | Utilisateur final qui utilise les outils créés |
| **Système** | Le wizard + resolver qui font tout le travail |

---

## Scénario Principal : Création d'un Kit Commercial

### Contexte initial

L'admin du cabinet "Dupont Conseil" a déjà uploadé dans son espace Marylink :
- Un PDF "Catalogue Services 2024" (importé il y a 2 mois)
- Un Excel "Grille Tarifaire" (importé la semaine dernière)
- Un document "Nos Références Clients" (créé il y a 1 mois)
- Un guide "Charte Éditoriale Dupont" (leur style maison)

Ces documents sont des **publications Marylink** classiques, visibles dans l'espace.

---

### Étape 1 : L'admin exprime son besoin

**Ce que voit l'admin :**

```
┌─────────────────────────────────────────────────────────────────┐
│  🧙 Assistant de création d'outils                              │
│                                                                 │
│  Décrivez ce dont votre équipe a besoin :                       │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Je voudrais que mes commerciaux puissent répondre       │   │
│  │ rapidement aux appels d'offres et relancer les          │   │
│  │ prospects de manière personnalisée.                     │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│                                        [Analyser mon besoin →]  │
└─────────────────────────────────────────────────────────────────┘
```

**Ce que fait l'admin :** Il tape son besoin en français courant et clique.

---

### Étape 2 : Le système analyse et propose

**Ce que voit l'admin :**

```
┌─────────────────────────────────────────────────────────────────┐
│  📋 Analyse de votre besoin                                     │
│                                                                 │
│  J'ai identifié 2 outils qui répondraient à votre besoin :      │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ✅ Générateur de Réponse AO                              │   │
│  │    Rédige des réponses structurées aux appels d'offres  │   │
│  │                                                          │   │
│  │    📄 Sources qui seront utilisées :                     │   │
│  │    • Catalogue Services 2024 ✓ trouvé                   │   │
│  │    • Grille Tarifaire ✓ trouvé                          │   │
│  │    • Nos Références Clients ✓ trouvé                    │   │
│  │                                                          │   │
│  │    🎨 Style : Charte Éditoriale Dupont ✓ trouvé         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ✅ Assistant Relance Client                              │   │
│  │    Rédige des emails de relance personnalisés           │   │
│  │                                                          │   │
│  │    📄 Sources qui seront utilisées :                     │   │
│  │    • Catalogue Services 2024 ✓ trouvé                   │   │
│  │    • Nos Références Clients ✓ trouvé                    │   │
│  │                                                          │   │
│  │    🎨 Style : Charte Éditoriale Dupont ✓ trouvé         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  💡 Tous les contenus nécessaires sont déjà dans votre espace ! │
│                                                                 │
│                              [← Modifier]  [Créer les outils →] │
└─────────────────────────────────────────────────────────────────┘
```

**Ce qui s'est passé en coulisses :**

1. Le système a analysé "répondre aux appels d'offres" → besoin d'un outil AO
2. Le système a analysé "relancer les prospects" → besoin d'un outil Relance
3. Pour chaque outil, le système a cherché dans l'espace :
   - Y a-t-il un document qui ressemble à un catalogue ? → Oui, "Catalogue Services 2024"
   - Y a-t-il une grille tarifaire ? → Oui, "Grille Tarifaire"
   - Y a-t-il des références ? → Oui, "Nos Références Clients"
   - Y a-t-il un style/charte maison ? → Oui, "Charte Éditoriale Dupont"

**Ce que fait l'admin :** Il voit que tout est trouvé. Il clique "Créer les outils".

---

### Étape 3 : Le système crée les outils

**Ce que voit l'admin :**

```
┌─────────────────────────────────────────────────────────────────┐
│  ✅ Vos outils sont prêts !                                     │
│                                                                 │
│  2 outils ont été créés dans votre espace :                     │
│                                                                 │
│  📁 Générateur de Réponse AO                                    │
│     → Voir l'outil                                              │
│                                                                 │
│  📁 Assistant Relance Client                                    │
│     → Voir l'outil                                              │
│                                                                 │
│  📖 Guide d'utilisation du Kit Commercial                       │
│     → Voir le guide                                             │
│                                                                 │
│  Vos commerciaux peuvent maintenant utiliser ces outils !       │
│                                                                 │
│                                              [Terminer]         │
└─────────────────────────────────────────────────────────────────┘
```

**Ce qui s'est passé en coulisses :**

Le système a créé une publication "Outil" dont le contenu ressemble à :

```markdown
Tu es un expert en réponse aux appels d'offres B2B, spécialisé dans le conseil.

https://dupont-conseil.marylink.io/publication/catalogue-services-2024/
https://dupont-conseil.marylink.io/publication/grille-tarifaire/
https://dupont-conseil.marylink.io/publication/nos-references-clients/
https://dupont-conseil.marylink.io/publication/charte-editoriale-dupont/

Analyse l'appel d'offres fourni et rédige une réponse complète, structurée et persuasive qui met en valeur notre expertise et nos références pertinentes.
```

L'admin ne voit pas ce contenu technique. Il voit juste "Outil créé".

---

### Étape 4 : Un consultant utilise l'outil

**Ce que voit le consultant :**

```
┌─────────────────────────────────────────────────────────────────┐
│  🔧 Générateur de Réponse AO                                    │
│                                                                 │
│  Collez l'appel d'offres auquel vous souhaitez répondre :       │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ APPEL D'OFFRES - Mairie de Lyon                         │   │
│  │                                                          │   │
│  │ Objet : Accompagnement à la transformation digitale     │   │
│  │ Budget : 50-80k€                                         │   │
│  │ Délai : Réponse avant le 15 février                     │   │
│  │                                                          │   │
│  │ Critères :                                               │   │
│  │ - Expérience secteur public                             │   │
│  │ - Méthodologie agile                                     │   │
│  │ - Références similaires                                  │   │
│  │ ...                                                      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│                                              [Générer →]        │
└─────────────────────────────────────────────────────────────────┘
```

**Ce que fait le consultant :** Il colle l'AO et clique "Générer".

---

### Étape 5 : Le système résout et génère

**Ce qui se passe en coulisses (invisible pour l'utilisateur) :**

1. Le système lit le contenu de l'outil (avec les URLs)
2. Pour chaque URL détectée :
   - `catalogue-services-2024` → charge la publication → extrait le contenu
   - `grille-tarifaire` → charge la publication → extrait le contenu
   - `nos-references-clients` → charge la publication → extrait le contenu
   - `charte-editoriale-dupont` → charge la publication → extrait le contenu
3. Le système assemble le prompt final :

```
Tu es un expert en réponse aux appels d'offres B2B, spécialisé dans le conseil.

=== BEGIN REFERENCE: Catalogue Services 2024 ===
## Nos Offres

### Transformation Digitale
Accompagnement des organisations dans leur mutation numérique...
- Audit et diagnostic : 5-10 jours
- Feuille de route : 10-15 jours
- Accompagnement au changement : 3-12 mois
...
=== END REFERENCE ===

=== BEGIN REFERENCE: Grille Tarifaire ===
## Tarifs 2024

| Prestation | TJM | Forfait |
|------------|-----|---------|
| Consultant Senior | 1200€ | - |
| Audit complet | - | 15000€ |
...
=== END REFERENCE ===

=== BEGIN REFERENCE: Nos Références Clients ===
## Nos Références

### Secteur Public
- Métropole de Bordeaux : transformation digitale, 2023
- Région Occitanie : schéma directeur SI, 2022
...
=== END REFERENCE ===

=== BEGIN REFERENCE: Charte Éditoriale Dupont ===
## Notre Style

- Vouvoiement systématique
- Ton professionnel mais accessible
- Mettre en avant les résultats chiffrés
- Éviter le jargon technique non expliqué
...
=== END REFERENCE ===

Analyse l'appel d'offres fourni et rédige une réponse complète, structurée et persuasive qui met en valeur notre expertise et nos références pertinentes.

---
APPEL D'OFFRES À TRAITER :

APPEL D'OFFRES - Mairie de Lyon
Objet : Accompagnement à la transformation digitale
Budget : 50-80k€
...
```

4. Ce prompt complet est envoyé au LLM
5. Le LLM génère une réponse AO personnalisée

---

### Étape 6 : Le consultant reçoit sa réponse

**Ce que voit le consultant :**

```
┌─────────────────────────────────────────────────────────────────┐
│  📄 Votre réponse à l'appel d'offres                            │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ RÉPONSE À L'APPEL D'OFFRES                              │   │
│  │ Mairie de Lyon - Transformation Digitale                │   │
│  │                                                          │   │
│  │ ## 1. Compréhension de vos enjeux                       │   │
│  │                                                          │   │
│  │ La Mairie de Lyon souhaite moderniser ses processus...  │   │
│  │                                                          │   │
│  │ ## 2. Notre proposition                                  │   │
│  │                                                          │   │
│  │ Forts de notre expérience avec la Métropole de          │   │
│  │ Bordeaux et la Région Occitanie, nous proposons...      │   │
│  │                                                          │   │
│  │ ## 3. Budget prévisionnel                                │   │
│  │                                                          │   │
│  │ Notre intervention s'inscrit dans votre enveloppe       │   │
│  │ de 50-80k€ :                                             │   │
│  │ - Phase 1 - Audit : 15 000€                             │   │
│  │ - Phase 2 - Feuille de route : 20 000€                  │   │
│  │ - Phase 3 - Accompagnement : 35 000€                    │   │
│  │ ...                                                      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  [Copier]  [Exporter Word]  [Améliorer]  [Nouvelle réponse]    │
└─────────────────────────────────────────────────────────────────┘
```

**Résultat :** Une réponse AO complète qui :
- Utilise les vraies offres du catalogue Dupont
- Cite les vraies références (Bordeaux, Occitanie)
- Respecte les vrais tarifs de la grille
- Suit le style de communication de la charte Dupont

---

## Scénario Alternatif : Contenus manquants

### Contexte

L'admin d'un nouveau cabinet "StartConseil" n'a encore rien uploadé dans son espace.

### Ce que voit l'admin à l'étape 2 :

```
┌─────────────────────────────────────────────────────────────────┐
│  📋 Analyse de votre besoin                                     │
│                                                                 │
│  J'ai identifié 2 outils qui répondraient à votre besoin :      │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ✅ Générateur de Réponse AO                              │   │
│  │                                                          │   │
│  │    📄 Sources nécessaires :                              │   │
│  │    • Catalogue produits/services ⚠️ À COMPLÉTER         │   │
│  │    • Grille tarifaire ⚠️ À COMPLÉTER                    │   │
│  │    • Références clients ⚠️ À COMPLÉTER                  │   │
│  │                                                          │   │
│  │    🎨 Style : Style professionnel (par défaut)          │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ⚠️ 3 contenus sont manquants. Des fiches vides seront créées  │
│  que vous pourrez compléter ensuite.                            │
│                                                                 │
│                              [← Modifier]  [Créer les outils →] │
└─────────────────────────────────────────────────────────────────┘
```

### Ce qui se passe si l'admin continue :

1. Le système crée des **placeholders** (publications vides avec un template) :
   - "📝 Catalogue à compléter"
   - "📝 Grille tarifaire à compléter"
   - "📝 Références à compléter"

2. L'outil est créé avec les URLs vers ces placeholders

3. Quand un consultant utilise l'outil, le LLM reçoit :

```
=== BEGIN REFERENCE: Catalogue à compléter ===
[Ce document est en attente de contenu. Veuillez demander à votre administrateur de le compléter avec votre catalogue de produits et services.]
=== END REFERENCE ===
```

4. Le LLM génère une réponse générique (moins bonne, mais ça fonctionne)

5. L'admin reçoit une notification : "Vos outils seront plus performants une fois les contenus complétés"

---

## Scénario : Mise à jour d'un contenu source

### Contexte

Le cabinet Dupont met à jour sa grille tarifaire (hausse de 5%).

### Ce que fait l'admin :

1. Il va sur la publication "Grille Tarifaire"
2. Il modifie les prix
3. Il enregistre

### Ce qui se passe :

**Rien de plus à faire.**

La prochaine fois qu'un consultant utilise l'outil "Générateur AO", le système :
1. Résout l'URL `https://.../grille-tarifaire/`
2. Charge le contenu **mis à jour**
3. Le LLM utilise les nouveaux tarifs

**L'outil n'a pas besoin d'être modifié.** Il référence la publication par son URL, pas par une copie figée.

---

## Scénario : L'admin corrige une sélection automatique

### Contexte

Le système a proposé "Catalogue 2023" mais l'admin veut utiliser "Catalogue 2024" (qu'il vient d'uploader).

### Ce que voit l'admin :

```
┌─────────────────────────────────────────────────────────────────┐
│  📄 Sources qui seront utilisées :                              │
│                                                                 │
│  • Catalogue produits    Catalogue 2023 ▼                       │
│                          ┌─────────────────────────────────┐   │
│                          │ Catalogue 2023                  │   │
│                          │ Catalogue 2024 ← sélectionner   │   │
│                          │ Brochure commerciale            │   │
│                          └─────────────────────────────────┘   │
│  • Grille Tarifaire      Tarifs 2024 ✓                         │
│  • Références            Nos Références ✓                       │
└─────────────────────────────────────────────────────────────────┘
```

### Ce que fait l'admin :

Il sélectionne "Catalogue 2024" dans le menu déroulant.

### Ce qui se passe :

Le système utilise désormais "Catalogue 2024" pour créer l'outil.

---

## Résumé des bénéfices

### Pour l'admin

| Avant | Après |
|-------|-------|
| Créer un outil manuellement | Décrire son besoin en 1 phrase |
| Chercher et lier les contenus | Le système les trouve automatiquement |
| Configurer les dépendances | Rien à configurer |
| Mettre à jour l'outil si les sources changent | Automatique, transparent |

### Pour le consultant

| Avant | Après |
|-------|-------|
| Chercher le bon outil | Outil adapté à son métier |
| Se demander si les infos sont à jour | Toujours à jour (sources live) |
| Résultats génériques | Résultats personnalisés (vraies offres, vrais tarifs, vrai style) |

### Pour Marylink

| Avant | Après |
|-------|-------|
| Outils sous-utilisés car mal configurés | Outils prêts à l'emploi dès la création |
| Support pour expliquer les liaisons | Zéro configuration visible |
| Valeur perçue limitée | "Wow, il a trouvé tout seul mes documents !" |
