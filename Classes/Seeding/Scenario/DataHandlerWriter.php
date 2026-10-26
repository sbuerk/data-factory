<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Scenario;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the maps a `DataHandlerFactory` produced through `DataHandler`, one
 * pass per workspace, substituting `NEW` identifiers with the uids the
 * previous pass produced.
 *
 * Ported from `typo3/testing-framework` 9.6.1, class
 * `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerWriter`,
 * for the reason given on `DataHandlerFactory`.
 *
 * Four deliberate divergences from upstream: the optional
 * `$withoutSuggestedUids` documented on the constructor, the reset of
 * `DataHandler::$autoVersionIdMap` between two workspace rounds documented in
 * `invokeFactory()`, the minus that `updateDataMap()` and
 * `updateCommandMap()` keep on a substituted `-NEW…` value, and the
 * translations of a workspace round written in a round of their own, see
 * `splitDataMapByLanguage()`. The first two are additive - one is inert when
 * it is not passed, the other only matters for a scenario declaring more than
 * one workspace, which upstream has no test and TYPO3 Core no fixture for.
 *
 * Note what this class does **not** do, because the seeding pipeline has to add
 * it: it never sets `DataHandler::$isImporting`, it never checks that the
 * backend user is an admin — without which `suggestedInsertUids` is silently
 * ignored — and it does not throw on a failed write, it collects `errorLog`
 * into `getErrors()` and returns.
 *
 * This is a writer holding the state of one run, not a service.
 *
 * @internal This is part of the seeding implementation of this extension and
 *           not public API.
 */
#[Exclude]
final class DataHandlerWriter
{
    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * @param array<string, true> $withoutSuggestedUids Suggestions to hold
     *        back, keyed `<table>:<uid>` exactly as `getSuggestedIds()` keys
     *        them. Deliberate, additive divergence from
     *        `typo3/testing-framework` 9.6.1: with the default empty array the
     *        `array_diff_key()` below returns its first argument unchanged, so
     *        every call that does not pass it behaves byte for byte as upstream
     *        does. The seeding pipeline needs it because `--force` gives up the
     *        suggested uids of a table this installation already uses, and
     *        `invokeFactory()` assigns `suggestedInsertUids` itself - there is
     *        no moment between "the writer was constructed" and "the uid is
     *        read" in which a caller could reduce it from the outside.
     */
    public function __construct(
        private readonly DataHandler $dataHandler,
        private readonly BackendUserAuthentication $backendUser,
        private readonly array $withoutSuggestedUids = [],
    ) {}

