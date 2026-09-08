{**
 * templates/index.tpl
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Modal file upload form for the Publication Formats Uploader plugin.
 *}

<script>
	$(function() {ldelim}
		$('#publicationFormatsUploadForm').pkpHandler(
			'$.pkp.controllers.form.FileUploadFormHandler',
			{ldelim}
				$uploader: $('#plupload'),
				uploaderOptions: {ldelim}
					uploadUrl: {url|json_encode router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category=$pluginCategory plugin=$pluginName verb="uploadTemporaryFile" escape=false},
					baseUrl: {$baseUrl|json_encode}
				{rdelim}
			{rdelim}
		);
	{rdelim});
</script>

<form
	class="pkp_form"
	id="publicationFormatsUploadForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category=$pluginCategory plugin=$pluginName verb="uploadFile"}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="publicationFormatsUploadNotification"}

	{fbvFormArea id="publicationFormatsUpload"}
		{fbvFormSection title="plugins.generic.publicationFormatsUploader.instructions" required=true}
			{fbvElement type="hidden" id="temporaryFileId" value=""}
			{include file="controllers/fileUploadContainer.tpl" id="plupload"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="plugins.generic.publicationFormatsUploader.upload"}
</form>
