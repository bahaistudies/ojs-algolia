{**
 * plugins/generic/algolia/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Copyright (c) 2019 Jun Kim / Foster Made, LLC
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Algolia plugin settings
 *}

<div id="algoliaSettings">

<script>
    $(function() {ldelim}
        // Attach the form handler.
        $('#algoliaSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>
<form class="pkp_form" id="algoliaSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}
    {include file="common/formErrors.tpl"}

    {fbvFormArea id="algoliaSettingsFormArea" title="plugins.generic.algolia.settings.algoliaServerSettings"}
        <div id="description"><p>{translate key="plugins.generic.algolia.settings.description"}</p></div>

        {fbvElement type="text" id="index" value=$index label="plugins.generic.algolia.settings.index" required=true}
        <span class="instruct">{translate key="plugins.generic.algolia.settings.indexInstructions"}</span>
        <div class="separator"></div>
        <br>

        {fbvElement type="text" id="appId" value=$appId label="plugins.generic.algolia.settings.appId" required=true}
        <span class="instruct">{translate key="plugins.generic.algolia.settings.appIdInstructions"}</span>
        <div class="separator"></div>
        <br>

        {fbvElement type="text" id="searchOnlyKey" value=$searchOnlyKey label="plugins.generic.algolia.settings.searchOnlyKey" required=true}
        <span class="instruct">{translate key="plugins.generic.algolia.settings.searchOnlyKeyInstructions"}</span>
        <div class="separator"></div>
        <br>

        {fbvElement type="text" id="adminKey" value=$adminKey label="plugins.generic.algolia.settings.adminKey" required=true}
        <span class="instruct">{translate key="plugins.generic.algolia.settings.adminKeyInstructions"}</span>
        <div class="separator"></div>
        <br>

        <span class="formRequired">{translate key="common.requiredField"}</span>
    {/fbvFormArea}

    {fbvFormButtons}

    <a id="indexAdmin"> </a>
    <h3>{translate key="plugins.generic.algolia.settings.indexAdministration"}</h3>
    <script>
        function jumpToIndexAdminAnchor() {ldelim}
            $form = $('#algoliaSettings form');
            // Return directly to the rebuild index section.
            $form.attr('action', $form.attr('action') + '#indexAdmin');
            return true;
        {rdelim}
    </script>

    <div class="separator"></div>
    <br />

    <table class="data">
        <tr>
            <td class="label">{fieldLabel name="rebuildIndex" key="plugins.generic.algolia.settings.indexRebuild"}</td>
            <td class="value">
                <select name="journalToReindex" id="journalToReindex" class="selectMenu">
                    {html_options options=$journalsToReindex selected=$journalToReindex}
                </select>
                <script>
                    function rebuildIndexClick() {ldelim}
                        var confirmation = confirm({translate|json_encode key="plugins.generic.algolia.settings.indexRebuild.confirm"});
                        if (confirmation === true) jumpToIndexAdminAnchor();
                        return confirmation;
                    {rdelim}
                </script>
                <input type="submit" name="rebuildIndex" value="{translate key="plugins.generic.algolia.settings.indexRebuild"}" onclick="rebuildIndexClick()" class="action" /><br/>
                <br/>
                {if $rebuildIndexMessages}
                    <div id="rebuildIndexMessage">
                        <strong>{translate key="plugins.generic.algolia.settings.indexRebuildMessages"}</strong><br/>
                        {$rebuildIndexMessages|escape|replace:$smarty.const.PHP_EOL:"<br/>"|replace:" ":"&nbsp;"}
                    </div>
                {else}
                    <span class="instruct">{translate key="plugins.generic.algolia.settings.indexRebuildDescription"}</span><br/>
                {/if}
                <br/>
            </td>
        </tr>
    </table>
</form>
</div>
