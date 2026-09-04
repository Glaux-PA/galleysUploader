{**
 * templates/results.tpl
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Results of a publication format proof upload in the plugin modal.
 *}

<div class="pkp_form">
	{if $errors}
		<h2>{translate key="plugins.generic.publicationFormatsUploader.result.errors"}</h2>
		<ul>
			{foreach from=$errors item=error}
				<li>{$error|escape}</li>
			{/foreach}
		</ul>
	{/if}

	{if $successMessages}
		<h2>{translate key="plugins.generic.publicationFormatsUploader.result.summary"}</h2>
		<ul>
			{foreach from=$successMessages item=successMessage}
				<li>{$successMessage|escape}</li>
			{/foreach}
		</ul>
	{elseif !$errors}
		<p>{translate key="plugins.generic.publicationFormatsUploader.result.noChanges"}</p>
	{/if}
</div>
