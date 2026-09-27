..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension itself has nothing to configure. What is configured is the data:
a **seed set**, written in YAML, shipped by an extension, and read by the two
console commands.

A set is made of two kinds of file, and keeping them apart is the whole layout
of the format:

*   :file:`config.yml` describes the **set** - what it is called, which scenario
    files it is written from, which files it brings, which of those files are
    attached to which records, and which site configurations it writes.
*   One or more **scenario files** describe the **records**. They are written in
    the YAML scenario format of :php:`typo3/testing-framework`, the format the
    TYPO3 Core writes its own functional test fixtures in.

This chapter is the reference for both, and for the commands. It is written to
be read in order; if you would rather see a whole set first and look the keys
up afterwards, start at :ref:`A complete seed set <configuration-complete-set>`.

..  contents::
    :local:
    :depth: 2

..  _configuration-layout:

Where a seed set lives
======================

A seed set is a directory :file:`Configuration/DataFactory/<name>/` inside any
active extension, with :file:`config.yml` as its entry file:

..  code-block:: none

    packages/my_extension/Configuration/DataFactory/demo/
    ├── config.yml               entry file, and the only mandatory one
    ├── Scenario/
    │   ├── Pages.yaml           the records, named by "scenarios"
    │   └── Content.yaml
    ├── Files.yaml               optional, pulled in through "imports"
    ├── Files/
    │   └── placeholder.svg      resources named by "files"
    └── Sites/
        └── main/
            ├── config.yaml      site configuration template
            └── settings.yaml    optional site settings

Every relative path inside a set - a :yaml:`scenarios` entry, a file
:yaml:`source`, a site :yaml:`template` - is resolved against the directory
holding the **entry file**, not against the file declaring it. A set can
therefore be moved or renamed without touching a single path inside it.
:file:`EXT:` paths are accepted everywhere a path is, and an absolute path is
taken as it stands.

An :yaml:`imports` resource is the one exception: it never reaches this
extension, it is resolved by the TYPO3 YAML loader relative to the file that
declares it - see
:ref:`Splitting a set over several files <configuration-imports>`.

A set is found because the extension providing it is installed and activated.
There is no path to configure and nothing to register. A directory below
:file:`Configuration/DataFactory/` without a :file:`config.yml` is not a set and is
passed over, which is what lets a set keep partials next to itself.

..  _configuration-set-level:

The set descriptor
==================

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/demo/config.yml

    identifier: demo
    title: 'Demo page tree'
    description: 'Pages, content elements, a file and the site they are reachable through.'

    imports:
      - { resource: Files.yaml }

    scenarios:
      - Scenario/Pages.yaml
      - Scenario/Content.yaml

    files:
      - identifier: placeholder
        source: 'Files/placeholder.svg'
        folder: 'demo'

    references:
      - file: placeholder
        table: tt_content
        uid: 2000
        field: assets

    sites:
      - identifier: main
        rootPage: 1000
        template: 'Sites/main'

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`identifier`
        -   yes
        -   Globally unique across all active extensions. It is declared, never
            derived from the directory name - otherwise a collision between two
            extensions would be silent.
    *   -   :yaml:`title`
        -   yes
        -   Shown by :bash:`data-factory:list`.
    *   -   :yaml:`description`
        -   no
        -   Free text describing the set.
    *   -   :yaml:`imports`
        -   no
        -   Further YAML files merged into this descriptor.
    *   -   :yaml:`scenarios`
        -   yes
        -   The scenario files the records of the set are written from, in the
            order they are applied. A non-empty list of paths.
    *   -   :yaml:`files`
        -   no
        -   Files copied into a file storage before any record is written.
    *   -   :yaml:`references`
        -   no
        -   File references attached to seeded records, written after the
            records.
    *   -   :yaml:`sites`
        -   no
        -   Site configurations written after the records.

This list is **closed**: any other key at this level is refused, naming the
known ones. That is deliberate - :yaml:`scenario:` instead of :yaml:`scenarios:`
would otherwise be an import that reports success and writes nothing.

The descriptor carries **no** :yaml:`entitySettings` and **no** :yaml:`entities`.
One rule, no ambiguity: :file:`config.yml` describes the set, a scenario file
describes records. Nothing this extension invents is mixed into a scenario file,
and nothing a scenario file declares has to be understood by the descriptor.

The three metadata keys have to be declared in :file:`config.yml` itself. They
cannot be pulled in through :yaml:`imports`, because listing the sets of an
installation reads them without following imports.

..  _configuration-scenarios:

The scenario format
===================

A scenario file is a map of at most three keys. Anything else at the top level
is refused, naming the file:

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Meaning
    *   -   :yaml:`entitySettings`
        -   How a table is written: its name, its pointer columns, its column
            aliases, its default values.
    *   -   :yaml:`entities`
        -   The records themselves, per entity.
    *   -   :yaml:`__variables`
        -   A place to hang YAML anchors. It is read and dropped - anchors are
            resolved by the YAML parser and never cross a file.

..  _configuration-entity-settings:

entitySettings
--------------

An **entity** is a name a scenario writes records under. It is not a table: the
table is named by :yaml:`tableName`, which is what lets one table be written
under two names with different defaults - :yaml:`page` and :yaml:`folder` both
writing :sql:`pages`.

