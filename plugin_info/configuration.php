<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cloud"></i> {{Service ORES}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="http_timeout" placeholder="10">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à l'API ORES. 10 convient dans la quasi-totalité des cas.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Fréquence de rafraîchissement}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="refresh_minutes" placeholder="15" min="15" step="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Minutes entre deux lectures de l'API ORES. Plancher 15 minutes (cron15). L'API renvoie l'ensemble des pannes actives — environ 300 Ko — chaque appel : espacer limite la charge réseau.}}</span>
			</div>
		</div>
	</fieldset>
</form>
