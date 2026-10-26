# The scenario engine

The seed format of this extension is the YAML **scenario format** of
`typo3/testing-framework` — the one TYPO3 Core's own functional tests are
written in. This page explains why the engine that reads it lives in
`Classes/Seeding/Scenario/` as a port rather than as a dependency, what was
changed in the process, and what keeps the port honest.

The format itself is documented key by key in
[Seed definitions](../development/seed-definitions.md), together with the
`config.yml` that names the scenario files of a set; this page is about the code
that reads it.

## Why the format, and not one of our own

The format already solves what a seeder needs and what a bespoke syntax would
have had to grow into: records of any table, a page tree, translations through
`languageVariants`, workspace records through `versionVariants`, DataHandler
commands through `actions`, stable uids through suggested ids, and sequential
sibling ordering. It is exercised by 60 fixtures across TYPO3 Core v12, v13 and
v14, which is a far better proving ground than anything this repository could
assemble.

It is also **stable**. The recognised key set is byte for byte identical across
testing-framework branches 7, 8, 9 and main, and across the Core fixtures of
v12, v13 and v14. What differs between those Core branches is TCA drift inside
the fixtures — `list_type` becoming a dedicated `CType`, `url` becoming `link` —
never the format. That is why the engine needs no core version aware split.

## Why it is ported and not required

`typo3/testing-framework` cannot be a production dependency of this extension:

```
typo3/testing-framework requires:
  phpunit/phpunit: ^11.2.5 || ^12.1.2 || ^13.0.2
  typo3/cms-backend|core|extbase|fluid|frontend: 13.*.*@dev || 14.*.*@dev
```

`data-factory:import` runs in real installations. Moving that package into `require`
would install PHPUnit on every site that seeds anything, and would pull five
sysexts in as hard runtime requirements. The classes themselves have no such
problem — `DataHandlerFactory` imports **only** `Symfony\Component\Yaml\Yaml`,
and `DataHandlerWriter` only `DataHandler`, `BackendUserAuthentication` and
`GeneralUtility`. Nothing in either of them touches PHPUnit, `Testbase` or
`FunctionalTestCase`. They are a leaf that happens to sit in a test package.

So the three classes — `DataHandlerFactory`, `DataHandlerWriter` and
`EntityConfiguration`, 779 lines of upstream code — are ported into
`SBUERK\DataFactory\Seeding\Scenario`, where the added array shape annotations
and the divergence comments bring them to roughly 1,070, and
`typo3/testing-framework` stays in `require-dev`.

The version this branch is a port of is `typo3/testing-framework` **9.6.1**, for
`-t 13` and `-t 14` alike — the same package on both legs, which is what lets
`UpstreamConformanceTest` compare against one upstream rather than two. Every
divergence comment in the ported classes names that version, as do the docblocks
of the three classes and the four `Build/phpunit/` files; raising the constraint
in `composer.json` means re-reading all of them. Branch `1` resolves
`^8.3.3` instead, because the 8.x line is the one covering TYPO3 v12 — the
divergences below are the same there, but the version in the comments is not.

The cost of that decision is drift: upstream can change and we would not
notice. That cost is paid by a test, see below.

## What the port changed

Everything that is not listed here is unchanged.

