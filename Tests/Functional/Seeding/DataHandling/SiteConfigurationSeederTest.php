<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\DataHandling\FileImporterInterface;
use SBUERK\DataFactory\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\FileSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\DataFactory\Seeding\DataHandling\SiteConfigurationSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\SiteConfigurationSeedResult;
use SBUERK\DataFactory\Seeding\DataHandling\SiteConfigurationWriterInterface;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser;
use SBUERK\DataFactory\Seeding\Parser\SeedYamlFileLoaderInterface;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writing the site configurations a seed set declares, and reporting the site
 * roots that end up with none.
 *
 * The templates live in the "tests/seeds-demo" fixture extension rather than
 * next to this test, and not for tidiness: `YamlFileLoader` resolves through
 * `GeneralUtility::getFileAbsFileName()`, which refuses a path outside the
 * project. In a functional test the project is the test instance, so a template
 * below "Tests/" is unreadable while one below an extension linked into the
 * instance is exactly what a package ships.
 *
 * The **scenarios** are the other way round: `ScenarioComposer` reads them
 * itself and accepts an absolute path unchanged, so the shared fixtures below
 * "Tests/Functional/Fixtures/Scenarios/" are named absolutely while the base
 * path of every definition stays the template directory of the fixture
 * extension. One definition therefore exercises both path kinds at once.
 */
final class SiteConfigurationSeederTest extends AbstractFunctionalTestCase
{
    /**
     * The `id` the site root of "SiteRootScenario.yaml" declares, which is the
     * uid it is written with and therefore what a site names as its `rootPage`.
     */
    private const ROOT_PAGE = 700;

    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/seeds-demo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container.
     *
     * It is a private service nothing references yet, so Symfony removes it
     * while compiling the container. Its wiring is proven where it is first
     * injected - the import command - rather than by publishing it for a test.
     * All five collaborators are fetched, for two reasons: a container instance
     * is what the cache invalidation of `SiteConfiguration` and `SiteFinder`
     * hangs off, and a hand built `SiteFinder` would never see it; and the two
     * core version aware ones have to be the implementation of the running
     * version, which only the container knows - see
     * {@see self::registeredSiteConfigurationWriterMatchesTheRunningCoreVersion()}.
     */
    private function subject(): SiteConfigurationSeeder
    {
        return new SiteConfigurationSeeder(
            $this->get(SiteConfigurationWriterInterface::class),
            $this->get(SeedYamlFileLoaderInterface::class),
            $this->get(SiteConfiguration::class),
            $this->get(SiteFinder::class),
            $this->get(ConnectionPool::class),
        );
    }

    /**
     * The directory the fixture set lives in, inside the test instance. Every
     * relative template path of a definition built here resolves against it,
     * exactly as it would for a set discovered from that package.
     */
    private function templateBasePath(): string
    {
        return rtrim(ExtensionManagementUtility::extPath('tests_seeds_demo'), '/') . '/Configuration/DataFactory/basic';
    }

