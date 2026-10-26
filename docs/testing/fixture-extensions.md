# Fixture extensions

A *fixture extension* is a minimal TYPO3 extension that exists only inside
[`Tests/Functional/Fixtures/Extensions/`](../../Tests/Functional/Fixtures/Extensions)
and is loaded by functional tests to provide test doubles, additional TCA,
service overrides or a plugin to render.

This repository ships seven:

| Fixture              | Extension key              | Provides                                                                                                                                                |
|----------------------|----------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| `example-fixture`    | `tests_example_fixture`    | The plugin, model and dummy service the mechanism itself is proven with.                                                                                |
| `seeds-demo`         | `tests_seeds_demo`         | Two seed sets and four site configuration templates, plus a directory below `Configuration/DataFactory/` that is not a set.                             |
| `seeds-collision`    | `tests_seeds_collision`    | A seed set claiming the identifier of `seeds-demo`, so a collision can be tested.                                                                       |
| `seeds-import`       | `tests_seeds_import`       | The set `data-factory:import` is driven with — declared uids, a file, a reference, a site, an `imports` — and one that is discoverable and unparseable. |
| `inline-relations`   | `tests_inline_relations`   | A content element with an inline relation to an item table, which carries a file field and an inline relation to a link table of its own.               |
| `language-relations` | `tests_language_relations` | A language aware inline child with two inline parents, a content element and a group whose select on the child is `l10n_mode` `exclude`.                |
| `file-fields`        | `tests_file_fields`        | A content element with a `type => 'file'` column and a CType of its own.                                                                                |

A fixture providing seed data needs nothing but a `composer.json` and its
`Configuration/DataFactory/<name>/config.yml` — no `Classes/`, no autoload section,
no `ext_localconf.php`. The plugin adopts what is there and says so for what is
not. `file-fields`, `inline-relations` and `language-relations` are the same
thing for TCA: a `composer.json` and `Configuration/TCA/`, with no PHP class in
them at all.

The three TCA fixtures exist so that seeding is proven against a **real relation**
rather than against a data map. Which columns tie an inline child to its parent
comes from the TCA of the *parent field* and never from the child, and a file
field that a core version happens to ship on `tt_content` today is free to
change tomorrow — neither is the subject of a test about seeding, so both are
provided here.

All three are genuinely exercised, and by a test each:

| Fixture              | Test                                                                                                         | Proves                                                                                                                           |
|----------------------|--------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `file-fields`        | [`FileReferenceSeedingTest`](../../Tests/Functional/Seeding/DataHandling/FileReferenceSeedingTest.php)       | The `references:` of a `config.yml` become `sys_file_reference` rows with the structural columns, the values and the order.      |
| `inline-relations`   | [`InlineRelationSeedingTest`](../../Tests/Functional/Seeding/DataHandling/InlineRelationSeedingTest.php)     | A scenario expresses an inline relation with nothing but declared ids, two levels deep, in the declared order.                   |
| `language-relations` | [`LanguageRelationSeedingTest`](../../Tests/Functional/Seeding/DataHandling/LanguageRelationSeedingTest.php) | A translation keeps its language and its translation parent on TYPO3 14.3.10 when its second parent is written in the same seed. |

The `tt_content` column of `file-fields` is `tx_testsfilefields_media` and its
CType is `tests_file_fields_teaser`; `inline-relations` provides
`tt_content.tx_testsinlinerelations_items` over `tx_testsinlinerelations_item`,
which in turn carries `links` over `tx_testsinlinerelations_link`. The second
level is what makes "a child may have children of its own" a proven statement
rather than an assumed one.

`inline-relations` also declares a `type => 'file'` column on its item table.
Nothing seeds it today — the file reference tests use `file-fields` and the core
`pages.media` — and it is left in place because a file reference on an *inline
child* is the obvious next thing somebody will want to prove.

## Two things a table has to declare to be seedable

Both were found while building `inline-relations`, both cost an afternoon, and
both are invisible until a test goes red for a reason that names something else.
They are recorded in the TCA files themselves as well.

**A custom table needs `ctrl.security.ignorePageTypeRestriction`.** Without it, a
record of that table may not be written onto a standard page at all: DataHandler
asks `PageDoktypeRegistry`, whose default doktype allows
`pages,sys_category,sys_file_reference,sys_file_collection` and everything
carrying that flag, and refuses the rest with *"Attempt to insert record on
pages:1 where table … is not allowed"*. Every core table an editor puts on a
page, `tt_content` included, declares it — so a fixture table that does not would
be testing an installation nobody has.