..  code-block:: yaml

    entitySettings:
      '*':
        nodeColumnName: 'pid'
        columnNames: {id: 'uid', language: 'sys_language_uid'}
        defaultValues: {pid: 0}
      page:
        isNode: true
        tableName: 'pages'
        parentColumnName: 'pid'
        languageColumnNames: ['l10n_parent', 'l10n_source']
        columnNames: {type: 'doktype', root: 'is_siteroot'}
        defaultValues: {hidden: 0, doktype: 1}
        valueInstructions:
          shortcut:
            first: {shortcut: 0, shortcut_mode: 1}
      content:
        tableName: 'tt_content'
        languageColumnNames: ['l18n_parent', 'l10n_source']
        columnNames: {title: 'header', type: 'CType'}
        defaultValues: {hidden: 0, CType: 'text', colPos: 0}
      category:
        tableName: 'sys_category'

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Meaning
    *   -   :yaml:`isNode`
        -   The records of this entity can carry nested :yaml:`entities`, and
            become their node. In practice: the entity writing :sql:`pages`.
    *   -   :yaml:`tableName`
        -   The table the entity writes into. Defaults to the entity name, so
            an entity called :yaml:`sys_category` needs no setting at all.
    *   -   :yaml:`nodeColumnName`
        -   The column that receives the uid of the node a record sits on -
            :sql:`pid` for everything that lives on a page.
    *   -   :yaml:`parentColumnName`
        -   The column that receives the uid of the parent a :yaml:`children`
            entry hangs under - :sql:`pid` for a sub page.
    *   -   :yaml:`columnNames`
        -   Aliases: the key a record writes on the left, the real column on
            the right. :yaml:`{id: 'uid'}` is what makes :yaml:`id` the
            declared uid.
    *   -   :yaml:`languageColumnNames`
        -   The columns a :yaml:`languageVariants` entry gets its ancestor uids
            written into, in order - :yaml:`['l10n_parent', 'l10n_source']`.
    *   -   :yaml:`defaultValues`
        -   Written to every record of the entity unless the record declares
            the value itself.
    *   -   :yaml:`valueInstructions`
        -   A declared value expanded into several columns, see below.

Those eight keys are the whole vocabulary of an entity. The entry :yaml:`'*'` is
not an entity but the **defaults for the declared entities**, merged into each
of them.

..  warning::

    The :yaml:`'*'` entry is merged with :php:`array_merge_recursive()`, which
    does not override - it **appends**. A key declared on both sides becomes a
    list. Declaring :yaml:`defaultValues: {hidden: 0}` in :yaml:`'*'` *and*
    :yaml:`defaultValues: {hidden: 1}` in an entity does not produce
    :yaml:`hidden: 1`, it produces :yaml:`hidden: [0, 1]`, which reaches the
    database as the string ``Array``.

    Declare a given key on one side only. Where both sides need something,
    split it: put :yaml:`pid` in :yaml:`'*'` and :yaml:`doktype` in
    :yaml:`page`, never :yaml:`hidden` in both.

..  warning::

    The :yaml:`'*'` defaults reach only entities that are **listed** in
    :yaml:`entitySettings`. An entity that appears in :yaml:`entities:` and
    nowhere in :yaml:`entitySettings:` is built from nothing: its table is its
    own name, it has no aliases, no defaults and no :yaml:`nodeColumnName` - so
    its records get no :sql:`pid` and land on the page tree root.

    An entity that needs nothing but the wildcard defaults still has to be
    listed. An empty declaration is enough:

    ..  code-block:: yaml

        entitySettings:
          '*':
            nodeColumnName: 'pid'
            columnNames: {id: 'uid'}
          category:
            tableName: 'sys_category'

:yaml:`defaultValues` are written with the column names they are **declared**
with. Aliases from :yaml:`columnNames` are not applied to them, so a default
belongs under the real column name - :yaml:`{CType: 'text'}`, not
:yaml:`{type: 'text'}`.

A :yaml:`valueInstructions` block turns one declared value into several columns.
It is keyed by the name the record declares, then by the value it declares, and
its own keys are real column names:

..  code-block:: yaml

    page:
      valueInstructions:
        shortcut:
          first: {shortcut: 0, shortcut_mode: 1}

A page declaring :yaml:`shortcut: 'first'` is then written with
:yaml:`shortcut: 0` and :yaml:`shortcut_mode: 1` - "shortcut to the first sub
page" spelled once instead of on every page that wants it.

..  _configuration-entities:

entities
--------

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/demo/Scenario/Pages.yaml

    entities:
      page:
        - self: {id: 1000, title: 'Demo', root: true, slug: '/'}
          entities:
            content:
              - self: {id: 2000, title: 'A frontend to look at', type: 'header'}
              - self: {id: 2001, bodytext: '<p>Seeded, not clicked together.</p>'}
          children:
            - self: {id: 1100, title: 'About', slug: '/about'}
              entities:
                content:
                  - self: {id: 2100, title: 'About us'}
            - self: {id: 1200, title: 'Contact', slug: '/contact'}
        - self: {id: 1900, title: 'Storage', type: 254, slug: '/storage'}
          entities:
            category:
              - self: {id: 3000, title: 'News'}

:yaml:`entities` is a map of entity name to a **list of items**. Every item is a
map, and the keys it may carry are:

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Meaning
    *   -   :yaml:`self`
        -   The record itself, as a map of declared names to values. Required,
            unless :yaml:`version` takes its place.
    *   -   :yaml:`version`
        -   The record itself, written into a workspace instead of live. It
            requires a :yaml:`workspace`, and it cannot be combined with
            :yaml:`self`.
    *   -   :yaml:`children`
        -   Further items of the **same entity**, hung under this one through
            :yaml:`parentColumnName`.
    *   -   :yaml:`entities`
        -   Records of **other entities** placed on this one through
            :yaml:`nodeColumnName`. Only an entity with :yaml:`isNode: true`
            processes them.
    *   -   :yaml:`languageVariants`
        -   Translations of this record, see below.
    *   -   :yaml:`versionVariants`
        -   Workspace versions of this record, see below.
    *   -   :yaml:`actions`
        -   Commands run on this record after every record was written, see
            below.

Everything inside :yaml:`self` that is not :yaml:`id` is a field: it is resolved
through :yaml:`columnNames` and written as it stands. A table therefore needs no
support in this extension to be seedable - declare an entity for it, and if the
column exists and TYPO3 accepts the value, the seed writes it.

