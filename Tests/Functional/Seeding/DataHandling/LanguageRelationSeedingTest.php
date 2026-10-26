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
 * A translated inline child with a second inline parent that is not
 * translated.
 *
 * TYPO3 14.3.10 gives every inline child the language of a parent that is
 * created in the run (core #103970). The fixture extension
 * `language-relations` provides a member that belongs to a content element and
 * to a group, and the group is a select with `l10n_mode` `exclude`, so the
 * translation of a member names the group of its default language record.
 * Written in one run with the group, the translation became a member of the
 * default language without a translation parent. The writer writes the
 * translations of a round after its default language, which keeps them.
 *
 * TYPO3 13 aligns nothing, and the result is the same there.
 */
final class LanguageRelationSeedingTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        __DIR__ . '/../../Fixtures/Extensions/language-relations',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    #[Test]
    public function aTranslationKeepsItsLanguageWhenItsSecondParentIsWrittenInTheSameSeed(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid' => 51, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'content_uid' => 21, 'group_uid' => 61],
                ['uid' => 52, 'sys_language_uid' => 1, 'l10n_parent' => 51, 'content_uid' => 22, 'group_uid' => 61],
            ],
            $this->members(),
        );
    }

    #[Test]
    public function theTranslatedContentElementIsWrittenAsATranslation(): void
    {
        $this->seed();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'sys_language_uid', 'l18n_parent')
            ->from('tt_content')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertSame(
            [
                ['uid' => 21, 'sys_language_uid' => 0, 'l18n_parent' => 0],
                ['uid' => 22, 'sys_language_uid' => 1, 'l18n_parent' => 21],
            ],
            array_map(static fn(array $row): array => array_map(intval(...), $row), $rows),
        );
    }

    private function seed(): ScenarioSeedResult
    {
        $definition = new SeedDefinition(
            identifier: 'language-relations',
            title: 'A translated inline child with two inline parents',
            basePath: dirname(__DIR__, 2) . '/Fixtures/Scenarios',
            scenarios: ['LanguageRelationScenario.yaml'],
        );

        return (new ScenarioSeeder(
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        ))->seed(
            $definition,
            (new ScenarioComposer())->compose($definition),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * @return list<array<string, int>>
     */
    private function members(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_testslanguagerelations_member');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'sys_language_uid', 'l10n_parent', 'content_uid', 'group_uid')
            ->from('tx_testslanguagerelations_member')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => array_map(intval(...), $row), $rows);
    }
}
