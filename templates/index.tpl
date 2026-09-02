{**
 * templates/index.tpl
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * File upload form for the Publication Formats Uploader plugin.
 *}

{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{translate key="plugins.importexport.publicationFormatsUploader.displayName"}
	</h1>

	<script type="text/javascript">
		$(function() {ldelim}
		$('#publicationFormatsUploaderTabs').pkpHandler('$.pkp.controllers.TabHandler');
		{rdelim});
	</script>

	<div id="publicationFormatsUploaderTabs">
		<ul>
			<li><a href="#import-tab">{translate key="plugins.importexport.publicationFormatsUploader.settings"}</a></li>
		</ul>
		<div id="import-tab">
			<script type="text/javascript">
				$(function() {ldelim}
				// Attach the form handler.
				$('#publicationFormatsUploadForm').pkpHandler('$.pkp.controllers.form.FileUploadFormHandler',
					{ldelim}
					$uploader: $('#plupload'),
					uploaderOptions: {ldelim}
						uploadUrl: {plugin_url|json_encode path="publicationFormatsUploadTempFile" escape=false},
						baseUrl: {$baseUrl|json_encode}
					{rdelim}
					{rdelim}
				);
				{rdelim});
			</script>
			<form id="publicationFormatsUploadForm" class="pkp_form" action="{plugin_url path="publicationFormatsUploadFile"}" method="post">
				{csrf}
				{fbvFormArea id="importForm"}
				{* Container for uploaded file *}
				<input type="hidden" name="temporaryFileId" id="temporaryFileId" value="" />

				{fbvFormArea id="file"}
				{fbvFormSection title="plugins.importexport.publicationFormatsUploader.instructions"}
				{include file="controllers/fileUploadContainer.tpl" id="plupload"}
				{/fbvFormSection}
				{/fbvFormArea}

				{fbvFormButtons submitText="plugins.importexport.publicationFormatsUploader.upload" hideCancel="false"}
				{/fbvFormArea}
			</form>
		</div>
	</div>
{/block}
