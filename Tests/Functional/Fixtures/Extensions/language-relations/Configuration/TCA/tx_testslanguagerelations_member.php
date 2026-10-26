<?php

declare(strict_types=1);

/**
 * A language aware inline child with two inline parents.
 *
 * Its content element owns it through "content_uid", the relation of
 * "tt_content.tx_testslanguagerelations_members", and its group lists it
 * through "group_uid". The group is a select with `l10n_mode` `exclude`, so a
 * translation keeps the group of its default language record.
 *
 * As for the other fixture tables, there is no "ext_tables.sql": every column
 * is derived from this TCA.
 */
return [
    'ctrl' => [
        'title' => 'Language relations: member',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'versioningWS' => true,
        'hideTable' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'content_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'group_uid' => [
            'label' => 'Group',
            'l10n_mode' => 'exclude',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_testslanguagerelations_group',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'sys_language_uid, l10n_parent, title, group_uid',
        ],
    ],
];
