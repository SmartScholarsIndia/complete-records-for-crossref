{**
 * plugins/generic/crossrefComplete/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * The journal's choices for Complete Records for Crossref.
 *}
<script>
	$(function() {ldelim}
		$('#crossrefCompleteSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="crossrefCompleteSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="crossrefCompleteSettingsFormNotification"}

	<p>{translate key="plugins.generic.crossrefComplete.settings.description"}</p>

	{fbvFormArea id="crossrefCompleteWhat"}
		{fbvFormSection list=true title="plugins.generic.crossrefComplete.settings.send"}
			{fbvElement type="checkbox" id="references" checked=$references label="plugins.generic.crossrefComplete.settings.references"}
			{fbvElement type="checkbox" id="affiliations" checked=$affiliations label="plugins.generic.crossrefComplete.settings.affiliations"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="crossrefCompleteCheck"}
		{fbvFormSection list=true title="plugins.generic.crossrefComplete.settings.check"}
			{fbvElement type="checkbox" id="schemaCheck" checked=$schemaCheck label="plugins.generic.crossrefComplete.settings.schemaCheck"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="crossrefCompleteCrossmark"}
		{fbvFormSection title="plugins.generic.crossrefComplete.settings.crossmark"}
			<p>{translate key="plugins.generic.crossrefComplete.settings.crossmark.description"}</p>
			{fbvElement type="text" id="crossmarkPolicy" value=$crossmarkPolicy label="plugins.generic.crossrefComplete.settings.crossmarkPolicy" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection list=true description="plugins.generic.crossrefComplete.settings.crossmarkByRecord.description"}
			{fbvElement type="checkbox" id="crossmarkByRecord" checked=$crossmarkByRecord label="plugins.generic.crossrefComplete.settings.crossmarkByRecord"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
