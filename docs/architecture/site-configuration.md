# Site configurations

A seed set describes a page tree, and a page tree without a site configuration is
a frontend that cannot render. `SiteConfigurationSeeder` is what closes that gap,
and it runs last for a reason: the one value a site configuration cannot be
written without is the uid of a page the same definition creates.

The keys a `sites` entry declares are specified in
[Seed definitions](../development/seed-definitions.md#site-configurations). This
page is about what happens to them.

## Why it runs after the records

`rootPageId` is the uid of a seeded page. A scenario record has no symbolic
identifier - the `id` its entity declares *is* its handle - so a `sites` entry
names that number, and this class resolves it against
`ScenarioSeedResult::writtenUid('pages', …)`, the map of declared uid to written
uid that `ScenarioSeeder::seed()` returns.

Resolving it rather than trusting it is not ceremony. The declared uid is
missing from that map when no `pages` entity of the scenario declares it, and it
differs from what was written when `--force` gave up the uid suggestions of the
`pages` table. Both would produce a site pointing at a page this set never
seeded, so both are refused - the first by the command before anything is
written, the second by refusing a forced run that would give up the suggestions
of the `pages` table.

That second refusal is narrower than "a set declaring sites cannot be forced",
and deliberately so. It asks three things: that site configurations are being
written at all, that the set declares any, and that the run actually gives up a
**page** uid. A collision in another table leaves the page tree exactly as
declared, so the site still names the page it was going to name and the run is
refused by nothing; `--no-site-config` writes no file naming a number, so the
same collision becomes the ordinary forced one. Both boundaries are pinned by a
test, because a guard that is too wide is as wrong as one that is too narrow
and nothing about it is visible from its result.
See [Uid collisions and `--force`](../development/seed-sets.md#uid-collisions-and---force).

`--root-page` does not enter into any of this, and that is worth one sentence
because it looks as though it should. It rewrites the `pid` of the top level
items of the scenario and nothing else, so the declared uid of the site root is
untouched: the site is written for the same page it names without the option,
which is now a page inside another tree - a legal site root for TYPO3, and a
sub-site rather than a broken one.

The resolved uid **always wins** over whatever the template declares. That is not
a merge policy but the point of the construct: a template is a file someone
copied out of a working installation, its `rootPageId` names a page of *that*
installation, and a template that could point the site somewhere else would make
the seeded tree unreachable in exactly the way this extension exists to prevent.
The test fixture declares `rootPageId: 999` for a page that does not exist, so
the override is asserted rather than assumed.

## Why it goes through `SiteWriter`

`TYPO3\CMS\Core\Configuration\SiteWriter` is the only supported writer, and it is
a container service on v13.4 and v14.3 alike — both register it in
`Core/Classes/ServiceProvider.php::getSiteWriter()` with the very same three
arguments — so it is injected by type and no path handling is needed here.

Writing the file with `file_put_contents()` would produce the same bytes and a
different result. `write()` ends with

```php
$this->eventDispatcher->dispatch(new SiteConfigurationChangedEvent($identifier));
```

and both `SiteConfiguration::siteConfigurationChanged()` and
`SiteFinder::siteConfigurationChanged()` listen to it and flush their caches.
Going through `SiteWriter` is what makes a freshly written configuration visible
to `SiteFinder` **in the same PHP process** — which the run itself needs, because
the uncovered-site-roots check below asks `SiteFinder` a question about sites
this run has just written.

## What a template is

A directory holding a `config.yaml` and optionally a `settings.yaml` — which is
exactly the shape of a site below the instance's `config/sites/`. A template can
therefore be produced by copying a working site out of an installation, and it is
read with the loader TYPO3 reads its own site configurations with.

The default location is `Sites/<identifier>/` next to the entry file of the set.
The parser fills that in when a definition declares no `template`, so the value is
never empty and no consumer has to know the default.

### The minimum, and what is worth declaring

A minimal `config.yaml` needs exactly one key — `rootPageId` — and that is the one
key the seeder overwrites anyway. Everything else has a fallback:
`SiteConfiguration::resolveAllExistingSites()` skips a configuration whose
`rootPageId` is not greater than zero **without a word**, and
`Site::__construct()` substitutes a default `languages` entry and an empty `base`
for what is missing. Both are byte identical on 13.4 and 14.3 — the only
difference between the two `Site` classes is a `@todo` comment — so a template
that works on one version works on the other.

A *useful* template declares `base` and `languages`, because the defaults are a
site on `/` in "Default / en_US.UTF-8".

### `settings.yaml`

`settings.yaml` holds the site settings — the values `SiteSettingsFactory` reads
for `Site::getSettings()`, and where the site-local overrides of site set settings
are persisted (`SiteSettingsService` on both versions calls that file *"our
persistence target"*). It is separate from `config.yaml` because the backend
writes it separately, and it is copied verbatim when the template ships one.

It is written **after** `write()`, and both reasons are in `SiteWriter`:
`writeSettings()` does not create the site directory — it hands the file name to
`GeneralUtility::writeFile()`, which does not either — and it dispatches no
`SiteConfigurationChangedEvent`, so it cannot be the last thing that happens
before something reads the site back.

### `dependencies:` is not a v14 key

The site sets a site pulls in are declared under `dependencies:`, and that needs
**no version handling**: `Site::__construct()` reads
`$configuration['dependencies'] ?? []` into `$this->sets` on 13.4 and 14.3 alike.
What v14.3 adds is a fourth `array $dependencies = []` argument to
`SiteWriter::createNewBasicSite()`, which this class does not call, and route
enhancers resolved from sets in `SiteConfiguration`.

A template carrying `dependencies:` is therefore written unchanged on both
versions and understood on both — asserted by a functional test rather than
assumed, so that a version which starts rejecting the key is found here.

There is consequently **no version difference to apply to the finished array**.
Where one turns up it belongs right before the `write()` call, with a `@todo`
naming the condition under which it goes away, because configuration is the
[documented exception](core-version-aware-code.md#configuration-is-the-exception)
to the "split the class" rule.

### How a template is read

With `YamlFileLoader`, not with a plain YAML parse, for two reasons:

- it resolves `EXT:` paths and follows `imports:`, and following them **inlines**
  the imported content into the written file. An `imports:` kept verbatim would
  point at paths relative to the template directory and break the moment the file
  lands in `config/sites/`;
- placeholders are deliberately **not** processed. `%env(…)%` in a site
  configuration is meant to be resolved by the instance every time it reads the
  file, so resolving it here would bake the seeding machine's environment into the
  seeded site.

This is the opposite decision from the one the seed definition parser makes about
placeholders, and for the same underlying reason: a seed definition is content and
has to arrive as written, a site configuration is configuration and has to stay
resolvable.

### Where templates may live

`YamlFileLoader` resolves through `GeneralUtility::getFileAbsFileName()`, which
refuses a path outside `Environment::getProjectPath()` and
`Environment::getPublicPath()`. A template therefore has to sit inside the
project — which every package of an installation does, and which is the same
constraint the seed definition itself is already read under.

## An existing identifier is refused

`SiteWriter::write()` **merges**. When `config.yaml` already exists it loads the
unprocessed file, diffs the incoming configuration against the processed one and
applies only the modified and removed keys with
`ArrayUtility::mergeRecursiveWithOverrule()`. That is right for the backend site
module, where the file is the source of truth and the form supplies changes.

Seeding a set into an installation that already has a site of that identifier
would therefore produce neither the template nor the previous configuration, but a
silent hybrid of both. `SiteConfigurationSeeder` refuses instead, naming the
identifier and saying why, and the message says to remove the site first if the
seed is meant to replace it.

`SiteWriter::delete()` is not the answer either: it unlinks `config.yaml` and
leaves the directory and its `settings.yaml` behind — and deleting an
installation's hand-maintained site configuration is not a seeder's decision to
make.

## The uncovered-site-roots report

Seeding suppresses the `autogenerated-<uid>` site configuration TYPO3 writes for
every new site root, **unconditionally** and not only for a set that declares
`sites` — see [`isImporting`](seeding.md#isimporting-and-what-it-costs). That is
right, because an `autogenerated-<uid>` site is never what a seed wanted. It also
turns a set which seeds a site root and declares no site configuration into an
installation with a page tree and no frontend that can render it.

Nothing in the writing path notices that, so `SiteConfigurationSeeder` looks for
it afterwards and reports what it finds through
`SiteConfigurationSeedResult::$uncoveredSiteRoots`. It is collected rather than
logged where it is found: the seeder has no output channel, the command has, and a
warning naming the root pages is the difference between *"this seed is
incomplete"* and *"the frontend is broken and nothing said why"*.

Four details decide what the report is worth:

- **"Site root" is the core's definition, read back out of the database** rather
  than derived from the definition: a page on the page tree root or carrying
  `is_siteroot` that translates no other page, of a page type
  `CreateSiteConfiguration` would have acted on (`DOKTYPE_DEFAULT`,
  `DOKTYPE_LINK`, `DOKTYPE_SHORTCUT`, repeated from its `$allowedPageTypes` and
  identical on both versions). Reading it back makes the answer true for the
  record that was actually written, defaults and all — and keeping the page-type
  list means a seeded sysfolder on the page tree root is not reported as a broken
  frontend.
- **A translation is never a site root.** It is a `pages` row of its own on the
  `pid` of its original, and `is_siteroot` is `l10n_mode` `exclude`, so
  `DataHandler` copies it from the original. A translation therefore meets the
  page tree root and `is_siteroot` conditions whenever its original does.
  `CreateSiteConfiguration` passes it over by its `l10n_parent`, and so does the
  report: `SiteFinder::getSiteByPageId()` asked with the uid of the translation
  of a site's root page does not find that site, and whether a translation is
  reachable is decided by its original and the languages of the original's site
  anyway. A site root with translations that no site covers is reported once, by
  its own uid, and its translations are not. A translation of a page the run
  did not write is not reported at all.
- **Coverage is asked as `SiteFinder::getSiteByPageId()`**, not as
  `getSiteByRootPageId()`. The question is whether a frontend can render the tree,
  not whether the page is a site root of its own: a page seeded with `is_siteroot`
  below the root page of an existing site is reachable through that site, and
  reporting it would be a false alarm.
- **It runs after every declared site has been written**, so a site written in
  this run counts as coverage — which it does, because `SiteWriter` flushed the
  caches of `SiteConfiguration` and `SiteFinder` on its way out. This is the read
  that makes the `SiteWriter` choice above load bearing rather than tidy.

The pages are reported **by uid** - `page 11`, not `"home" (page 11)`. There is
no name to report: a scenario record's handle is its uid, and the `pages:` prefix
of the uid map is what tells a page apart from a `tt_content` record that happens
to carry the same number.

## See also

- [Seeding](seeding.md) — the pipeline this is the last step of
- [Seed definitions](../development/seed-definitions.md#site-configurations) — the keys
- [Seed sets and the CLI](../development/seed-sets.md) — `--base` and `--no-site-config`
- [Core version aware code](core-version-aware-code.md#configuration-is-the-exception)
- [Site based tests](../testing/site-based-tests.md)
