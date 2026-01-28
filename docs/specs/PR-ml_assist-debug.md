# PR: ml_assist Debug Mode

**Version cible:** 3.2.11
**Priorité:** P1 (diagnostique avant fix matching)
**Fichiers impactés:**
- `src/Services/Recommendation_Service.php`
- `src/MCP/Assist_Tool.php`

---

## Problème

`ml_assist` retourne "intent OK, 0 matching" sans explication.
Impossible de diagnostiquer si le problème vient :
- Du nombre de candidats (aucun espace accessible?)
- Du scoring (seuil trop haut?)
- De l'index (mauvais types de publications?)
- De la normalisation (mots-clés non trouvés?)

---

## Solution

Ajouter `include=["debug"]` qui expose les métriques internes :

```json
{
  "ok": true,
  "intent": { "detected": "sales_letter", "confidence": 0.75 },
  "recommendations": [],
  "debug": {
    "candidates_scanned": 0,
    "spaces_checked": [123, 456],
    "spaces_accessible": 0,
    "index_types": ["prompt", "tool", "style"],
    "query_normalized": ["lettre", "commerciale", "acme"],
    "threshold_used": 0.3,
    "top_scores": [],
    "timing_ms": {
      "intent_detection": 5,
      "candidate_fetch": 12,
      "scoring": 0,
      "total": 17
    }
  }
}
```

---

## Implémentation

### 1. Recommendation_Service::recommend()

Ajouter paramètre `$options['debug'] = true` :

```php
public function recommend(string $text, ?int $space_id = null, array $options = []): array {
    $debug_mode = $options['debug'] ?? false;
    $debug = [];

    // ... existing code ...

    // Step 2: Get candidates (avec timing)
    $t1 = microtime(true);
    $candidates = $this->get_prompt_candidates($space_id, $intent);
    $debug['timing_ms']['candidate_fetch'] = (int)((microtime(true) - $t1) * 1000);
    $debug['candidates_scanned'] = count($candidates);

    // ... scoring ...

    // Step 3: Score (avec top_scores)
    $scored = $this->score_candidates($candidates, $text, $intent);
    if ($debug_mode) {
        $debug['top_scores'] = array_map(fn($s) => [
            'id' => $s['post']->ID,
            'title' => $s['post']->post_title,
            'final_score' => round($s['final_score'], 3),
            'breakdown' => $s['scores'] ?? [],
        ], array_slice($scored, 0, 5));
        $debug['threshold_used'] = self::MINIMUM_SCORE_THRESHOLD ?? 0.3;
    }

    // Return with debug
    $result = [ /* existing */ ];
    if ($debug_mode) {
        $result['debug'] = $debug;
    }
    return $result;
}
```

### 2. get_prompt_candidates() - Exposer spaces_checked

```php
private function get_prompt_candidates(?int $space_id, array $intent, array &$debug = []): array {
    $space_ids = $space_id ? [$space_id] : $this->permissions->get_user_spaces();
    $debug['spaces_checked'] = $space_ids;
    $debug['spaces_accessible'] = 0;

    foreach ($space_ids as $sid) {
        if ($this->permissions->can_see_space($sid)) {
            $debug['spaces_accessible']++;
        }
        // ...
    }

    $debug['index_types'] = ['prompt', 'tool', 'style'];
    return $candidates;
}
```

### 3. Assist_Tool::execute() - Propager debug

```php
// Dans execute(), passer debug au service
$include = $args['include'] ?? [];
$debug_mode = in_array('debug', $include, true);

$recommendation_result = $service->recommend($text, $space_id, [
    'limit' => 1,
    'debug' => $debug_mode,
]);

// Propager dans la réponse
if ($debug_mode && isset($recommendation_result['debug'])) {
    $response['debug'] = $recommendation_result['debug'];
}
```

---

## Tests de non-régression

### NR1: Sans debug, pas de clé debug
```php
$result = Assist_Tool::execute(['text' => 'lettre commerciale'], 1);
assert(!isset($result['data']['debug']), 'NR1 - No debug without include');
```

### NR2: Avec debug, clé présente
```php
$result = Assist_Tool::execute([
    'text' => 'lettre commerciale',
    'include' => ['debug']
], 1);
assert(isset($result['data']['debug']), 'NR2 - Debug present with include');
assert(isset($result['data']['debug']['candidates_scanned']), 'NR2 - candidates_scanned present');
assert(isset($result['data']['debug']['spaces_checked']), 'NR2 - spaces_checked present');
```

### NR3: Debug avec 0 candidats montre why
```php
// User sans espaces
$result = Assist_Tool::execute([
    'text' => 'lettre commerciale',
    'include' => ['debug']
], 999); // user sans permissions

$debug = $result['data']['debug'] ?? [];
assert($debug['candidates_scanned'] === 0, 'NR3 - 0 candidates');
assert($debug['spaces_accessible'] === 0, 'NR3 - explains why: no accessible spaces');
```

### NR4: Debug avec candidats montre top_scores
```php
// User avec espaces
$result = Assist_Tool::execute([
    'text' => 'lettre commerciale',
    'include' => ['debug']
], 1); // admin

$debug = $result['data']['debug'] ?? [];
if ($debug['candidates_scanned'] > 0) {
    assert(isset($debug['top_scores']), 'NR4 - top_scores present when candidates > 0');
    assert(isset($debug['top_scores'][0]['final_score']), 'NR4 - scores have final_score');
}
```

---

## Checklist PR

- [ ] Ajouter `debug` param à `Recommendation_Service::recommend()`
- [ ] Exposer `spaces_checked`, `spaces_accessible`, `index_types`
- [ ] Exposer `top_scores` avec breakdown
- [ ] Exposer `timing_ms` par étape
- [ ] Propager `include=["debug"]` dans `Assist_Tool`
- [ ] Tests NR1-NR4
- [ ] Bump version 3.2.11
- [ ] Déployer sur tous les sites

---

## Estimation

- Complexité: Moyenne
- Risque de régression: Faible (ajout uniquement, pas de modification logique)
- Fichiers: 2
- Tests: 4
