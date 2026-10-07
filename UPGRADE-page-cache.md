# Upgrading to contenir/contenir-page-cache-laminas-mvc

The package is now `contenir/contenir-page-cache-laminas-mvc` and the
namespace, including the Laminas module name, is
`Contenir\PageCache\Laminas\Mvc`, following the core package's rename to
`contenir/contenir-page-cache` (`Contenir\PageCache\`). No `class_alias`
shims are provided.

The package declares `conflict` (any version) with
`contenir/cache-laminas-mvc` and `contenir/contenir-cache-laminas-mvc`. It
does not replace them, because the namespace change means it cannot stand
in for either. Composer refuses to install old and new together, so sites
switch deliberately.

## Composer

```bash
composer remove contenir/contenir-cache-laminas-mvc \
  && composer require contenir/contenir-page-cache-laminas-mvc:^2.0@RC
```

If you still require `contenir/cache-laminas-mvc`, remove that instead.

## Module

`laminas/laminas-component-installer` registers the new module. If you list
modules by hand, replace `Contenir\Cache\Laminas\Mvc` with
`Contenir\PageCache\Laminas\Mvc` in `config/modules.config.php`.

## Settings

The `pagecache` keys are unchanged. Two behaviours differ:

- The admin's `pagecache.local.php` is now read on every request, so a change
  applies immediately even with a cached merged config. If it lives somewhere
  other than `config/autoload/pagecache.local.php`, set `pagecache.file`.
- If that file lists `routes`, they replace `pagecache.routes` instead of
  merging with them, as the Contenir admin presents them. A site that kept
  extra routes in its own config alongside admin-managed routes should move
  them into the admin's list.

## Code

`CacheStrategy::setOptions()` and `setRoutes()` are replaced by
`setRepository()`, which takes a
`Contenir\PageCache\CacheControlRepositoryInterface`. Only code that builds
the listener itself is affected; the factory wires it.

```diff
-use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
+use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
```

To cover fully qualified class names in code, strings and config across a
project in one pass:

```bash
grep -rlF 'Contenir\Cache\' module config tests | xargs sed -i 's/Contenir\\Cache\\/Contenir\\PageCache\\/g'
```

Configuration files may write the separator doubled (`Contenir\\Cache\\`),
so search for that form too.