    public static function withBackendUser(BackendUserAuthentication $backendUser): self
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        if (isset($backendUser->uc['copyLevels']) && property_exists($dataHandler, 'copyTree')) {
            $dataHandler->copyTree = $backendUser->uc['copyLevels'];
        }
        return new self($dataHandler, $backendUser);
    }

    public function invokeFactory(DataHandlerFactory $factory): void
    {
        $this->dataHandler->suggestedInsertUids = array_diff_key(
            $factory->getSuggestedIds(),
            $this->withoutSuggestedUids,
        );
        foreach ($factory->getDataMapPerWorkspace() as $workspaceId => $workspaceDataMap) {
            $backendUser = clone $this->backendUser;
            $backendUser->workspace = $workspaceId;
            // Deliberate divergence from `typo3/testing-framework` 9.6.1,
            // which reuses one `DataHandler` for every round and leaves this
            // map alone. `DataHandler::$autoVersionIdMap` remembers which
            // workspace version it auto-created for a live uid, `start()` does
            // not reset it, and `process_datamap()` reads it *before* it asks
            // whether a version for the current workspace exists. A second
            // workspace declaring a version of the same record therefore
            // overwrites the version of the first one and creates nothing of
            // its own - silently, with an empty error log.
            //
            // The map is per round by nature: it exists so children versioned
            // along with their parent are written to the version rather than
            // to the live record, and that is finished when the round is.
            $this->dataHandler->autoVersionIdMap = [];
            foreach ($this->splitDataMapByLanguage($workspaceDataMap) as $dataMap) {
                $dataMap = $this->updateDataMap($dataMap);
                $this->dataHandler->start($dataMap, [], $backendUser);
                $this->dataHandler->process_datamap();
                $this->errors = array_merge($this->errors, $this->errorLog());
            }
        }
        foreach ($factory->getCommandMapPerWorkspace() as $workspaceId => $commandMap) {
            $commandMap = $this->updateCommandMap($commandMap);
            $backendUser = clone $this->backendUser;
            $backendUser->workspace = $workspaceId;
            $this->dataHandler->start([], $commandMap, $backendUser);
            $this->dataHandler->process_cmdmap();
            $this->errors = array_merge($this->errors, $this->errorLog());
        }
    }

    /**
     * Splits the data map of one workspace round into runs of `DataHandler`:
     * the records of the default language and of "all languages" first, then
     * the translations, and a translation of a translation after the
     * translation it was made from.
     *
     * Deliberate divergence from `typo3/testing-framework` 9.6.1, which writes
     * a round in one run. TYPO3 14.3.10 gives every inline child the language
     * of its parent when that parent is created in the run (core #103970,
     * "Inline children follow the language of their parent"), and it expects
     * an inline child to have one parent. A child with a second inline parent
     * whose relation column is `l10n_mode` `exclude` - a contact that lists
     * the role of its default language record, for example - is a child of
     * the default language parent in its translation as well. Written in one
     * run, that parent is new, and the translation becomes a record of the
     * default language without a translation parent. Written after the
     * default language, the parent already exists and is left alone, as it
     * is when an editor translates the record in the backend.
     *
     * A translation is a record of a language aware table whose language
     * field is above 0, and it is written in the run after the record its
     * translation source - or, without one, its translation parent - is
     * written in. Its values that name a record of an earlier run by its
     * `NEW…` identifier are substituted by `updateDataMap()`, exactly as
     * between two workspace rounds.
     *
     * The factory positions a record behind the one declared before it on
     * the same page, translations included. A record positioned behind a
     * translation of a later run is positioned where that translation was
     * positioned instead, which is behind the record it translates. The order
     * of the records of one run does not change.
     *
     * @param array<string, array<string, array<string, mixed>>> $dataMap
     * @return list<array<string, array<string, array<string, mixed>>>>
     */
    private function splitDataMapByLanguage(array $dataMap): array
    {
        $runOf = [];
        $sourcesOf = [];
        foreach ($dataMap as $tableName => $tableDataMap) {
            // Read from the TCA array rather than through the schema API: the
            // writer is constructed with `new`, also where no container is
            // there to build a `TcaSchemaFactory`.
            $ctrl = $GLOBALS['TCA'][$tableName]['ctrl'] ?? [];
            $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : null;
            $sourceFields = array_values(array_filter(
                [$ctrl['translationSource'] ?? null, $ctrl['transOrigPointerField'] ?? null],
                is_string(...),
            ));
            foreach ($tableDataMap as $key => $values) {
                $runOf[$tableName . ':' . $key] = 0;
                if ($languageField === null || (int)($values[$languageField] ?? 0) <= 0) {
                    continue;
                }
                foreach ($sourceFields as $sourceField) {
                    if (is_string($values[$sourceField] ?? null) && str_starts_with($values[$sourceField], 'NEW')) {
                        $sourcesOf[$tableName . ':' . $key] = $tableName . ':' . $values[$sourceField];
                        break;
                    }
                }
                $sourcesOf[$tableName . ':' . $key] ??= null;
            }
        }
        if ($sourcesOf === []) {
            return [$dataMap];
        }
        // A translation is written one run after its source, and a source of
        // this round is resolved first. A source that is not part of the round
        // was written before it, so the translation goes into the second run.
        $resolve = static function (string $record) use (&$resolve, &$runOf, $sourcesOf): int {
            if (!array_key_exists($record, $sourcesOf)) {
                return $runOf[$record] ?? 0;
            }
            $source = $sourcesOf[$record];
            return $runOf[$record] = ($source !== null && isset($runOf[$source]) ? $resolve($source) : 0) + 1;
        };
        foreach (array_keys($sourcesOf) as $record) {
            $resolve($record);
        }

        $runs = [];
        foreach ($dataMap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $key => $values) {
                $run = $runOf[$tableName . ':' . $key];
                $pid = $values['pid'] ?? null;
                // Follow the position chain past every record of a later run.
                while (is_string($pid) && str_starts_with($pid, '-NEW')
                    && ($runOf[$tableName . ':' . substr($pid, 1)] ?? 0) > $run
                ) {
                    $pid = $dataMap[$tableName][substr($pid, 1)]['pid'] ?? null;
                }
                if ($pid !== null) {
                    $values['pid'] = $pid;
                }
                $runs[$run][$tableName][$key] = $values;
            }
        }
        ksort($runs);

        return array_values($runs);
    }

    /**
     * `DataHandler::$errorLog` is declared as a plain `array`. It only ever
     * holds the rendered message strings, which this narrows down to so the
     * accumulated `$errors` keeps a usable type.
     *
     * @return list<string>
     */
    private function errorLog(): array
    {
        /** @var list<string> $errorLog */
        $errorLog = $this->dataHandler->errorLog;
        return $errorLog;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $dataMap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function updateDataMap(array $dataMap): array
    {
        $updatedTableDataMap = [];
        foreach ($dataMap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $key => $values) {
                $key = $this->dataHandler->substNEWwithIDs[$key] ?? $key;
                $values = array_map(
                    function ($value) {
                        if (!is_string($value)) {
                            return $value;
                        }
                        if (str_starts_with($value, 'NEW')) {
                            return $this->dataHandler->substNEWwithIDs[$value] ?? $value;
                        }
                        if (str_starts_with($value, '-NEW')) {
                            // Deliberate divergence from
                            // `typo3/testing-framework` 9.6.1, which returns
                            // the substituted uid without the minus it just
                            // stripped. `-42` means "put this record behind
                            // record 42"; `42` means "put it on page 42". The
                            // minus is the whole meaning of the value.
                            $substitutedId = $this->dataHandler->substNEWwithIDs[substr($value, 1)] ?? null;
                            return $substitutedId === null ? $value : '-' . $substitutedId;
                        }
                        return $value;
                    },
                    $values
                );
                if ((string)$key === (string)(int)$key) {
                    unset($values['pid']);
                }
                if (isset($values['uid']) && isset($this->withoutSuggestedUids[$tableName . ':' . $values['uid']])) {
                    // Part of the same additive divergence as the constructor
                    // parameter, and not cosmetic. `process_datamap()` reads the
                    // suggested uid out of `uid` and passes it to `insertDB()`,
                    // which honours it only when `suggestedInsertUids` carries
                    // it - but `postProcessDatabaseInsert()` then returns that
                    // very number on PostgreSQL whether it was honoured or not
                    // (DataHandler.php:9669ff), so `substNEWwithIDs` would map
                    // the identifier to a uid no row has. Every later record
                    // pointing at it - a child's `pid`, a sibling's `-NEW` -
                    // then points at nothing. Dropping `uid` along with the
                    // suggestion is what keeps the two in step, and it costs
                    // nothing: `insertDB()` unsets the field before the INSERT
                    // either way.
                    unset($values['uid']);
                }
                $updatedTableDataMap[$tableName][$key] = $values;
            }
        }
        return $updatedTableDataMap;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $commandMap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function updateCommandMap(array $commandMap): array
    {
        $updatedTableCommandMap = [];
        foreach ($commandMap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $key => $values) {
                $key = $this->dataHandler->substNEWwithIDs[$key] ?? $key;
                $values = array_map(
                    function ($value) {
                        if (!is_string($value)) {
                            return $value;
                        }
                        if (str_starts_with($value, 'NEW')) {
                            return $this->dataHandler->substNEWwithIDs[$value] ?? $value;
                        }
                        if (str_starts_with($value, '-NEW')) {
                            // Deliberate divergence from
                            // `typo3/testing-framework` 9.6.1, which returns
                            // the substituted uid without the minus it just
                            // stripped. `-42` means "put this record behind
                            // record 42"; `42` means "put it on page 42". The
                            // minus is the whole meaning of the value.
                            $substitutedId = $this->dataHandler->substNEWwithIDs[substr($value, 1)] ?? null;
                            return $substitutedId === null ? $value : '-' . $substitutedId;
                        }
                        return $value;
                    },
                    $values
                );
                $updatedTableCommandMap[$tableName][$key] = $values;
            }
        }
        return $updatedTableCommandMap;
    }
}
