# Harvested corpus

Real templates copied verbatim from the Magento Open Source / Mage-OS tree
(`app/code/Magento/**/*.html`), used as parser test input.

They are included so the parser is tested against content that actually exists rather than
content invented to suit it, including `.html` files whose `{{` sequences are not directives
at all, such as translation strings and JS templates.

To add more, copy `.html` files out of a Magento tree and name them for their source path with
`/` replaced by `__`, matching the files already here. Only `*.html` is picked up, by both
`CorpusTest` and `tools/record-legacy.php`. Re-record the parity fixtures afterwards; see
CONTRIBUTING.md.

## Licence

These files are not part of this package's own source.

    Copyright © 2013-present Magento, Inc.

Licensed under OSL 3.0 and AFL 3.0. The package itself is OSL 3.0 only, so this directory is
the one place where AFL 3.0 also applies; see LICENSE_AFL.txt in the repository root.
