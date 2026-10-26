<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\FileSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What `languageVariants` puts in the database.
 *
 * The data map a translation produces is pinned by
 * {@see \SBUERK\DataFactory\Tests\Unit\Seeding\Scenario\DataHandlerFactoryLanguageVariantTest},
 * key by key and without a database. This test is about the other half: what
 * survives `DataHandler`. The two are not the same thing, and the difference is
 * the reason this file exists rather than a second unit test - a translation
 * chain the factory expresses correctly reaches the database flattened, and
 * only a real TCA and a real record can show it.
 *
 * `EXT:workspaces` is deliberately not loaded here. A translation is an
 * ordinary record with language columns; the workspace half of the engine is
 * covered by {@see WorkspaceSeedingTest}, which pays for the extra extension.
 */
final class LanguageVariantSeedingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    #[Test]
    public function everyTranslationIsWrittenWithTheUidItDeclares(): void
    {
        $this->seed();

        $this->assertSame(
            [100, 101, 102, 110, 111, 200, 210, 211],
            array_column($this->rows('pages', 'uid'), 'uid'),
        );
        $this->assertSame(
            [300, 301, 302],
            array_column($this->rows('tt_content', 'uid'), 'uid'),
        );
    }

    #[Test]
    public function theMappedLanguageColumnBecomesTheLanguageOfTheRecord(): void
    {
        $this->seed();

        // The fixture declares "language", not "sys_language_uid": the column
        // is reached through "columnNames", exactly as every scenario file of
        // TYPO3 Core reaches it.
        $this->assertSame(
            [100 => 0, 101 => 1, 102 => 2, 110 => 0, 111 => 1, 200 => 0, 210 => 0, 211 => 1],
            $this->column('pages', 'sys_language_uid'),
        );
        $this->assertSame(
            [300 => 0, 301 => 1, 302 => 2],
            $this->column('tt_content', 'sys_language_uid'),
        );
    }

    #[Test]
    public function aTranslationPointsBothLanguageColumnsAtTheRecordItTranslates(): void
    {
        $this->seed();

        // One ancestor, two declared language columns: the positional walk of
        // "processLanguageValues()" puts the same id in both.
        $this->assertSame(100, $this->column('pages', 'l10n_parent')[101]);
        $this->assertSame(100, $this->column('pages', 'l10n_source')[101]);
        $this->assertSame(110, $this->column('pages', 'l10n_parent')[111]);
        $this->assertSame(110, $this->column('pages', 'l10n_source')[111]);
    }

    #[Test]
    public function aContentTranslationUsesTheTransOrigPointerFieldOfItsOwnTable(): void
    {
        $this->seed();

        // "tt_content" spells it "l18n_parent" while "pages" spells it
        // "l10n_parent". Nothing in the engine knows that - the fixture
        // declares the two column names per entity, and that is the whole
        // mechanism.
        $this->assertSame(300, $this->column('tt_content', 'l18n_parent')[301]);
        $this->assertSame(300, $this->column('tt_content', 'l10n_source')[301]);
    }

    #[Test]
    public function aTranslationOfATranslationDeclaresTheTranslationItCameFrom(): void
    {
        $definition = $this->definition();
        $dataMap = (new ScenarioComposer())->compose($definition, 0)->getDataMapPerWorkspace()[0];

        $identifiers = array_keys($dataMap['pages']);
        $original = $identifiers[0];
        $firstTranslation = $identifiers[1];
        $nestedTranslation = $identifiers[2];

        // The factory gets it right: the nested translation parents the
        // original and sources the translation it is nested in. This is the
        // premise of the test below - without it, that one would be asserting
        // that a value nothing ever produced is missing.
        $this->assertSame($original, $dataMap['pages'][$nestedTranslation]['l10n_parent']);
        $this->assertSame($firstTranslation, $dataMap['pages'][$nestedTranslation]['l10n_source']);
    }

    #[Test]
    public function aTranslationOfATranslationKeepsTheSourceItDeclares(): void
    {
        $this->seed();

        // "l10n_source" is a "passthrough" column. Written in the same run as
        // its source, a "NEW" identifier in it went on the remap stack of
        // "DataHandler" with no "func" to resolve it, and
        // "DataHandler::processRemapStack()", which never resets "$newValue",
        // wrote the "l10n_parent" remapped immediately before it. TYPO3 Core
        // carries that as a "@todo" on the nested "languageVariants" of its
        // own "CommonScenario.yaml".
        //
        // The writer writes a translation one run after its source, so the
        // identifier is substituted by the uid of the source before the run,
        // and the remap stack never sees it.
        $this->assertSame(100, $this->column('pages', 'l10n_parent')[102]);
        $this->assertSame(101, $this->column('pages', 'l10n_source')[102]);
        $this->assertSame(300, $this->column('tt_content', 'l18n_parent')[302]);
        $this->assertSame(301, $this->column('tt_content', 'l10n_source')[302]);
    }

    #[Test]
    public function aTranslatedPageOfANodeEntityWithoutANodeColumnFallsOutOfTheTree(): void
    {
        $this->seed();

        // Page 110 is a child of 100 and sits on it. Its translation is not a
        // child of anything: "processLanguageVariantItem()" passes no parent
        // id, so "parentColumnName" is never assigned. What is assigned is
        // "nodeColumnName", and the "page" entity of the fixture declares
        // none - so the "pid" falls back to the "defaultValues" of the entity,
        // 0 here, and 0 is where the translation lands.
        $this->assertSame(100, $this->column('pages', 'pid')[110]);
        $this->assertSame(0, $this->column('pages', 'pid')[111]);
    }

    #[Test]
    public function aTranslatedPageOfANodeEntityWithANodeColumnStaysNextToItsOriginal(): void
    {
        $this->seed();

        // The same declaration one entity further down the fixture, and the
        // only difference is "nodeColumnName: 'pid'" - which every scenario
        // file of TYPO3 Core declares, on the "'*'" entity, and which is
        // therefore the normal case rather than this one.
        //
        // A language variant of a node entity is handed the node id
        // "-<identifier of the original>", the "insert directly after"
        // convention, so the translation is written onto the page its original
        // sits on and directly behind it.
        $this->assertSame(200, $this->column('pages', 'pid')[210]);
        $this->assertSame(200, $this->column('pages', 'pid')[211]);
    }

    #[Test]
    public function aTranslatedContentElementStaysOnThePageItWasDeclaredUnder(): void
    {
        $this->seed();

        // The contrast to the page above: a non node entity keeps the node id
        // of its surroundings, so "nodeColumnName" is assigned for the
        // translation as well and the element stays where its original is.
        $this->assertSame(100, $this->column('tt_content', 'pid')[301]);
        $this->assertSame(100, $this->column('tt_content', 'pid')[302]);
    }

    #[Test]
    public function theResultReportsEveryTranslationAsARecordOfItsOwn(): void
    {
        $result = $this->seed();

        $this->assertSame(['pages' => 8, 'tt_content' => 3], $result->recordCounts);
        $this->assertSame(102, $result->writtenUid('pages', 102));
        $this->assertSame(302, $result->writtenUid('tt_content', 302));
    }

    private function definition(): SeedDefinition
    {
        return new SeedDefinition(
            identifier: 'language-variant-seeding',
            title: 'Translations',
            basePath: dirname(__DIR__, 2) . '/Fixtures/Scenarios',
            scenarios: ['LanguageVariantScenario.yaml'],
        );
    }

    private function seed(): ScenarioSeedResult
    {
        $definition = $this->definition();
        $seeder = new ScenarioSeeder(
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        );

        return $seeder->seed(
            $definition,
            (new ScenarioComposer())->compose($definition, 0),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what happens to be visible.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string ...$columns): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', ...$columns)
            ->from($table)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * One column of a table, keyed by uid and cast to int - the DBMS decide for
     * themselves whether an integer column comes back as an int or as a string.
     *
     * @return array<int, int>
     */
    private function column(string $table, string $column): array
    {
        $values = [];
        foreach ($this->rows($table, $column) as $row) {
            $values[(int)$row['uid']] = (int)$row[$column];
        }

        return $values;
    }
}
