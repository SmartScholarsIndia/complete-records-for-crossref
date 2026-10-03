{**
 * plugins/generic/crossrefComplete/templates/status.tpl
 *
 * Copyright (c) 2026 Smart Scholars
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * What the plugin did with the last file OJS built, and the records deposited since the plugin was turned on, read
 * back from Crossref: what landed, what is still waiting.
 *}
<div class="crossrefComplete_status">
	<p>{translate key="plugins.generic.crossrefComplete.status.description"}</p>
	{if $testModeLine}
		<div class="pkp_notification"><div class="notifyWarning">{$testModeLine|escape}</div></div>
	{/if}
	{if $schemaCheckLine}<p class="crossrefComplete_schemaCheck">{$schemaCheckLine|escape}</p>{/if}
	<h4>{translate key="plugins.generic.crossrefComplete.status.lastFile.title"}</h4>
	<p class="crossrefComplete_lastFile">{$lastFileHead|escape}</p>
	{if $lastFileRows}
		<ul class="crossrefComplete_lastFileRows">
			{foreach from=$lastFileRows item=line}<li>{$line|escape}</li>{/foreach}
		</ul>
	{/if}
	{if $error}
		<div class="pkp_notification"><div class="notifyWarning">{$error|escape}</div></div>
	{/if}
	{if !$rows}
		<p>{translate key="plugins.generic.crossrefComplete.status.none"}</p>
	{else}
		<table class="pkpTable" style="width:100%">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.crossrefComplete.status.doi"}</th>
					<th>{translate key="plugins.generic.crossrefComplete.status.deposited"}</th>
					<th>{translate key="plugins.generic.crossrefComplete.status.result"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$rows item=row}
					<tr>
						<td><a href="https://doi.org/{$row.doi|escape:"url"}" target="_blank" rel="noopener">{$row.doi|escape}</a></td>
						<td>{$row.at|date_format:$datetimeFormatShort}</td>
						<td>
							{if !$row.checked}
								{translate key="plugins.generic.crossrefComplete.status.notChecked"}
							{else}
								{foreach from=$row.result key=element item=state}
									<span class="crossrefComplete_{$state|escape}" style="display:inline-block;margin:0 .6em .2em 0;padding:0 .4em;border-radius:3px;{if $state == 'landed'}background:#e3f4e8;color:#1b5e2a{elseif $state == 'waiting'}background:#fff4d6;color:#7a5200{else}background:#fde4e1;color:#8a1c12{/if}">
										{if isset($labels[$element])}{$labels[$element]|escape}{else}{$element|escape}{/if}: {translate key="plugins.generic.crossrefComplete.state.`$state`"}
									</span>
								{/foreach}
							{/if}
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>