```php
'ctrl' => [
    'security' => [
        'ignorePageTypeRestriction' => true,
    ],
],
```

**An inline child of a workspace aware table has to be workspace aware itself.**
On **TYPO3 v14 only**, a child that is not gets migrated by the TCA migration —
with a deprecation, which this suite turns into a failure. `tt_content` is
workspace aware, so the item table below it needs `'versioningWS' => true`, and
the link table below *that* needs it in turn.

Neither is a rule about seeding. Both are rules about TCA that a fixture author
meets for the first time here, because a fixture table is usually the first table
somebody writes by hand.

## Why load them by composer package name

`typo3/testing-framework` resolves the entries of `$testExtensionsToLoad`
through its `ComposerPackageManager`, which only knows packages composer has
installed. A fixture extension is not installed — it lives inside the test
directory — so without help it can only be referenced by a path relative to the
repository root:

```php
protected array $testExtensionsToLoad = [
    'Tests/Functional/Fixtures/Extensions/example-fixture',
];
```

Paths are brittle: moving the fixture breaks every test naming it, and the
autoload configuration of the fixture still has to be registered by hand
somewhere. The [`sbuerk/fixture-packages`](https://github.com/sbuerk/fixture-packages)
composer plugin removes both problems, and the entry becomes the identifier the
extension itself declares:

```php
protected array $testExtensionsToLoad = [
    'tests/example-fixture',
];
```

## How it is wired

Three pieces, all of them already in place:

**1. The plugin is a development dependency and is allowed to run** — in
[`composer.json`](../../composer.json):

```json
"require-dev": {
    "sbuerk/fixture-packages": "^1.1.3"
},
"config": {
    "allow-plugins": {
        "sbuerk/fixture-packages": true
    }
}
```

**2. The paths to scan are configured** in the `extra` section of the same file:

```json
"extra": {
    "sbuerk/fixture-packages": {
        "paths": {
            "Tests/Functional/Fixtures/Extensions/*": [
                "autoload"
            ]
        }
    }
}
```

Every directory below that path containing a `composer.json` is picked up. Its
`autoload` section is adopted into the **`autoload-dev`** section of the root
package, which is what makes the fixture classes autoloadable in tests without
being autoloadable in a production installation. The plugin does this while
dumping the autoloader, so a newly added fixture extension becomes available
with:

```bash
Build/Scripts/runTests.sh -s composer -- dump-autoload
```

It also generates `.Build/vendor/sbuerk/AvailableFixturePackages.php`.

**3. The generated class is handed to the testing framework** in
[`Build/phpunit/FunctionalTestsBootstrap.php`](../../Build/phpunit/FunctionalTestsBootstrap.php):

```php
if (class_exists(AvailableFixturePackages::class)) {
    (new AvailableFixturePackages())->adoptFixtureExtensions();
}
```

`adoptFixtureExtensions()` registers each fixture extension with the
`ComposerPackageManager`, which is what allows both the composer package name
and the extension key to be used in `$testExtensionsToLoad`. The `class_exists()`
guard keeps the bootstrap working when the plugin is not installed, for example
in a `--no-dev` installation.

This is a deviation from the testing-framework boilerplate and is recorded as
such — see
[PHPUnit configuration](phpunit-configuration.md#deliberate-deviations-from-the-template).

## Layout of a fixture extension

```
Tests/Functional/Fixtures/Extensions/example-fixture/
├── composer.json
├── ext_localconf.php
├── ext_tables.sql
├── Classes/
│   ├── Controller/
│   │   └── HelloController.php
│   ├── Domain/
│   │   ├── Model/
│   │   │   └── Greeting.php
│   │   └── Repository/
│   │       └── GreetingRepository.php
│   └── Service/
│       ├── DummyService.php
│       └── DummyServiceInterface.php
├── Configuration/
│   ├── Services.php
│   ├── TCA/
│   │   ├── Overrides/
│   │   │   └── tt_content.php
│   │   └── tx_examplefixture_domain_model_greeting.php
│   └── TypoScript/
│       └── setup.typoscript
└── Resources/
    └── Private/
        └── Templates/
            └── Hello/
                └── Index.html
```

A fixture extension is a normal extension: `ext_localconf.php`, `ext_tables.sql`
and the TCA are loaded in the test instance exactly as they would be in a real
installation. Two parts of it exist for the tests of later sections:

- `Classes/Controller/`, `Configuration/TCA/Overrides/`,
  `Configuration/TypoScript/` and `Resources/` hold the Extbase plugin the
  [site based test](site-based-tests.md) renders.
- `Classes/Domain/`, `Configuration/TCA/tx_examplefixture_*` and `ext_tables.sql`
  hold the table, model and repository the
  [environment state test](environment-state.md) queries.

Note the table name: Extbase derives it from the **class name of the model**,
not from the extension key. `TESTS\ExampleFixture\Domain\Model\Greeting` becomes
`tx_examplefixture_domain_model_greeting` — the vendor part is dropped and the
rest lower cased. The extension key `tests_example_fixture` does not appear in
it.

`ext_tables.sql` declares the own fields only. TYPO3 derives `uid`, `pid`,
`deleted`, the language fields and the workspace fields from the TCA, so
declaring them by hand is redundant and drifts.

The TCA also carries the one core version switch of the fixture — `searchFields`
exists on v13 and was removed on v14. Configuration cannot use the `Core13/` and
`Core14/` split, so it is applied to the array before returning it; see
[Core version aware code](../architecture/core-version-aware-code.md#configuration-is-the-exception).
A fixture extension is held to the same rules as the extension itself here.

The [`composer.json`](../../Tests/Functional/Fixtures/Extensions/example-fixture/composer.json)
is what turns the directory into a package the plugin can find. It needs a name,
the `typo3-cms-extension` type, an extension key, a `version` — nothing resolves
one for a package that is not installed — and the autoload configuration to be
adopted:

```json
{
    "name": "tests/example-fixture",
    "type": "typo3-cms-extension",
    "version": "1.0.0-dev",
    "autoload": {
        "psr-4": {
            "TESTS\\ExampleFixture\\": "Classes/"
        }
    },
    "extra": {
        "typo3/cms": {
            "extension-key": "tests_example_fixture"
        }
    }
}
```

No `ext_emconf.php` is needed: the test instance is built in composer mode.

[`Configuration/Services.php`](../../Tests/Functional/Fixtures/Extensions/example-fixture/Configuration/Services.php)
is deliberately generic and does nothing but register the classes of the
fixture, exactly as a real extension would. Services are wired with
[dependency injection attributes](../architecture/dependency-injection.md) on
the classes themselves; the dummy service uses the same interface plus default
implementation pattern as the extension:

```php
#[AsAlias(id: DummyServiceInterface::class, public: true)]
final readonly class DummyService implements DummyServiceInterface
```

A fixture extension is **not** core version aware. There is no `Core13/` and
`Core14/` split — if a fixture needs to behave differently per core version,
that belongs in the test, not in the fixture.

## What the test proves

[`Tests/Functional/FixturePackagesTest.php`](../../Tests/Functional/FixturePackagesTest.php)
has the wiring as its subject, not the fixture:

| Assertion                                             | What breaks without it                                            |
|-------------------------------------------------------|-------------------------------------------------------------------|
| The extension is loaded under `tests/example-fixture` | The `adoptFixtureExtensions()` call in the bootstrap.             |
| The extension is loaded under `tests_example_fixture` | The extension key registration.                                   |
| `DummyServiceInterface` resolves from the container   | The `Configuration/Services.php` of the fixture is not processed. |
| `getExtensionKey()` returns its static result         | The adopted `autoload` configuration.                             |

That last assertion is why a static return value is enough: the point is that
the class was found and instantiated, not what it computes.

## Adding a fixture extension

1. Create the directory with a `composer.json` as above.
2. Run `Build/Scripts/runTests.sh -s composer -- dump-autoload` so the plugin
   picks it up.
3. Name it in `$testExtensionsToLoad` of the test that needs it. Redeclaring
   that property **replaces** the one of
   [`AbstractFunctionalTestCase`](../../Tests/Functional/AbstractFunctionalTestCase.php),
   so repeat the extension itself:

   ```php
   protected array $testExtensionsToLoad = [
       'sbuerk/data-factory',
       'tests/example-fixture',
   ];
   ```

Name a fixture after **what it provides**, not after the extension it belongs
to. The identifier is what appears in the `$testExtensionsToLoad` of every test
loading it, and there it is the only indication of what the fixture is for.

## See also

- [Functional tests](functional-tests.md)
- [PHPUnit configuration](phpunit-configuration.md)
- [Site based tests](site-based-tests.md)
- [Dependency injection](../architecture/dependency-injection.md)
- [Seed definitions](../development/seed-definitions.md) — the descriptor and the scenario format the seed fixtures are written in
- [Seeding](../architecture/seeding.md)
