{**
 * templates/results.tpl
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Results of a publication format proof upload.
 *}

{if $errors}
	<h2>{translate key="plugins.importexport.publicationFormatsUploader.result.errors"}</h2>
	<ul>
		{foreach from=$errors item=error}
			<li>{$error|escape}</li>
		{/foreach}
	</ul>
{/if}

{if $successMessages}
	<h2>{translate key="plugins.importexport.publicationFormatsUploader.result.summary"}</h2>
	<ul>
		{foreach from=$successMessages item=successMessage}
			<li>{$successMessage|escape}</li>
		{/foreach}
	</ul>
{elseif !$errors}
	<p>{translate key="plugins.importexport.publicationFormatsUploader.result.noChanges"}</p>
{/if}

<a href="{plugin_url path="index"}">{translate key="plugins.importexport.publicationFormatsUploader.result.return"}</a>
