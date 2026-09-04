# Publication Formats Uploader for OMP 3.5

This generic plugin bulk uploads proof files into OMP 3.5 publication formats.
It is an OMP-specific implementation and targets OMP only. The same plugin also
hides dependent submission files from the **Edit Chapter > Files** selector
without deleting them or changing their chapter or parent-file associations.

## Installation

Install the plugin through **Dashboard > Website Settings > Plugins > Upload A New
Plugin**, or extract it to the exact directory
`plugins/generic/publicationFormatsUploader` in an OMP 3.5 installation. Enable
it under **Website Settings > Plugins > Installed Plugins**.

When enabled, open **Publication Formats Uploader > Upload publication formats**
in the Installed Plugins list. The uploader opens in the standard plugin modal.
The action is hidden, and its component requests are rejected, while the plugin
is disabled. Disabling the plugin also stops its chapter-form filtering, so OMP
returns to its stock chapter file behavior.

## Migrating a Docker lab from the import/export plugin

This generic plugin is a separate category installation. Do not edit the old
`plugins.importexport` row in the `versions` table to turn it into a generic
row. OMP must discover/install `publicationFormatsUploader` under
`plugins.generic`, creating its own version record; the old import/export row
may remain as installation history.

The lab configuration is intentionally not changed in this repository. After
review and before testing the single-plugin layout:

1. Change the uploader bind mount or copied directory so this checkout appears
   at `plugins/generic/publicationFormatsUploader` in the OMP container.
2. Remove the old `plugins/importexport/publicationFormatsUploader` mount or
   directory from the container so the former **Tools > Import/Export** entry is
   not loaded. Do not rewrite its existing database version row.
3. Clear OMP's application/template caches and restart the affected container.
4. Visit **Website Settings > Plugins > Installed Plugins**, allow OMP to record
   the generic product if needed, and enable **Publication Formats Uploader**.
5. Confirm that this plugin provides both the uploader action and the filtered
   **Edit Chapter > Files** selector.

## Removing the separate chapter-file filter from a lab

After installing this single-plugin version and confirming that it is enabled
for the press:

1. Disable **OMP Chapter File Filter** (`ompChapterFileFilter`) under **Website
   Settings > Plugins > Installed Plugins**.
2. Remove its separate bind mount or its
   `plugins/generic/ompChapterFileFilter` directory from the OMP container. Do
   not remove or alter this repository's `ChapterFileFilter.php`.
3. Clear OMP's application and template caches, then restart the affected
   container.
4. Verify that only **Publication Formats Uploader** is enabled, that its upload
   action still opens, and that dependent files remain absent from **Edit
   Chapter > Files**.

The old `ompChapterFileFilter` database version row may remain as installation
history; no database rewrite is required. The integrated filtering targets OMP
3.5's `ChapterForm` hooks and submission-file collector API and has been designed
for the OMP 3.5 line.

## ZIP naming contract

The preferred filename forms keep the submission ID at the end:

```
<name>-<submissionId>.<extension>
<name>-<locale>-<submissionId>.<extension>
<name>-cap<chapterId>-<submissionId>.<extension>
<name>-cap<chapterId>-<locale>-<submissionId>.<extension>
```

For compatibility, the previous explicit-locale order is also accepted:

```
<name>-<submissionId>-<locale>.<extension>
<name>-cap<chapterId>-<submissionId>-<locale>.<extension>
```

`submissionId` and `chapterId` are positive decimal integers within PHP's
integer range and without leading zeroes. The parser reads the structural suffix
from the end, so `name` may
contain arbitrary hyphens. A final name segment shaped like an OMP locale
(for example `en`, `es`, or `pt_BR`) is reserved for the explicit locale
position. Explicit locales must be in the press's supported submission locales;
when omitted, the submission locale is used.

Examples:

```
monografia-123.pdf
monografia-en-123.pdf
introduccion-cap1-123.pdf
introduccion-cap1-en-123.pdf
capitulo-segundo-cap7-es-123.html
```

The extension identifies the publication format (for example PDF, HTML, XML, or
EPUB). A chapter ID must belong to the submission's latest publication. Existing
proofs are revised only when publication format, language, and chapter assignment
all match exactly; book-level and chapter-level proofs never match each other.
Uploaded proofs are marked approved/viewable and retain open-access metadata.

JPG and CSS files continue to be treated as dependent files. They use the same
filename suffix contract and must be included with an HTML or XML proof for the
same submission, exact chapter assignment, and language in the same ZIP upload.
