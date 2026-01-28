# SPEC BOOTSTRAP WIZARD - Templates & Data Types

## 1. Architecture du Wizard

```
┌─────────────────────────────────────────────────────────────────┐
│                     ml_bootstrap_wizard                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  STAGE: analyze                                                 │
│  ───────────────                                                │
│  Input: problems (texte libre), target_space_id                 │
│  Output: detected_tools[], required_data[], session_id          │
│                                                                 │
│  STAGE: propose                                                 │
│  ──────────────                                                 │
│  Input: session_id                                              │
│  Action: Auto-sélection des composants via Component_Picker     │
│  Output: proposed_kit, components (avec scores)                 │
│                                                                 │
│  STAGE: collect (optionnel)                                     │
│  ────────────────────────────                                   │
│  Input: session_id, data_id, publication_id                     │
│  Action: Override une sélection automatique                     │
│  Output: updated components                                     │
│                                                                 │
│  STAGE: validate                                                │
│  ────────────────                                               │
│  Input: session_id                                              │
│  Action: Vérifier readiness, compter placeholders               │
│  Output: summary, warnings                                      │
│                                                                 │
│  STAGE: execute                                                 │
│  ───────────────                                                │
│  Input: session_id, confirmed=true                              │
│  Action: Créer placeholders, outils, landing page               │
│  Output: created[]                                              │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Patterns de Détection (analyze)

Le système analyse le texte de l'admin pour détecter les besoins.

```php
const DETECTION_PATTERNS = [
    'ao_response' => [
        'patterns' => [
            '/appel[s]?\s*(d\'|d\s)?offre/i',
            '/\bAO\b/',
            '/répondre.*marché/i',
            '/consultation/i',
            '/soumission/i',
        ],
        'keywords' => ['ao', 'appel offre', 'marché public', 'consultation', 'rfp', 'tender'],
        'confidence_boost' => 1.0,
    ],
    'follow_up' => [
        'patterns' => [
            '/relance[r]?/i',
            '/follow.?up/i',
            '/recontact/i',
            '/rappel.*client/i',
        ],
        'keywords' => ['relance', 'follow-up', 'rappel', 'recontacter', 'prospect'],
        'confidence_boost' => 0.9,
    ],
    'proposal' => [
        'patterns' => [
            '/proposition?\s+commerciale/i',
            '/devis/i',
            '/offre\s+commerciale/i',
            '/chiffr/i',
        ],
        'keywords' => ['proposition', 'devis', 'offre commerciale', 'chiffrage'],
        'confidence_boost' => 0.85,
    ],
    'email_commercial' => [
        'patterns' => [
            '/email.*commercial/i',
            '/mail.*prospect/i',
            '/courrier.*client/i',
        ],
        'keywords' => ['email', 'mail', 'courrier', 'prospection'],
        'confidence_boost' => 0.8,
    ],
    'presentation' => [
        'patterns' => [
            '/présentation/i',
            '/pitch/i',
            '/powerpoint/i',
            '/slides?/i',
        ],
        'keywords' => ['présentation', 'pitch', 'slides', 'ppt'],
        'confidence_boost' => 0.75,
    ],
    'summary' => [
        'patterns' => [
            '/résumé/i',
            '/synthèse/i',
            '/compte.?rendu/i',
            '/récapitulatif/i',
        ],
        'keywords' => ['résumé', 'synthèse', 'compte-rendu', 'récap'],
        'confidence_boost' => 0.7,
    ],
];
```

---

## 3. Data Types (contenus requis)

```php
const DATA_TYPES = [
    'catalog' => [
        'id' => 'catalog',
        'label' => 'Catalogue produits/services',
        'label_short' => 'Catalogue',
        'description' => 'Liste de vos offres avec descriptions détaillées',
        'placeholder_template' => "## Catalogue de nos offres\n\n### [Nom de l'offre 1]\n**Description** : [Décrivez cette offre]\n**Bénéfices** : [Listez les bénéfices clients]\n**Livrables** : [Listez les livrables]\n\n### [Nom de l'offre 2]\n...",
        'required_for' => ['ao_response', 'follow_up', 'proposal', 'email_commercial'],
        'optional_for' => ['presentation'],
        'keywords' => ['catalog', 'catalogue', 'offre', 'service', 'produit', 'prestation', 'solution'],
        'min_score' => 20,
    ],
    'pricing' => [
        'id' => 'pricing',
        'label' => 'Grille tarifaire',
        'label_short' => 'Tarifs',
        'description' => 'Vos tarifs, TJM, forfaits et conditions',
        'placeholder_template' => "## Grille Tarifaire\n\n| Prestation | TJM | Forfait |\n|------------|-----|--------|\n| [Prestation 1] | [XXX€] | [XXXX€] |\n| [Prestation 2] | [XXX€] | [XXXX€] |\n\n### Conditions\n- [Conditions de paiement]\n- [Frais de déplacement]\n- [Validité des tarifs]",
        'required_for' => ['ao_response', 'proposal', 'quote'],
        'optional_for' => ['follow_up'],
        'keywords' => ['tarif', 'prix', 'pricing', 'grille', 'cout', 'coût', 'devis', 'tjm', 'forfait'],
        'min_score' => 20,
    ],
    'references' => [
        'id' => 'references',
        'label' => 'Références clients',
        'label_short' => 'Références',
        'description' => 'Projets réalisés, témoignages, cas clients',
        'placeholder_template' => "## Nos Références\n\n### [Nom du client 1]\n**Secteur** : [Secteur d'activité]\n**Projet** : [Description du projet]\n**Résultats** : [Résultats obtenus, chiffres clés]\n**Année** : [20XX]\n\n### [Nom du client 2]\n...",
        'required_for' => ['ao_response', 'proposal'],
        'optional_for' => ['follow_up', 'presentation'],
        'keywords' => ['reference', 'référence', 'client', 'portfolio', 'cas', 'projet', 'témoignage', 'réalisation'],
        'min_score' => 20,
    ],
    'company_info' => [
        'id' => 'company_info',
        'label' => 'Présentation entreprise',
        'label_short' => 'Entreprise',
        'description' => 'Histoire, équipe, valeurs, certifications',
        'placeholder_template' => "## Présentation de [Nom de l'entreprise]\n\n### Notre histoire\n[Année de création, fondateurs, évolution]\n\n### Notre équipe\n[Nombre de personnes, expertises clés]\n\n### Nos valeurs\n- [Valeur 1]\n- [Valeur 2]\n\n### Certifications\n- [Certification 1]\n- [Certification 2]",
        'required_for' => ['ao_response'],
        'optional_for' => ['proposal', 'presentation'],
        'keywords' => ['entreprise', 'société', 'cabinet', 'équipe', 'histoire', 'valeur', 'certification', 'présentation', 'qui sommes'],
        'min_score' => 15,
    ],
    'brand_guide' => [
        'id' => 'brand_guide',
        'label' => 'Charte éditoriale',
        'label_short' => 'Style',
        'description' => 'Ton, style de communication, vocabulaire',
        'placeholder_template' => "## Charte Éditoriale\n\n### Ton général\n[Professionnel/Décontracté, Vouvoiement/Tutoiement, etc.]\n\n### Vocabulaire\n- Utiliser : [termes préférés]\n- Éviter : [termes à éviter]\n\n### Structure type\n[Comment structurer les communications]",
        'required_for' => [],
        'optional_for' => ['*'],  // Optionnel pour tous
        'keywords' => ['charte', 'style', 'éditorial', 'brand', 'marque', 'ton', 'voix', 'communication'],
        'min_score' => 15,
        'is_style' => true,
    ],
];
```

---

## 4. Tool Templates (modèles d'outils)

```php
const TOOL_TEMPLATES = [
    'ao_response' => [
        'id' => 'ao_response',
        'name' => 'Générateur de Réponse AO',
        'description' => 'Rédige des réponses structurées aux appels d\'offres publics et privés',
        'domain' => 'commercial',
        'instruction' => "Tu es un expert en réponse aux appels d'offres B2B avec 15 ans d'expérience.
Tu connais parfaitement les attentes des acheteurs publics et privés.
Tu structures tes réponses de manière claire, factuelle et persuasive.
Tu sais mettre en valeur les points forts tout en restant honnête.",
        'final_task' => "Analyse l'appel d'offres fourni et rédige une réponse complète et structurée.

Ta réponse doit inclure :
1. Une synthèse de compréhension du besoin
2. Notre proposition détaillée
3. Nos références pertinentes
4. Le planning proposé
5. Le budget détaillé

Adapte le style au contexte (public/privé, formel/moins formel).",
        'required_data' => ['catalog', 'pricing', 'references'],
        'optional_data' => ['company_info'],
        'style' => 'formal_b2b',
        'output_format' => 'markdown',
    ],
    
    'follow_up' => [
        'id' => 'follow_up',
        'name' => 'Assistant Relance Client',
        'description' => 'Rédige des emails de relance personnalisés et efficaces',
        'domain' => 'commercial',
        'instruction' => "Tu es un expert en relation client B2B.
Tu sais relancer avec tact et pertinence, sans être insistant ni agressif.
Tu personnalises chaque message en fonction du contexte et de l'historique.
Tu apportes toujours de la valeur dans tes relances.",
        'final_task' => "Rédige un email de relance professionnel et personnalisé.

L'email doit :
- Rappeler subtilement le contexte sans être lourd
- Apporter une information utile ou une nouveauté
- Proposer une action concrète (rdv, démo, appel)
- Rester court (max 150 mots)

Adapte le ton au stade de la relation.",
        'required_data' => ['catalog'],
        'optional_data' => ['references'],
        'style' => 'professional_friendly',
        'output_format' => 'text',
    ],
    
    'proposal' => [
        'id' => 'proposal',
        'name' => 'Générateur de Proposition Commerciale',
        'description' => 'Crée des propositions commerciales personnalisées',
        'domain' => 'commercial',
        'instruction' => "Tu es un expert en rédaction de propositions commerciales B2B.
Tu sais structurer une offre de manière claire et convaincante.
Tu adaptes le niveau de détail au contexte et au montant.",
        'final_task' => "Rédige une proposition commerciale complète.

Structure attendue :
1. Page de garde
2. Contexte et compréhension du besoin
3. Notre approche
4. Déroulement et planning
5. Équipe proposée
6. Budget détaillé
7. Conditions et prochaines étapes

Sois précis sur les livrables et les engagements.",
        'required_data' => ['catalog', 'pricing', 'company_info'],
        'optional_data' => ['references'],
        'style' => 'formal_b2b',
        'output_format' => 'markdown',
    ],
    
    'email_commercial' => [
        'id' => 'email_commercial',
        'name' => 'Rédacteur Email Commercial',
        'description' => 'Crée des emails de prospection et de communication commerciale',
        'domain' => 'commercial',
        'instruction' => "Tu es un expert en copywriting B2B.
Tu sais capter l'attention dès l'objet et les premières lignes.
Tu écris des emails qui génèrent des réponses.",
        'final_task' => "Rédige un email commercial percutant.

L'email doit :
- Avoir un objet accrocheur (max 50 caractères)
- Aller droit au but dès la première phrase
- Expliquer la valeur ajoutée clairement
- Terminer par un CTA précis
- Faire max 200 mots",
        'required_data' => ['catalog'],
        'optional_data' => [],
        'style' => 'professional_friendly',
        'output_format' => 'text',
    ],
    
    'summary' => [
        'id' => 'summary',
        'name' => 'Synthétiseur de Documents',
        'description' => 'Résume et synthétise des documents longs',
        'domain' => 'productivity',
        'instruction' => "Tu es un expert en synthèse et analyse de documents.
Tu sais extraire l'essentiel sans perdre les informations clés.
Tu structures tes synthèses de manière claire et actionnable.",
        'final_task' => "Produis une synthèse structurée du document fourni.

La synthèse doit inclure :
- Les points clés (bullet points)
- Les chiffres importants
- Les actions à retenir
- Les questions en suspens

Longueur : environ 20% du document original.",
        'required_data' => [],
        'optional_data' => [],
        'style' => 'neutral',
        'output_format' => 'markdown',
    ],
];
```

---

## 5. Style Templates

```php
const STYLE_TEMPLATES = [
    'formal_b2b' => [
        'id' => 'formal_b2b',
        'name' => 'Professionnel B2B',
        'content' => "## Style de Communication

### Ton général
- Vouvoiement systématique
- Ton professionnel et confiant
- Factuel et précis

### Structure
- Phrases courtes et claires
- Paragraphes aérés
- Utilisation de listes pour la clarté

### Vocabulaire
- Utiliser : \"nous vous proposons\", \"notre expertise\", \"vos enjeux\"
- Éviter : superlatifs excessifs, promesses vagues, jargon non expliqué

### Mise en forme
- Titres et sous-titres clairs
- Chiffres et données mis en valeur
- Appels à l'action explicites",
    ],
    
    'professional_friendly' => [
        'id' => 'professional_friendly',
        'name' => 'Professionnel Accessible',
        'content' => "## Style de Communication

### Ton général
- Vouvoiement mais chaleureux
- Accessible sans être familier
- Orienté relation

### Structure
- Phrases naturelles
- Ton conversationnel
- Personnalisation visible

### Vocabulaire
- Utiliser : \"je\", \"vous\", prénom si connu
- Éviter : formules trop rigides, ton distant

### Mise en forme
- Emails courts
- Un sujet par message
- Signature personnelle",
    ],
    
    'neutral' => [
        'id' => 'neutral',
        'name' => 'Neutre et Factuel',
        'content' => "## Style de Communication

### Ton général
- Objectif et factuel
- Sans opinion ni jugement
- Clair et direct

### Structure
- Information pure
- Organisation logique
- Pas de fioritures

### Vocabulaire
- Termes précis
- Pas d'adverbes inutiles
- Chiffres exacts",
    ],
];
```

---

## 6. Sessions et État

```php
// Structure d'une session wizard
$session = [
    'session_id' => 'boot_' . uniqid(),
    'created_at' => time(),
    'expires_at' => time() + 3600, // 1 heure
    'user_id' => $current_user_id,
    'target_space_id' => $space_id,
    
    // Résultat de analyze
    'problems' => "texte original de l'admin",
    'detected_tools' => ['ao_response', 'follow_up'],
    'required_data' => ['catalog', 'pricing', 'references'],
    
    // Résultat de propose (auto-sélection)
    'proposed_kit' => [...],
    'components' => [
        'catalog' => ['found' => true, 'publication_id' => 123, 'score' => 0.95],
        'pricing' => ['found' => true, 'publication_id' => 456, 'score' => 0.88],
        'references' => ['found' => false, 'publication_id' => null, 'score' => 0],
    ],
    
    // Overrides manuels (collect)
    'overrides' => [
        // 'catalog' => 789, // Si l'admin a corrigé
    ],
    
    // État
    'current_stage' => 'propose',
    'validated' => false,
    'executed' => false,
];
```

---

## 7. Kits Pré-définis

```php
const PREDEFINED_KITS = [
    'commercial' => [
        'id' => 'commercial',
        'name' => 'Kit Commercial',
        'description' => 'Outils pour les équipes commerciales',
        'tools' => ['ao_response', 'follow_up', 'proposal', 'email_commercial'],
        'patterns' => ['commercial', 'vente', 'business', 'client'],
    ],
    'support' => [
        'id' => 'support',
        'name' => 'Kit Support Client',
        'description' => 'Outils pour le support et la relation client',
        'tools' => ['follow_up', 'summary'],
        'patterns' => ['support', 'client', 'ticket', 'réclamation'],
    ],
    'management' => [
        'id' => 'management',
        'name' => 'Kit Management',
        'description' => 'Outils pour les managers',
        'tools' => ['summary', 'email_commercial'],
        'patterns' => ['manager', 'direction', 'pilotage', 'reporting'],
    ],
];
```