Records come out in the order they are declared, whatever table they belong to,
and records of different tables on one page do not disturb each other's
sorting. Where the order has to be something other than the declared one,
declare an :ref:`actions <configuration-actions>` entry with
:yaml:`{action: 'move', type: 'afterRecord', target: <uid>}`.

..  warning::

    The scenario format is not key checked beyond its three top level keys. An
    item key that is not one of the seven above is **silently ignored** - a
    misspelled :yaml:`childern:` writes nothing and reports nothing. Only
    :file:`config.yml` refuses an unknown key.

    A dry run is the cheapest guard against that: :bash:`data-factory:import demo
    --dry-run -v` lists every record the scenario builds, per table and with
    its uid.

..  _configuration-language-variants:

Translations
------------

:yaml:`languageVariants` is the first-class translation construct of the format.
A variant is an ordinary item of the same entity, and the seeder fills the
columns named by :yaml:`languageColumnNames` with the uids of its ancestors:

..  code-block:: yaml

    entitySettings:
      '*':
        nodeColumnName: 'pid'
        columnNames: {id: 'uid', language: 'sys_language_uid'}
        defaultValues: {pid: 0}
      page:
        isNode: true
        tableName: 'pages'
        parentColumnName: 'pid'
        languageColumnNames: ['l10n_parent', 'l10n_source']
        columnNames: {type: 'doktype', root: 'is_siteroot'}
        defaultValues: {hidden: 0, doktype: 1}
      content:
        tableName: 'tt_content'
        languageColumnNames: ['l18n_parent', 'l10n_source']
        columnNames: {title: 'header', type: 'CType'}
        defaultValues: {hidden: 0, CType: 'text', colPos: 0}

    entities:
      page:
        - self: {id: 1000, title: 'EN: Demo', root: true, slug: '/'}
          children:
            - self: {id: 1100, title: 'EN: About', slug: '/about'}
              languageVariants:
                - self: {id: 1101, title: 'DE: Über uns', language: 1, slug: '/ueber-uns'}
                - self: {id: 1102, title: 'FR: À propos', language: 2, slug: '/a-propos'}
              entities:
                content:
                  - self: {id: 2100, title: 'EN: About us'}
                    languageVariants:
                      - self: {id: 2101, title: 'DE: Über uns', language: 1}

Three things follow from how the variants are written:

*   **The language columns are structure.** With
    :yaml:`languageColumnNames: ['l10n_parent', 'l10n_source']`, the first
    variant of a record gets both columns pointing at the original. A variant
    nested inside a variant - a translation of a translation - is built with
    :sql:`l10n_parent` from the first ancestor and :sql:`l10n_source` from the
    one directly above it, which is the chain TYPO3 expects.

    ..  note::

        That chain does **not** survive the write. :sql:`l10n_source` is a
        :yaml:`passthrough` column, so the reference to a record that does not
        exist yet is resolved on :php:`DataHandler`'s remap stack - and an
        entry of that stack without a resolver reuses the value the entry
        before it produced, which for a translation is always the
        :sql:`l10n_parent` that was resolved immediately before. A translation
        of a translation therefore ends up with both columns on the original.

        TYPO3 Core's own scenario fixture carries the same observation as a
        :yaml:`@todo`. Declare :sql:`l10n_source` explicitly on the variant if
        the chain matters - a declared value wins, as the next point says.
*   **A declared value still wins.** A variant declaring :sql:`l10n_source`
    itself overrides what the seeder computed.
*   **The language itself is an ordinary field.** :yaml:`language` is only a
    column alias for :sql:`sys_language_uid`, declared in
    :yaml:`columnNames`. It is not built into the format.
*   **A translated page follows its original through** :yaml:`nodeColumnName`
    **, not through** :yaml:`parentColumnName`. A language variant is written
    without the parent pointer of the item it translates - what it is given
    instead is the node pointer, set to "directly after the original". A node
    entity declaring :yaml:`nodeColumnName: 'pid'`, as every scenario file of
    TYPO3 Core does on its :yaml:`'*'` entry, therefore puts the translated
    page on the page its original sits on and right behind it. A node entity
    that declares only :yaml:`parentColumnName` has nothing to be positioned
    by, and the :sql:`pid` falls back to the :yaml:`defaultValues` of the
    entity - usually ``0``, which is out of the tree. A nested record such as a
    content element is not affected either way: it keeps the node it was
    declared under.

..  _configuration-version-variants:

Workspace records
-----------------

There are two ways into a workspace, and they mean different things.

:yaml:`version:` **in place of** :yaml:`self:` writes the record itself into a
workspace. There is no live record - it is a record that was created in a
workspace and never published:

..  code-block:: yaml

    entitySettings:
      workspace:
        tableName: 'sys_workspace'

    entities:
      workspace:
        - self: {id: 1, title: 'Draft'}
      page:
        - self: {id: 1000, title: 'Demo', root: true, slug: '/'}
          children:
            - version: {id: 1300, title: 'EN: Coming soon', slug: '/soon', workspace: 1}

:yaml:`versionVariants:` writes a workspace **overlay of a live record**: the
record exists live as its :yaml:`self` declares, and the variant is the changed
version an editor would see in that workspace:

..  code-block:: yaml

    entities:
      page:
        - self: {id: 1100, title: 'EN: About', slug: '/about'}
          versionVariants:
            - version: {title: 'EN: About us, revised', workspace: 1}

Rules for both:

*   :yaml:`version` **requires** :yaml:`workspace`, and the workspace has to be
    a :sql:`sys_workspace` record - seed it in the same scenario, as above.
