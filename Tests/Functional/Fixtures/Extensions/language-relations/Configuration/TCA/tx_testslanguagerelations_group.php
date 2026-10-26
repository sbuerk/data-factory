<?php

declare(strict_types=1);

/**
 * The second inline parent of a member.
 *
 * A member names its group in a select that is `l10n_mode` `exclude`, so the
 * translation of a member names the group of its default language record,
 * and the inline list of the group holds the translation as well. That is the
 * shape TYPO3 14.3.10 aligns to the language of the group when the group is
 * created in the same run as the translation (core #103970).
 *
 * As for the other fixture tables, there is no "ext_tables.sql": every column
 * is derived from this TCA.
 */
return [
    'ctrl' => [
        'title' => 'Language relations: group',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
        'members' => [
            'label' => 'Members',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testslanguagerelations_member',
                'foreign_field' => 'group_uid',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'sys_language_uid, l10n_parent, title, members',
        ],
    ],
];
