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

## Why it goes through the core's own writer, and why that needs a seam

Writing the file with `file_put_contents()` would produce the same bytes and a
different result: nothing would invalidate the caches that `SiteConfiguration`
and `SiteFinder` keep, and the uncovered-site-roots check below asks `SiteFinder`
a question about sites this run has *just* written. So the write goes through the
core API — and that API is one of the three places where the two supported core
versions differ enough to need a `Core12/` / `Core13/` split.
See [Core version aware code](core-version-aware-code.md).

The difference is a class that does not exist on the older version:

| Version | Writes through                                  | After the write                                                                                                     |
|---------|-------------------------------------------------|---------------------------------------------------------------------------------------------------------------------|
| 12.4.45 | `Core\Configuration\SiteConfiguration::write()` | Clears its own first level cache and its cache entry, in the method (`SiteConfiguration.php:343`). No event exists. |
| 13.4    | `Core\Configuration\SiteWriter::write()`        | Dispatches `SiteConfigurationChangedEvent` (`SiteWriter.php:139`).                                                  |

`SiteWriter` was extracted from `SiteConfiguration` for v13; on 12.4.45
`Classes/Configuration/SiteWriter.php` is simply not there, and neither is
`Configuration/Event/SiteConfigurationChangedEvent.php`. The *writing* is
otherwise the same code on both: `write()` merges an incoming configuration into
an existing `config.yaml` with `ArrayUtility::mergeRecursiveWithOverrule()` in
both versions (12.4: `SiteConfiguration.php:308`; 13.4: `SiteWriter.php:105`),
and `writeSettings()` hands the file name to `GeneralUtility::writeFile()` in
both.

What differs is what happens **after** it, and that is the part the seeder
depends on:

- On 13.4 the event has two `#[AsEventListener]` consumers,
  `SiteConfiguration::siteConfigurationChanged()` (`SiteConfiguration.php:302`)
  and `SiteFinder::siteConfigurationChanged()` (`SiteFinder.php:118`), so a
  freshly written configuration is visible to `SiteFinder` in the same PHP
  process without anyone asking.
- On 12.4.45 there is no event and `SiteFinder` has no listener at all: it fills
  `$this->sites` in its **constructor** (`SiteFinder.php:52`) and only refills it
  when `getAllSites(false)` is called explicitly (`SiteFinder.php:60`). A site
  written during the run is therefore invisible to a `SiteFinder` that was
  constructed before it, and the coverage question below would answer from a
  stale list.

The consequence for this extension: the seam is not only *which writer to call*
but *how to make the following read fresh*. The v12 implementation has to force
the refresh the v13 event does for it. `SiteConfigurationSeederTest` pins both
ends of that — `registeredSiteConfigurationWriterMatchesTheRunningCoreVersion()`
for the wiring, and
`aWrittenConfigurationIsFoundBySiteFinderInTheSameProcess()` for the effect,
because a stale answer is indistinguishable from a correct one in every other
respect.

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
`rootPageId` is not greater than zero **without a word** (12.4:
`SiteConfiguration.php:157`; 13.4: `SiteConfiguration.php:124`), and
`Site::__construct()` substitutes a default `languages` entry — `languageId: 0`,
title `Default`, locale `en_US.UTF-8` — and resolves an absent `base` to the
empty string.

Those two fallbacks were compared line by line on 12.4.45 and 13.4.34 and are
the same, so a minimal template that works on one version works on the other.
The `Site` classes as a whole are **not** identical, and the differences are
substantial rather than cosmetic: v13 adds site sets, `SiteTypoScript`,
`SiteTSconfig`, `getRawConfiguration()` and two more constructor arguments. What
is claimed here is the defaulting, not the class.

A *useful* template declares `base` and `languages`, because the defaults are a
site on `/` in "Default / en_US.UTF-8".

### `settings.yaml`

`settings.yaml` holds the site settings and is read for `Site::getSettings()` on
both supported versions — by `SiteConfiguration::getSiteSettings()` on 12.4
(`SiteConfiguration.php:270`) and by the dedicated `Site\SiteSettingsFactory` on
13.4, which 12.4 does not have at all. It is separate from `config.yaml` because
the backend writes it separately, and it is copied verbatim when the template
ships one.

It is written **after** the configuration, and both reasons hold on both
versions: `writeSettings()` does not create the site directory — it hands the
file name to `GeneralUtility::writeFile()`, which does not either — and it
signals nothing afterwards, so it cannot be the last thing that happens before
something reads the site back. On 13.4 that means it dispatches no
`SiteConfigurationChangedEvent`; on 12.4 there is no event to dispatch and
`writeSettings()` does not touch the caches `write()` clears.

### `dependencies:` needs no version handling, but does not mean the same thing