*   A :yaml:`versionVariants` entry may **not** declare :yaml:`self`, and may
    **not** declare an :yaml:`id`. The overlay is a row of its own with a uid
    the database assigns, and it is found through :sql:`t3ver_oid`, which holds
    the uid of the live record - the one the item above it declared. A uid that
    could be declared for it would therefore be a uid nothing honours: the
    overlay is created by :php:`DataHandler` while it versions the live record,
    not inserted by the seed.
*   :yaml:`languageVariants` and :yaml:`versionVariants` combine. A language
    variant may carry version variants of its own, and a
    :yaml:`languageVariants` entry may itself use :yaml:`version:` instead of
    :yaml:`self:` - a translation that only exists in a workspace.

The workspaces are written one after another, in the order they first appear in
the scenario, and a :yaml:`versionVariants` entry is reached after the live
record it hangs under. A workspace overlay therefore always finds the live
record it belongs to.

..  _configuration-actions:

actions
-------

An :yaml:`actions` list is not written with the record: it becomes a
:php:`DataHandler` **command**, and the commands run after every record of every
workspace exists. That is what lets an action name a record the same scenario
creates.

..  code-block:: yaml

    entities:
      page:
        - self: {id: 1000, title: 'Demo', root: true, slug: '/'}
          children:
            - self: {id: 1100, title: 'About', slug: '/about'}
            - self: {id: 1200, title: 'Archive', slug: '/archive'}
              versionVariants:
                # In workspace 1 the page was moved below "About".
                - version: {workspace: 1}
                  actions:
                    - {action: 'move', type: 'toPage', target: 1100}
            - self: {id: 1300, title: 'Draft only', slug: '/draft'}
              versionVariants:
                # In workspace 1 the page was deleted.
                - version: {workspace: 1}
                  actions:
                    - {action: 'delete'}

..  list-table::
    :header-rows: 1

    *   -   Action
        -   Effect
    *   -   :yaml:`{action: 'move', type: 'toPage', target: <uid>}`
        -   Move the record onto the page :yaml:`target`.
    *   -   :yaml:`{action: 'move', type: 'toTop'}`
        -   Move the record to the top of the node it sits on. It needs a node,
            so it applies to a nested record rather than to a top level page.
    *   -   :yaml:`{action: 'move', type: 'afterRecord', target: <uid>}`
        -   Move the record directly behind :yaml:`target`.
    *   -   :yaml:`{action: 'delete'}`
        -   Delete the record.
    *   -   :yaml:`{action: 'discard'}`
        -   Discard the workspace version, leaving the live record as it is.
            The version row is deleted outright rather than soft deleted, so it
            cannot be recovered. Only inside a workspace: on a record of the
            live workspace the action is dropped.

An unknown :yaml:`action` is ignored rather than refused, in line with the rest
of the scenario format.

..  _configuration-uids:

Declared uids, and what makes a seed reproducible
=================================================

**Every record of a scenario has a uid before it is written.** Either the item
declares one as :yaml:`id`, or the seeder hands out a dynamic one from ``10000``
upwards. Both are suggested to :php:`DataHandler`, so a seeded page tree is the
*same* page tree in every installation - a site configuration, a TypoScript
condition or a test may refer to a page by its number.

Declare the uid of everything that is referred to from outside the set: a site
root, a shortcut target, a storage folder. Let the rest be dynamic.

..  note::

    The dynamic uids are handed out **per entity name**, not per table. Two
    entities writing the same table - :yaml:`page` and :yaml:`folder` both on
    :sql:`pages` - each start counting at ``10000``, and the second collides
    with the first. The import refuses that before writing anything, naming the
    identifier. Declare :yaml:`id` on the records of the second entity, or give
    the two a single entity.

TYPO3 treats a suggested uid as a *suggestion*. If the uid is already used in
its table, the record is not written elsewhere - the insert fails. The import
therefore checks every suggested uid up front and refuses when one is taken,
naming the records in the way:

..  code-block:: none

    [ERROR] The seed set "demo" suggests 2 uids this installation already uses.

     ---------- ----- -------------------
      Table      Uid   Occupied by
     ---------- ----- -------------------
      pages      1000  Company site
      pages      1100  Products
     ---------- ----- -------------------

Nothing is written in that case. :bash:`--force` imports anyway: every record of
a **table something collides in** is written with a free uid instead of the
suggested one, a table nothing collides in keeps its uids, and nothing that is
in the way is deleted or changed.

A deleted record occupies its uid as much as any other one - the row is still
there.

..  note::

    A forced run writes the records of a colliding table with the uid the
    database assigns, on every supported DBMS - PostgreSQL included. What the
    set declares for that table is given up entirely; the relations between its
    records are not.

..  warning::

    :bash:`--force` is refused for a set that declares :yaml:`sites` and
    collides in :sql:`pages`. Every site names its root page by uid, so giving
    up the page uids would point the site at a different page, or at none.
    Free the uids, or import with :bash:`--no-site-config`.

Suggested uids are honoured for an **administrator** backend user only.
:php:`DataHandler` ignores them silently for anybody else, so the import refuses
to run as a non-admin rather than write a set whose uids are not the ones it
declares.

..  _configuration-inline-relations:

Relations between records
=========================

A relation needs no key of its own, in :file:`config.yml` or anywhere else.
Because every record has a uid **before** it is written, the record holding the
relation can name the records on the other end of it by writing their uids into
its relation field, exactly as a backend form submits them:

..  code-block:: yaml
    :caption: an inline relation, expressed with what the scenario format already has

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
        tableName: 'tx_myext_item'

    entities:
      page:
        - self: {id: 1, title: 'Root', slug: '/', is_siteroot: 1}
          entities:
            content:
              - self: {id: 21, title: 'List', type: 'my_itemlist', tx_myext_items: '32,31'}
            item:
              - self: {id: 31, title: 'One'}
              - self: {id: 32, title: 'Two'}

