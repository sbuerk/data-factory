# Seeding

How a seed set becomes records in the database, and why each step is the way it
is. The two formats a set is written in have their own page -
[Seed definitions](../development/seed-definitions.md) - the engine that reads
the scenario format has
[another](scenario-engine.md), and the command surface around both is described
in [Seed sets and the CLI](../development/seed-sets.md). This page is about the
mechanism between them.

Everything here was established by reading the core on disk, on **TYPO3 12.4.45
and 13.4** - the two versions this branch supports - and every claim names the
file it came from so the next reader can check it instead of trusting it. The
class docblocks carry the same citations with line numbers; line numbers move,
the cited method names do not. Where the two versions genuinely differ, both
are named; where a claim was checked on one of them only, it says so.

## The pipeline

One import runs in this order, and the order is a consequence of what each step
needs from the one before it:

| Step                       | Class                     | Needs from the previous step                                   |
|----------------------------|---------------------------|----------------------------------------------------------------|
| Discover the set           | `SeedSetRepository`       | -                                                              |
| Parse the descriptor       | `SeedDefinitionParser`    | the path of the `config.yml`                                   |
| Compose the scenario       | `ScenarioComposer`        | the parsed descriptor and its `scenarios` list                 |
| Build the maps             | `DataHandlerFactory`      | the merged scenario settings                                   |
| Check the suggested uids   | `UidCollisionDetector`    | `getSuggestedIds()` of the factory                             |
| Copy the files             | `FileSeeder`              | -                                                              |
| Write the records          | `ScenarioSeeder`          | the factory, an admin backend user, the uids to hold back      |
| Attach the file references | `FileReferenceSeeder`     | the `sys_file` uids and the uids the records were written with |
| Write the sites            | `SiteConfigurationSeeder` | the written uids, for `rootPageId`                             |

The last four rows are the **four writing passes** of a run - files, records,
file references, site configurations - and their order is not a preference.
`FileSeeder` and `FileReferenceSeeder` are called by `ScenarioSeeder` around the
record pass, so the three of them are one `ScenarioSeedResult`; the sites are
written by the command afterwards, from the uids that result reports.

`ScenarioComposer` and `DataHandlerFactory` run **before** anything is written,
and they run in the command as well as in the seeder. That is deliberate:
composing is what validates everything only the factory can see - a duplicate
suggested uid, an item without `self`, a version variant declaring an `id` - so
a set that cannot be written fails before its first row exists, and a dry run
does the work a real run does minus the writing.

The writing itself is `DataHandlerWriter`, the second ported class: one
`process_datamap()` pass per workspace, then one `process_cmdmap()` pass per
workspace, with `NEW…` identifiers substituted by the uids the previous pass
produced. What `ScenarioSeeder` adds around it is the subject of the sections
below.

## Why it goes through DataHandler

Writing rows directly would be faster and would produce something that is not a
page tree. `DataHandler` is what generates slugs, applies the TCA defaults and
`eval` rules, computes `sorting`, resolves relations, updates the reference
index and flushes the caches. A seeder writing SQL has to reimplement all of
that, and gets it subtly wrong - in ways that are visible in the frontend weeks
later and not in the seed definition.

The price is that DataHandler is built for a backend form, and several of its
behaviours are wrong for a seed. Each of the sections below is one of them.

## The admin user requirement

`ScenarioSeeder::seed()` refuses a backend user that is not an admin, and the
refusal is not a permission policy of this extension. `DataHandler::insertDB()`
evaluates a suggested uid behind `$this->BE_USER->isAdmin() &&` - *"As a
security measure this feature is available only for Admin Users (for now)"* -
and says nothing when the check fails.

A non-admin run therefore writes the page tree with whatever uids happened to be
free, reports success, and every site configuration, TypoScript condition or
test pointing at a root page by its number is wrong. That is exactly the class
of failure this extension exists to avoid, so it is turned into a refusal.

It bites hard, because **every** record of a scenario carries a suggested uid -
a declared `id` or a dynamic one from 10000 upwards. There is no subset of a
scenario that would survive a non-admin run intact.

`ImportSeedCommand` asks the same question before the seeder does, which is what
turns the exception into a sentence and an exit code of its own
(`EXIT_NO_ADMIN_USER`). The CLI application creates the `_cli_` user object
without logging it in; `Bootstrap::initializeBackendAuthentication()`
authenticates it and creates the record on first use, with `admin = 1`
(`Core\Authentication\CommandLineUserCreation`). An already authenticated user
is left alone, which is what lets a test drive the command as a user of its
choosing.

