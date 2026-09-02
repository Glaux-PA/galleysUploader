# Publication Formats Uploader for OMP 3.5

This import/export plugin bulk uploads proof files into OMP 3.5 publication formats.
It is an OMP-specific implementation and targets OMP only.

## Installation

Install the plugin through **Dashboard > Website Settings > Plugins > Upload A New
Plugin**, or extract the plugin directory under `plugins/importexport` in an OMP
3.5 installation. Enable it from the plugin gallery after installation.

## ZIP naming contract

Main proof files use this form:

`<prefix>-<submissionId>[-<locale>].<extension>`

The extension identifies the publication format (for example PDF, HTML, XML, or
EPUB), and the optional locale is stored on the proof SubmissionFile. Existing
formats are matched by an exact localized name. Ambiguous matches are reported
and left unchanged.

JPG and CSS files continue to be treated as dependent files. They must use the
same submission identifier and be included with an HTML or XML proof for that
submission in the same ZIP upload.