The site sets a site pulls in are declared under `dependencies:`, and **site sets
are a TYPO3 v13 feature**: `Classes/Site/Set/` does not exist in
`typo3/cms-core` 12.4.45, `Site` there has no `$sets` property and no
`getSets()`, and `Site::__construct()` on 12.4 never looks at the key. On 13.4 it
reads `$configuration['dependencies'] ?? []` into `$this->sets`
(`Site.php:136`).

That difference needs **no handling in this extension**, and the reason is worth
stating precisely rather than glossing: the key is not *transformed* on either
version, it is carried. `Site::getConfiguration()` returns the configuration array
as it was read on both versions, so a template carrying `dependencies:` is written
unchanged, read back unchanged and reaches `getConfiguration()['dependencies']`
unchanged on 12.4 and 13.4 alike — it is simply inert on the older one, where
nothing resolves a set from it.
`SiteConfigurationSeederTest::aTemplateCarryingSiteSetDependenciesIsWrittenOnBothCoreVersions()`
asserts exactly that, so a version which starts rejecting or rewriting the key is
found here rather than in an installation.

What a set author has to know is therefore not a syntax rule but a scope one: a
template declaring `dependencies:` produces a site that pulls in those sets on
v13 and a site that ignores them on v12. If the seeded frontend depends on a set,
the set is a v13-only feature of that seed.

There is consequently **no version difference to apply to the finished array**.
Where one turns up it belongs right before the write call, with a `@todo`
naming the condition under which it goes away, because configuration is the
[documented exception](core-version-aware-code.md#configuration-is-the-exception)
to the "split the class" rule.

### How a template is read

With `Core\Configuration\Loader\YamlFileLoader`, not with a plain YAML parse,
for two reasons:

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

`load()` and the `PROCESS_IMPORTS` flag are spelled identically on 12.4.45 and
13.4.34, so nothing about *using* the loader is version dependent. **Constructing
it is** — and it is the third `Core12/` / `Core13/` seam of this extension. On
13.4 the class is `readonly` with `__construct(private LoggerInterface $logger)`;
on 12.4 it is a plain class implementing `LoggerAwareInterface` through
`LoggerAwareTrait`, with no constructor and a `setLogger()` to call. Passing a
logger to the constructor on 12.4 does not fail — PHP discards arguments to a
class that has none — it leaves `$this->logger` unset, which turns the first
report into a fatal error instead of the exception it was supposed to become.
See [`ThrowOnErrorLogger`](seeding.md#errors-are-not-logged-they-are-raised) for
what that logger is for.

### Where templates may live

`YamlFileLoader` resolves through `GeneralUtility::getFileAbsFileName()`, which
refuses a path outside `Environment::getProjectPath()` and
`Environment::getPublicPath()`. A template therefore has to sit inside the
project — which every package of an installation does, and which is the same
constraint the seed definition itself is already read under.

## An existing identifier is refused

The core writer **merges**, on both versions. When `config.yaml` already exists
it loads the unprocessed file, diffs the incoming configuration against the
processed one and applies only the modified and removed keys with
`ArrayUtility::mergeRecursiveWithOverrule()` — 12.4:
`SiteConfiguration::write()`, 13.4: `SiteWriter::write()`, the same sequence of
statements in both. That is right for the backend site module, where the file is
the source of truth and the form supplies changes.

Seeding a set into an installation that already has a site of that identifier
would therefore produce neither the template nor the previous configuration, but a
silent hybrid of both. `SiteConfigurationSeeder` refuses instead, naming the
identifier and saying why, and the message says to remove the site first if the
seed is meant to replace it.

`delete()` is not the answer either, and it is not on either version: it unlinks
`config.yaml` and leaves the directory and its `settings.yaml` behind (12.4:
`SiteConfiguration::delete()`, 13.4: `SiteWriter::delete()`) — and deleting an
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
  `DOKTYPE_LINK`, `DOKTYPE_SHORTCUT`, repeated from its `$allowedPageTypes`,
  which is `[DOKTYPE_DEFAULT, DOKTYPE_LINK, DOKTYPE_SHORTCUT]` on 12.4.45 and
  13.4.34 alike — the whole `processDatamap_afterDatabaseOperations()` differs
  between the two versions only in which writer it reaches for and in one
  `writelog()` argument). Reading it back makes the answer true for the record
  that was actually written, defaults and all — and keeping the page-type list
  means a seeded sysfolder on the page tree root is not reported as a broken
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
  this run counts as coverage — which it does only because the write went through
  the core writer and the caches of `SiteConfiguration` and `SiteFinder` were
  invalidated behind it. This is the read that makes the writer choice above load
  bearing rather than tidy, and it is why the v12 half of that seam has to force
  a refresh the v13 event performs on its own.

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
