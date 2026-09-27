<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Adds a file to a file storage, replacing a file that is already there.
 *
 * The seam exists for exactly one reason: the conflict mode argument of
 * `ResourceStorage::addFile()` changed type between the two supported core
 * versions. TYPO3 v13 introduced the native enum
 * `TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior` (#101151, "Native
 * DuplicationBehavior enumeration") and deprecated the older
 * `TYPO3\CMS\Core\Resource\DuplicationBehavior`, whose class constants are the
 * only spelling TYPO3 v12 has. Neither spelling works on both versions:
 *
 * - The enum does not exist on v12 - `Classes/Resource/Enum/` is not part of
 *   `typo3/cms-core` 12.4 at all - so referencing it there is a fatal error and
 *   not a deprecation one could live with for a release.
 * - Passing the v12 constant on v13 is not a way out either: it is the string
 *   `'replace'`, and `ResourceStorage::addFile()` answers a `$conflictMode`
 *   that is not an instance of the enum with `trigger_error(…,
 *   E_USER_DEPRECATED)` (13.4: ResourceStorage.php:1320ff). Both PHPUnit
 *   configurations of this extension set `failOnDeprecation="true"`, so that
 *   turns the suite red rather than merely logging a notice.
 *
 * This is code and not configuration, so the difference is split into one
 * implementation per core version below `Core12/` and `Core13/` rather than
 * decided by a conditional, and only the directory matching the running core
 * version is registered in the container - see
 * `docs/architecture/core-version-aware-code.md` and
 * `Configuration/Services.php`.
 *
 * What is modelled here is the **operation**, not the argument value. A method
 * handing the conflict mode back to shared code would have to declare a `mixed`
 * return - an enum case on one version, a string constant on the other - which
 * is the version difference pushed back into `Classes/` in a shape the type
 * system cannot describe and PHPStan cannot check. With the whole `addFile()`
 * call inside the implementation, each version's argument type is concrete and
 * {@see FileSeeder} sees a single, fully typed method.
 *
 * @todo Delete this interface together with both implementations and inline the
 *       `addFile()` call back into {@see FileSeeder} as soon as TYPO3 v12
 *       support is dropped: from v13 on the enum is the only accepted spelling
 *       and there is nothing left to abstract over.
 *
 * @internal Part of the seeding implementation, not public API.
 */
interface FileImporterInterface
{
    /**
     * Copies `$localFilePath` into `$targetFolder` of `$storage` and indexes it
     * under `$targetFileName`.
     *
     * Two properties of the operation are part of its name rather than
     * arguments, because the seeder needs both and nothing else would be
     * correct here:
     *
     * - An existing file of the same name is **replaced**. Seeding is
     *   repeatable by definition, and the two alternatives core offers are both
     *   unusable for it: renaming fills the storage with `placeholder_01.svg`,
     *   `placeholder_02.svg`, … on every run, and cancelling throws an
     *   `ExistingTargetFileNameException` the second time a seed is applied.
     * - The file at `$localFilePath` is **kept**. `addFile()` defaults to
     *   removing it (12.4: ResourceStorage.php:1261, 13.4:
     *   ResourceStorage.php:1312). The seeder hands over a temporary copy of
     *   the seed file rather than the seed file itself, and removes that copy
     *   in a `finally` of its own, so keeping it here leaves one place that
     *   owns its lifetime, on success and on failure alike.
     *
     * `$targetFolder` is expected to belong to `$storage`. `addFile()` itself
     * makes that assumption: it only reads the folder's identifier and never
     * compares storages.
     */
    public function addFileReplacingExisting(
        ResourceStorage $storage,
        string $localFilePath,
        Folder $targetFolder,
        string $targetFileName,
    ): File;
}
