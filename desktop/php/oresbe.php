<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('oresbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une adresse}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes adresses surveillées}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune adresse pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter une adresse » et donnez-lui un nom, par exemple « ORES Maison ».}}</li>';
			echo '<li>{{Renseignez le code postal, le nom exact de la rue et le numéro.}}</li>';
			echo '<li>{{Cliquez sur « Tester l\'adresse » pour vérifier qu\'ORES reconnaît la rue, puis Sauvegardez.}}</li>';
			echo '</ol>';
			echo '<div style="margin-top:10px;font-style:italic;">{{Note : seule l\'électricité est couverte. ORES ne publie pas d\'API pour les coupures gaz ; pour une fuite ou odeur, le numéro d\'urgence 0800/87.087 reste la seule voie.}}</div>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-bolt" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#incidenttab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-exclamation-triangle"></i><span class="hidden-xs"> {{Pannes}}</span></a></li>
			<li role="presentation"><a href="#alerttab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bell"></i><span class="hidden-xs"> {{Alertes}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{ORES Maison}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-map-marker-alt"></i> {{Adresse}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Code postal}}</label>
								<div class="col-sm-3">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="zipcode" placeholder="7062" maxlength="4" pattern="\d{4}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Rue}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="street" placeholder="{{Rue des Prés Mercq}}">
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Nom tel qu'ORES le publie. Accents et casse indifférents : la comparaison est normalisée.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Numéro}}</label>
								<div class="col-sm-2">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="houseNumber" placeholder="3A">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{Numéro de maison. La parité (pair/impair) est utilisée pour matcher les plages ORES. « 3A » → 3, « 128/RD » → 128.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<a class="btn btn-info" id="bt_oresbeTest"><i class="fas fa-vial"></i> {{Tester l'adresse}}</a>
									<a class="btn btn-default" id="bt_oresbeRefresh" title="{{Relit l'API pour l'adresse enregistrée.}}"><i class="fas fa-sync"></i> {{Rafraîchir maintenant}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<span id="span_oresbeTestResult"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Dernier rafraîchissement}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_oresbeLastUpdate">-</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse retenue}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_oresbeAddress">-</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-info-circle"></i> {{Note sur le gaz}}</legend>
							<div class="alert alert-warning" style="margin:0;">
								{{ORES ne publie pas d'API ni de carte pour les coupures gaz. Ce plugin couvre uniquement l'électricité. En cas de fuite ou d'odeur, appelez le 0800/87.087 — numéro d'urgence 24h/24.}}
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== PANNES ========================== -->
			<div role="tabpanel" class="tab-pane" id="incidenttab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">{{Les pannes et interruptions ORES qui touchent cette adresse. Cette liste est relue à chaque rafraîchissement.}}</div>
					<div class="table-responsive">
						<table id="table_oresbeIncidents" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Titre}}</th>
								<th style="width:120px;">{{Type}}</th>
								<th style="width:160px;">{{Début}}</th>
								<th style="width:160px;">{{Fin prévue}}</th>
								<th style="width:100px;">{{Clients}}</th>
								<th style="width:80px;">{{Carte}}</th>
							</tr>
						</thead>
						<tbody></tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- ============================ ALERTES =========================== -->
			<div role="tabpanel" class="tab-pane" id="alerttab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Être prévenu dès qu'une nouvelle panne apparaît.}}</b>
						{{Une alerte joue une ou plusieurs actions — notification mobile, SMS, message vocal, allumer une lampe — à l'instant où ORES publie une nouvelle panne ou interruption touchant cette adresse. Les actions miroirs à la fin de l'incident permettent par exemple d'effacer un affichage matrix une fois le courant rétabli.}}
					</div>

					<form class="form-horizontal">
						<div id="div_oresbeNotifications"></div>
					</form>

					<a class="btn btn-default btn-sm" id="bt_oresbeAddNotification"><i class="fas fa-plus-circle"></i> {{Ajouter une alerte}}</a>

					<fieldset style="margin-top:20px;">
						<legend><i class="fas fa-code"></i> {{Jetons utilisables dans le titre et le message}}</legend>
						<div class="table-responsive">
							<table class="table table-bordered table-condensed">
								<tbody>
									<tr><td style="width:170px;"><code>#titre#</code></td><td>{{« Panne électrique » ou « Interruption planifiée ».}}</td></tr>
									<tr><td><code>#type#</code></td><td>{{« panne » ou « interruption » (brut, pour conditions de scénario).}}</td></tr>
									<tr><td><code>#debut#</code></td><td>{{Date et heure de début, format FR : « sam 03/10/2026 09:00 ».}}</td></tr>
									<tr><td><code>#fin#</code></td><td>{{Date et heure de fin prévue.}}</td></tr>
									<tr><td><code>#retard#</code></td><td>{{« oui » si ORES a signalé un retard sur la fin prévue, « non » sinon.}}</td></tr>
									<tr><td><code>#clients#</code></td><td>{{Nombre total de clients impactés par la panne.}}</td></tr>
									<tr><td><code>#rue#</code></td><td>{{Rue impactée (telle qu'ORES l'écrit).}}</td></tr>
									<tr><td><code>#generateur#</code></td><td>{{« oui » si un groupe électrogène de secours est prévu, « non » sinon.}}</td></tr>
									<tr><td><code>#url#</code></td><td>{{Lien vers la page générique ORES des pannes en cours (pas de fiche panne publique).}}</td></tr>
									<tr><td><code>#equipement#</code></td><td>{{Le nom de cet équipement.}}</td></tr>
									<tr><td><code>#cp#</code></td><td>{{Le code postal surveillé.}}</td></tr>
								</tbody>
							</table>
						</div>
						<span class="help-block" style="margin:0;">{{Les jetons Jeedom restent utilisables par-dessus : #[Objet][Équipement][Commande]#, variable(), date(), etc.}}</span>
					</fieldset>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:180px;">{{Type}}</th>
								<th style="width:250px;">{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'oresbe', 'js', 'oresbe'); ?>
