<?php

// Read by TYPO3 v13 and by the TER. TYPO3 v14 derives the same metadata from
// composer.json and never opens this file (#108345), so every value here has a
// counterpart over there that has to be kept in sync by hand. The file is not
// removable regardless: "tailor create-artefact" validates 'version' against the
// release tag and refuses an extension without 'constraints.depends.typo3'.

$EM_CONF['data_factory'] = [
    'title' => 'TYPO3 Data Factory',
    'description' => 'Seeds pages, records, files and site configurations into a TYPO3 installation from YAML definitions shipped by extensions.',
    'version' => '2.0.2',
    'category' => 'misc',
    // Describes the release line, not the working tree, and no tooling rewrites
    // it — "setVersion.sh" only touches 'version'. It is not cosmetic either:
    // TYPO3 v13 derives a state from the composer version and then overrides it
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
            // "^13.4 || ^14.3" disjunction, so the upper bound follows what
            // composer resolves — below 15.0.0 — instead of capping at the
            // newest v14 minor that exists today. A cap of 14.3.99 would
            // declare TYPO3 14.4 unsupported on the day composer installs it.
            //
            // 'core' is deliberately absent: TYPO3 maps 'typo3' onto
            // require->core itself, in both v13 and v14
            // (PackageManager::mapExtensionManagerConfigurationToComposerManifest()),
            // so a second entry writes the same property and only adds a value
            // that can drift away from this one.
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
