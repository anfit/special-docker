# Runtime dependencies

No framework, ORM or DI container. Two third-party code dependencies, installed differently on purpose.

## Parsedown (vendored)

- Upstream: [erusev/parsedown](https://github.com/erusev/parsedown), version `1.8.0`, revision `96baaad00f71ba04d76e45b4620f54d3beabd6f7`
- Source: `src/ThirdParty/Parsedown.php`; licence `src/ThirdParty/Parsedown.LICENSE.txt` (MIT)
- Installation: none, a single file copied into the repository and the image.

`src/Markdown.php` is an adapter, not a second parser: it creates Parsedown with safe mode enabled and narrows the link whitelist to `http://` and `https://`. It is used only at the browser display boundary; storage, MCP results, resources, form controls and the copy button use the original Markdown source.

When upgrading Parsedown, replace the vendored source and licence from an upstream tagged release, update the version assertion in `tests/markdown_contract_test.py`, review the upstream security guidance and run the complete test set.

## AWS SDK for PHP (Composer, build time)

- Package: [`aws/aws-sdk-php`](https://github.com/aws/aws-sdk-php), constraint `^3.320` in `composer.json`
- Used for: DynamoDB requests (`DynamoDbClient`, `Marshaler`), SigV4 signing, retries, and EC2 instance-profile credentials.
- Installation: `composer install --no-dev` inside the Docker build (the `composer:2` stage); the resulting `vendor/` is copied into the runtime image. Nothing is fetched at runtime or during a deployment.
- Loaded lazily by `special_store()`, only when `SPECIAL_STORE=dynamodb`. The in-memory test store and the Python contract tests do not need it.
- **Reproducibility:** `composer.lock` is committed, so builds are repeatable (it was resolved against a PHP 8.3 platform, matching the image).
- Upgrading: `composer update aws/aws-sdk-php`, run `tests/behavior_test.php dynamodb` against DynamoDB Local, rebuild.

## Base images

- `php:8.3-apache` (runtime) and `composer:2` (build stage): rebuild regularly for security fixes.
- `amazon/dynamodb-local` (local compose file only).
