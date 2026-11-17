# Developer docs (Mintlify)

Documentation of the REST API and the MCP server.

- `docs.json`: Mintlify configuration and navigation.
- `api-reference/openapi.yaml`: **the single source of truth of the API v1 contract.** The API reference tab is generated from it, and `tests/Feature/Api/OpenApiContractTest.php` fails when a route, a status code or an MCP tool name drifts from it.
- `*.mdx`: guides.

## Preview and validate

```bash
cd docs-site
npx mint dev             # http://localhost:3000
npx mint broken-links    # internal links
npx mint validate        # content and OpenAPI
```

Needs Node.js 20.17 or newer.

## Publish

1. In the Mintlify dashboard, install the Mintlify GitHub app on this repository.
2. Set the docs directory to `docs-site/` and the branch to `main`.

Every push to `main` that touches `docs-site/` redeploys the site.
