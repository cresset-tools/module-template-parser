# Harvested corpus

These are real templates copied verbatim from the Magento Open Source / Mage-OS tree
(`app/code/Magento/**/*.html`), used as parser test input.

They are Adobe's, licensed OSL-3.0 / AFL-3.0 — the same licence this package carries.
They are included so the parser is tested against content that actually exists rather than
content invented to suit it, including `.html` files whose `{{` sequences are not directives
at all (translation strings, JS templates).

Regenerate with the harvest step described in the root README.