That is the whole declaration. The relation itself is described where it is
always described, in the TCA of the field:

..  code-block:: php
    :caption: the TCA of tt_content.tx_myext_items

    'tx_myext_items' => [
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_myext_item',
            'foreign_field' => 'parentid',
            'foreign_table_field' => 'parenttable',
            'foreign_sortby' => 'sorting_foreign',
        ],
    ],

The children are an entity of their own in the same scenario, and the parent
declares its relation field as the **comma separated list of the ids they
declare**. Nothing else is needed: :php:`DataHandler` resolves that list like
any other relation and writes the columns tying a child to its parent by
itself. Which columns those are is read from the TCA of the **parent field** -
its ``foreign_field``, ``foreign_table_field`` and ``foreign_sortby``, which
are :sql:`parentid`, :sql:`parenttable` and :sql:`sorting_foreign` above. A
scenario therefore never names them, and a relation whose TCA calls them
something else needs nothing different here. The relation field of the
parent ends up holding the number of children, the way TYPO3 stores an inline
relation.

Two consequences are worth stating, because they are what an integrator will
actually rely on:

*   **The order of the relation is the order of the declared list**, not the
    order of the uids. The example writes ``'32,31'``, and item 32 comes first.
*   **It works more than one level deep.** A child that is itself the parent of
    a relation declares its own list the same way, and the second level is
    resolved from the TCA of the child's field. Nothing about it is special
    cased.

The children live on the page the scenario declares them under, like every
other record: the relation ties them to the parent record, not to its page.

..  note::

    The same trick cannot work for a **file** reference, and that is the whole
    reason :ref:`references <configuration-references>` exists. A
    :sql:`sys_file_reference` points at its file through :sql:`uid_local`, and
    that uid is handed out by the FAL indexer while the file is being placed -
    nobody can write it down in a scenario in advance. A file reference is
    therefore declared in :file:`config.yml`, where the file is declared too.

..  _configuration-files:

Files
=====

..  code-block:: yaml

    files:
      - identifier: placeholder             # required, unique among the files
        source: 'Files/placeholder.svg'     # required; relative to the set, or EXT:
        folder: 'demo'                      # optional, default the storage root
        name: 'placeholder.svg'             # optional, default the source basename
        storage: 1                          # optional, default the default storage

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`identifier`
        -   yes
        -   Unique among the files of the set.
    *   -   :yaml:`source`
        -   yes
        -   Where the file comes from: a path relative to the directory holding
            the set, or an :file:`EXT:` path.
    *   -   :yaml:`folder`
        -   no
        -   Target folder inside the storage. Defaults to the storage root.
    *   -   :yaml:`name`
        -   no
        -   The name the file is written under. Defaults to the base name of
            the :yaml:`source`.
    *   -   :yaml:`storage`
        -   no
        -   The uid of the storage to write into. Defaults to the default
            storage of the installation.

This list is **closed** as well: any other key on a file is refused, naming the
known ones - :yaml:`foldr:` instead of :yaml:`folder:` would otherwise put the
file in the storage root and report success.

Files are copied before the records are written, through the file storage API,
which is what indexes them - a file copied into :file:`fileadmin/` by hand
exists on disk and does not exist for TYPO3. A missing target folder is created,
an existing file of the same name is replaced, and the source file in the
extension is left where it is.

Placing a file is one thing and attaching it to a record is another, and the
second is what :ref:`references <configuration-references>` below does.

..  _configuration-references:

File references
===============

..  code-block:: yaml

    references:
      - file: placeholder                 # required, an identifier declared under "files"
        table: tt_content                 # required, the table of the record it hangs on
        uid: 2000                         # required, the uid the scenario declares as "id"
        field: assets                     # required, the file relation column of that record
        values:                           # optional, the fields of the sys_file_reference row
          title: 'A placeholder'
          alternative: 'Nothing to see here'

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`file`
        -   yes
        -   The :yaml:`identifier` of a file the same set declares under
            :yaml:`files`. A name no file of the set carries is refused.
    *   -   :yaml:`table`
        -   yes
        -   The table of the record the reference hangs on.
    *   -   :yaml:`uid`
        -   yes
        -   The **uid** of that record: the :yaml:`id` an entity of the
            scenario declares for it. A positive integer.
    *   -   :yaml:`field`
        -   yes
        -   The column of that record the file is attached to - a TCA file
            relation, such as :sql:`assets` or :sql:`image` on
            :sql:`tt_content` and :sql:`media` on :sql:`pages`.
    *   -   :yaml:`values`
        -   no
        -   The fields written on the :sql:`sys_file_reference` row itself: the
            ones an editor fills in on a file relation, such as ``title``,
            ``alternative``, ``description``, ``link`` or ``crop``. Every value
            has to be a string, a number, a boolean or null.

This list is **closed** as well: any other key on a reference is refused, naming
the known ones.

A scenario record carries no symbolic name, so a reference names its record by
uid - the same rule :yaml:`rootPage` follows, and for the same reason. That uid
is **resolved against what the run actually wrote** rather than trusted. A
reference naming a record no entity of the scenario declares is refused before
anything is written:

..  code-block:: none

    [ERROR] The seed set "demo" declares a file reference to "placeholder" on the
            record tt_content:2001, which no entity of its scenario declares as
            its "id".

Five columns of the :sql:`sys_file_reference` row are **structural** and are
written by the seeder: :sql:`uid_local`, :sql:`uid_foreign`,
:sql:`tablenames`, :sql:`fieldname` and :sql:`pid`. Declaring one of them under
:yaml:`values` does not change it - the seeder's value wins, because a
definition may not detach a reference from the record it declares it on.
:sql:`pid` follows the convention TYPO3 uses for a file relation: a reference
belongs to the page its record is on, and for a record that *is* a page, to that
page itself.

