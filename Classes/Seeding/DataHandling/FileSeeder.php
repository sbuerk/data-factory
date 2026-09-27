<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Definition\SeedFile;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Copies the files a seed definition brings into a file storage and returns the
 * `sys_file` uid each one was indexed under.
 *
 * The copy goes through the storage API rather than through the filesystem,
 * because that is what *indexes* the file: a file copied into `fileadmin/` with
 * `copy()` exists on disk and does not exist for TYPO3, so nothing can
 * reference it - and nothing says why.
 *
 * Four details of the copy are worth naming, each of them easy to get wrong
 * and none of them loud about it:
 *
 * - **`ResourceStorage::addFile()` may rewrite the file it is given.** It
 *   dispatches `BeforeFileAddedEvent` with the path it was handed, and the
 *   core's `Resource\Security\SvgEventListener` sanitizes an SVG at that path
 *   in place, on a first import and on one replacing the stored file alike.
 *   `addFile()` treats that path as a file it may consume. Here it would be a
 *   file of the package shipping the seed, so the storage is handed a
 *   temporary copy instead, see {@see self::temporaryPathFor()}. The stored
 *   file is sanitized all the same, and the package stays as it was committed
 *   or installed.
 * - **`addFile()` moves by default.** Its `$removeOriginal` argument
 *   defaults to `true` (13.4: ResourceStorage.php:1312, 14.3:
 *   ResourceStorage.php:1218). The source is out of its reach since it only
 *   gets the copy, and `false` keeps it that way for the copy as well: the
 *   `finally` around the call is then the one place that removes it, on
 *   success and on failure alike.
 * - **The conflict mode is the native enum**
 *   {@see \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior}. TYPO3 v13 still
 *   carries the older class `\TYPO3\CMS\Core\Resource\DuplicationBehavior` of
 *   the same name, and `addFile()` answers that one with a deprecation
 *   (#101151) which this test suite turns into a failure. The native enum
 *   exists on 13.4 and 14.3 alike - the old class only on 13.4 - so this needs
 *   no version split.
 * - **Permission evaluation is suspended around the copy.** A storage
 *   evaluates the file mounts of the backend user, which is meaningless while
 *   seeding: that runs on the command line, into a folder no user has been
 *   given a mount for, and the storage otherwise refuses with "You are not
 *   allowed to access the given folder". The flag is restored in a `finally`,
 *   because the storage is shared and the next caller is entitled to the
 *   checks.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class FileSeeder
{
    public function __construct(
        private StorageRepository $storageRepository,
    ) {}

    /**
     * @return array<string, int> The `sys_file` uid of every file of the
     *         definition, keyed by its seed identifier. That map is what a
     *         declared file reference is resolved against.
     * @throws InvalidSeedDefinitionException
     * @throws SeedingFailedException
     */
    public function seed(SeedDefinition $definition): array
    {
        $uids = [];
        foreach ($definition->files as $file) {
            $source = $this->resolveSource($definition, $file);
            $storage = $this->resolveStorage($definition, $file);
            // The name comes from the definition or the source, never from the
            // copy, which carries a generated one. An empty declared name counts
            // as none: `addFile()` would fall back to the basename of the path
            // it is handed, which is the copy.
            $targetFileName = $file->name !== null && $file->name !== '' ? $file->name : basename($source);

            $copy = '';
            $evaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            try {
                $copy = $this->temporaryPathFor($targetFileName);
                $this->copySource($definition, $file, $source, $copy);
                // Inside the suspension as well: creating the target folder
                // evaluates the same permissions the copy does.
                $folder = $this->resolveFolder($storage, $file->folder);
                $addedFile = $storage->addFile(
                    $copy,
                    $folder,
                    $targetFileName,
                    DuplicationBehavior::REPLACE,
                    false,
                );
            } finally {
                $storage->setEvaluatePermissions($evaluatePermissions);
                GeneralUtility::unlink_tempfile($copy);
            }

            $uids[$file->identifier] = $addedFile->getUid();
        }

        return $uids;
    }

    /**
     * Resolves the declared source to an absolute path.
     *
     * An `EXT:` path is resolved by the core; everything else is taken as
     * relative to the directory the definition was read from, which is what
     * lets a set be moved or renamed without touching its paths.
     *
     * `GeneralUtility::getFileAbsFileName()` is deliberately *not* used for the
     * relative form. It prepends the public web folder to a relative path
     * (13.4/14.3: GeneralUtility.php, `getFileAbsFileName()`), and a seed set
     * lives in an extension rather than below the document root - so the path
     * it built would name a file that is never there. The absolute form is left
     * to it likewise only for `EXT:`, because it answers an absolute path
     * outside the project path with an empty string, and a set can perfectly
     * well sit outside it: a path repository during development, and a test
     * fixture parsed with a base path of its own.
     *
     * @throws InvalidSeedDefinitionException
     */
    private function resolveSource(SeedDefinition $definition, SeedFile $file): string
    {
        $source = PathUtility::isExtensionPath($file->source)
            ? GeneralUtility::getFileAbsFileName($file->source)
            : $this->resolveRelativeSource($definition, $file);

        if ($source === '' || !is_file($source)) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The file "%s" of the seed definition "%s" declares the source "%s", which does not exist%s.',
                    $file->identifier,
                    $definition->identifier,
                    $file->source,
                    $source === '' ? '' : sprintf(' at "%s"', $source),
                ),
                1787076001,
            );
        }

        return $source;
    }

    /**
     * A temporary file the source is copied to, which is what the storage is
     * handed instead of the source itself.
     *
     * The file carries the extension of the name it is stored under.
     * `SvgTypeCheck::forFilePath()` decides whether `SvgEventListener`
     * sanitizes a file by the extension of the path it is given, or else by
     * the MIME type detected from its content. The resource consistency check
     * `addFile()` runs after the listeners compares the MIME type detected
     * from the content with the extension of the target name. An SVG that
     * content detection does not recognize, one opening with a comment and no
     * XML declaration for instance, which it takes for `text/html`, is
     * therefore sanitized only because of the extension, and it passes the
     * check only because the sanitized output starts with an XML declaration.
     * A copy without the extension would be left unsanitized and refused.
     *
     * The file is created in the `transient/` directory of the var path by
     * `GeneralUtility::tempnam()`, which is where
     * `GeneralUtility::unlink_tempfile()` agrees to delete it from again. The
     * caller does that in the `finally` around the copy and `addFile()`, and
     * holds the path before either of them runs, so a failure in any of them
     * leaves nothing behind.
     */
    private function temporaryPathFor(string $targetFileName): string
    {
        $extension = pathinfo($targetFileName, PATHINFO_EXTENSION);

        return GeneralUtility::tempnam('data-factory-', $extension !== '' ? '.' . $extension : '');
    }

    /**
     * @throws SeedingFailedException
     */
    private function copySource(SeedDefinition $definition, SeedFile $file, string $source, string $copy): void
    {
        if (!copy($source, $copy)) {
            throw new SeedingFailedException(
                sprintf(
                    'The file "%s" of the seed definition "%s" could not be copied from "%s" to "%s" before it is'
                    . ' added to the storage.',
                    $file->identifier,
                    $definition->identifier,
                    $source,
                    $copy,
                ),
                1787076003,
            );
        }
    }

    private function resolveRelativeSource(SeedDefinition $definition, SeedFile $file): string
    {
        if (PathUtility::isAbsolutePath($file->source)) {
            return $file->source;
        }
        if ($definition->basePath === '') {
            // A definition parsed from an array rather than from a file has no
            // directory to resolve against, so a relative source cannot mean
            // anything. Returning nothing lets the message above name the
            // declared path, which is the only thing there is to say about it.
            return '';
        }

        return $definition->basePath . '/' . ltrim($file->source, '/');
    }

    /**
     * @throws SeedingFailedException
     */
    private function resolveStorage(SeedDefinition $definition, SeedFile $file): ResourceStorage
    {
        $storage = $file->storage !== null
            ? $this->storageRepository->findByUid($file->storage)
            : $this->storageRepository->getDefaultStorage();

        if (!$storage instanceof ResourceStorage) {
            // Not an invalid definition: the same definition writes without a
            // word on an instance that has the storage, which is why the
            // message says how to get one rather than what to change in the
            // definition.
            //
            // The default storage is missing less often than one would expect,
            // and never because the instance is new: `findAll()` creates the
            // `fileadmin/` storage itself when the table is empty and flags it
            // as the default (13.4: StorageRepository.php:141ff, 14.3:
            // StorageRepository.php:133ff). What is left is an instance that
            // has storages of which none is the default one - so the message
            // names both ways out.
            throw new SeedingFailedException(
                $file->storage !== null
                    ? sprintf(
                        'The file "%s" of the seed definition "%s" declares the file storage %d, which this'
                        . ' instance does not have.',
                        $file->identifier,
                        $definition->identifier,
                        $file->storage,
                    )
                    : sprintf(
                        'No default file storage is available for the file "%s" of the seed definition "%s". A'
                        . ' TYPO3 instance gets its default storage from "typo3 setup", and an existing storage'
                        . ' counts as the default one only while it is flagged as such; a file may also name the'
                        . ' storage to write into, with "storage: <uid>".',
                        $file->identifier,
                        $definition->identifier,
                    ),
                1787076002,
            );
        }

        return $storage;
    }

    private function resolveFolder(ResourceStorage $storage, string $folder): Folder
    {
        $folder = trim($folder, '/');
        if ($folder === '') {
            return $storage->getRootLevelFolder();
        }

        return $storage->hasFolder($folder)
            ? $storage->getFolder($folder)
            : $storage->createFolder($folder);
    }
}
