<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Definition\SeedSiteConfiguration;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use SBUERK\DataFactory\Seeding\Parser\ThrowOnErrorLogger;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlFileLoadingException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Writes the site configurations of a seed definition, once its records exist
 * and their uids are known.
 *
 * ## Why this runs after the records, and through `SiteWriter`
 *
 * A site cannot be written before its root page exists: `rootPageId` is the uid
 * of a page the same set creates. The set therefore names the page by the `id`
 * its scenario declares, and this class resolves that against what
 * {@see ScenarioSeeder::seed()} actually wrote. The resolved uid always wins
 * over whatever the template declares, so a template cannot point the site
 * somewhere else - that is the one value a seed set cannot delegate.
 *
 * `TYPO3\CMS\Core\Configuration\SiteWriter` is the only supported writer and a
 * container service on TYPO3 v13.4 and v14.3 alike (both register it in
 * `Core/Classes/ServiceProvider.php::getSiteWriter()` with the very same three
 * arguments), so it is injected by type and no path handling is needed here.
 * Writing the file with `file_put_contents()` instead would produce the same
 * bytes and a different result: `write()` ends with
 * `$this->eventDispatcher->dispatch(new SiteConfigurationChangedEvent(...))`,
 * and `SiteConfiguration::siteConfigurationChanged()` and
 * `SiteFinder::siteConfigurationChanged()` both listen to it and flush their
 * caches (13.4: SiteConfiguration.php:301, SiteFinder.php:117; 14.3:
 * SiteConfiguration.php:322, SiteFinder.php:117). Going through `SiteWriter`
 * is what makes a freshly written configuration visible to `SiteFinder` in the
 * same PHP process - which the seeding run itself needs, see
 * {@see self::findUncoveredSiteRoots()}.
 *
 * ## What a template is
 *
 * A directory holding a `config.yaml` and optionally a `settings.yaml`, which
 * is exactly the shape of a site below the instance's `config/sites/`. A
 * template can therefore be produced by copying a working site out of an
 * instance, and it is read with the loader TYPO3 reads its own site
 * configurations with.
 *
 * What a **minimal** `config.yaml` needs is one key: `rootPageId`, and it is
 * the one key this class overwrites anyway. Everything else has a fallback -
 * `SiteConfiguration::resolveAllExistingSites()` skips a configuration whose
 * `rootPageId` is not greater than zero **without a word**, and
 * `Site::__construct()` substitutes a default `languages` entry and an empty
 * `base` for what is missing. Both are byte identical on 13.4 and 14.3 (the
 * only difference between the two `Site` classes is a `@todo` comment), so a
 * template that works on one version works on the other. A useful template
 * declares `base` and `languages`, because the defaults are a site on `/` in
 * "Default / en_US.UTF-8".
 *
 * `settings.yaml` holds the site settings - the values `SiteSettingsFactory`
 * reads for `Site::getSettings()`, which is where the site-local overrides of
 * site set settings are persisted (`SiteSettingsService` on both versions
 * calls that file "our persistence target"). It is separate from `config.yaml`
 * because the backend writes it separately, and it is copied verbatim when the
 * template ships one.
 *
 * `dependencies:` - the site sets a site pulls in - is **not** a v14 only key
 * and needs no version handling: `Site::__construct()` reads
 * `$configuration['dependencies'] ?? []` into `$this->sets` on 13.4 and 14.3
 * alike. What v14.3 adds is a fourth `array $dependencies = []` argument to
 * `SiteWriter::createNewBasicSite()`, which this class does not call, and
 * route enhancers resolved from sets in `SiteConfiguration`. A template
 * carrying `dependencies:` is therefore written unchanged on both versions and
 * understood on both - asserted by a functional test rather than assumed, so
 * that a version which starts rejecting the key is found here.
 *
 * There is consequently **no version difference to apply to the finished
 * array**. Where one turns up it belongs right before the `write()` call, with
 * a `@todo` naming the condition under which it goes away, because
 * configuration is the documented exception to the "split the class" rule.
 *
 * ## An identifier that already exists is refused
 *
 * `SiteWriter::write()` **merges**: when `config.yaml` already exists it loads
 * the unprocessed file, diffs the incoming configuration against the processed
 * one and applies only the modified and removed keys with
 * `ArrayUtility::mergeRecursiveWithOverrule()`. It is built for the backend
 * site module, where the file is the source of truth and the form supplies
 * changes. Seeding a set into an instance that already has a site of that
 * identifier would therefore produce neither the template nor the previous
 * configuration but a silent hybrid of both, so this class refuses instead.
 * `SiteWriter::delete()` is not the answer either - it unlinks `config.yaml`
 * and leaves the directory and its `settings.yaml` behind, and deleting an
 * instance's hand-maintained site configuration is not a seeder's decision to
 * make.
 *
 * ## Where templates may live
 *
 * `YamlFileLoader` resolves through `GeneralUtility::getFileAbsFileName()`,
 * which refuses a path outside `Environment::getProjectPath()` and
 * `Environment::getPublicPath()`. A template therefore has to sit inside the
 * project, which every package of an installation does, and which is the same
 * constraint the seed definition itself is already read under
 * ({@see \SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser::parseFile()}).
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class SiteConfigurationSeeder
{
    /**
     * The file names of a site below `config/sites/<identifier>/`, which are
     * also the file names of a template directory. Private constants on
     * `SiteWriter`, repeated here rather than guessed: they are what
     * `SiteConfiguration::CONFIG_FILE_NAME` and
     * `SiteWriter::SETTINGS_FILE_NAME` hold on 13.4 and 14.3.
     */
    private const CONFIG_FILE_NAME = 'config.yaml';
    private const SETTINGS_FILE_NAME = 'settings.yaml';

    /**
     * The page types `CreateSiteConfiguration` considers a site root, repeated
     * here so the report of uncovered site roots covers exactly the pages the
     * suppressed core hook would have written a configuration for - no more, so
     * a seeded sysfolder on the page tree root is not reported as a broken
     * frontend, and no fewer.
     *
     * Identical on 13.4 and 14.3 (`CreateSiteConfiguration::$allowedPageTypes`).
     */
    private const SITE_ROOT_PAGE_TYPES = [
        PageRepository::DOKTYPE_DEFAULT,
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
    ];

    /**
     * The translation pointer of `pages`, which `CreateSiteConfiguration`
     * reads by this literal name to leave out every page that translates
     * another one.
     *
     * A translated page is a `pages` row of its own on the `pid` of its
     * original, and `is_siteroot` is `l10n_mode` `exclude`, so `DataHandler`
     * copies it from the original. A translation therefore meets the page
     * tree root and `is_siteroot` conditions whenever its original does. It
     * is no site root: the site of a translation is the site of its original,
     * and `SiteFinder::getSiteByPageId()` asked with the uid of the
     * translation of a site's root page does not find that site.
     *
     * The hook reads the same literal field on 13.4 and 14.3
     * (`CreateSiteConfiguration::processDatamap_afterDatabaseOperations()`).
     */
    private const TRANSLATION_POINTER_FIELD = 'l10n_parent';

    public function __construct(
        private SiteWriter $siteWriter,
        private SiteConfiguration $siteConfiguration,
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param ScenarioSeedResult $seedResult What the scenario wrote - the uid a
     *        declared page id ended up under, and every page uid of the run.
     * @param string|null $base Replaces the `base` of every site written by
     *        this run, whatever the template and the definition declare. It is
     *        what makes one set usable in more than one instance - the seed
     *        carries a base that is right for exactly one of them, and the
     *        instance importing it knows its own. `null` changes nothing.
     * @param bool $writeSiteConfigurations Whether the declared site
     *        configurations are written at all. `false` skips them and keeps
     *        everything else, which is deliberately *not* the same as calling
     *        this method not at all: the suppression of the automatic site
     *        configuration is unconditional ({@see ScenarioSeeder}), so skipping
     *        the declared ones is exactly the case where the uncovered site
     *        roots of {@see SiteConfigurationSeedResult} have to be reported -
     *        and this is what still finds them.
     * @throws SeedingFailedException
     */
    public function seed(
        SeedDefinition $definition,
        ScenarioSeedResult $seedResult,
        ?string $base = null,
        bool $writeSiteConfigurations = true,
    ): SiteConfigurationSeedResult {
        $written = [];
        if ($writeSiteConfigurations) {
            foreach ($definition->sites as $site) {
                $this->writeSite($definition, $site, $seedResult, $base);
                $written[] = $site->identifier;
            }
        }

        return new SiteConfigurationSeedResult(
            $written,
            $this->findUncoveredSiteRoots($seedResult),
        );
    }

    /**
     * @param string|null $base The override of {@see self::seed()}, which wins
     *        over the `base` of the definition and over the one of the
     *        template alike.
     * @throws SeedingFailedException
     */
    private function writeSite(
        SeedDefinition $definition,
        SeedSiteConfiguration $site,
        ScenarioSeedResult $seedResult,
        ?string $base = null,
    ): void {
        // "rootPage" is the uid a scenario entity declares for the page. It is
        // missing from the map when no entity of the "pages" table declared it,
        // and it differs from what was written when "--force" gave up the uid
        // suggestions of that table - both of which would produce a site
        // pointing somewhere the set never seeded.
        $rootPageId = $seedResult->writtenUid('pages', $site->rootPage) ?? 0;
        if ($rootPageId <= 0) {
            throw new SeedingFailedException(
                sprintf(
                    'The site "%s" of the seed set "%s" declares the root page %d, which the scenario of this'
                    . ' set does not write. It names the "id" an entity of the "pages" table declares.',
                    $site->identifier,
                    $definition->identifier,
                    $site->rootPage,
                ),
                1787077001,
            );
        }

        if (array_key_exists($site->identifier, $this->siteConfiguration->getAllSiteConfigurationPaths())) {
            throw new SeedingFailedException(
                sprintf(
                    'The seed set "%s" writes the site configuration "%s", which this installation already has.'
                    . ' It is not overwritten: SiteWriter merges an incoming configuration into an existing'
                    . ' "%s" instead of replacing it, so seeding it again would produce neither the template nor'
                    . ' the existing site. Remove "%s" first if the seed is meant to replace it.',
                    $definition->identifier,
                    $site->identifier,
                    self::CONFIG_FILE_NAME,
                    $site->identifier,
                ),
                1787077002,
            );
        }

        $configuration = $this->readTemplateFile(
            $definition,
            $site,
            $this->templateDirectory($definition, $site) . '/' . self::CONFIG_FILE_NAME,
            true,
        );

        // The resolved uid of the declared root page always wins over the
        // template, which is the whole reason a seed names the page by its seed
        // identifier.
        $configuration['rootPageId'] = $rootPageId;
        // The override of the run first, the "base" the definition declares
        // behind it, and the template's own last - each of the two overrides is
        // written by someone who knows more about the instance than the layer
        // below it.
        $base ??= $site->base;
        if ($base !== null) {
            $configuration['base'] = $base;
        }

        try {
            $this->siteWriter->write($site->identifier, $configuration);
        } catch (SiteConfigurationWriteException $exception) {
            throw new SeedingFailedException(
                sprintf(
                    'The site configuration "%s" of the seed set "%s" could not be written: %s',
                    $site->identifier,
                    $definition->identifier,
                    $exception->getMessage(),
                ),
                1787077003,
                $exception,
            );
        }

        $this->writeSettings($definition, $site);
    }

    /**
     * Copies the `settings.yaml` of the template, when it has one.
     *
     * It is written **after** `write()` and not before, for two reasons that
     * are both in `SiteWriter`: `writeSettings()` does not create the site
     * directory - it hands the file name to `GeneralUtility::writeFile()`,
     * which does not either - and it dispatches no
     * `SiteConfigurationChangedEvent`, so it cannot be the last thing that
     * happens before something reads the site back. `write()` creates the
     * directory and flushes the caches, and the only read that follows in this
     * run is the uncovered-root check, after every site has been written.
     *
     * @throws SeedingFailedException
     */
    private function writeSettings(SeedDefinition $definition, SeedSiteConfiguration $site): void
    {
        $settingsFile = $this->templateDirectory($definition, $site) . '/' . self::SETTINGS_FILE_NAME;
        if (!is_file($settingsFile)) {
            return;
        }

        $settings = $this->readTemplateFile($definition, $site, $settingsFile, false);
        if ($settings === []) {
            return;
        }

        try {
            $this->siteWriter->writeSettings($site->identifier, $settings);
        } catch (SiteConfigurationWriteException $exception) {
            throw new SeedingFailedException(
                sprintf(
                    'The site settings of "%s" of the seed set "%s" could not be written: %s',
                    $site->identifier,
                    $definition->identifier,
                    $exception->getMessage(),
                ),
                1787077004,
                $exception,
            );
        }
    }

    /**
     * The absolute directory the site is written from.
     *
     * `EXT:` paths are absolute in the sense that matters and ignore the base
     * path of the set; everything else that is not already absolute resolves
     * against it, which is what lets a set be moved or renamed without touching
     * its paths.
     *
     * The unresolved path is kept when `getFileAbsFileName()` refuses it - a
     * template outside the project, see the class docblock - so the message of
     * the failure that follows names what the definition declared instead of an
     * empty string.
     */
    private function templateDirectory(SeedDefinition $definition, SeedSiteConfiguration $site): string
    {
        $template = $site->template;
        if (!PathUtility::isExtensionPath($template) && !PathUtility::isAbsolutePath($template)) {
            $template = $definition->basePath . '/' . $template;
        }
        $absoluteTemplate = GeneralUtility::getFileAbsFileName($template);

        return rtrim($absoluteTemplate !== '' ? $absoluteTemplate : $template, '/');
    }

    /**
     * Reads one file of a template directory.
     *
     * `YamlFileLoader` is used rather than a plain YAML parse because it is
     * what TYPO3 reads its own site configurations with: it resolves `EXT:`
     * paths and it follows `imports:`. Following them **inlines** the imported
     * content into the written file, which is the behaviour a seed needs - an
     * `imports:` key kept verbatim would point at paths relative to the
     * template directory and break the moment the file lands in
     * `config/sites/`.
     *
     * Placeholders are deliberately **not** processed. `%env(…)%` in a site
     * configuration is meant to be resolved by the instance every time it reads
     * the file, so resolving it here would bake this machine's environment into
     * the seeded site.
     *
     * @param bool $required Whether a missing file is an error. It is for
     *        `config.yaml`, which is what makes a directory a template at all.
     * @return array<string, mixed>
     * @throws SeedingFailedException
     */
    private function readTemplateFile(
        SeedDefinition $definition,
        SeedSiteConfiguration $site,
        string $file,
        bool $required,
    ): array {
        if ($required && !is_file($file)) {
            throw new SeedingFailedException(
                sprintf(
                    'The site "%s" of the seed set "%s" has no template: "%s" does not exist.',
                    $site->identifier,
                    $definition->identifier,
                    $file,
                ),
                1787077005,
            );
        }

        $loader = new YamlFileLoader(new ThrowOnErrorLogger($file));
        try {
            /** @var array<string, mixed> $content */
            $content = $loader->load($file, YamlFileLoader::PROCESS_IMPORTS | YamlFileLoader::ALLOW_EMPTY_FILE);
        } catch (YamlFileLoadingException|YamlParseException $exception) {
            throw new SeedingFailedException(
                sprintf(
                    'The site template "%s" of the seed set "%s" is not readable YAML: %s',
                    $file,
                    $definition->identifier,
                    $exception->getMessage(),
                ),
                1787077006,
                $exception,
            );
        }

        return $content;
    }

    /**
     * The seeded site roots no site configuration covers.
     *
     * "Site root" is the core's own definition, read back out of the database
     * rather than derived from the definition: a page on the page tree root or
     * carrying `is_siteroot` that translates no other page, of a page type
     * `CreateSiteConfiguration` would have acted on. Reading it back is what
     * makes the answer true for the record that was actually written, defaults
     * and all.
     *
     * A translation is never reported, whether its original was seeded in the
     * same run or existed before. Whether it is reachable is decided by its
     * original and the languages of that page's site, so an uncovered seeded
     * original is reported as itself, and an original the run did not write is
     * not the run's to report.
     *
     * "Covered" is asked as `SiteFinder::getSiteByPageId()` and not as
     * `getSiteByRootPageId()`, because the question is whether a frontend can
     * render the tree, not whether the page is a site root of its own. A page
     * seeded with `is_siteroot` below the root page of an existing site is
     * reachable through that site, and reporting it would be a false alarm.
     *
     * This runs after every declared site has been written, so a site written
     * in this run counts as coverage - which it does, because `SiteWriter`
     * flushed the caches of `SiteConfiguration` and `SiteFinder` on its way
     * out.
     *
     * @return list<int>
     */
    private function findUncoveredSiteRoots(ScenarioSeedResult $seedResult): array
    {
        $seededPages = $seedResult->pageUids();
        if ($seededPages === []) {
            return [];
        }

        $uncovered = [];
        foreach ($this->siteRootsAmong($seededPages) as $rootPageId) {
            try {
                $this->siteFinder->getSiteByPageId($rootPageId);
            } catch (SiteNotFoundException) {
                $uncovered[] = $rootPageId;
            }
        }

        return $uncovered;
    }

    /**
     * @param list<int> $pageIds
     * @return list<int>
     */
    private function siteRootsAmong(array $pageIds): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // No restrictions: what is asserted is what was written, not what
        // happens to be visible to the default restriction set.
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'pid', 'is_siteroot')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY),
                ),
                $queryBuilder->expr()->in(
                    'doktype',
                    $queryBuilder->createNamedParameter(self::SITE_ROOT_PAGE_TYPES, Connection::PARAM_INT_ARRAY),
                ),
                $queryBuilder->expr()->eq(
                    self::TRANSLATION_POINTER_FIELD,
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $siteRoots = [];
        foreach ($rows as $row) {
            if ((int)$row['pid'] === 0 || (int)$row['is_siteroot'] > 0) {
                $siteRoots[] = (int)$row['uid'];
            }
        }

        return $siteRoots;
    }
}