## Declaration order, and the negative pid

DataHandler places a new record at the **top** of its parent. Records created in
the order they are declared therefore come out reversed, and nothing says so -
the tree exists, it is simply upside down.

The convention DataHandler offers instead is a negative `pid`, meaning "directly
after this record": it strips the sign, resolves the placeholder and hands the
signed value to `resolveSortingAndPidForNewRecord()`.
`DataHandlerFactory::setInDataMap()` uses it, so only the **first** sibling
addresses its parent and every following one addresses the sibling before it.

The predecessor is looked up per workspace, per table and per resolved page, in
`filterDataMapByPageId()`. A negative pid names a record of the same table, and
the children of a page are a mix of sub pages, content elements and records of
other tables - pointing a content element at the page before it would place it
somewhere else entirely.

**A back reference is resolved through the data map of the record's own
table.** `typo3/testing-framework` hard-codes `pages` there, which made every
other table lose its declared order from the third record of a page onwards -
content elements declared 1, 2, 3 came out as 1, 3, 2. That is one of the
divergences of this port, and it is the single most consequential property of
the engine for anyone reading a seeded site. See
[A back reference resolved through the wrong table](scenario-engine.md#a-back-reference-resolved-through-the-wrong-table).

A **language variant of a node entity** is given the same convention - a `pid`
of `-<identifier of the original>` - so a translated page sits next to its
original rather than at the top of the page tree.

## Nothing is defaulted for the scenario

A seed should say what it writes rather than inherit whatever the TCA of an
installation happens to default to - and in this format **the scenario says
it**, in `entitySettings`. This extension adds nothing on its behalf. What the
engine writes into a row is the suggested `uid`, the node pointer, the parent
pointer and the sibling `pid`. Everything else comes from `defaultValues` and
from the declared values, in that order.

Two consequences reach an integrator, and both are stated on
[Seed definitions](../development/seed-definitions.md#hidden-is-not-defaulted-for-you):

- A new page is **hidden by default** on both supported versions, so a scenario
  that does not carry `defaultValues: {hidden: 0}` seeds a hidden page tree.
  Every TYPO3 Core scenario carries it, which is why nobody notices when copying
  one. Where the default comes from differs and does not matter: 12.4 declares
  `columns.hidden.config.default = 1` in the shipped
  `EXT:core/Configuration/TCA/pages.php` itself, 13.4 has no `hidden` column in
  that file and sets the same value from
  `EXT:core/Configuration/TCA/Overrides/pages.php` instead.
- `doktype`, `l10n_parent` and `sys_language_uid` come from the TCA of the
  installation. `l10n_parent` has a default of `0` on both, again from different
  places - declared in the shipped `pages` TCA on 12.4, supplied by
  `Core\Configuration\Tca\TcaEnrichment::enrichTransOrigPointerField()` on
  13.4, a class 12.4 does not have. `sys_language_uid` has no TCA default on
  either, which DataHandler covers with `addDefaultPermittedLanguageIfNotSet()`
  (12.4: `DataHandler.php:8385`; 13.4: `DataHandler.php:8256`).

The one place where relying on the TCA is not merely a matter of taste is
`Core\Hooks\CreateSiteConfiguration`. Its early return reads
`(int)$fieldValues['l10n_parent']` **before** it reaches
`|| $dataHandler->isImporting`, so the read happens for every seeded page even
though the hook does nothing afterwards - and it is unguarded on **both**
versions: `processDatamap_afterDatabaseOperations()` is byte identical on
12.4.45 and 13.4.34 down to the line, the two files differing only in which
writer `generateSiteConfigurationForRootPage()` reaches for. Exactly one TCA
override removing that default therefore separates a seeded page from an
`Undefined array key` warning - which this test suite turns into a failure. A
scenario that declares `l10n_parent: 0` in its `'*'` defaults is immune; this
extension does not declare it on the scenario's behalf.

## Suggested uids need both halves

A declared `id` is a *suggestion*, and DataHandler reads it from two places at
once:

- the `uid` column of the **data map row**, which is where `insertDB()` takes
  the suggestion from (it then drops the column again - *"Do NOT insert the UID
  field, ever!"* - so putting it there cannot write a uid by itself).
  `DataHandlerFactory::processEntityValues()` writes it;
- `DataHandler::$suggestedInsertUids`, keyed **`"<table>:<uid>"`** and not by the
  placeholder, because that is the key `insertDB()` looks up.
  `DataHandlerWriter::invokeFactory()` assigns it from `getSuggestedIds()`.

Getting either half wrong fails silently: DataHandler assigns the next free uid,
the run reports whatever it got, and the result is right only as long as
declaration order happens to equal insertion order.

That the writer assigns `suggestedInsertUids` **itself** is why the port gained
its one additive parameter. `--force` has to hold back the suggestions of a
colliding table, and there is no moment between "the writer was constructed" and
"the uid is read" in which a caller could reduce the array from the outside; so
`DataHandlerWriter::__construct()` takes the set to withhold. See
[A withheld set of suggested uids](scenario-engine.md#a-withheld-set-of-suggested-uids).

A suggestion is not a demand in the other direction either. Leaving it in for a
uid another row already holds does not write the record elsewhere; it makes the
`INSERT` fail on the primary key, because DataHandler forces the uid into the
field array. What happens then is a **silent** half-import on both supported
versions: `insertDB()` logs the failure as a `SYSTEM_ERROR` *"SQL error"* entry
and returns `null` (12.4: `DataHandler.php:7794`; 13.4: `DataHandler.php:7791`),
and every call site discards that return value (12.4: `DataHandler.php:1148`
and `:1152`; 13.4: `DataHandler.php:854` and `:858`). Nothing throws, nothing
stops, and the run continues with a record that was never written.

That is exactly the failure mode this extension refuses to inherit, which is why
there is a `UidCollisionDetector` at all - the collision is found **before**
anything is written rather than reconstructed from an error log afterwards - and
why `--force` drops the suggestions of the colliding table instead of overriding
the refusal; see
[Seed sets and the CLI](../development/seed-sets.md#uid-collisions-and---force).
The error log is the second net: `ScenarioSeeder` raises it rather than
returning it, see [below](#errors-are-not-logged-they-are-raised).

### Reading back what was written

`ScenarioSeeder` needs to know which uid a declared `id` ended up under, because
a site configuration names its root page by that number and `--force` may have
taken the suggestion away.

`DataHandler::$substNEWwithIDs` is the only place that answer exists, which is
why the seeder constructs `DataHandlerWriter` with a `DataHandler` of its own
rather than through `DataHandlerWriter::withBackendUser()`: that named
constructor makes the instance and keeps it private. The second reason is
`isImporting`, which has to be set before the first `start()`. What the named
constructor adds on top - carrying `uc['copyLevels']` into
`DataHandler::$copyTree` - applies to a `copy` command, and the command map a
scenario produces knows `move`, `delete` and `version` only.

A record of a later workspace round whose key was already substituted has no
entry in `substNEWwithIDs`: it updated an existing row rather than inserting
one, and is passed over.

## The file pass

`FileSeeder` runs **before** the records: it copies the files a set declares into
a storage through the FAL API - which is what indexes them, a file copied into
`fileadmin/` with `cp` exists on disk and does not exist for TYPO3 - and returns
the `sys_file` uid each one was indexed under, keyed by the identifier the
descriptor gave it.

It has to run first because a `sys_file_reference` needs a `uid_local` that
exists, and because that uid is the one thing about a seeded file that **cannot
be declared**: the FAL indexer assigns it while the file is being placed. That
single fact is what keeps files out of the scenario format entirely, and it is
why `config.yml` carries a `references:` list rather than the scenario carrying a
relation - see
[File references](../development/seed-definitions.md#file-references).

## The file reference pass

`FileReferenceSeeder` runs **after** the records, in a second `DataHandler` pass
of its own, and both halves of that sentence are load bearing.

### Why it is a separate pass

A `sys_file_reference` carries the record it belongs to in `uid_foreign`, and
that column is a plain integer - not a relation DataHandler resolves. A `NEW…`
placeholder written into it stays a string, is read as `0`, and the reference
silently belongs to **record 0**, with an empty `errorLog`. Nothing fails, and
the images are simply not on the record.

There is therefore no data map in which a reference and the record it hangs on
can be described at the same time: the record has to have been written before
its references can be expressed at all. That is the reason for the pass, and it
is the same reason from the other side as the file pass has - the `sys_file` uid
is not knowable in advance either.

The pass builds one data map from the whole `references:` list, so a set with
twenty references makes one `process_datamap()` call, and reports the
`sys_file_reference` uid of every declared reference in declared order.

### Why the relation field of the parent is written too

Two things go into that data map per relation, and the second one is what is
easy to leave out:

- the `sys_file_reference` row itself, under a `NEW…` placeholder, carrying
  `uid_local`, `uid_foreign`, `tablenames`, `fieldname` and `pid`;
- **the relation field of the parent record**, as the comma separated list of
  those placeholders - a plain update, keyed by the parent's uid.

Without the second, DataHandler sees no relation to resolve,
`RelationHandler::writeForeignField()` never runs, and every seeded reference
keeps a `sorting_foreign` of `0`.

What that costs is invisible, which is why it is worth stating: in the frontend
`FileRepository::findByRelation()` selects the references by
`uid_foreign`/`tablenames`/`fieldname` and only **orders** by `sorting_foreign`,
so the files appear either way. All that is lost is the *order* of a multi file
relation - to whatever the database feels like returning that day. The functional
test asserts the column for exactly that reason.

Writing the parent field is also what puts the count of the relation into the
parent's column: DataHandler calls `writeForeignField()` and then
`countItems(false)`, and stores the result there.

### Two details that cost debugging time

**The placeholder carries no underscore.** It is `NEWsysfilereference-1`, not
`NEWsys_file_reference_1`, because `DataHandler::processRemapStack()` reads a
relation value containing an underscore as the `<table>_<uid>` form and splits it
there. The obvious placeholder would be taken apart into a table
`NEWsys_file_reference` and an id `1`, neither of which resolves - and the
relation would be written empty, again with an empty error log.

**The structural columns are merged over the declared ones.** `uid_local`,
`uid_foreign`, `tablenames`, `fieldname` and `pid` win over anything `values:`
declares, so a descriptor cannot detach a reference from the record it declares
it on. `pid` is the parent's page, and for a parent that *is* a page it is that
page itself - a site root has a `pid` of `0`, and taking it would put the
reference outside the tree.

### What it refuses

A reference naming a record the run did not write is an error, not a lookup.
`data-factory:import` checks the whole list against the suggested ids of the composed
scenario **before** anything is written, so a mistyped uid does not surface after
the page tree, the content and the files are in the database.

`FileReferenceSeeder` asks `ScenarioSeedResult` again, and that call does more
than check: it *translates*. The `uid` a descriptor declares is what the scenario
declared, and `--force` gives up the suggestions of a colliding table, so the
record may well have been written under a different number. The reference is
attached to the number the run produced, never to the declared one - which is the
same rule `sites[].rootPage` follows, minus the refusal, because a reference on a
differently numbered record is still a reference on the right record.

A non-empty `errorLog` after the pass becomes a `SeedingFailedException`, exactly
as it does for the record pass.

## Inline relations need no pass at all

An inline relation is expressible in the scenario format as it stands, and this
extension adds nothing for it: the parent declares its relation field as the
comma separated list of the declared ids of its children, those ids are suggested
uids, and DataHandler resolves the list exactly as it resolves the one a backend
form submits. `parentid`, `parenttable` and `sorting_foreign` are written by
`RelationHandler::writeForeignField()` from the TCA of the parent field.

The mechanism, its order guarantee and the proof are on
[Seed definitions](../development/seed-definitions.md#inline-relations-need-no-support).
It is worth knowing here because it is the contrast that explains the reference
pass: what an inline child has and a file reference does not is a uid that can be
written down before the run.

## `isImporting`, and what it costs

`ScenarioSeeder` sets `DataHandler::$isImporting` before the first `start()`. It
is the flag that suppresses the `autogenerated-<uid>` site configuration TYPO3
writes for every new page on the page tree root or carrying `is_siteroot` that
translates no other page - `Core\Hooks\CreateSiteConfiguration`, whose early
return ends in `|| $dataHandler->isImporting` on 12.4.45 and 13.4.34 alike (both
at `CreateSiteConfiguration.php:75`). It is the same flag `EXT:impexp` sets for
the same reason, six times in its `Import.php` on both branches. It is not
deprecated: searching the changelog sets shipped with `typo3/cms-core` 12.4 and
13.4 for `isImporting`, `CreateSiteConfiguration` and `processDatamapClass`
returns nothing at all.

It is a **public flag with a handful of other consumers**, so adopting it blind
is not acceptable. Enumerated on both versions - and here the lists are *not*
identical, which is the one thing about this flag that changed between them:

| Consumer                                               | Effect                                                                             | 12.4 | 13.4 | Verdict        |
|--------------------------------------------------------|------------------------------------------------------------------------------------|------|------|----------------|
| `Core\Hooks\CreateSiteConfiguration`                   | no automatic site configuration                                                    | yes  | yes  | the point      |
| `Backend\Hooks\DataHandlerAuthenticationContext`       | skips sudo mode and authentication context evaluation                              | yes  | yes  | neutral        |
| `Core\Resource\Security\FilePermissionAspect`          | lets `sys_file` be written through DataHandler at all                              | yes  | yes  | neutral        |
| `Core\Resource\Security\FilePermissionAspect`          | skips `usesDisallowedFileMount()` for `sys_file_reference` and `sys_file_metadata` | yes  | yes  | **beneficial** |
| `Core\Resource\Security\FileMetadataPermissionsAspect` | allows a `sys_file_metadata` record with an empty `file`                           | yes  | yes  | neutral        |
| `Core\Hooks\UpdateFileIndexEntry`                      | skips re-indexing after a new `sys_file_metadata` record                           | -    | yes  | neutral        |
| `Core\Hooks\BackendUserPasswordCheck`                  | no generated password and `autogenerated-<md5>` username for a `be_users` record   | yes  | yes  | **caveat**     |
| `DataHandler`                                          | the flag is handed on to the copy handler                                          | yes  | yes  | neutral        |

`Core\Hooks\UpdateFileIndexEntry` does not exist in `typo3/cms-core` 12.4.45 at
all - `Classes/Hooks/` there holds eight files and that is not one of them - so
the v12 leg has one consumer fewer. It makes no difference here for the same
reason the row is neutral on v13: no `sys_file_metadata` record is written by a
seed.

The other neutral ones are neutral for a reason each: the authentication context
hook returns immediately without a backend request and seeding runs on the CLI;
files are seeded through the storage API rather than through DataHandler; nothing
is copied.

The **beneficial** one is why `FileReferenceSeeder` sets the same flag on its own
DataHandler. Without it, `FilePermissionAspect::processDatamap_preProcessFieldArray()`
nulls the entire field array of a `sys_file_reference` whose `uid_local` sits in
a folder the backend user has no read file mount for, and logs an error. An admin
passes that check today, so setting the flag in the reference pass is a guard
rather than a fix - but the two passes belong to one import, and declaring the
run an import in only one of them is an inconsistency waiting to be found the
hard way.

The **caveat** is real and has to be stated in the user documentation: a seed
set writing a `be_users` record has to declare `username` and `password` itself,
and gets a user that cannot log in if it does not.

The flag is set **unconditionally**, not only for a definition that declares
`sites`. An `autogenerated-<uid>` site is never what a seed wanted, and a set
declaring no site is a set whose site configuration comes from elsewhere - not
an invitation to invent one. What that leaves behind is reported rather than
silently accepted; see
[Site configurations](site-configuration.md#the-uncovered-site-roots-report).

## Errors are not logged, they are raised

DataHandler reports a refused write by logging it and carrying on, so a run that
wrote half a page tree looks exactly like one that wrote all of it. The port
inherits that: `DataHandlerWriter::invokeFactory()` returns `void` and collects
`DataHandler::$errorLog` into `getErrors()`, and a caller that does not look
never learns.

`ScenarioSeeder` looks. A non-empty error list becomes a
`SeedingFailedException` naming the set and the distinct messages, which is what
makes the difference visible. An empty data map is refused for the same reason
before anything runs - a set that writes nothing is a set whose scenario was not
read the way it was meant.

The same reasoning applies one level up, in the parser:
`YamlFileLoader::processImports()` catches its exceptions per import and reports
them to a logger on both supported versions (12.4:
`YamlFileLoader.php:171`; 13.4: `YamlFileLoader.php:180`, the same
`$this->logger->error(...)` call inside the same `catch`). For a site
configuration that is a reasonable trade - the site still loads, minus an
optional include. For a seed descriptor it is data loss, so `ThrowOnErrorLogger`
raises the report back into an exception. Only error level and above, so a future
core version logging something informational from the loader does not turn a
working definition into a failure.

**Getting that logger into the loader is core version aware**, and it is one of
the three `Core12/` / `Core13/` seams of this extension. On 13.4 `YamlFileLoader`
is a `readonly class` taking `LoggerInterface` as its only constructor argument;
on 12.4 it is a plain class implementing `LoggerAwareInterface` via
`LoggerAwareTrait`, with no constructor at all - so the logger arrives through
`setLogger()`. Passing it to the constructor there does not raise anything: PHP
discards arguments to a class without a constructor, `$this->logger` stays unset,
and the first report that was meant to become a `SeedingException` becomes a
fatal error instead. `load()` itself and the `PROCESS_IMPORTS` flag are spelled
identically on both versions, so only the construction is split.
See [Core version aware code](core-version-aware-code.md).

## The layout of `Classes/Seeding/`

| Directory       | What lives there                                                                                                                                                                                |
|-----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Definition/`   | The parsed **set descriptor** and its parts: `SeedDefinition`, `SeedFile`, `SeedFileReference`, `SeedSiteConfiguration`. Data, `#[Exclude]`, no dependencies.                                   |
| `Parser/`       | `SeedDefinitionParser` and the `ThrowOnErrorLogger` it hands to `YamlFileLoader`.                                                                                                               |
| `Scenario/`     | The ported engine - `DataHandlerFactory`, `DataHandlerWriter`, `EntityConfiguration` - and `ScenarioComposer`, which is ours and composes the files of a set.                                   |
| `DataHandling/` | Everything that writes: `ScenarioSeeder`, `FileSeeder`, `FileReferenceSeeder`, `SiteConfigurationSeeder`, plus `UidCollisionDetector`, the `OccupiedUid` it reports and the two result objects. |
| `Exception/`    | `SeedingException` and its five subclasses, so a caller can tell "unknown set" from "invalid definition".                                                                                       |
| root            | `SeedSetRepository` and the `SeedSet` it returns - discovery, which is deliberately not parsing.                                                                                                |

The three **version aware seams** of this branch sit in that layout rather than
beside it: `SeedYamlFileLoaderInterface` in `Parser/`, `FileImporterInterface`
and `SiteConfigurationWriterInterface` in `DataHandling/`, each with one
implementation in `Core12/` and one in `Core13/` and no consumer that knows
which of them it got.
→ [Core version aware code](core-version-aware-code.md)

Three properties of that layout are worth naming because they are easy to erode:

- **`Definition/` depends on nothing.** No service, no TYPO3 API, no other part
  of this extension - which is what would let the descriptor model be extracted
  into a package of its own later. It models the *set*, not its records, which is
  why it holds no record type at all. `SeedFileReference` is not a counter
  example: it declares which seeded *file* hangs on which record, which is a
  statement about the set and not a record of a scenario.
- **`Scenario/` is a boundary.** Three of its four classes are upstream's and
  are held to what upstream does by a conformance test, minus a written list of
  divergences that fix upstream defects; anything this extension *invents*
  belongs beside them, not inside them. `ScenarioComposer` is on the
  right side of that line: it composes and transforms *input*, and hands a
  finished settings array to an unmodified factory.
- **Discovery does not parse.** `SeedSetRepository` reads three keys with
  `Yaml::parseFile()` and stops. Parsing every set in full to show a title would
  make `data-factory:list` as fragile as the least well maintained set in the
  installation; see
  [Seed sets and the CLI](../development/seed-sets.md#discovery-reads-metadata).

A field needs **no support anywhere in this pipeline to be seedable**. Every
declared value is copied into the data map untouched, through
`EntityConfiguration::processValues()` and nothing else. That is a design
decision made of the absence of a branch - a factory special-casing a field it
does not have to will special-case the next one too - and it is now upstream's
absence of a branch as well as ours.

## See also

- [The scenario engine](scenario-engine.md) - the port, its divergences and the conformance test
- [Seed definitions](../development/seed-definitions.md) - the two formats
- [Seed sets and the CLI](../development/seed-sets.md) - discovery, ordering, the commands
- [Site configurations](site-configuration.md) - what runs after the records
- [Class design](class-design.md) - why the definition objects carry `#[Exclude]`
- [Dependency injection](dependency-injection.md)
