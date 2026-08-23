# REFCONCEPT BRAND RENAME CHECKLIST

The old project name was RefOne. Current brand is **RefConcept**.

If an existing repository contains old references, migrate them.

Search:
```bash
grep -RniE 'RefOne|REFONE|refone' . \
  --exclude-dir=.git \
  --exclude-dir=node_modules \
  --exclude-dir=vendor
```

Review/replace:
- UI labels
- docs
- package/app names where safe
- namespaces if project-specific
- Docker service names
- DB seed demo data
- environment variable prefixes if project-specific
- S3 key prefixes
- log service names
- CI workflow names
- OpenAPI title
- email templates
- analytics event metadata
- error/reporting project labels

Do **not** blindly rename third-party identifiers or historical external IDs.

Final gate:
No unintended old-brand text remains in customer-facing or new internal project naming.