    private function scenarioPath(string $scenario): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Scenarios/' . $scenario;
    }

    /**
     * The parser takes the core version aware YAML loader, so it is fetched
     * from the container here rather than the parser being newed up bare.
     */
    private function parser(): SeedDefinitionParser
    {
        return new SeedDefinitionParser($this->get(SeedYamlFileLoaderInterface::class));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function parse(array $definition): SeedDefinition
    {
        return $this->parser()->parse($definition, 'test definition', $this->templateBasePath());
    }

    /**
     * Writes the records of the definition, which is what gives the sites a
     * root page to point at.
     */
    private function seedScenario(SeedDefinition $definition): ScenarioSeedResult
    {
        $scenarioSeeder = new ScenarioSeeder(
            new FileSeeder(
                GeneralUtility::makeInstance(StorageRepository::class),
                $this->get(FileImporterInterface::class),
            ),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        );

        return $scenarioSeeder->seed(
            $definition,
            (new ScenarioComposer())->compose($definition),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * The whole run, in the order the import command runs it.
     *
     * @return array{0: ScenarioSeedResult, 1: SiteConfigurationSeedResult}
     */
    private function seed(SeedDefinition $definition, ?string $base = null): array
    {
        $seedResult = $this->seedScenario($definition);

        return [$seedResult, $this->subject()->seed($definition, $seedResult, $base)];
    }

    /**
     * A page tree with one site root and one page below it, which is what
     * nearly every case below needs.
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function treeWithSite(array $site): array
    {
        return [
            'identifier' => 'demo',
            'title' => 'A page tree with a site',
            'scenarios' => [$this->scenarioPath('SiteRootScenario.yaml')],
            'sites' => [$site],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writtenConfiguration(string $identifier): array
    {
        $file = Environment::getConfigPath() . '/sites/' . $identifier . '/config.yaml';
        $this->assertFileExists($file);

        /** @var array<string, mixed> $configuration */
        $configuration = Yaml::parseFile($file);

        return $configuration;
    }

    /**
     * Where a seeded page sits, which language it is in and which page it
     * translates, and whether it is flagged as a site root.
     *
     * @return array{pid: int, sys_language_uid: int, l10n_parent: int, is_siteroot: int}
     */
    private function pageLocation(int $uid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('pid', 'sys_language_uid', 'l10n_parent', 'is_siteroot')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($row, sprintf('Page %d was not written.', $uid));

        return [
            'pid' => (int)$row['pid'],
            'sys_language_uid' => (int)$row['sys_language_uid'],
            'l10n_parent' => (int)$row['l10n_parent'],
            'is_siteroot' => (int)$row['is_siteroot'],
        ];
    }

    /**
     * @return list<string>
     */
    private function automaticSiteConfigurations(): array
    {
        return glob(Environment::getConfigPath() . '/sites/autogenerated-*') ?: [];
    }

    /**
     * The uid a seeded page ended up with, read back out of the database rather
     * than taken from the seed result: an assertion that the site points at a
     * page this installation has is worth nothing if both sides of it come from
     * the same map.
     *
     * Read through the `QueryBuilder` - hand written SQL would pass here and
     * fail on PostgreSQL, which folds an unquoted identifier to lower case.
     */
    private function pageUidByTitle(string $title): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The writer the container hands out is the one of the running core
     * version.
     *
     * Both `Core12/` and `Core13/` are autoloaded by composer, and only the
     * directory of the running version is registered as services - so this
     * assertion is what catches a `Configuration/Services.php` that stopped
     * selecting, an `#[AsAlias]` that was dropped, or a class that ended up in
     * the wrong directory. All three fail at the first `write()` otherwise, with
     * a message about a missing core class rather than about the wiring.
     *
     * The expected class name is **computed** from `Typo3Version` rather than
     * written out, so this stays one test instead of two and carries no PHPUnit
     * group.
     */
    #[Test]
    public function registeredSiteConfigurationWriterMatchesTheRunningCoreVersion(): void
    {
        $writer = $this->get(SiteConfigurationWriterInterface::class);

        $this->assertInstanceOf(SiteConfigurationWriterInterface::class, $writer);
        $this->assertSame(
            sprintf(
                'SBUERK\\DataFactory\\Core%d\\Seeding\\DataHandling\\SiteConfigurationWriter',
                (new Typo3Version())->getMajorVersion(),
            ),
            $writer::class,
            'The container registered the site configuration writer of a different core version than the one '
            . 'running. Only the "Core<major>/" directory of the running version is loaded, see '
            . '"Configuration/Services.php".',
        );
    }

    /**
     * A set declaring a site gets that site and nothing else - in particular
     * not the "autogenerated-<uid>" configuration TYPO3 writes by itself for
     * every new root page.
     */
    #[Test]
    public function aSetDeclaringSitesProducesNoAutomaticSiteConfiguration(): void
    {
        [, $result] = $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
        ])));

        $this->assertSame(['main'], $result->writtenSites);
        $this->assertSame([], $this->automaticSiteConfigurations());
        $this->assertSame(
            ['main'],
            array_map('basename', glob(Environment::getConfigPath() . '/sites/*') ?: []),
        );
    }

    /**
     * The uid of the declared root page wins over the template.
     *
     * The template names 999, which is not a page of this installation, so an
     * assertion on the seeded uid alone would pass just as well if the template
     * were written through unchanged.
     */
    #[Test]
    public function theRootPageIdComesFromTheSeededPageRatherThanFromTheTemplate(): void
    {
        [$seedResult] = $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
        ])));

        $configuration = $this->writtenConfiguration('main');

        $this->assertSame($seedResult->writtenUid('pages', self::ROOT_PAGE), (int)$configuration['rootPageId']);
        $this->assertSame($this->pageUidByTitle('Home'), (int)$configuration['rootPageId']);
        $this->assertNotSame(999, (int)$configuration['rootPageId']);
        // Everything the template declares beside it is written unchanged.
        $this->assertSame('https://template.example.org/', $configuration['base']);
        $this->assertSame('en_US.UTF-8', $configuration['languages'][0]['locale']);
    }

    /**
     * A "base" in the definition overrides the one of the template, which is
     * what lets one set be imported into instances that are reached under
     * different host names.
     */
    #[Test]
    public function aBaseInTheDefinitionOverridesTheTemplate(): void
    {
        $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
            'base' => 'https://seeded.example.com/',
        ])));

        $this->assertSame('https://seeded.example.com/', $this->writtenConfiguration('main')['base']);
    }

    /**
     * The "base" of the run wins over both, which is the top of the same
     * ladder: the template knows the least about the instance being seeded, the
     * definition more, and whoever starts the import most.
     */
    #[Test]
    public function aBaseGivenToTheRunOverridesTheDefinitionAndTheTemplate(): void
    {
        $this->seed(
            $this->parse($this->treeWithSite([
                'identifier' => 'main',
                'rootPage' => self::ROOT_PAGE,
                'base' => 'https://seeded.example.com/',
            ])),
            'https://this-instance.example.net/',
        );

        $this->assertSame('https://this-instance.example.net/', $this->writtenConfiguration('main')['base']);
    }

    /**
     * A set declaring no sites gets no automatic site configuration either -
     * suppression is unconditional - and the site root it leaves without one is
     * reported rather than left for the user to discover in the frontend.
     */
    #[Test]
    public function aSetWithoutSitesReportsTheSiteRootsNoConfigurationCovers(): void
    {
        [$seedResult, $result] = $this->seed($this->parse([
            'identifier' => 'demo',
            'title' => 'A page tree without a site',
            'scenarios' => [$this->scenarioPath('SiteRootScenario.yaml')],
        ]));

        $this->assertSame([], $result->writtenSites);
        $this->assertSame([], $this->automaticSiteConfigurations());
        // The site root, and only it: the page below it is not a site root and
        // reporting it would be noise.
        $this->assertSame([$seedResult->writtenUid('pages', self::ROOT_PAGE)], $result->uncoveredSiteRoots);
    }

    /**
     * The site root a declared configuration covers is not reported, which is
     * the other half of the same question: the report has to be empty in the
     * normal case or it says nothing in the abnormal one.
     */
    #[Test]
    public function aSiteRootCoveredByADeclaredSiteIsNotReported(): void
    {
        [, $result] = $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
        ])));

        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * A seeded record on the page tree root that is not a page type
     * `CreateSiteConfiguration` would have acted on is not reported either.
     *
     * A sysfolder has no frontend, so warning that it has no site would be a
     * warning about nothing - and the report would lose the meaning it has in
     * the test above.
     */
    #[Test]
    public function aSeededRootLevelSysfolderIsNoUncoveredSiteRoot(): void
    {
        [$seedResult, $result] = $this->seed($this->parse([
            'identifier' => 'storage',
            'title' => 'A storage folder',
            'scenarios' => [$this->scenarioPath('RootLevelSysfolderScenario.yaml')],
        ]));

        // The record was written and it does sit on the page tree root, so the
        // empty report below is about its doktype and nothing else.
        $this->assertSame([800], $seedResult->pageUids());
        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * The translations of a site root covered by a declared site are not
     * reported: they are reachable through the site of their original, in the
     * languages that site declares.
     *
     * The assertions before the last one are what make the empty report mean
     * something. Every translation was written and carries the `is_siteroot`
     * of its original, and the translations of the root page sit on the page
     * tree root as well, which is every condition of a site root but one.
     */
    #[Test]
    public function theTranslationsOfASiteRootCoveredByADeclaredSiteAreNotReported(): void
    {
        [$seedResult, $result] = $this->seed($this->parse([
            'identifier' => 'translated',
            'title' => 'A translated page tree with a site',
            'scenarios' => [$this->scenarioPath('TranslatedSiteRootScenario.yaml')],
            'sites' => [['identifier' => 'multilingual', 'rootPage' => 720]],
        ]));

        $rootPage = (int)$seedResult->writtenUid('pages', 720);
        $aboutPage = (int)$seedResult->writtenUid('pages', 730);
        $this->assertSame(
            ['pid' => 0, 'sys_language_uid' => 1, 'l10n_parent' => $rootPage, 'is_siteroot' => 1],
            $this->pageLocation((int)$seedResult->writtenUid('pages', 721)),
        );
        $this->assertSame(
            ['pid' => 0, 'sys_language_uid' => 2, 'l10n_parent' => $rootPage, 'is_siteroot' => 1],
            $this->pageLocation((int)$seedResult->writtenUid('pages', 722)),
        );
        $this->assertSame(
            ['pid' => $rootPage, 'sys_language_uid' => 1, 'l10n_parent' => $aboutPage, 'is_siteroot' => 1],
            $this->pageLocation((int)$seedResult->writtenUid('pages', 731)),
        );
        $this->assertSame(
            [0, 1, 2],
            array_keys($this->get(SiteFinder::class)->getSiteByPageId($rootPage)->getLanguages()),
        );
        $this->assertSame(['multilingual'], $result->writtenSites);
        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * Site roots with translations that no configuration covers are reported
     * once each, by their own uid, and their translations are not. A site
     * configuration names the translated page, and the translations are
     * reachable through it as soon as it has one.
     */
    #[Test]
    public function uncoveredSiteRootsAreReportedWithoutTheirTranslations(): void
    {
        [$seedResult, $result] = $this->seed($this->parse([
            'identifier' => 'translated',
            'title' => 'A translated page tree without a site',
            'scenarios' => [$this->scenarioPath('TranslatedSiteRootScenario.yaml')],
        ]));

        $this->assertSame(
            [$seedResult->writtenUid('pages', 720), $seedResult->writtenUid('pages', 730)],
            $result->uncoveredSiteRoots,
        );
    }

    /**
     * A translation of a page the run did not write is not reported, although
     * it sits on the page tree root, carries the `is_siteroot` of its original,
     * and that original is covered by no site.
     *
     * The warning says that suppressing the automatic site configuration left
     * a seeded page tree without a frontend. For an original that existed
     * before the run, that is not true, and `CreateSiteConfiguration` would
     * not have acted on the translation either.
     */
    #[Test]
    public function aTranslationOfAPageTheRunDidNotWriteIsNotReported(): void
    {
        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/ExistingUncoveredSiteRoot.csv');

        [$seedResult, $result] = $this->seed($this->parse([
            'identifier' => 'translation-only',
            'title' => 'A translation of an existing page',
            'scenarios' => [$this->scenarioPath('TranslationOfAnExistingPageScenario.yaml')],
        ]));

        $this->assertSame(
            ['pid' => 0, 'sys_language_uid' => 1, 'l10n_parent' => 20, 'is_siteroot' => 1],
            $this->pageLocation((int)$seedResult->writtenUid('pages', 740)),
        );
        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * A configuration written through {@see SiteConfigurationWriterInterface}
     * is visible to `SiteFinder` in the same PHP process.
     *
     * `getAllSites()` is called **before** seeding on purpose. It fills the
     * runtime cache of `SiteConfiguration` and the root-page-id mapping of
     * `SiteFinder` with the state of an installation that has no sites, so a
     * finder that never heard about the new file would still answer from that.
     * Without the warm-up this test would pass on a cold finder and prove
     * nothing.
     *
     * It is also what pins the one behaviour the two core version aware writers
     * have to produce by different means: TYPO3 v13 dispatches a
     * `SiteConfigurationChangedEvent` the finder listens to, TYPO3 v12 has no
     * such event and a `SiteFinder` that fills its site list in the constructor,
     * so the v12 implementation refreshes it itself. Remove that and this test
     * fails on v12 and passes on v13.
     */
    #[Test]
    public function aWrittenConfigurationIsFoundBySiteFinderInTheSameProcess(): void
    {
        $siteFinder = $this->get(SiteFinder::class);
        $this->assertSame([], $siteFinder->getAllSites());

        [$seedResult] = $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
        ])));

        $rootPageId = $seedResult->writtenUid('pages', self::ROOT_PAGE);
        $site = $siteFinder->getSiteByIdentifier('main');

        $this->assertSame($rootPageId, $site->getRootPageId());
        $this->assertSame($rootPageId, $siteFinder->getSiteByRootPageId((int)$rootPageId)->getRootPageId());
        $this->assertSame('https://template.example.org/', (string)$site->getBase());
    }

    /**
     * A template needs no `rootPageId`, because the seed supplies it, and it
     * needs nothing else either: `Site` substitutes a default language for a
     * configuration that declares none, identically on TYPO3 v13.4 and v14.3.
     *
     * The one thing that is not optional is that `rootPageId` ends up greater
     * than zero - `SiteConfiguration::resolveAllExistingSites()` drops a
     * configuration without one silently, which is why this asserts through
     * `SiteFinder` rather than on the written file.
     */
    #[Test]
    public function aTemplateDeclaringNothingButABaseProducesAUsableSite(): void
    {
        [$seedResult] = $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'minimal',
            'rootPage' => self::ROOT_PAGE,
            'template' => 'Sites/minimal',
        ])));

        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('minimal');

        $this->assertSame($seedResult->writtenUid('pages', self::ROOT_PAGE), $site->getRootPageId());
        $this->assertSame('https://minimal.example.org/', (string)$site->getBase());
        // The default language `Site` substitutes, which the template does not
        // declare. Its locale is not asserted: `Locale` normalises what the
        // core writes there and the normalised form is not the seeder's claim.
        $this->assertSame([0], array_keys($site->getLanguages()));
        $this->assertSame(0, $site->getDefaultLanguage()->getLanguageId());
    }

    /**
     * The `settings.yaml` of a template is written beside the configuration,
     * unchanged.
     *
     * It is asserted on the file rather than through `Site::getSettings()`
     * because a setting that no site set declares is not necessarily kept by
     * the settings resolution, and what this class is responsible for is that
     * the file arrives.
     */
    #[Test]
    public function aTemplateSettingsFileIsWrittenBesideTheConfiguration(): void
    {
        $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'settings',
            'rootPage' => self::ROOT_PAGE,
            'template' => 'Sites/withsettings',
        ])));

        $this->assertSame(
            ['seeds' => ['demo' => ['greeting' => 'from the template']]],
            Yaml::parseFile(Environment::getConfigPath() . '/sites/settings/settings.yaml'),
        );
    }

    /**
     * A template that carries `dependencies:` is written unchanged and read
     * back into the site entity on both core versions.
     *
     * The **writing** half is version independent: the seeder hands the
     * template's array to the writer and the writer dumps it, so a key a core
     * version has no concept of still arrives in the file unchanged.
     *
     * The read-back is asserted on `Site::getConfiguration()` rather than on
     * `Site::getSets()`, which is what the key finally becomes on TYPO3 v13.
     * `getSets()` does not exist on v12 - site sets are a v13 concept - and what
     * this seeder is responsible for is that the key survives the round trip
     * into the site entity, which is true on both versions. A version that
     * starts rejecting or renaming the key on the way in fails here rather than
     * in an installation.
     */
    #[Test]
    public function aTemplateCarryingSiteSetDependenciesIsWrittenOnBothCoreVersions(): void
    {
        $this->seed($this->parse($this->treeWithSite([
            'identifier' => 'dependencies',
            'rootPage' => self::ROOT_PAGE,
            'template' => 'Sites/withdependencies',
        ])));

        $this->assertSame(
            ['tests/a-site-set-that-does-not-exist'],
            $this->writtenConfiguration('dependencies')['dependencies'],
        );
        $this->assertSame(
            ['tests/a-site-set-that-does-not-exist'],
            $this->get(SiteFinder::class)->getSiteByIdentifier('dependencies')->getConfiguration()['dependencies'],
        );
    }

    /**
     * A template directory without a `config.yaml` fails with a message naming
     * the set and the path that was looked at.
     */
    #[Test]
    public function aMissingTemplateFailsNamingTheSetAndThePath(): void
    {
        $definition = $this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
            'template' => 'Sites/does-not-exist',
        ]));

        try {
            $this->seed($definition);
            $this->fail('A site with a missing template was not refused.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787077005, $exception->getCode());
            $this->assertStringContainsString('"demo"', $exception->getMessage());
            $this->assertStringContainsString(
                $this->templateBasePath() . '/Sites/does-not-exist/config.yaml',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A `rootPage` no entity of the scenario wrote is refused here as well, and
     * before the site is written.
     *
     * The import command catches the same case earlier, on the suggested ids of
     * the composed scenario, and never gets this far. This is the guard for
     * everything that does not go through the command - and for the one case
     * the command cannot rule out, a "--force" run that gave up the suggested
     * page uids, where the declared uid exists in the scenario and not in the
     * result map.
     */
    #[Test]
    public function aRootPageTheScenarioDidNotWriteIsRefused(): void
    {
        $definition = $this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => 999,
        ]));

        try {
            $this->seed($definition);
            $this->fail('A site pointing at a page the scenario does not write was written.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787077001, $exception->getCode());
            $this->assertStringContainsString('999', $exception->getMessage());
            $this->assertStringContainsString('"main"', $exception->getMessage());
        }

        $this->assertSame([], glob(Environment::getConfigPath() . '/sites/*') ?: []);
    }

    /**
     * An identifier the installation already uses is refused rather than
     * merged into.
     *
     * The core writer's `write()` diffs an incoming configuration against the
     * existing file and applies only the changed keys, so writing a template
     * over an existing site produces a hybrid of the two. Seeding the same set
     * twice is the case a user runs into, and it has to say so.
     *
     * Only the site half of the run is repeated, not the records: the scenario
     * suggests the uids it declares, so a second write of the same set fails on
     * the primary key long before a site is written. The uid collision is the
     * import command's subject; this one is about the site configuration.
     */
    #[Test]
    public function anIdentifierTheInstallationAlreadyUsesIsRefused(): void
    {
        $definition = $this->parse($this->treeWithSite([
            'identifier' => 'main',
            'rootPage' => self::ROOT_PAGE,
            'base' => 'https://first.example.com/',
        ]));
        $seedResult = $this->seedScenario($definition);
        $this->subject()->seed($definition, $seedResult);

        try {
            $this->subject()->seed($definition, $seedResult);
            $this->fail('A site configuration that already exists was overwritten.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787077002, $exception->getCode());
            $this->assertStringContainsString('"main"', $exception->getMessage());
        }

        // And the existing configuration is untouched, which is the point of
        // refusing rather than merging.
        $this->assertSame('https://first.example.com/', $this->writtenConfiguration('main')['base']);
    }

    /**
     * The whole way through, from the `config.yml` of a set shipped by an
     * extension to a written site: the definition is read from the file, the
     * scenario and the template path are resolved against the directory the set
     * lives in, and the template path is the default the parser fills in.
     *
     * Everything above names its scenario absolutely and builds its definition
     * from an array, so nothing above would notice if the default template path
     * or the base path of a set were wrong.
     */
    #[Test]
    public function aSetShippedByAnExtensionIsSeededFromItsOwnDirectory(): void
    {
        $definition = $this->parser()->parseFile($this->templateBasePath() . '/config.yml');

        [$seedResult, $result] = $this->seed($definition);

        $this->assertSame(['main'], $result->writtenSites);
        $this->assertSame([], $result->uncoveredSiteRoots);
        $this->assertSame(1000, $seedResult->writtenUid('pages', 1000));
        $this->assertSame(1000, (int)$this->writtenConfiguration('main')['rootPageId']);
    }
}
