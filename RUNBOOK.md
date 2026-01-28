# MCP No Headless - Runbook

Guide opérationnel pour le plugin MCP No Headless.

## Table des matières

1. [Démarrage rapide](#démarrage-rapide)
2. [Health Checks](#health-checks)
3. [Monitoring](#monitoring)
4. [Incidents courants](#incidents-courants)
5. [Maintenance](#maintenance)
6. [API REST Ops](#api-rest-ops)
7. [Sécurité](#sécurité)

---

## Démarrage rapide

### Installation

```bash
# Copier le plugin dans wp-content/plugins/
cp -r mcp-no-headless /path/to/wp-content/plugins/

# Activer via WP-CLI
wp plugin activate mcp-no-headless
```

### Vérification post-installation

```bash
# Test health endpoint
curl -s https://your-site.com/wp-json/marylink-mcp/v1/health | jq

# Réponse attendue
{
  "ok": true,
  "timestamp": "2025-01-06T10:00:00+00:00",
  "version": "1.0.0"
}
```

### Prérequis

- **WordPress** >= 6.0
- **PHP** >= 8.0
- **AI Engine Pro** avec MCP activé
- Optionnel: BuddyBoss Platform (pour outils sociaux)
- Optionnel: Picasso Backend (pour publications)

---

## Health Checks

### Endpoint public

```bash
# Check simple (uptime monitoring)
curl -s -o /dev/null -w "%{http_code}" https://your-site.com/wp-json/marylink-mcp/v1/health
# 200 = OK, 503 = Problème
```

### Endpoint admin (détaillé)

```bash
# Nécessite authentification admin
curl -s https://your-site.com/wp-json/marylink-mcp/v1/health/full \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -b "wordpress_logged_in_xxx=..." | jq
```

### Interprétation des résultats

| Champ | Valeur OK | Action si KO |
|-------|-----------|--------------|
| `database.ok` | `true` | Voir [DB manquante](#table-audit-manquante) |
| `cron.ok` | `true` | Voir [Cron bloqué](#cron-bloqué) |
| `dependencies.ai_engine.available` | `true` | Installer AI Engine Pro |
| `rate_limits.global.utilization` | < 80% | Voir [Rate limit](#rate-limit-global-atteint) |

---

## Monitoring

### Métriques clés

Via l'admin WP : **MaryLink MCP → Status**

- **Total Requests (24h)** : Volume normal selon votre usage
- **Error Rate** : < 5% acceptable, > 10% à investiguer
- **Avg Latency** : < 500ms idéal, > 2000ms problème
- **Active Users** : Nombre d'utilisateurs actifs

### Logs à surveiller

```bash
# Erreurs récentes dans l'audit log
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/audit?result=error&limit=10" \
  -H "X-WP-Nonce: YOUR_NONCE" | jq '.logs[] | {timestamp, tool_name, error_code, debug_id}'
```

### Alerting (suggestion)

```bash
# Script cron pour alerting (à adapter)
#!/bin/bash
HEALTH=$(curl -s -w "\n%{http_code}" https://your-site.com/wp-json/marylink-mcp/v1/health)
HTTP_CODE=$(echo "$HEALTH" | tail -1)

if [ "$HTTP_CODE" != "200" ]; then
  # Envoyer alerte (Slack, email, etc.)
  echo "MCP Health check failed: $HTTP_CODE"
fi
```

---

## Incidents courants

### Table audit manquante

**Symptôme**: Erreur "Table doesn't exist" dans les logs

**Diagnostic**:
```bash
wp db query "SHOW TABLES LIKE '%mcpnh_audit'"
```

**Résolution**:
```bash
# Réactiver le plugin pour recréer la table
wp plugin deactivate mcp-no-headless
wp plugin activate mcp-no-headless

# Ou manuellement
wp eval "MCP_No_Headless\Ops\Audit_Logger::create_table();"
```

### Cron bloqué

**Symptôme**: Scores non recalculés, logs non purgés

**Diagnostic**:
```bash
wp cron event list | grep mcpnh
```

**Résolution**:
```bash
# Exécuter manuellement
wp cron event run mcpnh_recalculate_scores
wp cron event run mcpnh_purge_audit_logs

# Si les events n'existent pas
wp eval "MCP_No_Headless\Services\Scoring_Service::register_cron();"
wp eval "MCP_No_Headless\Ops\Audit_Logger::register_cron();"
```

### Rate limit global atteint

**Symptôme**: Erreur "global_limit_exceeded" pour tous les utilisateurs

**Diagnostic**:
```bash
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/health/full" | jq '.rate_limits'
```

**Résolution**:
```bash
# Reset via API REST
curl -X POST "https://your-site.com/wp-json/marylink-mcp/v1/rate-limits/reset?all=true" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Ou via WP-CLI
wp eval "MCP_No_Headless\Ops\Rate_Limiter::reset_all();"
```

### Rate limit utilisateur

**Symptôme**: Un utilisateur spécifique bloqué

**Résolution**:
```bash
# Via API
curl -X POST "https://your-site.com/wp-json/marylink-mcp/v1/rate-limits/reset?user_id=123" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Via WP-CLI
wp eval "MCP_No_Headless\Ops\Rate_Limiter::reset_user(123);"
```

### Token compromis

**Symptôme**: Activité suspecte pour un utilisateur

**Résolution immédiate**:
```bash
# Révoquer le token
wp eval "\$tm = new MCP_No_Headless\User\Token_Manager(); \$tm->revoke_token(USER_ID);"
```

L'utilisateur devra générer un nouveau token depuis son profil.

### Erreurs 500 sur les outils

**Diagnostic**:
1. Noter le `debug_id` retourné dans l'erreur
2. Rechercher dans les logs:

```bash
# Via API audit
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/audit?result=error" | \
  jq '.logs[] | select(.debug_id == "err_20250106_123456_abcd1234")'

# Ou dans debug.log WordPress
grep "err_20250106_123456_abcd1234" wp-content/debug.log
```

---

## Maintenance

### Purge manuelle des logs

```bash
# Purger les logs > 30 jours (défaut)
wp eval "MCP_No_Headless\Ops\Audit_Logger::purge_old_logs();"

# Purger les logs > 7 jours
wp eval "MCP_No_Headless\Ops\Audit_Logger::purge_old_logs(7);"
```

### Recalcul des scores

Normalement fait automatiquement chaque nuit.

```bash
# Forcer le recalcul
wp eval "MCP_No_Headless\Services\Scoring_Service::recalculate_all_scores();"

# Ou via API
curl -X POST "https://your-site.com/wp-json/marylink-mcp/v1/recalculate-scores" \
  -H "X-WP-Nonce: YOUR_NONCE"
```

### Diagnostics complets

```bash
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/diagnostics" \
  -H "X-WP-Nonce: YOUR_NONCE" | jq
```

Vérifie:
- Écriture audit log
- Rate limiter
- BuddyBoss (si disponible)

### Mise à jour du plugin

```bash
# Backup avant mise à jour
wp db export backup-before-mcp-update.sql

# Mettre à jour les fichiers
# ...

# Vérifier la santé
curl -s https://your-site.com/wp-json/marylink-mcp/v1/health

# Si problème, rollback
wp db import backup-before-mcp-update.sql
```

---

## API REST Ops

### Endpoints disponibles

| Endpoint | Méthode | Auth | Description |
|----------|---------|------|-------------|
| `/health` | GET | Public | Health check simple |
| `/health/full` | GET | Admin | Health check détaillé |
| `/diagnostics` | GET | Admin | Run diagnostics |
| `/recalculate-scores` | POST | Admin | Recalcul scores |
| `/audit` | GET | Admin | Liste logs audit |
| `/audit/stats` | GET | Admin | Stats audit |
| `/rate-limits` | GET | User | Limites utilisateur |
| `/rate-limits/reset` | POST | Admin | Reset limites |
| `/token` | GET | User | Info token |
| `/token/regenerate` | POST | User | Nouveau token |
| `/token/revoke` | POST | User | Révoquer token |
| `/token/scopes` | PUT | User | Modifier scopes |

### Exemples d'utilisation

```bash
# Stats audit dernières 24h
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/audit/stats?period=24h" \
  -H "X-WP-Nonce: NONCE" | jq

# Logs d'un outil spécifique
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/audit?tool_name=ml_search_publications" \
  -H "X-WP-Nonce: NONCE" | jq

# Limites rate du user courant
curl -s "https://your-site.com/wp-json/marylink-mcp/v1/rate-limits" \
  -H "X-WP-Nonce: NONCE" | jq
```

---

## Sécurité

### Rate Limits

| Opération | Limite/min | Burst (5s) |
|-----------|------------|------------|
| Read | 120/user | 15 |
| Write | 20/user | 5 |
| Global | 2000/5min | - |

### Token Scopes

| Scope | Permet |
|-------|--------|
| `read:content` | Lecture publications, espaces |
| `write:content` | Création/édition publications |
| `read:social` | Lecture groupes, activités, membres |
| `write:social` | Post activités, commentaires, join groups |

**Par défaut**: `read:content` + `read:social` (écriture désactivée)

### Bonnes pratiques

1. **Rotation régulière**: Demander aux utilisateurs de régénérer leur token périodiquement
2. **Scopes minimaux**: N'activer que les scopes nécessaires
3. **Monitoring**: Surveiller les taux d'erreur et les patterns inhabituels
4. **Logs**: Conserver les audit logs suffisamment longtemps pour investigation

### En cas de compromission

1. **Révoquer le token** de l'utilisateur affecté
2. **Analyser les logs** pour identifier les actions suspectes
3. **Reset rate limits** si utilisés de manière abusive
4. **Notifier l'utilisateur** pour qu'il génère un nouveau token

---

## Contacts

- **Documentation**: Ce fichier + README.md
- **Code source**: `src/` dans le plugin
- **Admin UI**: WP Admin → MaryLink MCP

---

## Changelog opérationnel

| Date | Version | Changement |
|------|---------|------------|
| 2025-01 | 1.0.0 | Version initiale avec Phase 5 Ops |
