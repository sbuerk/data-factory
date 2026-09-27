<?php

// Read by TYPO3 v12 and v13 and by the TER, alongside composer.json, so every
// value duplicated between the two files has to be kept in sync by hand. The
// file is not removable regardless: "tailor create-artefact" validates
// 'version' against the release tag and refuses an extension without
// 'constraints.depends.typo3'.

$EM_CONF['data_factory'] = [
    'title' => 'TYPO3 Data Factory',
    'description' => 'Seeds pages, records, files and site configurations into a TYPO3 installation from YAML definitions shipped by extensions.',
    'version' => '1.0.2',
    'category' => 'misc',
    // Describes the release line, not the working tree, and no tooling rewrites
    // it — "setVersion.sh" only touches 'version'. It is not cosmetic either:
    // TYPO3 derives a state from the composer version and then overrides it
    // with this one (ListUtility::enrichExtensionsWithEmConfInformation()), in
    // composer mode as well, and the TER listing is built from it. Leaving
    // 'alpha' here would label a stable release as alpha in both places.
    'state' => 'stable',
    'author' => 'Stefan Bürk',
    'author_email' => 'stefan@buerk.tech',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            // Mirrors "require" in composer.json, which is authoritative. This
            // format has one range per package and cannot express the
            // "^12.4.22 || ^13.4" disjunction, so a single range spans both
            // supported lines. Its upper bound is exact rather than a guess at
            // a version that does not exist yet: 13.4 is the final minor of
            // TYPO3 v13, so "^13.4" and an upper bound of 13.4.99 admit the
            // same releases.
            //
            // 'core' is deliberately absent: TYPO3 maps 'typo3' onto
            // require->core itself, in both v12 and v13
            // (PackageManager::mapExtensionManagerConfigurationToComposerManifest()),
            // so a second entry writes the same property and only adds a value
            // that can drift away from this one.
            'php' => '8.1.0-8.4.99',
            'typo3' => '12.4.22-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
