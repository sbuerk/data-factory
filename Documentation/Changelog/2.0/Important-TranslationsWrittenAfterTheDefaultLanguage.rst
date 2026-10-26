..  include:: /Includes.rst.txt

..  _important-translations-written-after-the-default-language:

================================================================
Important: Translations are written after the default language
================================================================

Description
===========

A seed set is written through :php:`DataHandler`. The records of a workspace
were written in one run, and are written in more than one now: the records of
the default language and of "all languages" first, then the translations, and
a translation of a translation after the translation it was made from.

TYPO3 14.3.10 gives every inline child the language of its parent when the
parent is created in the run, changes its language or gets a new child, see
`TYPO3 issue 103970 <https://forge.typo3.org/issues/103970>`__. A record can
be the inline child of two parents, for example a contact listed by its page
and by its role, with a role that is :php:`'l10n_mode' => 'exclude'` on the
contact. Its translation names the role of the default language. Written in
one run with that role, the translation became a record of the default
language without a translation parent. Written after it, the translation keeps
its language, as it does when an editor translates the record in the backend.

Impact
======

On TYPO3 14.3.10 and later the translations of such a record keep their
language and their translation parent.

A translation of a translation keeps its :sql:`l10n_source` on both TYPO3 13
and TYPO3 14. It was written with the original as source before, by a defect
of the remap stack of :php:`DataHandler`.

A record of the default language that is declared behind a translation on the
same page is positioned behind the record that translation translates. The
order of the records of the default language does not change.

Affected installations
======================

Seed sets with translations. A set that declared :sql:`l10n_source` on a
translation of a translation to work around the defect can drop the
declaration.