| Change                                                                                           | Why                                                                                                                                                    |
|--------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| Namespace `SBUERK\DataFactory\Seeding\Scenario`                                                  | It is our code now.                                                                                                                                    |
| `final class`, `new self()` instead of `new static()`                                            | Repository rule; the classes were never subclassed upstream either.                                                                                    |
| `#[Exclude]` on all three                                                                        | They are a builder, a writer and a value object — data, not services. Without it, directory based registration would pick them up.                     |
| PHPStan level 8 array shapes throughout                                                          | The baseline is empty and stays empty. No behaviour changed; only annotations were added.                                                              |
| `?int $workspaceId` became `int $workspaceId` on two private methods                             | Null was never passed by any call site, and a null array key would silently have become the empty string. Behaviour is identical for every real input. |
| The `elseif ($currentIndex > 0)` branch of `setInDataMap()` was fixed                            | See below. Upstream indexes a list of identifiers by an identifier.                                                                                    |
| `assignValueInstructions()` spells the null offset out as `$value ?? ''`                         | See below. PHP 8.5 deprecates coercing a `null` array offset; the key arrived at is unchanged.                                                         |
| `resolveDataMapPageId()` resolves through the record's own table                                 | See below. Upstream hard-codes `pages`, which collapses the declared order of every other table.                                                       |
| `{action: 'discard'}` emits `version`/`clearWSID` rather than the command `clearWSID`            | See below. `DataHandler::process_cmdmap()` has no case for the latter, in v13 or in v14.                                                               |
| `processEntityValues()` records a declared `id` in `$staticIdsPerEntity`                         | See below. Upstream declares the registry, reads it and never writes it.                                                                               |
| `processVersionVariantItem()` reads the workspace from the declaration                           | See below. Upstream reads it from the processed values, which a `columnNames` remap empties.                                                           |
| `DataHandlerWriter::__construct()` gained an optional third parameter                            | See below. Additive: with its default, every existing call behaves byte for byte as upstream.                                                          |
| `DataHandlerWriter::invokeFactory()` resets `DataHandler::$autoVersionIdMap` per workspace round | See below. Only observable for a scenario declaring more than one workspace.                                                                           |
| `updateDataMap()` and `updateCommandMap()` keep the minus of a substituted `-NEW…`               | See below. Upstream returns the uid without the sign, turning a position into a page pointer.                                                          |

The original TYPO3 file headers are kept. The code is GPL-2.0-or-later and so is
this repository; dropping the header to make the files look native would be
dishonest about where they came from.

## The deliberate divergences

There are ten. One is forced by PHP 8.5, one is additive - it changes nothing
for a caller that does not use it - seven are defects of the upstream engine
that are fixed here rather than carried, and one keeps a seed correct on a core
release that changed what a single run of `DataHandler` writes.

Every one of them is stated by a test in
`Tests/Unit/Seeding/Scenario/UpstreamConformanceTest.php` that runs the same
definition through both classes and asserts what each of them produces, so the
list below is not the only place the difference is written down.

### The double-indexed identifier list

Upstream `setInDataMap()` reads, when the identifier is already present in the
filtered data map:

```php
$previousIndex = $identifiers[$currentIndex - 1];
$values['pid'] = '-' . $identifiers[$previousIndex];
```

`$identifiers[$currentIndex - 1]` is already the identifier. Using it a second
time as an index into a list keyed by integers is a defect: upstream emits
`Warning: Undefined array key` and writes a `pid` of a bare `'-'`. Our port
indexes once:

```php
$values['pid'] = '-' . $identifiers[$currentIndex - 1];
```

**The branch is reachable**, which was not obvious and was initially got wrong
here. Records are normally keyed by a fresh `uniqid('NEW', true)` and so are
never already present — but a version variant reuses its *ancestor's* key
rather than allocating one. An entity that maps onto `pages` without being a
node inherits the parent's node pointer for its variants, so two
`versionVariants` of one record land in the same workspace data map on the same
page, and the second write finds its own identifier already there. `pages` is
also the one table whose `-NEW` back references resolve, so the record survives
its own page filter. That is the route in, it needs no reflection, and it is
pinned by a test.

Because it is reachable, this is a real behavioural divergence, and the
conformance test states it on a definition of its own rather than asserting
over it.

It was fixed rather than carried because PHPStan proves the defect, and a
growing baseline is a defect in this repository. Suppressing a proven bug to
stay byte-faithful would be the wrong trade.

### A null value instruction lookup

`EntityConfiguration::assignValueInstructions()` upstream indexes
`$this->valueInstructions[$name][$value]` directly. PHP coerces a `null` offset
to the empty string and **deprecates doing so as of PHP 8.5**, which this
extension supports and whose suite fails on a deprecation. A seed definition
declaring a null value on a column that has value instructions would emit it,
so the coercion is spelled out as `$value ?? ''` instead.

The lookup still hits exactly the key it hit before — this changes no
behaviour, only how the same key is arrived at. It is listed here because it is
a diff against upstream, not because anything observable moved.

