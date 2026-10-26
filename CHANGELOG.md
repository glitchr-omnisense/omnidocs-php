# Changelog

Every version of omnidocs/php, newest first ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
[Semantic Versioning](https://semver.org/)).

## Unreleased

### Changed
- The index is a card per class, by namespace (`section.api-group`, `li.api-card`): its name to its
  entry, its kind, its summary, what it holds counted ("2 options · 5 methods · 1 property"), the
  conventions omnidocs/jsdoc's index shares; the namespaces are no headings of the home any more (the
  parts are, in a one-page reading).
- Requires `omnisense/omnidocs`, the core's new name (Omniscient is now Omnisense); the repository is `glitchr-omnisense/omnidocs-php`.

### Added
- The source, its factory, omnidocs' conformance suite on a real tool's output.
