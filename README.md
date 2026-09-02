# Publication Formats Uploader for OMP 3.5

This import/export plugin bulk uploads proof files into OMP 3.5 publication formats.
It is an OMP-specific implementation and targets OMP only.

## Installation

Install the plugin through **Dashboard > Website Settings > Plugins > Upload A New
Plugin**, or extract the plugin directory under `plugins/importexport` in an OMP
3.5 installation. Enable it from the plugin gallery after installation.

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