### A back reference resolved through the wrong table

`setInDataMap()` chains siblings by rewriting `pid` to `-<previous identifier>`
so DataHandler appends rather than prepends, and it finds that previous record
by filtering the data map for records on the same page. Following the back
reference a previous record already carries is `resolveDataMapPageId()`, and
upstream looks it up in one place whatever table it is resolving for:

```php
$resolvedPageId = $this->dataMapPerWorkspace[$workspaceId]['pages'][$regularPageId]['pid'] ?? null;
```

On any table but `pages` that lookup misses, the record resolves to `null`,
drops out of the filter, and the filtered list never grows past its first
entry - so from the **third** record of a page onwards every one of them is
chained behind the *first*. Three content elements declared 300, 301, 302 reach
the backend as 300, 302, 301, and the declared order of a seed set is silently
reversed. Two records are not enough to see it, which is why nothing noticed:
TYPO3 Core's own scenario fixtures put at most two elements on a page.

The port passes the table name down and resolves through the record's own data
map. On `pages` the two are the same expression, which is what bounds the
divergence - and what lets the conformance test model it, see below.

### An action DataHandler no longer knows

`{action: 'discard'}` is meant to throw a workspace version away again.
Upstream writes `clearWSID` into the command map as the **command name**:

```php
$this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['clearWSID'] = true;
```

`DataHandler::process_cmdmap()` switches over the command name and has no
`clearWSID` case, in v13 or in v14. The command falls through with no branch
and no log entry, so the action does nothing at all and the import reports
success. `clearWSID` is an *action* of the `version` command, which is what the
testing framework's own `ActionService` uses, and that is what the port emits:

```php
$this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['version'] = ['action' => 'clearWSID'];
```

TYPO3 v14 added a `discard` command that says what it does, and core carries a
`@todo` to drop the `clearWSID` action once nothing uses it any more. Switching
to `discard` needs v13 support to be gone first; the port carries a `@todo`
naming that condition.

### A guard that reads a registry nothing writes

`DataHandlerFactory` declares `$staticIdsPerEntity`, reads it in
`hasStaticId()` and never writes it. The guard is therefore always false and
its exception `1533734370` is unreachable: a duplicate `id:` is caught one step
later by `addSuggestedId()` as `1568146788`.

Nothing is imported that should not be - both refuse the definition. What
differs is the message. `addSuggestedId()` knows the table and the uid the
declaration resolved to and reports `Cannot redeclare identifier "pages:100"
with "100"`; the guard that was meant to fire knows the entity and the number
the set author wrote and reports `Cannot assign ID "100" multiple times`. The
port registers the id, so the second one speaks.

The two guards are not redundant. The registry is keyed by entity name, so two
entities that share a table each declaring the same id still collide on the
table and are refused by `addSuggestedId()` - as is a declared id the dynamic
counter later walks into, which nobody declared twice at all.

### A workspace read from the values instead of the declaration

`processVersionVariantItem()` decides which workspace map the variant goes
into. Upstream reads that from the **processed** values, after `columnNames`
has been applied:

```php
$this->setInDataMap($tableName, $ancestorId, $values, (int)$values['workspace']);
```

An entity that maps `workspace` onto another column therefore leaves no
`workspace` key behind. The expression warns about an undefined key, evaluates
to workspace `0`, and the version variant is written under the key of the live
record - **overwriting the record it was declared to version**, with an empty
error log. The port reads `$itemSettings['version']['workspace']`, which is
what the two other call sites of `setInDataMap()` already do.

`columnNames` decides what is written, not where.

### A position that loses its sign

`DataHandlerWriter` substitutes the identifiers of earlier rounds before each
round. Two forms occur: `NEW…` points *at* a record, `-NEW…` points **behind**
one - a `pid` of `-42` means "on the page record 42 is on, sorted after it", a
`move` command of `-42` means "move behind record 42". Upstream strips the
minus to look the identifier up and does not put it back:

```php
return $this->dataHandler->substNEWwithIDs[substr($value, 1)] ?? $value;
```

