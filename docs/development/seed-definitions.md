# Seed definitions

A seed set is written in **two** formats, and keeping them apart is the point of
the construct:

| File               | Format                                                                          | Owner                          |
|--------------------|---------------------------------------------------------------------------------|--------------------------------|
| `config.yml`       | the **set descriptor**: identity, scenario files, files, file references, sites | this extension, closed key set |
| the scenario files | the YAML **scenario format** of `typo3/testing-framework`                       | upstream, key for key          |

`SeedDefinitionParser` implements the first, `ScenarioComposer` reads and merges
the second, and `DataHandlerFactory` turns the merge into the DataHandler maps.
Where this page and those classes disagree, they are what ships and the
disagreement is a bug in one of them.

Nothing this extension invents is mixed into a scenario file. That is what lets
a scenario file be lifted out of TYPO3 Core's own functional tests and seeded
unchanged, and it means every key below is upstream's with upstream's meaning.
Why that format rather than a bespoke one, and why its engine is a port rather
than a dependency, is on
[The scenario engine](../architecture/scenario-engine.md).

## Where a set lives

A seed set is a directory `Configuration/DataFactory/<name>/` in any active package,
with `config.yml` as its entry file:

```
Configuration/DataFactory/demo/
├── config.yml               entry file, and the only mandatory one
├── Scenario.yaml            the records, named under "scenarios"
├── Files.yaml               optional, pulled in through "imports"
├── Files/
│   └── placeholder.svg      resources named by "files"
└── Sites/
    └── main/
        ├── config.yaml      site configuration template
        └── settings.yaml    optional site settings
```

Every relative path inside a set - a `scenarios` entry, a file `source`, a site
`template` - resolves against the directory holding the **entry file**, not
against the file declaring it. That is what lets a set be moved or renamed
without touching its paths. `EXT:` paths are accepted everywhere a path is and
ignore the base directory.