Several references on one field come out in the order they are declared, which
is what :sql:`sorting_foreign` is written from - so a multi image field is a
gallery in the declared order rather than in whatever order the database returns.
The same file may be referenced from several records, or from none.

References are written in a pass of their own, through :php:`DataHandler`,
after every record of the set exists. That is what puts the relation into the
reference index, and it is why a mistyped :yaml:`uid` is caught up front rather
than after the whole tree has been written.

A worked example
----------------

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/demo/config.yml

    identifier: demo
    title: 'A content element with two images'

    scenarios:
      - Scenario.yaml

    files:
      - identifier: landscape
        source: 'Files/landscape.jpg'
        folder: 'demo'
      - identifier: portrait
        source: 'Files/portrait.jpg'
        folder: 'demo'

    references:
      - file: landscape
        table: tt_content
        uid: 2000
        field: assets
        values: {title: 'Landscape', alternative: 'The wide one'}
      - file: portrait
        table: tt_content
        uid: 2000
        field: assets
      - file: landscape
        table: pages
        uid: 1000
        field: media

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/demo/Scenario.yaml

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

    entities:
      page:
        - self: {id: 1000, title: 'Demo', slug: '/', is_siteroot: 1}
          entities:
            content:
              - self: {id: 2000, title: 'Teaser', type: 'textmedia'}

The scenario file knows nothing about a file, and that is deliberate: it stays a
file that could be lifted into a functional test unchanged. The content element
2000 ends up with two images in the declared order, and the page 1000 with the
first of them in its :sql:`media` field.

..  _configuration-sites:

Site configurations
===================

..  code-block:: yaml

    sites:
      - identifier: main                    # required, the directory in config/sites/
        rootPage: 1000                      # required, the uid of a seeded page
        template: 'Sites/main'              # optional, default Sites/<identifier>
        base: 'https://example.com/'        # optional, overrides the template

A site configuration is written from a **template**: a directory holding a
:file:`config.yaml` and optionally a :file:`settings.yaml`, which is exactly the
shape of a site below :file:`config/sites/`. A template is therefore produced by
copying a working site out of an installation.

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Required
        -   Meaning
    *   -   :yaml:`identifier`
        -   yes
        -   Becomes the directory name below :file:`config/sites/`. Letters,
            digits, dashes and underscores, starting with a letter or a digit.
    *   -   :yaml:`rootPage`
        -   yes
        -   The **uid** of the page that becomes the site root. It has to be the
            :yaml:`id` an entity of the :sql:`pages` table of this set declares.
    *   -   :yaml:`template`
        -   no
        -   The template directory, relative to the set or an :file:`EXT:` path.
    *   -   :yaml:`base`
        -   no
        -   Replaces the ``base`` of the template.

This list is **closed** too: a site is configuration rather than a record, so
nothing on it is written verbatim and an unknown key can only be a mistake.

A scenario record carries no symbolic name, so its stable handle is the uid it
declares - which is why :yaml:`rootPage` is a number here and not a name. A site
naming a uid that no :sql:`pages` entity of the scenario declares is refused
before anything is written, rather than after the whole tree exists.

Three rules apply to the result:

*   **The root page always wins.** ``rootPageId`` is taken from the uid the
    declared page was actually written with, whatever the template says.
*   **An existing site identifier is refused.** TYPO3 *merges* an incoming site
    configuration into an existing one, so seeding over it would produce neither
    the template nor the previous configuration but a mixture of both. Remove
    the site first if the seed is meant to replace it.
*   **A minimal template needs almost nothing.** ``base`` and ``languages`` are
    worth declaring, because their defaults are a site on ``/`` in
    "Default / en_US.UTF-8". ``dependencies`` - the site sets a site pulls in -
    is written unchanged, and is a TYPO3 v13 key: TYPO3 v12 has no site sets, so
    a template declaring it seeds a configuration whose ``dependencies`` v12
    simply ignores.

Placeholders such as ``%env(...)%`` inside a template are **not** resolved while
seeding. They are written as they stand, so the installation resolves them every
time it reads the file - which is what they are for.

The site TYPO3 creates by itself
--------------------------------

TYPO3 writes an ``autogenerated-<uid>`` site configuration whenever a new page
becomes a site root. An import suppresses that, always - whether the set
declares :yaml:`sites` or not - because such a configuration is never what a
seed wanted.

The consequence is reported rather than left to be discovered: when a seeded
site root ends up covered by no site configuration at all, the import warns and
names the pages by uid. A page tree without a site is a frontend that cannot
render, and nothing else would say so.

The warning never names a translation. A translated page is reachable through
the site of the page it translates, in the languages that site declares, so a
site root with translations that no site covers is named once, by its own uid,
when the import wrote that page.

..  _configuration-complete-set:

A complete seed set
===================

Everything above, in one set. It is the set this extension's own functional
tests import, and a test compares the three files printed here against the
files on disk - so what is shown is what runs.

..  code-block:: none

    packages/my_extension/Configuration/DataFactory/complete/
    ├── config.yml
    ├── Scenario.yaml
    ├── Files/
    │   └── placeholder.svg
    └── Sites/
        └── main/
            └── config.yaml

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/complete/config.yml

    identifier: import-documented
    title: 'A complete seed set'
    description: 'Pages with content and translations, a file, a file reference and a site.'

    scenarios:
      - Scenario.yaml

    files:
      - identifier: placeholder
        source: 'Files/placeholder.svg'
        folder: 'documented'

    references:
      - file: placeholder
        table: pages
        uid: 1000
        field: media
        values:
          title: 'The placeholder'
          alternative: 'A grey rectangle'

    sites:
      - identifier: documented-main
        rootPage: 1000
        template: Sites/main

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/complete/Scenario.yaml

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
        defaultValues: {doktype: 1}
      content:
        tableName: 'tt_content'
        languageColumnNames: ['l18n_parent', 'l10n_source']
        columnNames: {title: 'header', type: 'CType'}

    entities:
      page:
        - self: {id: 1000, title: 'EN: Demo', slug: '/', is_siteroot: 1}
          children:
            - self: {id: 1100, title: 'EN: About', slug: '/about'}
              languageVariants:
                - self: {id: 1101, title: 'DE: Über uns', language: 1, slug: '/ueber-uns'}
              entities:
                content:
                  - self: {id: 2100, title: 'EN: About us', type: 'header'}
                    languageVariants:
                      - self: {id: 2101, title: 'DE: Über uns', language: 1, type: 'header'}