`-NEW…` therefore becomes `42`: a page pointer instead of a position, so the
record is created *inside* the record it was meant to follow, and a move lands
on a page instead of behind a record. The port returns `'-' . $substitutedId`.

The command map half is the reachable one. A language variant of a node
inherits `-<identifier of its original>` as its node pointer, so
`{action: 'move', type: 'toTop'}` on a page translation is a command value of
`-NEW…` - and the command map rounds run after every data map round, so the
identifier is always substituted by then.

### A withheld set of suggested uids

`DataHandlerWriter::__construct()` takes a third parameter:

```php
public function __construct(
    private readonly DataHandler $dataHandler,
    private readonly BackendUserAuthentication $backendUser,
    private readonly array $withoutSuggestedUids = [],
) {}
```

and `invokeFactory()` assigns

```php
$this->dataHandler->suggestedInsertUids = array_diff_key(
    $factory->getSuggestedIds(),
    $this->withoutSuggestedUids,
);
```

where upstream assigns `$factory->getSuggestedIds()` directly. It is **strictly
additive**: with the default empty array `array_diff_key()` returns its first
argument unchanged, so every call that does not pass the parameter behaves byte
for byte as upstream does, and the conformance test needs no exclusion for it.

It exists because `--force` has to hold back the suggested uids of a colliding
table, and `invokeFactory()` assigns `suggestedInsertUids` itself - there is no
moment between "the writer was constructed" and "the uid is read" in which a
caller could reduce the array from the outside. Reaching into
`DataHandler::$suggestedInsertUids` after the writer was handed the factory
would mean reaching into a run that has already started; passing the set in is
the same decision, made at construction time. The keys are `<table>:<uid>`,
exactly as `getSuggestedIds()` keys them.

`updateDataMap()` drops the `uid` field of a record whose suggestion was held
back, guarded by the same array and therefore inert by default:

```php
if (isset($values['uid']) && isset($this->withoutSuggestedUids[$tableName . ':' . $values['uid']])) {
    unset($values['uid']);
}
```

That half is not tidiness, it is a workaround for a TYPO3 core defect that only
PostgreSQL exposes. `process_datamap()` reads the suggested uid out of the `uid`
field and passes it to `insertDB()`, which honours it only when
`suggestedInsertUids` carries it - but `postProcessDatabaseInsert()` then does

```php
if ($suggestedUid !== 0 && $connection->getDatabasePlatform() instanceof PostgreSqlPlatform) {
    $this->postProcessPostgresqlInsert($connection, $tableName);
    return $suggestedUid;
}
```

and returns that number **whether the insert used it or not**
(`DataHandler.php:9669ff`). `substNEWwithIDs` then maps the identifier to a uid
no row has, and everything pointing at that identifier - a child's `pid`, a
sibling's `-NEW` "insert after" - points at nothing. `getSortNumber()` returns
`false`, `$fieldArray['pid']` is never set, and the run dies with a `TypeError`
in `addDefaultPermittedLanguageIfNotSet()`. MySQL and SQLite hide it, because
they take `lastInsertId()` and return the truth.

The same path is reachable upstream: `insertDB()` skips the forced uid for a
non-admin too, so any non-admin scenario run on PostgreSQL hits it. Dropping the
field costs nothing - `insertDB()` unsets it before the INSERT either way -
and `DataHandlerWriterTest` pins the chain that broke. It is worth reporting to
TYPO3 Core.

One annotation moved with it: `getSuggestedIds()` is documented as
`array<string, true>` rather than `array<string, bool>`. `true` is the only
value the class ever writes, and stating it is what lets PHPStan see the result
of the `array_diff_key()` above as the same shape rather than as a widened one.
No code path changed.

### A workspace round that forgets the previous one

`invokeFactory()` resets one property of the `DataHandler` before each round:

```php
$this->dataHandler->autoVersionIdMap = [];
$this->dataHandler->start($dataMap, [], $backendUser);
```

Upstream reuses one `DataHandler` for every round and leaves the map alone.
`DataHandler::$autoVersionIdMap` remembers which workspace version it
auto-created for a live uid, `start()` does not reset it, and
`process_datamap()` reads it **before** it asks whether a version for the
current workspace exists:

