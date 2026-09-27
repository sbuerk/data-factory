<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\DataHandling\FileSeeder;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Definition\SeedFile;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copies the files a seed definition brings into a file storage and indexes
 * them, which is the pass that runs *before* any record is written.
 *
 * What is under test is provisioning, not referencing: a `sys_file` row that
 * exists, points at the right storage and carries the identifier the
 * definition asked for. The row is the whole point of going through the
 * storage API - a file put into `fileadmin/` with `copy()` sits on disk in
 * exactly the same place and does not exist for TYPO3, so nothing can
 * reference it and nothing says why.
 *
 * The other silent failures covered here are what `ResourceStorage::addFile()`
 * does to the file it is handed. It *moves* that file by default, and the core
 * sanitizes an SVG in it in place, so handing it the source would delete or
 * rewrite a file of the package shipping the seed, once, quietly, and only on
 * the machine that ran the seeder. The seeder hands it a temporary copy
 * instead, and the tests below assert that the source stays where it is and
 * as it is, and that a stored SVG is sanitized all the same.
 *
 * Every row is read back through the `QueryBuilder`. Hand written SQL would
 * pass here and fail on PostgreSQL, which folds an unquoted identifier to lower
 * case - `SELECT CType` asks for a column `ctype` that does not exist.
 */
final class FileSeedingTest extends AbstractFunctionalTestCase
{
    /**
     * The set is read from disk rather than built in the test, because a
     * `source` relative to the directory holding the set has nothing to resolve
     * against otherwise.
     */
    private const SEED = 'EXT:data_factory/Tests/Functional/Fixtures/Seeds/FileSeeding.yaml';

