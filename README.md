[![CORS - One system. Down to the Core.](https://raw.githubusercontent.com/cors-gmbh/.github/refs/heads/main/cors-banner.jpg)](https://cors.gmbh)

# CORS Pimcore CDN (cors/pimcore-cdn)

Provider implementations for Pimcore's built-in CDN integration (Pimcore ≥ 2026.2):
**Cloudflare**, **Google Cloud CDN**, **AWS CloudFront** and **Azure Front Door** in one
bundle. Pimcore emits `Surrogate-Key` / `Cache-Tag` headers on thumbnail responses and
dispatches purge messages on asset and thumbnail-config changes; this bundle supplies the
`Pimcore\Cdn\PurgeClientInterface` implementations (and, for Cloudflare, an
`ImageTransformAdapterInterface`) that talk to the provider APIs.

See Pimcore's `doc/08_Development_Details/03_Cache/03_CDN_Integration.md` for the core
mechanics (tags, messenger transport `pimcore_cdn_purge`, `pimcore:cdn:purge` command).

## Providers

| `CDN_PROVIDER` | Tag purge | URL purge | Image transform (`CDN_IMAGE_OPTIMIZER`) | Extra dependency |
|---|---|---|---|---|
| `cloudflare` | yes | yes | `cloudflare` (`/cdn-cgi/image/`) | – |
| `google` | yes | yes | – | `google/auth` |
| `aws` | yes (`#tag` items, needs `CacheTagConfig`) or via path mapping | yes | – | `aws/aws-sdk-php` |
| `azure` | via path mapping | yes | – | – |
| `chain` | every provider in `chain.providers` | yes | – | – |

### Stacked CDNs (`chain`)

For setups with two CDN layers, e.g. Cloudflare in front of Azure Front Door, set
`CDN_PROVIDER=chain` and list the providers in `cors_cdn.chain.providers`. Every purge goes
to all of them, in the listed order. Put the layer closest to the origin first, otherwise the
outer CDN can refetch the stale object from the inner one before that is purged.

If one provider fails, the others still run; the first failure is re-thrown afterwards so
Messenger retries the message (re-purging the successful ones, which is harmless). Any
registered purge client works in a chain, including Pimcore's `fastly`. The `Cache-Tag`
header is rewritten to commas if any chained provider needs it.

### Cache-Tag header

Pimcore writes `Cache-Tag` space-separated. Cloudflare, CloudFront and Cloud CDN read
comma-separated tags, so `CacheTagHeaderRewriteListener` rewrites the header for those
providers (priority -10, after Pimcore's listener). `Surrogate-Key` is left untouched.

### Providers without cache tags

Azure Front Door has no cache tags, and CloudFront only has them when the distribution
carries a `CacheTagConfig` (set `HeaderName` to `Cache-Tag`; `aws.tag_invalidation: false`
switches to path mapping). For path-only purges `TagToPathMapper` translates Pimcore's tags:

| Tag | Path pattern |
|---|---|
| `asset-{id}` | `{thumbnail prefix}{asset folder}/{id}/*` (asset must still exist) |
| `asset-{id}-thumb-{config}` | `…/{id}/image-thumb__{id}__{config}/*` and `video-thumb…` |
| `thumb-{config}` | unmappable |
| `asset-path-{hash}` | unmappable (originals are URL-purged anyway) |

Unmappable tags are skipped with a warning, or with `unmappable_tag_strategy: purge_all`
collapsed into one `/*` invalidation. Deleted assets cannot be resolved, so their
thumbnails expire by TTL; the original is still purged by URL.

## Installation

```bash
composer require cors/pimcore-cdn:^2026.1
```

Enable `CORS\Bundle\CdnBundle\CORSCdnBundle`, set `CDN_PROVIDER` and `CDN_BASE_URL`, and
run a worker for the `pimcore_cdn_purge` transport.

## Configuration

```yaml
cors_cdn:
    cloudflare:
        zone_id: '%env(CLOUDFLARE_ZONE_ID)%'
        api_token: '%env(CLOUDFLARE_API_TOKEN)%'
    google:
        project: '%env(GOOGLE_CLOUD_PROJECT)%'
        url_map: '%env(GOOGLE_CDN_URL_MAP)%'
        max_tags_per_request: 100      # Google documents no hard limit
    aws:
        distribution_id: '%env(AWS_CLOUDFRONT_DISTRIBUTION_ID)%'
        region: us-east-1
        tag_invalidation: true         # false = path mapping
    azure:
        resource_id: '%env(AZURE_FRONTDOOR_RESOURCE_ID)%'
        tenant_id: '%env(AZURE_TENANT_ID)%'
        client_id: '%env(AZURE_CLIENT_ID)%'
        client_secret: '%env(AZURE_CLIENT_SECRET)%'
        api_version: '2024-02-01'
    chain:
        providers: [azure, cloudflare]  # only used with CDN_PROVIDER=chain; origin-side first
    path_only_purge:
        unmappable_tag_strategy: skip   # skip | purge_all
```

Google uses Application Default Credentials (`GOOGLE_APPLICATION_CREDENTIALS` or Workload
Identity) with the `compute` scope; the service account needs `compute.urlMaps.invalidateCache`.
AWS uses the default SDK credential chain and needs `cloudfront:CreateInvalidation`.
Azure uses an Entra ID app registration (client credentials) with the *CDN Profile Contributor*
role on the Front Door profile; `resource_id` is the ARM id of the AFD endpoint.

Google and AWS clients are only registered when `google/auth` respectively `aws/aws-sdk-php`
is installed.

## Not yet verified against live APIs

- Cloudflare `trim.*` crop options in the transform adapter.
- Google's maximum number of `cacheTags` per request and invalidation rate limits.
- Whether Azure Front Door honours partial-folder wildcards such as `/folder/42/*` (documented
  is `/folder/*`); if not, `asset-{id}` purges on Azure need `purge_all`.

## Development

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console pimcore:install --profile cors-cdn-studio
docker compose exec php composer test
```

## License

[MIT](https://opensource.org/license/mit), see [LICENSE.md](LICENSE.md).