```php
if (!empty($this->autoVersionIdMap[$table][$id])) {
    …
    $id = $this->autoVersionIdMap[$table][$id];
} elseif (($errorCode = $this->workspaceCannotEditRecord($table, $currentRecord))) {
```

A second workspace declaring a `versionVariants` entry for the same live record
therefore writes its values into the version of the **first** workspace and
creates nothing of its own. Silently: the error log stays empty and the import
reports success.

The map is per round by nature. It exists so that children versioned along with
their parent are written to the version rather than to the live record, and
that is finished when the round is. Nothing upstream reaches this - the format
has no test at all, and no TYPO3 Core fixture declares two workspaces - so the
divergence is invisible to every scenario file that exists today.
`WorkspaceSeedingTest::twoWorkspacesEachGetAVersionOfTheSameRecord()` is the
test that goes red without the reset.

### Translations written after the default language

`invokeFactory()` writes the data map of a workspace round in more than one run
of `DataHandler`, where upstream writes it in one: the records of the default
language and of "all languages" first, then the translations, and a translation
of a translation after the translation it was made from. A translation is a
record of a language aware table whose language field is above 0.

TYPO3 14.3.10 gives every inline child the language of its parent when the
parent is created in the run, changes its language or gets a new child (core
#103970, "Inline children follow the language of their parent"), and it
expects an inline child to have one parent. A child can have a second one: a
contact that lists the role of its default language record in a select with
`l10n_mode` `exclude`, which a role lists in an inline relation of its own.
Its translation names that role as well. Written in one run, the role is new,
the core aligns every child it lists, and the translation becomes a record of
the default language without a translation parent. Written after the default
language, the role exists and is left alone - which is what happens when an
editor translates the record in the backend. TYPO3 13 aligns nothing, so the
result there is the same as before.

Two things follow from the runs and are handled where they are split:

- **Positions across languages.** The factory positions a record behind the
  one declared before it on the same page, and that can be a translation. A
  record positioned behind a translation of a later run is positioned where
  that translation was positioned instead, which is behind the record it
  translates. The order of the records of one run does not change.
- **The `NEW…` identifiers of an earlier run** - the translation parent and
  source, the node a translated page is written next to - are substituted by
  `updateDataMap()`, exactly as between two workspace rounds.

The second one fixes a TYPO3 Core defect on the way. A translation of a
translation used to reach the database with its `l10n_source` on the original,
because a `NEW…` value in that `passthrough` column went on the remap stack of
`DataHandler` without a resolver, and `processRemapStack()` never resets
`$newValue`. Written one run after its source, the column holds a uid before
the run starts, and the remap stack never sees it.

`LanguageRelationSeedingTest` is the test that goes red without the runs, on
TYPO3 14.3.10 and later, against the fixture extension `language-relations`.
`LanguageVariantSeedingTest` asserts the source of a translation of a
translation.

## What keeps the port honest

`Tests/Unit/Seeding/Scenario/UpstreamConformanceTest.php` runs the same
definitions through **both** our port and the upstream classes — available
because `typo3/testing-framework` is still a dev dependency — and asserts that
the data maps, command maps, table names and suggested ids match.

The comparison cannot be a plain `assertSame()`. Data map keys are
`uniqid('NEW', true)` with the dots stripped, so they differ between the two
instances and between runs, and they also appear *inside* values as `pid`, node
and parent pointers. The test therefore normalises every `NEW…` occurrence —
as a key, and anywhere inside a string value including the `-NEW…` form — to a
stable ordinal in first-appearance order, and compares the normalised
structures.

When upstream changes something, that test goes red and the change becomes a
decision instead of a surprise.

Seven deliberate divergences are a problem for a test like that: excluding the
definitions that reach them would take most of the assertion with them - eight
of the eleven core scenarios differ in the sibling chain alone. Two of the
seven are therefore **modelled onto upstream's own output** before the
comparison, so all four observables stay under one `assertSame()`:

- the sibling chain, which is undone by pointing each record that upstream
  chained behind the first at the one written before it instead - on `pages`
  only, where the two implementations run the identical expression, this is
  skipped, and that restriction is a proof rather than a heuristic;
- the `discard` command, which is renamed in place.

The other five are unreachable by the definitions the test feeds in - two of
them are refusals, one needs a remapped `workspace` column, one needs the
`$currentIndex > 0` construction, and one is in `DataHandlerWriter`, which this
test does not run.

The models are written against upstream's output and share no code with the
fixes, so they cannot reproduce a mistake in one. What they cannot catch is a
*regression* of a fix - a port that stopped chaining would model to the same
structure - and that is what the dedicated divergence tests and
`ScenarioSeederTest::threeRecordsOfAnyOtherTableKeepTheOrderTheyWereDeclaredIn()`
are for.

## What upstream does not do, and the pipeline must

Reading the engine tells you as much by omission as by content. None of the
following exists in it, and each is added on top rather than inside:

- **Files.** There is no `sys_file`, no `sys_file_reference`, no storage
  handling, no FAL call of any kind — verified by grep over the whole package.
  The structural reason is that a `sys_file_reference` needs a `uid_local`, and
  the `sys_file` uid is assigned by the FAL indexer while the file is placed, so
  it cannot be declared in a scenario at all. Both halves are added on top:
  `FileSeeder` before the record pass, `FileReferenceSeeder` after it, from the
  `files:` and `references:` lists of `config.yml`. See
  [The file reference pass](seeding.md#the-file-reference-pass).

  An **inline relation** is the opposite case, and it is worth naming next to
  this one: the engine needs nothing for it either, and nothing was added. A
  declared `id` is a suggested uid, so a parent can name its children by listing
  their ids in its relation field, and `DataHandler` does the rest. See
  [Inline relations need no support](../development/seed-definitions.md#inline-relations-need-no-support).
- **Site configurations.** Core's tests write sites with their own helpers.
- **A set concept** — an identifier, a `config.yml`, discovery over active
  packages, an ordered composition of several scenario files.
- **`DataHandler::$isImporting`**, which is what suppresses the
  `autogenerated-<uid>` site configuration.
- **An admin check.** `suggestedInsertUids` is honoured by core only
  `if ($this->BE_USER->isAdmin() && …)`. For any other user the declared ids are
  ignored *silently*, and every literal cross reference in the definition then
  points at the wrong record.
- **Errors that stop anything.** `invokeFactory()` returns `void` and never
  throws; failures are collected from `DataHandler::$errorLog` into
  `getErrors()`, and a caller that does not look never learns.

## Tests

Upstream ships **no test at all** for this subsystem — no unit test, no
functional test, not one fixture; its own suite covers composer package
resolution and the CSV `DataSet`. The format is only ever exercised indirectly,
by Core tests asserting rendered output.

That gap is filled here, in `Tests/Unit/Seeding/Scenario/`, from a written
inventory of every untested behaviour of the three classes. Five of those tests
found defects that are now fixed - they are the divergences listed above, and
each of them was shown to fail against the unfixed code before the fix went in.

One behaviour is pinned rather than fixed, because it is not engine behaviour.
A translation of a translation used to be the second one, until the writer
started writing translations in runs of their own, see
[Translations written after the default language](#translations-written-after-the-default-language).

- **A translated page follows its original only if the entity declares a node
  column.** `processLanguageVariantItem()` is called without a parent id, so
  `parentColumnName` never positions a variant; a node entity without
  `nodeColumnName` produces a translation whose `pid` falls back to
  `defaultValues`. Every TYPO3 Core scenario declares `nodeColumnName: 'pid'`
  on its wildcard entity, which is why this is a footnote rather than a bug.

The first of the two is a TYPO3 Core defect and is worth reporting; the second
is a property of the format that seed authors need to know about, and it is
documented for them in `Documentation/Configuration`.

## See also

- [Seed definitions](../development/seed-definitions.md) — the format itself, key by key
- [Seeding](seeding.md) — the DataHandler behaviours seeding works around
- [Unit tests](../testing/unit-tests.md)