..  code-block:: yaml
    :caption: packages/my_extension/Configuration/DataFactory/complete/Sites/main/config.yaml

    rootPageId: 0
    base: 'https://documented.example.org/'
    languages:
      -
        title: English
        enabled: true
        languageId: 0
        base: /
        locale: en_US.UTF-8
      -
        title: Deutsch
        enabled: true
        languageId: 1
        base: /de/
        locale: de_DE.UTF-8
    errorHandling: []
    routes: []

Importing it with :bash:`vendor/bin/typo3 data-factory:import import-documented`
writes, in this order:

*   the file :file:`placeholder.svg` into :file:`fileadmin/documented/`, indexed
    as a :sql:`sys_file`;
*   the pages ``1000``, ``1100`` and its translation ``1101``, and the content
    elements ``2100`` and its translation ``2101``, each with the uid it
    declares;
*   a :sql:`sys_file_reference` attaching the file to :sql:`pages.media` of page
    ``1000``, carrying the declared :yaml:`title` and :yaml:`alternative`;
*   the site :file:`config/sites/documented-main/config.yaml`, from the
    template, with :yaml:`rootPageId` replaced by ``1000``.

Two details of the example are deliberate and worth copying. The entities
declare :yaml:`nodeColumnName`, which is what puts the translated page onto the
page its original sits on rather than out of the tree, and :yaml:`hidden` sits
on the :yaml:`'*'` entry while :yaml:`doktype` sits on :yaml:`page` - a key
declared on both sides is merged into a **list**, not overridden.

Workspaces are the one construct the example leaves out. They need a
:sql:`sys_workspace` record and have a
:ref:`section of their own <configuration-version-variants>`; a set that seeds
a workspace is not what a first set looks like.

..  _configuration-imports:

Splitting a set over several files
==================================

A set splits in two independent ways, and they are not interchangeable.

The descriptor: imports
-----------------------

..  code-block:: yaml

    imports:
      - { resource: Files.yaml }
      - { resource: 'EXT:my_extension/Configuration/DataFactory/shared/Sites.yaml' }

:yaml:`imports` is handled by the loader TYPO3 reads its own site
configurations with. Imported lists are **merged** into the importing file
rather than replacing it, and the imported entries come **first**: a
:file:`config.yml` declaring :yaml:`scenarios: [Content.yaml]` and importing a
file declaring :yaml:`scenarios: [Pages.yaml]` composes
``Pages.yaml, Content.yaml``. That is the order the scenarios are applied in, so
the entry file wins a conflicting :yaml:`entitySettings` value against anything
it imports. Resources are resolved relative to the file **declaring** them - not
against the entry file, which is the one path in a set that behaves this way -
and :file:`EXT:` paths are accepted. An imported file carries the same keys as
the entry file - except the three metadata keys, which belong into
:file:`config.yml`.

A resource that cannot be read stops the import with an error. It is not
skipped: a typo in a path would otherwise mean the files of that resource are
silently not seeded while the import reports success.

Placeholders are **not** substituted in a set descriptor, unlike in a site
configuration. A title or a description is content and has to arrive as it was
written, and ``%`` occurs in pairs in perfectly ordinary texts.

The records: several scenarios
------------------------------

..  code-block:: yaml

    scenarios:
      - Scenario/Pages.yaml
      - Scenario/Content.yaml
      - 'EXT:my_extension/Configuration/DataFactory/shared/Categories.yaml'

The listed files are composed into **one** scenario before anything is built,
in the order they are declared:

..  list-table::
    :header-rows: 1

    *   -   Key
        -   How it is merged
    *   -   :yaml:`entitySettings`
        -   Merged recursively. A later file wins a conflicting value, so a
            shared file can declare the entities and a set specific one can
            change a default.
    *   -   :yaml:`entities`
        -   Appended per entity name, in the order the files are declared. A
            later file adds records, it never replaces them.
    *   -   :yaml:`__variables`
        -   Dropped. YAML anchors are resolved per file and never cross one, so
            every file that uses anchors declares them itself.

One composed scenario rather than one per file, because the dynamic uids are
handed out per scenario: two scenarios would each start at ``10000`` and the
second insert would fail on the primary key. Composed, the same collision is
reported by name before anything is written.

A scenario file that does not exist, cannot be read, is not valid YAML, is not
a map, or declares an unknown top level key stops the import.

..  _configuration-limits:

What this version does not do
=============================

Two boundaries of the format, named here rather than left to be discovered:

*   **A file reference reaches only the records of its own set.** The
    :yaml:`uid` of a :yaml:`references` entry is resolved against what the run
    writes, so it cannot attach a file to a record that is already in the
    installation. Naming one is refused as an undeclared record, not answered
    with a database lookup.
*   **A file reference cannot be declared in a scenario file.** The scenario
    format has no concept of a file, and this extension does not give it one -
    that is the point of :ref:`references <configuration-references>` living in
    :file:`config.yml`. A scenario file of a seed set stays a file that could be
    lifted into a functional test unchanged.

..  _configuration-commands:

The commands
============

..  _configuration-command-list:

data-factory:list
-----------------

..  code-block:: bash

    vendor/bin/typo3 data-factory:list
    vendor/bin/typo3 data-factory:list -v

