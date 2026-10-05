# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.11] - 2026-10-05

### Fixed
- Inline editing no longer writes values you did not touch: only changed cells are sent, so empty select attributes such as Country of Manufacture or Meta Robots are no longer saved with their first option, and select editors show a blank choice for empty values.
- Saving an inline edit with the Price column visible no longer fails with "Invalid value for attribute price"; formatted prices such as `$24.00` or `$1,234.50` are parsed with the admin locale.
- The "Mass Edit Attributes" panel loads its attribute list again; it failed with "Failed to load attributes" because the request URL had no admin secret key. "Categories" is listed once in the panel.
- The "Low Stock" and "Backorders" filters now filter the grid; before, they were ignored and logged an exception.
- Sorting by "Qty Sold", "Availability", "Backorders" and "Low Stock" now orders the grid.
- The "Image" column shows the product image; before, its cells were empty.
- The "Categories" column offered in the column manager now exists in the grid, with a category filter that includes "No Categories". Long category lists are limited to three lines.
- The "Backorders" column honours the configured backorders setting for products that use the config value.
- The "Manage Columns" panel lists every grid column (ID, Type, Attribute Set, Quantity, Salable Quantity, Websites and Action were missing), shows the grid's column labels instead of attribute codes or attribute labels, and shows the visibility, edit and filter state the grid actually uses.
- The product grid toolbar has an "Export" button (CSV and Excel XML), so the visible-columns export described in the README can be used.
- The Save and Cancel buttons of an inline edit on the last grid row are no longer cut off.
- Small text in the column manager and mass edit panel is at least 12px with stronger contrast.
- The descriptions of the settings this version does not read (Editing Mode, Warn About Unsaved Changes, Enable Qty Sold Column) now say so instead of promising behaviour that does not exist.
- "Enable Extended Grid" now works: when set to No, Catalog > Products shows the standard Magento product grid (no extra columns or filters, inline editing, mass edit, export button or column manager), saved grid views keep loading, and filters or sorting saved on the extended columns are ignored. Before, the setting was not read.