An `imports` resource is the one exception, because it is not this extension
that resolves it: `YamlFileLoader::getStreamlinedFileName()` resolves a relative
import against the **importing file**. The two coincide for an import declared
in `config.yml`, and diverge for an import of an import - see
[`imports`](#imports).

Which path kinds an **absolute** path may leave the project with is a second,
independent split, and it does not run along the same line. An absolute
`scenarios` entry and an absolute file `source` are taken as they are and are
deliberately **not** sent through
`GeneralUtility::getFileAbsFileName()`, which answers with an empty string for
anything outside the project path and would turn "a set in a path repository
during development" into "the scenario does not exist". A site `template`, an
`imports` resource and `config.yml` itself *are* resolved through it, and
therefore have to sit inside the project — see
[Where templates may live](../architecture/site-configuration.md#where-templates-may-live).

Discovery is described in [Seed sets and the CLI](seed-sets.md).

## The set descriptor

```yaml
identifier: demo
title: 'Demo page tree'
description: 'Pages, content and a site configuration.'

imports:
  - { resource: Files.yaml }

scenarios:
  - Pages.yaml
  - Content.yaml

files: []
references: []
sites: []
```

| Key           | Required | Type   | Default | Meaning                                                                                                                                      |
|---------------|----------|--------|---------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `identifier`  | yes      | string | -       | Globally unique across all active packages. Declared, never derived from the directory name - a derived identifier makes a collision silent. |
| `title`       | yes      | string | -       | Shown by `data-factory:list`.                                                                                                                |
| `description` | no       | string | `''`    | Long text. Carried on the set and the definition; no command prints it today.                                                                |
| `imports`     | no       | list   | `[]`    | Further YAML files merged into **this descriptor**. Consumed by `YamlFileLoader` before the parser sees it.                                  |
| `scenarios`   | **yes**  | list   | -       | The scenario files the records come from, in the order they are applied. Non-empty.                                                          |
| `files`       | no       | list   | `[]`    | Files copied into a storage before any record is written.                                                                                    |
| `references`  | no       | list   | `[]`    | `sys_file_reference` rows attaching a seeded file to a record, written after the records.                                                    |
| `sites`       | no       | list   | `[]`    | Site configurations written after the records.                                                                                               |

This list is **closed**: any other key is refused and the message names the
known ones. Accepting `scenario:` for `scenarios:` silently is how an import
reports success and writes nothing.

`description:` with nothing behind it decodes to `null` and is treated exactly
like an absent key.

`scenarios` is required rather than optional-and-empty. A set that names no
scenario writes no record, and a descriptor saying so by omission cannot be told
apart from one that misspelled the key.

**`imports` does not import records.** It is a mechanism of the descriptor: a
set may split its own `files:` or `sites:` into a second file, and that file is
merged into `config.yml`. A scenario file is *never* pulled in that way - it is
named under `scenarios` and read by `ScenarioComposer` with a plain
`Yaml::parseFile()`. A scenario file carrying `imports:` is refused as an
unknown key, and `config.yml` carrying `entitySettings:` or `entities:` is
refused for the same reason. One rule, no ambiguity.

### Validation

Everything below is checked before a single row is written. These are the codes
an integrator meets while a descriptor is being read and its scenario files are
being composed; `SeedDefinitionParser` raises a further fourteen, in the same
shape, for a malformed `files` or `sites` entry — among them the site identifier
pattern and the `rootPage` shape this page describes under
[Site configurations](#site-configurations).

| Code         | Condition                                                                           |
|--------------|-------------------------------------------------------------------------------------|
| `1787072801` | the `config.yml` does not exist                                                     |
| `1787072802` | the `config.yml` cannot be read                                                     |
| `1787072803` | the `config.yml` is not readable YAML                                               |
| `1787072810` | the descriptor is not a map                                                         |
| `1787072811` | no `identifier`, or not a non-empty string                                          |
| `1787072812` | no `title`, or not a non-empty string                                               |
| `1787072813` | `description` is not a string                                                       |
| `1787072814` | the descriptor declares an unknown key                                              |
| `1787256301` | `scenarios` is missing, is not a list, or is empty                                  |
| `1787256302` | a `scenarios` entry is not a non-empty string                                       |
| `1787256303` | a `files` entry declares an unknown key                                             |
| `1787256310` | `references` is not a list                                                          |
| `1787256311` | a `references` entry is not a map                                                   |
| `1787256312` | a `references` entry declares an unknown key                                        |
| `1787256313` | a reference declares no `file`, or not a non-empty string                           |
| `1787256314` | a reference names a `file` the set does not declare under `files`                   |
| `1787256315` | a reference declares no `table`, or not a non-empty string                          |
| `1787256316` | a reference declares no `uid`, or one that is not an integer >= 1                   |
| `1787256317` | a reference declares no `field`, or not a non-empty string                          |
| `1787256318` | the `values` of a reference are not a map                                           |
| `1787256319` | a `values` field of a reference has no name                                         |
| `1787256320` | a `values` field of a reference is not a scalar or null                             |
| `1787256401` | a scenario file does not exist, with the path it was looked for at                  |
| `1787256402` | a scenario file cannot be read                                                      |
| `1787256403` | a scenario file is not readable YAML                                                |
| `1787256404` | a scenario file is not a map                                                        |
| `1787256405` | a scenario file declares a key other than `entitySettings`/`entities`/`__variables` |
| `1787256406` | the `entitySettings` of a scenario file are not a map                               |
| `1787256407` | the `entities` of a scenario file are not a map                                     |
| `1787256408` | an entity is not a list of items                                                    |
| `1787256409` | an item of an entity is not a map                                                   |

Three of the last five - `1787256406`, `1787256407` and `1787256409` - exist
because `DataHandlerFactory` would answer the same input with a `TypeError` out
of a private method: `buildEntityConfigurations(array $settings)`,
`processEntities(array $settings)` and
`processEntityItem(…, array $itemSettings, …)`. `1787256408` guards a bare
`foreach()` over a non-array, which is a warning rather than a `TypeError`, and
`1787256405` is there for the reason every key set on this page is closed - the
factory reads `entitySettings` and `entities` and would ignore an unknown key in
silence. Naming the scenario file and the entity is worth the walk.

The `LogicException` codes the engine itself raises - `1533734368` out of
`EntityConfiguration`, and `1533734369`, `1533734370`, `1534872399`,
`1534872400`, `1568146788`, `1574365935`, `1574365936` out of
`DataHandlerFactory` - are **not** wrapped. They are upstream's codes and stay traceable
to the class that raised them; the command turns them into
`EXIT_INVALID_DEFINITION` all the same.

## The scenario format

A scenario file has three top level keys, and only three:

```yaml
__variables: []       # YAML anchors, ignored by the engine
entitySettings: {}    # how an entity name maps onto a table
entities: {}          # the records
```

An **entity** is a name a scenario gives to a kind of record. `entitySettings`
says which table that name writes to and how its values are translated;
`entities` lists the records themselves under that name. The indirection is what
lets one scenario read as a page tree rather than as a table dump.

### `entitySettings`

```yaml
entitySettings:
  '*':
    nodeColumnName: 'pid'
    columnNames: {id: 'uid', language: 'sys_language_uid'}
    defaultValues: {pid: 0, hidden: 0}
  page:
    isNode: true
    tableName: 'pages'
    parentColumnName: 'pid'
    languageColumnNames: ['l10n_parent', 'l10n_source']
    columnNames: {type: 'doktype', root: 'is_siteroot'}
    defaultValues: {doktype: 1}
    valueInstructions:
      shortcut:
        first: {shortcut: 0, shortcut_mode: 1}
  content:
    tableName: 'tt_content'
    languageColumnNames: ['l18n_parent', 'l10n_source']
    columnNames: {title: 'header', type: 'CType'}
```

| Key                   | Type              | Default         | Does                                                                                                          |
|-----------------------|-------------------|-----------------|---------------------------------------------------------------------------------------------------------------|
| `isNode`              | bool              | `false`         | Items of this entity may carry nested `entities`, and become the node those are written below.                |
| `tableName`           | string            | the entity name | The table the records are written to.                                                                         |
| `parentColumnName`    | string            | none            | The column an item declared under `children` receives its parent's identifier in.                             |
| `nodeColumnName`      | string            | none            | The column an item receives the identifier of its enclosing node in.                                          |
| `columnNames`         | map alias->column | `[]`            | Renames a declared key on its way into the record. `title: 'header'` writes a declared `title` into `header`. |
| `languageColumnNames` | list of columns   | `[]`            | The columns a `languageVariants` item receives its ancestor identifiers in.                                   |
| `defaultValues`       | map               | `[]`            | Written into every record of the entity, unless the item declares the column itself.                          |
| `valueInstructions`   | map               | `[]`            | Expands one declared value into a set of values. See below.                                                   |

Two of them are easy to mix up. `nodeColumnName` is the pointer to the
**structure** an item sits in - the page a content element is on - and it is
inherited downwards. `parentColumnName` is the pointer to the item that
*declared* this one under `children`. On `pages` both are `pid`, and the parent
pointer is assigned second, so it wins where an item has both.

`tableName` defaulting to the entity name is why an entity called `sys_category`
needs no `entitySettings` entry at all to write categories - and why a typo in
an entity name produces an attempt to write a table of that name rather than an
error.

#### `columnNames` and `defaultValues`

`EntityConfiguration::processValues()` starts from `defaultValues`, then copies
every declared value in under its resolved column name. A declared value
therefore always wins over a default, and a default is never applied to a column
the item declares.

#### `valueInstructions`

```yaml
valueInstructions:
  shortcut:
    first: {shortcut: 0, shortcut_mode: 1}
```

Read as: when an item declares `shortcut: first`, merge `shortcut: 0` and
`shortcut_mode: 1` into its values. It is a named macro for a combination of
columns that has to be right together - here "shortcut to the first subpage",
which is a `shortcut` of `0` **and** a `shortcut_mode` of `1`, and is
meaningless as either half.

Three details decide what it does:

- it is keyed by the **declared** name, before `columnNames` resolves it, and by
  the raw declared value;
- the values it merges in are **column names**, not aliases, and they overwrite
  what is already there - which is what lets `shortcut: first` end up as a
  numeric `shortcut`;
- an instruction whose value is empty or falsy is skipped, and a column whose
  instruction map is not an array raises `1533734368` while the configuration is
  built.

#### `languageColumnNames`

A list, filled from the chain of ancestors of a language variant: every column
but the last gets the ancestor at its own position, the last gets the *nearest*
ancestor. With the usual `['l10n_parent', 'l10n_source']` that means
`l10n_parent` always points at the original record and `l10n_source` at the
record the variant was translated from - the same value for a first level
translation, different values for a translation of a translation.

Declared values are merged **over** the language columns, so an item may still
name `l10n_source` itself.

#### The `'*'` entity

`'*'` is not a table. It holds the settings every *declared* entity gets merged
in, which is how a scenario states `nodeColumnName: pid` and
`columnNames: {id: uid}` once instead of on every entity.

It is also where the two traps of this format live.

##### Trap 1: the merge is recursive, not overriding

`DataHandlerFactory::buildEntityConfigurations()` merges with
`array_merge_recursive()`. For a key declared on **both** sides that does not
override - it **appends**:

```yaml
entitySettings:
  '*':
    defaultValues: {hidden: 0}
  page:
    defaultValues: {hidden: 0}     # <- both sides
```

produces `defaultValues: {hidden: [0, 0]}`, and an array reaching a `check`
column is written to the database as the string `Array`. Nothing warns; the page
is simply not visible and the column does not look like what the scenario says.

The rule that follows is mechanical: **a key belongs to `'*'` or to the entity,
never to both.** The fixture set of this repository states it in a comment above
its `entitySettings` for exactly that reason.

##### Trap 2: `'*'` reaches only declared entities

`buildEntityConfigurations()` iterates `entitySettings` and skips `'*'`. An
entity that is **used** under `entities:` but is **not listed** in
`entitySettings:` never goes through that loop at all:
`provideEntityConfiguration()` creates a bare `EntityConfiguration` for it, with

- no default values, so no `pid`, no `hidden`, nothing;
- no node and no parent column, so it is written wherever the TCA defaults put
  it rather than below its node;
- a table name equal to the **entity name itself**.

Writing `sys_category` records by naming the entity `sys_category` and nothing
else therefore works and is not the same as declaring it - it opts out of every
`'*'` setting the scenario has. Where that matters, list the entity with an
empty settings map.

This is also why `--root-page` is applied to the declared items rather than to
`'*'.defaultValues.pid`; see [below](#--root-page).

### `entities`

```yaml
entities:
  page:
    - self: {id: 11, title: 'Import home', slug: '/', is_siteroot: 1}
      entities:
        content:
          - self: {id: 21, title: 'Imported content', type: 'header'}
      children:
        - self: {id: 12, title: 'About', slug: '/about'}
```

A map of entity name to a **list of items**. Everything an item declares under
`self` or `version` is a value of the record, resolved through `columnNames` and
merged onto `defaultValues`. The keys of the item itself are the fixed set
below, and the factory reads no others - it also refuses none, so a misspelled
`childern:` is dropped in silence.

| Key                | Does                                                                                                       |
|--------------------|------------------------------------------------------------------------------------------------------------|
| `self`             | The record's own values, in the live workspace.                                                            |
| `version`          | The record's own values, in the workspace it names. Mutually exclusive with `self`.                        |
| `children`         | Items of the **same** entity, carrying this item's identifier in `parentColumnName`.                       |
| `entities`         | Items of **other** entities, written below this item as their node. Honoured only when `isNode` is `true`. |
| `languageVariants` | Translations of this item, wired through `languageColumnNames`.                                            |
| `versionVariants`  | Workspace overlays of this item.                                                                           |
| `actions`          | DataHandler commands on this item, run after every record was written.                                     |

#### `self` and `version`

```yaml
- self: {id: 1100, title: 'EN: Welcome'}
- version: {id: 1240, title: 'EN: Managing data', workspace: 1}
```

`self` writes the record in workspace 0. `version` writes it in the workspace it
names, and the `workspace` key is mandatory there. Declaring both raises
`1534872399`, a `version` without `workspace` raises `1534872400`, and an item
with neither raises `1533734369`.

Both accept `id`, which is the **uid** the record is written with - see
[Uids](#uids-declared-dynamic-and-suggested).

#### `children`

Items of the same entity, one level down. The declaring item's identifier is
written into `parentColumnName`; the node pointer is passed through unchanged.
For `pages`, where `parentColumnName` is `pid`, that is what builds the tree.

An entity **without** a `parentColumnName` may still declare `children`, and
they are then written as plain siblings - the nesting in the YAML says nothing
about the records. That is a property of the format worth knowing before reading
a nesting as a relation.

#### Nested `entities`

The other direction: records of a *different* entity, below this item. The
declaring item's identifier goes into the child entity's `nodeColumnName`, which
is how content elements land on their page.

It is honoured **only when the declaring entity has `isNode: true`**. On any
other entity, `entities:` is silently ignored - no error, no record. This is
upstream behaviour and it is the single most likely way to write a scenario that
seeds less than it says.

#### `languageVariants`

```yaml
- self: {id: 1100, title: 'EN: Welcome'}
  languageVariants:
    - self: {id: 1101, title: 'FR: Welcome', language: 1}
      languageVariants:
        - self: {id: 1102, title: 'FR-CA: Welcome', language: 2}
```

Items of the same entity, with `languageColumnNames` filled from the ancestor
chain. They nest: a variant of a variant carries both ancestors, which is what
distinguishes `l10n_parent` from `l10n_source`.

That distinction is real in the data map and **does not survive the write**.
`l10n_source` is a `passthrough` column, so a `NEW…` value in it goes on
`DataHandler`'s remap stack with no resolver, and `processRemapStack()` declares
`$newValue` once outside its loop and never resets it — an entry without a
resolver writes whatever the entry before it produced, which for a translation
is always the `l10n_parent` resolved immediately before. A translation of a
translation therefore reaches the database with both columns on the original.
TYPO3 Core carries the same observation as a `@todo` on the nested
`languageVariants` of its own `CommonScenario.yaml`. Declare `l10n_source`
explicitly on the variant when the chain matters — a declared value wins.
Pinned by `LanguageVariantSeedingTest`.

The node pointer differs by entity kind, and deliberately. For a **node** entity
the variant is given `-<identifier of the original>` - the "insert directly
after" convention, so a translated page sits next to its original rather than at
the top of the page tree. For any other entity the enclosing node is passed
through unchanged, so a translated content element stays on its page.

Both halves of that sentence depend on the entity declaring `nodeColumnName`.
It is the node pointer that positions a language variant, never
`parentColumnName` - `processLanguageVariantItem()` is called without a parent
id at all. A node entity declaring only `parentColumnName` therefore has nothing
to position its translations by, and their `pid` falls back to `defaultValues`,
usually `0` and out of the tree. Every scenario file of TYPO3 Core declares
`nodeColumnName: 'pid'` on its `'*'` entry, which is why the normal case is the
first one. Both are pinned by `LanguageVariantSeedingTest`.

A language variant may carry `versionVariants` and further `languageVariants`,
and it may carry `actions`. It may not carry `children` or `entities` - those
keys are not read there.

#### `versionVariants`

```yaml
- self: {id: 1400, title: 'EN: ACME in your Region'}
  versionVariants:
    - version: {title: 'EN: Features modified', workspace: 1}
      actions:
        - {action: 'delete'}
```

A workspace overlay of the declaring record. Two things are different from every
other item:

- it declares `version:` and **must not** declare `self` (`1574365935`) or an
  `id` (`1574365936`). The overlay is a row of its own with a uid the database
  assigns; it is found through `t3ver_oid`, which holds the uid of the live
  record. A uid that could be declared for it would be a uid nothing honours -
  the row is created by `DataHandler` while it versions the live record, not
  inserted by the seed;
- it is written into the data map under the **ancestor's key**, in the workspace
  its `version.workspace` names, so `DataHandler` sees an overlay of that record
  rather than a new one.

A `columnNames` remap of `workspace` changes the column the value is written
to, and nothing else: the workspace a variant belongs to is read from the
declaration. `typo3/testing-framework` reads it from the processed values and
loses it in exactly that case - see
[A workspace read from the values instead of the declaration](../architecture/scenario-engine.md#a-workspace-read-from-the-values-instead-of-the-declaration).

#### `actions`

Actions are not values; they become the DataHandler **command map**, which
`DataHandlerWriter` processes after every data map of every workspace. An action
may therefore target a record the same scenario creates.

| Declaration                                      | Command                                            |
|--------------------------------------------------|----------------------------------------------------|
| `{action: move, type: toPage, target: 110}`      | `move` to page `110`                               |
| `{action: move, type: toTop}`                    | `move` to the enclosing node, at the top           |
| `{action: move, type: afterRecord, target: 300}` | `move` to `-300`, directly after that record       |
| `{action: delete}`                               | `delete`                                           |
| `{action: discard}`                              | `version`/`clearWSID`, **only** in a workspace > 0 |

`toTop` needs an enclosing node and does nothing on a top level item; `toPage`
and `afterRecord` need a `target`. An action that matches none of the rows is
dropped without a word - there is no unknown-action error in this format.

`discard` deletes the workspace version outright rather than soft deleting it,
and leaves the live record alone. It is one of the places this extension
diverges from `typo3/testing-framework`, which emits `clearWSID` as the
*command name* - a spelling `DataHandler::process_cmdmap()` has no case for in
v13 or in v14, so upstream's `discard` does nothing at all and reports success.
See [An action DataHandler no longer knows](../architecture/scenario-engine.md#an-action-datahandler-no-longer-knows).

`delete` on a record the same scenario just wrote is not pointless: it is how a
scenario produces a deleted row to test against, and how a workspace overlay
expresses "this record is deleted in that workspace".

### `__variables`

```yaml
__variables:
  - &pageStandard 0
  - &contentText 'text'

entities:
  page:
    - self: {id: 1000, type: *pageStandard}
```

A place to declare YAML **anchors**, and nothing else. The anchors are resolved
by the YAML parser, so what reaches the engine is the substituted value and the
key itself is inert. `ScenarioComposer` accepts and drops it rather than
refusing it, because every TYPO3 Core scenario carries one.

Anchors are a property of the parse of **one file**. A scenario split over
several files needs its own `__variables` in each of them; an anchor declared in
the first file is not defined in the second.

### Uids: declared, dynamic, and suggested

Every record of a scenario gets a uid before it is written, and every one of
them is registered as a **suggested uid**.

- An item declaring `id` gets that uid.
- An item declaring none gets one from a counter that starts at **10000** and
  runs **per entity name**.
- A `version` item declaring no `id` advances that counter by two rather than
  one, so it leaves a gap in the dynamic sequence. Declaring an `id` takes one
  off that: a `version` item with an `id` advances the counter by one, and a
  `self` item with an `id` leaves it where it was.

Two consequences are worth stating.

**A scenario without a single declared `id` still suggests uids**, from 10000
upwards, and `data-factory:import` checks those against the installation exactly like
declared ones. There is no "let the database decide" mode.

**The counter is per entity name, the suggestion is per table.** Two entities
mapping onto the same table - a `page` and a `folder`, both
`tableName: 'pages'` - each start at 10000, so the first item of each collides
on `pages:10000` and the composition fails with `1568146788`. Declaring `id` on
one of them, or on all of them, is the way out.

A duplicate suggestion is caught while the scenario is composed, before anything
is written, and the message names the `<table>:<uid>` identifier. Why that check
is the one that fires rather than a duplicate primary key at insert time is a
consequence of composing everything into one factory; see below.

**Declaring the same `id` twice on one entity** is caught one step earlier, by
`1533734370`, whose message names the id rather than the table it resolved to.
`typo3/testing-framework` never reaches that guard - it declares the registry it
reads and never writes it - so upstream reports this case as `1568146788` too.

### `hidden` is not defaulted for you

The `pages` TCA ships `$GLOBALS['TCA']['pages']['columns']['hidden']['config']['default'] = 1`
(`EXT:core/Configuration/TCA/Overrides/pages.php`), so a page written without a
`hidden` value is **hidden**. `tt_content` and the tables enriched by
`TcaEnrichment::enrichDisabledField()` default to `0`.

Nothing in this extension overrides that. A scenario has to say so itself,
which is why every TYPO3 Core scenario carries `defaultValues: {hidden: 0}` on
its `'*'` entity. A seeded tree that exists and renders nothing is almost always
this.

The same applies to `doktype`, `l10n_parent` and `sys_language_uid`: they come
from the TCA of the installation unless the scenario declares them. A seed that
wants the same records in two installations declares them.

## Composing several scenario files

`scenarios` is a list, and the files are composed into **one**
`DataHandlerFactory` rather than one per file. That is not a convenience:
`DataHandlerFactory` hands out its dynamic uids per entity name starting at
10000, so two factories would suggest the same uid twice and the second insert
would fail as a duplicate primary key - an `SQL error` line in
`DataHandler::$errorLog` naming neither file. Composed, the same collision is
`1568146788` naming the identifier, before anything is written.

The merge is defined per key, because the two keys mean opposite things:

| Key              | Rule                                                          |
|------------------|---------------------------------------------------------------|
| `entitySettings` | merged recursively, a later file wins a conflicting scalar    |
| `entities`       | appended per entity name, in the order the files are declared |
| `__variables`    | ignored - it holds YAML anchors, which never cross a file     |
| anything else    | `1787256405`                                                  |

`entitySettings` describes *how* a table is written and is naturally overridden.
`entities` are the records, and a later file adds to them rather than replacing
them. A set may therefore ship a base scenario and a second file that extends
it, and the extending file may redeclare `columnNames` for an entity without
losing the records of the first.

Note that the `entitySettings` merge is `ArrayUtility::mergeRecursiveWithOverrule()`
and *does* override, unlike the `'*'` merge inside the factory. The two merges
are not the same operation and only one of them has
[trap 1](#trap-1-the-merge-is-recursive-not-overriding).

## `--root-page`

`data-factory:import --root-page=<uid>` writes the set below an existing page instead
of at the page tree root. The transformation is applied to the **merged
settings**, before the factory is built, and it touches exactly this:

> For every item of every **top level** entity, the `self` or `version` map gets
> `pid: <rootPageId>` - unless it already declares a `pid` that is not `0`.

Nested items are untouched. A `children` item, a nested `entities` item, a
language variant and a version variant all take their `pid` from their parent or
their node, and moving them would take them off the tree they were declared in.
Applied only when the option is greater than zero, so a default run leaves the
scenario byte identical to what it declares.

The two obvious alternatives do not work:

- **Overriding `entitySettings.*.defaultValues.pid`** misses every entity that
  is not listed in `entitySettings`, because of
  [trap 2](#trap-2--reaches-only-declared-entities).
- **Rewriting the built data map** would mean changing the port:
  `DataHandlerFactory` exposes its maps read-only and `DataHandlerWriter` takes
  the factory, not the maps.

One limitation follows from where it acts: the check for an already declared
`pid` reads the literal key `pid`, so an item declaring its `pid` through a
`columnNames` alias is moved anyway. Declare the column under its own name where
that matters.

## What the engine does that a scenario cannot

Two properties of the engine reach through the format and are documented with
the engine rather than here, because they are properties of the ported code:

- **A scenario file behaves the same here and in a TYPO3 Core functional test,
  with seven stated exceptions.** All seven are defects of
  `typo3/testing-framework` that are fixed here - among them the sibling
  ordering of every table other than `pages`, and `{action: 'discard'}`, which
  upstream drops without a word. See
  [The deliberate divergences](../architecture/scenario-engine.md#the-deliberate-divergences).
- **Two behaviours are pinned rather than fixed**, because neither belongs to
  the engine: a translation of a translation reaches the database with the
  original as its `l10n_source`, and a translated page is positioned by
  `nodeColumnName` rather than by `parentColumnName`. See
  [Tests](../architecture/scenario-engine.md#tests).

## Files

```yaml
files:
  - identifier: placeholder             # required, unique among the files
    source: 'Files/placeholder.svg'     # required; relative to the set, or EXT:
    folder: 'seed-files'                # optional, default the storage root
    name: 'placeholder.svg'             # optional, default the source basename
    storage: 1                          # optional, default the default storage
```

| Key          | Required | Type   | Default                    |
|--------------|----------|--------|----------------------------|
| `identifier` | yes      | string | -                          |
| `source`     | yes      | string | -                          |
| `folder`     | no       | string | `/`, the storage root      |
| `name`       | no       | string | the basename of the source |
| `storage`    | no       | int    | the default storage        |

A `folder` that does not exist is created. The file is copied into the storage
through the storage API, which is what indexes it - a file copied into
`fileadmin/` with `cp` exists on disk and does not exist for TYPO3. An existing
file of the same name is **replaced**. The source itself is never written to:
the storage is handed a temporary copy, see
[The file pass](../architecture/seeding.md#the-file-pass).

The key set is closed, like the set level and a site: a misspelled `foldr:` is
refused with `1787256303` rather than silently putting the file in the storage
root. What is *not* refused is a value of the wrong type - `folder`, `name` and
`storage` fall back to their default instead, which is the older behaviour and
is left alone. A missing source file *is* refused, naming both the declared path
and the absolute path it resolved to.

Files are written **before** the records, and the `sys_file` uid each one was
indexed under is reported by the command. Which record a file ends up on is not
declared here but under [`references`](#file-references) - `files:` is about
*placing* a file, and the same file may be referenced from several records or
from none.

## File references

```yaml
references:
  - file: placeholder                   # required, an identifier declared under "files"
    table: tt_content                   # required, the table of the record it hangs on
    uid: 21                             # required, the uid the SCENARIO declares as "id"
    field: tx_testsfilefields_media     # required, a TCA "type => file" column of that table
    values:                             # optional, the fields of the sys_file_reference row
      title: 'The placeholder'
      alternative: 'A grey rectangle'
```

| Key      | Required | Type   | Default | Meaning                                                                                 |
|----------|----------|--------|---------|-----------------------------------------------------------------------------------------|
| `file`   | yes      | string | -       | The `identifier` of a file the same descriptor declares under `files`.                  |
| `table`  | yes      | string | -       | The table of the record the reference is attached to.                                   |
| `uid`    | yes      | int    | -       | An integer >= 1: the `id` the scenario entity of that record declares.                  |
| `field`  | yes      | string | -       | The column of `table` the reference hangs on - a TCA `type => 'file'` relation.         |
| `values` | no       | map    | `[]`    | Fields written on the `sys_file_reference` row itself: `title`, `alternative`, `crop` … |

The key set is **closed** on the entry, exactly like a `files` or a `sites`
entry. `values` is the one open map of the whole descriptor, because it is the
one thing here that is written verbatim to a record; only its *shape* is
checked, and a nested map or a list is refused rather than reaching DataHandler
as the string `Array`.

**`file` is resolved against the descriptor, `uid` against the run.** That a
reference names a file the set does not declare is a mistake the parser can name
before anything happens, so it does. That `table`/`uid` names a record is
deliberately *not* checked there: the records live in the scenario files, which
the parser never reads. `data-factory:import` checks it against the composed scenario
before the first row is written - and `FileReferenceSeeder` checks it again
against what the run actually wrote, because that is the number the reference is
written with. It is the same rule [`rootPage`](#site-configurations) follows, and
for the same reason: a scenario record has no symbolic identifier, so its
declared `id` is its handle.

**The structural columns always win.** `uid_local`, `uid_foreign`, `tablenames`,
`fieldname` and `pid` are written by the seeder and are merged **over** whatever
`values` declares. A descriptor may not detach a reference from the record it
declares it on by naming `uid_foreign` itself. `pid` is the page the parent is
on - and for a parent that *is* a page, the page itself, which is the convention
TYPO3 follows for a page's own file fields.

References are written in a **pass of their own, after the records**. Why that
is not negotiable, and why the relation field of the parent record is written
along with them, is on
[Seeding](../architecture/seeding.md#the-file-reference-pass).

A reference to a record no scenario of the set declares is an error, not a
lookup: `data-factory:import` refuses the set with `EXIT_INVALID_DEFINITION`. Seeding
into an existing tree is not what this key is for.

## Inline relations need no support

An inline relation - a content element with children in a table of its own -
needs **nothing** from this extension and no key in either format. It is
expressible in the scenario format as it stands, and the mechanism is the one
the format already has:

> The parent declares its relation field as the **comma separated list of the
> declared ids of its children**, and the children are an entity of their own in
> the same scenario.

```yaml
entitySettings:
  '*':
    nodeColumnName: 'pid'
    columnNames: {id: 'uid'}
    defaultValues: {pid: 0, hidden: 0}
  page:
    isNode: true
    tableName: 'pages'
    parentColumnName: 'pid'
    defaultValues: {doktype: 1}
  content:
    tableName: 'tt_content'
    columnNames: {title: 'header', type: 'CType'}
  item:
    tableName: 'tx_testsinlinerelations_item'
  link:
    tableName: 'tx_testsinlinerelations_link'

entities:
  page:
    - self: {id: 1, title: 'Root', slug: '/', is_siteroot: 1}
      entities:
        content:
          - self: {id: 21, title: 'List', type: 'tests_inline_relations_itemlist', tx_testsinlinerelations_items: '32,31'}
        item:
          - self: {id: 31, title: 'One', links: '41,42'}
          - self: {id: 32, title: 'Two'}
        link:
          - self: {id: 41, title: 'First link'}
          - self: {id: 42, title: 'Second link'}
```

That is the body of
[`Tests/Functional/Fixtures/Scenarios/InlineRelationScenario.yaml`](../../Tests/Functional/Fixtures/Scenarios/InlineRelationScenario.yaml),
its header comment aside, and every sentence below is asserted by
[`InlineRelationSeedingTest`](../../Tests/Functional/Seeding/DataHandling/InlineRelationSeedingTest.php)
against the fixture extension
[`inline-relations`](../testing/fixture-extensions.md).

It works because a declared `id` is a **suggested uid**: the child rows really
are written under 31 and 32, so the list the parent carries names rows that exist
by the time `DataHandler::processRemapStack()` resolves it. DataHandler then
treats that list exactly like the one a backend form submits.

- **The columns that tie a child to its parent are not in the scenario.**
  `parentid`, `parenttable` and `sorting_foreign` are written by
  `TYPO3\CMS\Core\Database\RelationHandler::writeForeignField()`, and *which*
  columns those are comes from the `foreign_field`, `foreign_table_field` and
  `foreign_sortby` of the **parent field's TCA** - never from the child. A
  scenario declaring them itself would be describing the relation twice.
- **The order comes from the declared list, not from the uid order.** The example
  declares `'32,31'`, and the children come out with a `sorting_foreign` of
  `32 → 1` and `31 → 2`. Nothing sorts by uid anywhere.
- **The relation field of the parent ends up as the count of its children**, `2`
  here, because DataHandler writes the counter back into the relation column.
- **It works a level deeper.** `links: '41,42'` on the item is resolved against
  the TCA of `tx_testsinlinerelations_item.links` in exactly the same way, and
  the link rows carry a `parenttable` of `tx_testsinlinerelations_item`.
- **The children live where the scenario put them.** The relation ties a child to
  its parent *record*, not to its page: `pid` still comes from the enclosing node
  entity, as it does for the content element.

The nesting in the YAML is what puts the children on the page - the `entities:`
of the page entity - and says nothing about the relation. A child of an inline
relation is a sibling in the file and a child only in the database.

**The same trick does not work for a file reference**, and that is the entire
reason [`references`](#file-references) exists. A `sys_file_reference` needs
`uid_local`, and that is the `sys_file` uid the FAL indexer assigns while the
file is being placed. Nobody can write it down in advance, so there is no
declared id for the parent to list.

## Site configurations

```yaml
sites:
  - identifier: main                    # required, becomes config/sites/<identifier>/
    rootPage: 1000                      # required, the uid a "pages" entity declares
    template: 'Sites/main'              # optional, default Sites/<identifier>
    base: 'https://example.com/'        # optional, overrides the template's base
```

| Key          | Required | Type   | Default              | Notes                                                                  |
|--------------|----------|--------|----------------------|------------------------------------------------------------------------|
| `identifier` | yes      | string | -                    | Matches `/^[A-Za-z0-9][A-Za-z0-9_-]*$/`, unique within the definition. |
| `rootPage`   | yes      | int    | -                    | An integer >= 1: the `id` a `pages` entity of the scenario declares.   |
| `template`   | no       | string | `Sites/<identifier>` | Directory, relative to the set or `EXT:`.                              |
| `base`       | no       | string | the template's own   | `null` leaves the template alone, which is not an empty base.          |

This list of keys is **closed** as well: a site is configuration rather than a
record, nothing here is written verbatim, and an unknown key can only be a
mistake.

**`rootPage` is a page uid, not a name.** A scenario record has no symbolic
identifier - the `id` an entity declares *is* its handle, and it is the uid the
record is written with. The parser checks the shape only; that the uid is one a
`pages` entity of this set declares is checked once the scenario is composed,
and the import is refused with `EXIT_INVALID_DEFINITION` when it is not.

Two consequences of naming a uid:

- a set whose scenario declares no `id` for its root page cannot declare a site,
  because the dynamic uid is not knowable from the descriptor;
- `--force` gives up the uid suggestions of a colliding table. When that table
  is `pages` and the set declares sites, the import is **refused** rather than
  forced: the root page would silently be written under a different uid than the
  site points at.

What the template directory is and what happens to it is on
[Site configurations](../architecture/site-configuration.md).

## Imports

```yaml
imports:
  - { resource: Files.yaml }
  - { resource: 'EXT:my_extension/Configuration/DataFactory/shared/Sites.yaml' }
```

`imports` is handled by `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader`,
the loader the core reads its own site configurations with. It resolves a
resource relative to the file declaring it, accepts `EXT:` paths, and **merges**
an imported list into the importing one instead of replacing it - which means
this extension requires nothing beyond `typo3/cms-core` for it.

It applies to the **descriptor only**. An imported file carries the same keys as
`config.yml`, so it may contribute `files:`, `sites:` and further `scenarios:`
entries. It cannot contribute records: those live in the files `scenarios` names
and are read outside the loader.

Two deliberate deviations from how the core calls it:

- **Placeholders are switched off.** A `%…%` fragment that happens to name a key
  of the descriptor would be substituted with that key's value, and a title or a
  description is content that has to arrive as it was written. Note that this
  differs from a **site template**, where `%env(…)%` *is* meaningful and is
  deliberately left unresolved for the instance to evaluate.
- **A failing import raises** instead of being logged. The loader catches its
  exceptions per import and reports them to its logger; for a seed descriptor
  that is data loss - a typo in a resource path means those files or sites are
  silently not seeded and the import reports success.

The three metadata keys `identifier`, `title` and `description` have to be
declared in `config.yml` itself, because discovery reads them without the
importing loader; see
[Discovery reads metadata](seed-sets.md#discovery-reads-metadata).

## What is not in the format

| Not covered                                    | Why                                                                                                                                                                                                                                                                                       |
|------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| A file reference in a **scenario file**        | The scenario format has no concept of a file and does not gain one. A `sys_file_reference` needs the `sys_file` uid the FAL indexer assigns, which cannot be declared, so it is declared in [`references`](#file-references) of `config.yml` instead.                                     |
| A reference to a record the run does not write | `uid` is resolved against what the run wrote, not looked up in the installation. Naming a record no scenario of the set declares is refused before anything is written.                                                                                                                   |
| Updating an existing tree                      | Seeding writes. It does not reconcile an existing tree against a definition, and nothing here is idempotent.                                                                                                                                                                              |
| Explicit MM relation construction              | Not needed: a relation is expressed by writing the target into the relation field, and DataHandler writes the MM rows into a table the seeder never names.                                                                                                                                |
| A `be_users` record without credentials        | `isImporting` disables the generated password and username, so such a record cannot log in. Declare `username` and `password`.                                                                                                                                                            |
| Deleting anything                              | An import refuses a uid collision and refuses an existing site identifier, and there is no mode in which it removes a record. The one thing it overwrites is a file: `files:` is placed with `DuplicationBehavior::REPLACE`, so a file of the same name in the target folder is replaced. |

## A worked set that is executed

`Documentation/Configuration/Index.rst` prints one complete set — a page tree
with content, a translation of both, a file, a file reference and a site. That
example is not written in prose: it is the fixture set
`Tests/Functional/Fixtures/Extensions/seeds-import/Configuration/DataFactory/documented/`,
and `DocumentedSeedSetTest` both imports it and compares every captioned code
block of that section against the file it names. Change either side alone and a
test goes red.

Use it when a change to the format needs an end-to-end example, rather than
adding a second one that nothing runs.

## See also

- [The scenario engine](../architecture/scenario-engine.md) - why this format, and what the port changed
- [Seeding](../architecture/seeding.md) - what happens between the composition and the database
- [Seed sets and the CLI](seed-sets.md) - discovery, ordering and the commands
- [Site configurations](../architecture/site-configuration.md)
- [Quality gates](quality-gates.md)