    /**
     * The test instance is created once for the **whole test case** and only
     * the database is reset between tests (13.4/14.3 testing framework:
     * FunctionalTestCase::setUp(), which creates `fileadmin/` in the
     * `isFirstTest` branch only). A file another test copied into a storage is
     * therefore still on disk, while its `sys_file` row is gone - and half of
     * what is asserted here is that a folder or a file was *not* there before
     * the seeder ran.
     *
     * The storages are wiped rather than the assertions weakened, because a
     * test that only passes when it runs first is a test that will fail for a
     * reason that has nothing to do with the change that made it fail.
     */
    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::rmdir($this->instancePath . '/fileadmin', true);
        GeneralUtility::rmdir($this->instancePath . '/secondary', true);
        GeneralUtility::mkdir($this->instancePath . '/fileadmin');
    }

    /**
     * The subject, constructed rather than fetched from the container: the
     * service is private and its wiring is proven where it is injected, not by
     * publishing it for a test.
     */
    private function subject(): FileSeeder
    {
        return new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class));
    }

    /**
     * A functional instance has no file storage: the testing framework creates
     * the `fileadmin/` folder but no `sys_file_storage` record, which a real
     * instance gets from `typo3 setup`.
     *
     * Created through the API rather than from a CSV fixture, so the flexform
     * driver configuration and the capability flags stay the core's business
     * instead of a hand written record that is wrong in a way nothing reports.
     *
     * Explicitly, although `StorageRepository` would create a `fileadmin/`
     * storage by itself as soon as it is asked for one and finds the table
     * empty (13.4: StorageRepository.php:141ff, 14.3:
     * StorageRepository.php:133ff). Leaning on that would make every test here
     * depend on a fallback meant for a fresh installation, and would say
     * nothing about an instance that was set up.
     *
     * @return int The uid of the created storage, which is what a `sys_file`
     *         row is asserted to point at.
     */
    private function createDefaultStorage(): int
    {
        return GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);
    }

    /**
     * A second storage, so a declared `storage` has something to pick that is
     * not the one it would have got anyway.
     *
     * The base path is created first: `createLocalStorage()` probes the case
     * sensitivity of the filesystem by touching a file in it, and `touch()`
     * does not create directories.
     */
    private function createSecondaryStorage(): int
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/secondary/');

        return GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('secondary', 'secondary/', 'relative', 'A second storage', false);
    }

    private function fixtureDirectory(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Seeds';
    }

    /**
     * A definition built in the test rather than read from disk, for the cases
     * a fixture set cannot express twice: a declared `name`, a declared
     * `storage`, and a source that is not there.
     *
     * `basePath` is the directory of the fixture sets, so a relative `source`
     * resolves the same way it does for the set on disk.
     *
     * @param list<SeedFile> $files
     */
    private function definition(array $files, string $identifier = 'file-seeding'): SeedDefinition
    {
        return new SeedDefinition(
            identifier: $identifier,
            title: 'Files to provision',
            basePath: $this->fixtureDirectory(),
            files: $files,
        );
    }

    /**
     * @return array<string, int> The `sys_file` uids, keyed by seed identifier.
     */
    private function seedFileSet(): array
    {
        return $this->subject()->seed((new SeedDefinitionParser())->parseFile(self::SEED));
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what happens to be visible.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function queryTable(string $table, array $columns, string $orderBy): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy($orderBy)
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * The indexed files, keyed by uid, so a returned uid can be looked up
     * rather than assumed to be the first row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function indexedFiles(): array
    {
        return array_column(
            $this->queryTable('sys_file', ['uid', 'identifier', 'name', 'storage'], 'uid'),
            null,
            'uid',
        );
    }

    /**
     * A copy of an unsanitized fixture inside the test instance, which is the
     * source the SVG tests seed from.
     *
     * Seeding the fixture itself would rewrite a file of this repository the
     * moment the behaviour under test regresses. The copy only ever lives in
     * the test instance instead.
     */
    private function unsanitizedSvgSource(string $fixture = 'unsanitized.svg'): string
    {
        $directory = $this->instancePath . '/seed-sources';
        GeneralUtility::mkdir_deep($directory);
        $source = $directory . '/' . $fixture;
        copy($this->fixtureDirectory() . '/Files/' . $fixture, $source);
        $this->assertStringContainsString('<script>', (string)file_get_contents($source));

        return $source;
    }

    private function unsanitizedSvgDefinition(string $source): SeedDefinition
    {
        return $this->definition(
            [new SeedFile(identifier: 'unsanitized', source: $source, folder: 'seed-files')],
            'svg-seeding',
        );
    }

    private function assertSanitizedSvg(string $file): void
    {
        $content = (string)file_get_contents($file);
        $this->assertStringContainsString('<rect', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onload', $content);
    }

    /**
     * The temporary copies the seeder left behind, which should be none.
     *
     * @return list<string>
     */
    private function temporaryCopies(): array
    {
        return glob(Environment::getVarPath() . '/transient/data-factory-*') ?: [];
    }

    /**
     * A `source` that is neither `EXT:` nor absolute resolves against the
     * directory holding the set, which is what lets a set be moved or renamed
     * without touching its paths.
     *
     * The `sys_file` row is what proves the copy went through the storage API
     * rather than through the filesystem.
     */
    #[Test]
    public function aRelativeSourceIsResolvedAgainstTheSetDirectoryAndIndexed(): void
    {
        $storage = $this->createDefaultStorage();

        $uids = $this->seedFileSet();

        $file = $this->indexedFiles()[$uids['landscape']];

        $this->assertSame('/seed-files/placeholder.svg', (string)$file['identifier']);
        $this->assertSame('placeholder.svg', (string)$file['name']);
        $this->assertSame($storage, (int)$file['storage']);
        $this->assertFileExists($this->instancePath . '/fileadmin/seed-files/placeholder.svg');
    }

    /**
     * The other accepted form is resolved by the core, so a set may ship a file
     * that lives in a different extension than the set itself.
     *
     * `GeneralUtility::getFileAbsFileName()` handles only this form: it
     * prepends the public web folder to a *relative* path, and a seed set lives
     * in an extension rather than below the document root, so the path it would
     * build names a file that is never there.
     */
    #[Test]
    public function anExtensionPathSourceIsResolvedByTheCoreAndIndexed(): void
    {
        $storage = $this->createDefaultStorage();

        $uids = $this->seedFileSet();

        $file = $this->indexedFiles()[$uids['portrait']];

        $this->assertSame('/seed-files/placeholder-portrait.svg', (string)$file['identifier']);
        $this->assertSame('placeholder-portrait.svg', (string)$file['name']);
        $this->assertSame($storage, (int)$file['storage']);
        $this->assertFileExists($this->instancePath . '/fileadmin/seed-files/placeholder-portrait.svg');
    }

    /**
     * `ResourceStorage::addFile()` *moves* by default, because its
     * `$removeOriginal` argument defaults to `true`. The seeder hands it a
     * temporary copy, so the source stays where it is. This goes red as soon
     * as the source itself is handed over with that default.
     */
    #[Test]
    public function theSourceFilesStayWhereTheDefinitionDeclaresThem(): void
    {
        $this->createDefaultStorage();

        $this->seedFileSet();

        $this->assertFileExists($this->fixtureDirectory() . '/Files/placeholder.svg');
        $this->assertFileExists($this->fixtureDirectory() . '/Files/placeholder-portrait.svg');
    }

    /**
     * An SVG is sanitized on its way into the storage, and the source it came
     * from is left as it was.
     *
     * The core's `SvgEventListener` sanitizes the path `addFile()` is given,
     * in place. The first assertion is the one that goes red when that path is
     * the source itself, the other two are what make sure the fix did not
     * simply get the sanitizer out of the way.
     */
    #[Test]
    public function anSvgIsSanitizedInTheStorageAndLeftUnchangedAtItsSource(): void
    {
        $this->createDefaultStorage();
        $source = $this->unsanitizedSvgSource();
        $hash = sha1_file($source);

        $this->subject()->seed($this->unsanitizedSvgDefinition($source));

        $this->assertSame($hash, sha1_file($source));
        $this->assertSanitizedSvg($this->instancePath . '/fileadmin/seed-files/unsanitized.svg');
        $this->assertSame([], $this->temporaryCopies());
    }

    /**
     * The same holds for an import replacing a file stored by an earlier one:
     * `addFile()` dispatches `BeforeFileAddedEvent` before it looks at the
     * conflict mode, so the replacing import runs through the same listener.
     *
     * Between the two runs the source is written back unsanitized and with a
     * different fill, so the second run is asserted on its own rather than on
     * what the first one did, and the stored file shows that it was replaced
     * rather than left from the first run.
     */
    #[Test]
    public function anSvgReplacingAStoredOneIsSanitizedAndLeftUnchangedAtItsSource(): void
    {
        $this->createDefaultStorage();
        $source = $this->unsanitizedSvgSource();
        $stored = $this->instancePath . '/fileadmin/seed-files/unsanitized.svg';
        $this->subject()->seed($this->unsanitizedSvgDefinition($source));
        $this->assertStringNotContainsString('#000000', (string)file_get_contents($stored));
        $unsanitized = (string)file_get_contents($this->fixtureDirectory() . '/Files/unsanitized.svg');
        file_put_contents($source, str_replace('#d7d7d7', '#000000', $unsanitized));
        $hash = sha1_file($source);

        $this->subject()->seed($this->unsanitizedSvgDefinition($source));

        $this->assertSame($hash, sha1_file($source));
        $this->assertStringContainsString('#000000', (string)file_get_contents($stored));
        $this->assertSanitizedSvg($stored);
        $this->assertCount(1, $this->indexedFiles());
        $this->assertSame([], $this->temporaryCopies());
    }

    /**
     * An SVG that only its extension identifies as one is sanitized as well.
     *
     * Content detection takes an SVG opening with a comment and no XML
     * declaration for `text/html`. That it does not take it for an SVG
     * is what the first assertion makes sure of, so the fixture keeps
     * testing this case on whatever version of the detection runs it.
     * `SvgTypeCheck` recognizes it by the extension of the path it is
     * given, and the sanitized output starts with an XML declaration,
     * which is what then lets the resource consistency check accept the
     * content under its `.svg` name. This is the case that goes red when
     * the temporary copy does not carry the extension: the import then
     * fails with "Resource consistency check failed".
     */
    #[Test]
    public function anSvgRecognizedByItsExtensionOnlyIsSanitizedInTheStorage(): void
    {
        $this->createDefaultStorage();
        $source = $this->unsanitizedSvgSource('unsanitized-exported.svg');
        $this->assertNotContains(
            (new \finfo())->file($source, FILEINFO_MIME_TYPE),
            ['image/svg', 'image/svg+xml', 'application/svg', 'application/svg+xml'],
        );
        $hash = sha1_file($source);

        $this->subject()->seed($this->unsanitizedSvgDefinition($source));

        $this->assertSame($hash, sha1_file($source));
        $this->assertSanitizedSvg($this->instancePath . '/fileadmin/seed-files/unsanitized-exported.svg');
        $this->assertSame([], $this->temporaryCopies());
    }

    /**
     * The declared folder is created on the way, rather than the files landing
     * in the storage root because the target was not there.
     *
     * Its absence is asserted first, so what the test shows is the seeder
     * creating the folder rather than the test instance happening to have one.
     */
    #[Test]
    public function theDeclaredTargetFolderIsCreatedInsideTheStorage(): void
    {
        $this->createDefaultStorage();

        $this->assertDirectoryDoesNotExist($this->instancePath . '/fileadmin/seed-files');

        $this->seedFileSet();

        $this->assertDirectoryExists($this->instancePath . '/fileadmin/seed-files');
    }

    /**
     * The returned map is keyed by the seed identifier of the file, in the
     * order the definition declares, and every value is the uid of a row that
     * is actually there. That map is what a declared file reference is
     * resolved against, so a key that does not match the definition is a
     * relation that cannot be built.
     */
    #[Test]
    public function theReturnedMapIsKeyedBySeedFileIdentifier(): void
    {
        $this->createDefaultStorage();

        $uids = $this->seedFileSet();

        $this->assertSame(['landscape', 'portrait'], array_keys($uids));
        $this->assertSame(array_values($uids), array_keys($this->indexedFiles()));
    }

    /**
     * A declared `name` wins over the basename of the source, which is what
     * lets two sets ship a `placeholder.svg` each without one of them
     * overwriting the other in the storage.
     */
    #[Test]
    public function aDeclaredNameOverridesTheBasenameOfTheSource(): void
    {
        $this->createDefaultStorage();

        $uids = $this->subject()->seed($this->definition([
            new SeedFile(
                identifier: 'renamed',
                source: 'Files/placeholder.svg',
                folder: 'seed-files',
                name: 'a-different-name.svg',
            ),
        ]));

        $file = $this->indexedFiles()[$uids['renamed']];

        $this->assertSame('/seed-files/a-different-name.svg', (string)$file['identifier']);
        $this->assertSame('a-different-name.svg', (string)$file['name']);
    }

    /**
     * An empty declared name counts as no name, and the file is stored under
     * the basename of its source.
     *
     * `addFile()` falls back to the basename of the path it is handed for an
     * empty target name. That path is a temporary copy with a generated name
     * and no extension, so the fallback has to happen before the copy is
     * handed over. Otherwise the resource consistency check refuses the import
     * where `security.system.enforceAllowedFileExtensions` is enabled, as in
     * the test instance, and the file lands under the generated name of the
     * copy where it is not.
     */
    #[Test]
    public function anEmptyDeclaredNameFallsBackToTheBasenameOfTheSource(): void
    {
        $this->createDefaultStorage();

        $uids = $this->subject()->seed($this->definition([
            new SeedFile(
                identifier: 'unnamed',
                source: 'Files/placeholder.svg',
                folder: 'seed-files',
                name: '',
            ),
        ]));

        $file = $this->indexedFiles()[$uids['unnamed']];

        $this->assertSame('/seed-files/placeholder.svg', (string)$file['identifier']);
        $this->assertSame('placeholder.svg', (string)$file['name']);
    }

    /**
     * A declared `storage` picks the storage to write into, rather than the
     * default one. Asserted against a second storage that is *not* the default,
     * so the row cannot be right by accident.
     */
    #[Test]
    public function aDeclaredStorageIsWrittenIntoInsteadOfTheDefaultOne(): void
    {
        $default = $this->createDefaultStorage();
        $secondary = $this->createSecondaryStorage();

        $uids = $this->subject()->seed($this->definition([
            new SeedFile(
                identifier: 'elsewhere',
                source: 'Files/placeholder.svg',
                folder: 'seed-files',
                storage: $secondary,
            ),
        ]));

        $file = $this->indexedFiles()[$uids['elsewhere']];

        $this->assertNotSame($default, $secondary);
        $this->assertSame($secondary, (int)$file['storage']);
        $this->assertSame('/seed-files/placeholder.svg', (string)$file['identifier']);
        $this->assertFileExists($this->instancePath . '/secondary/seed-files/placeholder.svg');
        $this->assertFileDoesNotExist($this->instancePath . '/fileadmin/seed-files/placeholder.svg');
    }

    /**
     * A declared source that is not there names the file and the path it was
     * looked for at, because that is the difference between a typo in the
     * definition and a file that was not shipped.
     */
    #[Test]
    public function aMissingSourceFileIsRefusedWithTheIdentifierAndThePath(): void
    {
        $this->createDefaultStorage();

        $definition = $this->definition(
            [new SeedFile(identifier: 'absent', source: 'Files/absent.svg')],
            'missing-file',
        );

        try {
            $this->subject()->seed($definition);
            $this->fail('A seed file with a missing source was not refused.');
        } catch (InvalidSeedDefinitionException $exception) {
            $this->assertSame(1787076001, $exception->getCode());
            $this->assertStringContainsString('"absent"', $exception->getMessage());
            $this->assertStringContainsString('Files/absent.svg', $exception->getMessage());
        }

        // Refused before anything was indexed, so a broken definition leaves no
        // half provisioned storage behind.
        $this->assertSame([], $this->queryTable('sys_file', ['uid'], 'uid'));
    }

    /**
     * An instance without a default storage is refused with a message saying
     * how to get one, rather than with a null dereference somewhere in the
     * storage API.
     *
     * This is deliberately not an invalid definition: the same definition
     * writes without a word on the next instance.
     *
     * A storage is created here rather than none, and that is the whole point
     * of the test: `StorageRepository` creates a `fileadmin/` storage itself
     * when the table is empty and flags it as the default one (13.4:
     * StorageRepository.php:141ff, 14.3: StorageRepository.php:133ff), so an
     * instance *without* any storage cannot reach this failure at all. What
     * reaches it is an instance whose storages exist and of which none is the
     * default - the state a hand made storage or an import leaves behind.
     */
    #[Test]
    public function anInstanceWithoutADefaultStorageIsRefused(): void
    {
        $this->createSecondaryStorage();

        try {
            $this->seedFileSet();
            $this->fail('Seeding files without a default storage was not refused.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787076002, $exception->getCode());
            $this->assertStringContainsString('typo3 setup', $exception->getMessage());
            $this->assertStringContainsString('storage: <uid>', $exception->getMessage());
        }

        $this->assertSame([], $this->queryTable('sys_file', ['uid'], 'uid'));
    }

    /**
     * A `storage` naming a storage the instance does not have is refused the
     * same way, with a message that names the declared uid rather than the way
     * to a default storage the definition never asked for.
     */
    #[Test]
    public function aDeclaredStorageTheInstanceDoesNotHaveIsRefused(): void
    {
        $this->createDefaultStorage();

        $definition = $this->definition(
            [new SeedFile(identifier: 'nowhere', source: 'Files/placeholder.svg', storage: 99)],
            'unknown-storage',
        );

        try {
            $this->subject()->seed($definition);
            $this->fail('A seed file naming an unknown storage was not refused.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787076002, $exception->getCode());
            $this->assertStringContainsString('"nowhere"', $exception->getMessage());
            $this->assertStringContainsString('file storage 99', $exception->getMessage());
        }

        $this->assertSame([], $this->queryTable('sys_file', ['uid'], 'uid'));
    }
}
