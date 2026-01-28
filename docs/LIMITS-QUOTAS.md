# MCP No Headless - Limits & Quotas Matrix

## Sprint 0 Hardening Documentation

This document defines the rate limits, quotas, and operational constraints for the MCP plugin.

---

## Plan Tiers

| Plan | Target | Description |
|------|--------|-------------|
| `free` | Anonymous/Trial | Minimal access for testing |
| `pro` | Individual users | Standard authenticated access |
| `cabinet` | B2B clients | Professional/agency access |
| `enterprise` | B2B2B clients | Full API access with mission tokens |

---

## Rate Limits by Plan

### Operations per Minute

| Plan | Read/min | Write/min | Bulk/hour | Chain Depth | Export/day |
|------|----------|-----------|-----------|-------------|------------|
| Free | 60 | 10 | 0 | 3 | 0 |
| Pro | 120 | 30 | 5 | 5 | 5 |
| Cabinet | 300 | 60 | 20 | 10 | 20 |
| Enterprise | 600 | 120 | 100 | 20 | 100 |

### Burst Protection (per 5 seconds)

| Plan | Read Burst | Write Burst |
|------|------------|-------------|
| Free | 10 | 3 |
| Pro | 15 | 5 |
| Cabinet | 30 | 10 |
| Enterprise | 50 | 20 |

### Bulk Operations

| Plan | Max Items/Operation | Operations/Hour |
|------|---------------------|-----------------|
| Free | 0 (disabled) | 0 |
| Pro | 10 | 5 |
| Cabinet | 50 | 20 |
| Enterprise | 200 | 100 |

---

## Timeouts

| Operation | Timeout (seconds) |
|-----------|-------------------|
| Default request | 30 |
| Chain resolution | 10 |
| Bulk item processing | 5 |
| Bulk total operation | 300 (5 min) |
| Export operation | 60 |

---

## Global Limits

| Metric | Limit | Window |
|--------|-------|--------|
| Total requests | 2000 | 5 minutes |
| Concurrent bulk jobs | 10 | - |
| Session TTL | 300 | seconds |

---

## Tool Classification

### Read Tools (relaxed limits)
- `ml_list_*`, `ml_get_*`, `ml_search_*`
- `ml_recommend`, `ml_recommend_styles`
- `ml_*_prepare` (prepare stage)

### Write Tools (strict limits)
- `ml_create_*`, `ml_edit_*`, `ml_append_*`
- `ml_add_comment`, `ml_import_as_comment`
- `ml_*_commit` (commit stage)
- `ml_rate_publication`, `ml_subscribe_space`
- `ml_duplicate_publication`
- `ml_team_invite`, `ml_team_remove`

### Bulk Tools (strictest limits)
- `ml_bulk_apply_tool`
- `ml_bulk_move_step`
- `ml_bulk_tag`
- `ml_export_crew_bundle`

---

## Error Responses

All rate limit errors return a consistent format:

```json
{
  "ok": false,
  "error": {
    "code": "rate_limit_exceeded",
    "reason": "user_limit_exceeded",
    "retry_after": 45,
    "limits": {
      "plan": "pro",
      "op_type": "write",
      "current": 30,
      "limit": 30
    }
  },
  "request_id": "req_abc123"
}
```

### Error Codes

| Reason | Description |
|--------|-------------|
| `burst_limit_exceeded` | Too many requests in short time |
| `user_limit_exceeded` | Minute/hour limit reached |
| `token_limit_exceeded` | API token limit reached |
| `global_limit_exceeded` | Service overloaded |
| `bulk_not_allowed` | Bulk not available for plan |
| `bulk_item_limit_exceeded` | Too many items in bulk |

---

## Debug & Audit

Every request includes:
- `request_id`: Unique identifier for tracing
- `debug_id`: Audit log reference (for write operations)

Audit logs capture:
- `tool_name`, `user_id`, `result`
- `token_type`: `user` | `mission`
- `mission_token_id`, `mission_label` (for B2B2B)
- `token_hash_prefix`: First 8 chars of token hash
- `latency_ms`, `stage`, `error`

---

## Mission Token (B2B2B) Specifics

Mission tokens:
- Default to `cabinet` plan limits
- Are scoped to specific space IDs
- Have separate audit trail with label
- Can be revoked independently

---

## API Endpoints

### Get Your Limits
```
GET /wp-json/mcp/v1/limits
Authorization: Bearer <token>
```

Response:
```json
{
  "plan": "pro",
  "read_per_minute": 120,
  "write_per_minute": 30,
  "bulk_per_hour": 5,
  "bulk_max_items": 10,
  "chain_depth": 5,
  "export_per_day": 5,
  "current_usage": {
    "read": { "current": 15, "limit": 120 },
    "write": { "current": 3, "limit": 30 },
    "bulk": { "current": 0, "limit": 5 }
  }
}
```

---

## Neutral Error Messages (Anti-Leak)

For security, permission and not-found errors return the same message:

```json
{
  "ok": false,
  "error": {
    "code": "not_found",
    "message": "Resource not found"
  }
}
```

This prevents:
- Enumeration of existing resources
- Information leakage about permissions
- Discovery of other users' content

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2025-01 | Initial Sprint 0 hardening |