Lists identifier, title and providing extension of every set found. :bash:`-v`
adds the directory the set lives in. Sets appear in the order the installation
loads their extensions in, and within one extension sorted by directory name.

The command exits non-zero when an identifier is provided by more than one
extension - the sets cannot be told apart, so neither listing nor importing may
pick one of them - and when a :file:`config.yml` cannot be read or does not name
itself. An installation without any seed set is not an error.

..  _configuration-command-import:

data-factory:import
-------------------

..  code-block:: bash

    vendor/bin/typo3 data-factory:import demo
    vendor/bin/typo3 data-factory:import demo --dry-run -v
    vendor/bin/typo3 data-factory:import demo --base='https://example.com/'

Without an identifier the command asks which set to import. Without a terminal
to ask on - a deployment script, a pipeline, a hook - it lists the available
sets and exits non-zero rather than guessing. An identifier that names nothing
is answered with the sets that look like it.

..  list-table::
    :header-rows: 1

    *   -   Option
        -   Effect
    *   -   :bash:`--dry-run`
        -   Parse the descriptor, compose the scenario, build every record and
            check its uid, report what an import would do, and write nothing.
    *   -   :bash:`--force`
        -   Import although the set suggests uids this installation already
            uses. See :ref:`Declared uids <configuration-uids>`.
    *   -   :bash:`--root-page`
        -   The page the set is written below. ``0``, the default, is the page
            tree root. The page has to exist.
    *   -   :bash:`--base`
        -   Replaces the ``base`` of every site configuration the set writes.
            Use it to import one set into several installations.
    *   -   :bash:`--no-site-config`
        -   Skip the site configurations the set declares. The warning about
            uncovered site roots still appears.
    *   -   :bash:`-v`
        -   Add the table of uids: what a dry run would suggest, or what a real
            run wrote for each declared uid.

:bash:`--root-page` moves the **top level** items of every entity onto the given
page, and only those that do not declare a non-zero :sql:`pid` of their own - a
declared ``pid: 0`` names the page tree root, which is exactly what the option
replaces. Nested records, children, language variants and version variants are
untouched - they take their page from their node or from their ancestor, and
moving them would take them off the tree they were declared in.

Exit codes
~~~~~~~~~~

..  list-table::
    :header-rows: 1

    *   -   Code
        -   Meaning
    *   -   0
        -   The set was imported, or the dry run found nothing to complain
            about.
    *   -   2
        -   No identifier and no terminal to ask on, or an option value that
            cannot be used.
    *   -   3
        -   No active extension provides a set of that identifier.
    *   -   4
        -   The set cannot be told apart: the identifier is provided more than
            once, or a :file:`config.yml` in this installation cannot be read.
    *   -   5
        -   The set is not a valid seed definition: an unknown key, a scenario
            that cannot be read or built, a scenario without a single record, a
            site whose :yaml:`rootPage` the scenario does not declare, or a
            file reference whose record it does not declare.
    *   -   6
        -   The set suggests uids this installation already uses, or
            :bash:`--force` would give up the page uids a declared site needs.
    *   -   7
        -   There is no administrator backend user to write as.
    *   -   8
        -   Writing the set failed.

A dry run does everything a real run does except the writing: it resolves the
set, parses the descriptor, composes and builds the scenario, and checks the
uids. A set that a dry run accepts fails afterwards only for a reason that lives
in the installation rather than in the set.

..  _configuration-written:

What the seeder writes by itself
================================

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Rule
    *   -   ``uid``
        -   Suggested for every record: the declared :yaml:`id`, or a dynamic
            one from ``10000`` upwards.
    *   -   The node column
        -   Structure. The column named by :yaml:`nodeColumnName` gets the uid
            of the record the item is nested under, and is never taken from the
            item.
    *   -   The parent column
        -   Structure, likewise, for a :yaml:`children` entry and the column
            named by :yaml:`parentColumnName`.
    *   -   The language columns
        -   The columns named by :yaml:`languageColumnNames` get the ancestors
            of a :yaml:`languageVariants` entry. A value the variant declares
            itself wins.
    *   -   ``sorting``
        -   Computed by TYPO3 so that records appear in the order they are
            declared.
    *   -   ``slug``, TCA defaults and evaluations
        -   Everything :php:`DataHandler` derives from a record is derived,
            because the records go through it rather than through an
            :sql:`INSERT`.
    *   -   The reference columns
        -   :sql:`uid_local`, :sql:`uid_foreign`, :sql:`tablenames`,
            :sql:`fieldname` and :sql:`pid` of a :sql:`sys_file_reference`
            written from :yaml:`references`. A value declared for one of them
            under :yaml:`values` does not win.
    *   -   ``sorting_foreign``
        -   Written by TYPO3 for a relation, from the order the children - or
            the references - are declared in.

Everything else in a record comes from its :yaml:`self` or :yaml:`version`, from
the :yaml:`defaultValues` of its entity, or from the TCA of the installation
where neither declares anything. This extension adds no default of its own - in
particular, a **page** is created hidden unless the scenario says otherwise,
because :sql:`pages` is the one table whose TCA sets
:php:`'hidden' => ['config' => ['default' => 1]]`. :sql:`tt_content` and the
other tables of an installation default to ``0``. That is why every example
above declares :yaml:`defaultValues: {hidden: 0}`: it costs nothing on the
tables that already default to ``0``, and it is what keeps a seeded page tree
visible.

The same holds for :sql:`doktype`, :sql:`l10n_parent` and
:sql:`sys_language_uid` - they come from the TCA of the installation unless the
scenario declares them, so a set that wants the same records in two
installations declares them.

See also
========

*   :ref:`Introduction <introduction>`
*   :ref:`Installation <installation>`
*   :ref:`Changelog <changelog>`
