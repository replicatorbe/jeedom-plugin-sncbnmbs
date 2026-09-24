<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('sncbnmbs');
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
				<span>{{Ajouter un trajet}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes trajets}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun trajet pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un trajet » et donnez-lui un nom, par exemple « Aller matin ».}}</li>';
			echo '<li>{{Tapez les premières lettres de la gare de départ, puis choisissez-la dans la liste. Faites de même pour la gare d\'arrivée.}}</li>';
			echo '<li>{{Réglez le créneau : les heures entre lesquelles vous prenez habituellement le train, et les jours concernés.}}</li>';
			echo '<li>{{Enregistrez : les commandes sont créées et les horaires du jour sont récupérés.}}</li>';
			echo '</ol>';
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
			echo '<i class="fas fa-train" style="font-size:4em;"></i>';
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
			<li role="presentation"><a href="#boardtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-train"></i><span class="hidden-xs"> {{Trains}}</span></a></li>
			<li role="presentation"><a href="#networktab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-exclamation-triangle"></i><span class="hidden-xs"> {{Réseau}}</span></a></li>
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
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Aller matin}}">
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

						<!-- ============================ TRAJET ============================ -->
						<fieldset>
							<legend><i class="fas fa-route"></i> {{Trajet}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Gare de départ}}</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_sncbnmbsFrom" placeholder="{{Premières lettres de la gare}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_sncbnmbsSearchFrom" title="{{Chercher la gare de départ}}"><i class="fas fa-search"></i></a>
										</span>
									</div>
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Deux caractères au minimum. « bruxelles midi » trouve « Bruxelles-Midi » : les accents et les tirets sont ignorés.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-6">
									<select class="form-control" id="sel_sncbnmbsFrom"></select>
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="from_id" style="display:none;">
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="from_label" style="display:none;">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Gare d'arrivée}}</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_sncbnmbsTo" placeholder="{{Premières lettres de la gare}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_sncbnmbsSearchTo" title="{{Chercher la gare d'arrivée}}"><i class="fas fa-search"></i></a>
										</span>
									</div>
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Le trajet n'est exploitable que si les deux gares diffèrent : une gare vers elle-même fait expirer la requête sans jamais rendre d'horaire.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-6">
									<select class="form-control" id="sel_sncbnmbsTo"></select>
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="to_id" style="display:none;">
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="to_label" style="display:none;">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<a class="btn btn-default" id="bt_sncbnmbsSwap"><i class="fas fa-exchange-alt"></i> {{Inverser le trajet}}</a>
									<span class="help-block" style="margin:0;">{{Échange départ et arrivée. Dupliquez le trajet du matin, inversez-le et décalez son créneau : vous tenez le retour du soir sans ressaisir les gares.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Trajet retenu}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" id="span_sncbnmbsRoute">-</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<!-- ============================ CRÉNEAU ============================ -->
						<fieldset>
							<legend><i class="fas fa-clock"></i> {{Créneau}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Heure de début}}</label>
								<div class="col-sm-3">
									<input type="time" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="slot_start" placeholder="07:00">
								</div>
								<label class="col-sm-2 control-label">{{Fin}}</label>
								<div class="col-sm-3">
									<input type="time" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="slot_end" placeholder="09:00">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Seuls les trains partant entre ces deux heures sont suivis. Une fin antérieure au début décrit un créneau de nuit, qui se termine le lendemain matin.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Jours}}</label>
								<div class="col-sm-8">
									<?php
									$days = array(
										1 => '{{Lu}}',
										2 => '{{Ma}}',
										3 => '{{Me}}',
										4 => '{{Je}}',
										5 => '{{Ve}}',
										6 => '{{Sa}}',
										7 => '{{Di}}',
									);
									foreach ($days as $number => $label) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="day_' . $number . '"> ' . $label;
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Les jours où vous faites ce trajet. Aucune case cochée vaut tous les jours : un navetteur cochera du lundi au vendredi pour que le plugin cesse d'interroger le réseau le week-end.}}</span>
								</div>
							</div>
						</fieldset>

						<!-- ========================== SURVEILLANCE ========================== -->
						<fieldset>
							<legend><i class="fas fa-binoculars"></i> {{Surveillance}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Seuil de retard}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="1" step="1" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="threshold" placeholder="5">
										<span class="input-group-addon roundedRight">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{À partir de ce retard, le train est déclaré en retard et l'alerte part. Trop bas, les deux minutes quotidiennes de la SNCB vous réveillent pour rien.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Surveillance à la minute}}</label>
								<div class="col-sm-3">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="watch_enabled" checked>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Cochée, le trajet est relu chaque minute pendant le créneau : un retard est connu presque aussitôt. Décochée, il n'est plus relu qu'au quart d'heure ; les alertes partent toujours, mais avec jusqu'à quinze minutes de retard.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Minutes d'avance}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="0" max="240" step="5" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="watch_before" placeholder="60">
										<span class="input-group-addon roundedRight">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Combien de temps avant le début du créneau la surveillance à la minute démarre. Comptez large : être prévenu chez soi permet encore de prendre le train d'avant.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Temps jusqu'à la gare}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="0" max="120" step="1" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="walk_time" placeholder="0">
										<span class="input-group-addon roundedRight">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Le temps qu'il vous faut pour rejoindre la gare de départ. La commande « Partir dans » en déduit quand quitter la maison, retard compris : un scénario sur « Partir dans = 5 » vous dit de mettre votre manteau. Si le prochain train est supprimé, le calcul porte sur le train de repli.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nombre de trains}}</label>
								<div class="col-sm-3">
									<input type="number" min="1" max="6" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="max_trains" placeholder="6">
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Nombre de départs demandés par créneau, 6 au maximum : au-delà, iRail ignore la demande sans le dire et en rend six quand même. Au-delà de ce que dure votre créneau, les trains supplémentaires ne vous concernent plus de toute façon.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Commande à déclencher}}</label>
								<div class="col-sm-5">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_sncbnmbsAlertCmd" placeholder="{{Aucune}}" readonly>
										<span class="input-group-btn">
											<a class="btn btn-default" id="bt_sncbnmbsSelectAlertCmd" title="{{Choisir une commande d'action}}"><i class="fas fa-list-ul"></i></a>
											<a class="btn btn-default roundedRight" id="bt_sncbnmbsClearAlertCmd" title="{{Ne plus rien déclencher}}"><i class="fas fa-times"></i></a>
										</span>
									</div>
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="alert_cmd" id="in_sncbnmbsAlertCmdId" style="display:none;">
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Commande d'action exécutée dès qu'un problème apparaît sur le trajet : un message parlé, une notification. Le texte de l'alerte lui est passé en paramètre.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<a class="btn btn-default" id="bt_sncbnmbsRefresh" title="{{Interroge le réseau immédiatement. Inutile juste après une sauvegarde, qui le fait déjà.}}"><i class="fas fa-sync"></i> {{Rafraîchir maintenant}}</a>
									<a class="btn btn-info" id="bt_sncbnmbsAcknowledge" title="{{Fait taire l'alerte en cours jusqu'au prochain changement de situation.}}"><i class="fas fa-bell-slash"></i> {{Acquitter l'alerte}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span id="span_sncbnmbsResult"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================ TRAINS ============================ -->
			<div role="tabpanel" class="tab-pane" id="boardtab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						{{Les trains du jour et du lendemain retenus pour ce trajet, tels que le réseau les annonce. Cet onglet lit ce que le plugin a déjà récupéré : l'ouvrir n'interroge pas le réseau.}}
						<br>
						<b>{{Dernière vérification}} :</b> <span id="span_sncbnmbsLastUpdate">-</span>
						&nbsp;&nbsp;
						<b>{{Surveillance}} :</b> <span id="span_sncbnmbsWatching">-</span>
					</div>
					<div id="div_sncbnmbsTrainAlerts"></div>
					<div class="table-responsive">
						<table id="table_sncbnmbsTrains" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:100px;">{{Jour}}</th>
								<th style="width:90px;">{{Départ}}</th>
								<th style="width:110px;">{{Retard}}</th>
								<th style="width:90px;">{{Train}}</th>
								<th>{{Direction}}</th>
								<th style="width:60px;">{{Voie}}</th>
								<th style="width:80px;">{{Arrivée}}</th>
								<th style="width:80px;">{{Durée}}</th>
								<th style="width:80px;">{{Corresp.}}</th>
								<th style="width:100px;">{{Occupation}}</th>
							</tr>
						</thead>
						<tbody></tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- ============================ RÉSEAU ============================ -->
			<div role="tabpanel" class="tab-pane" id="networktab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						{{Les perturbations que la SNCB publie pour l'ensemble du réseau, trajet ou pas. Celles qui citent une de vos deux gares sont reprises dans l'onglet « Trains ».}}
					</div>
					<a class="btn btn-default" id="bt_sncbnmbsLoadNetwork"><i class="fas fa-sync"></i> {{Actualiser}}</a>
					<br><br>
					<div id="div_sncbnmbsNetwork"></div>
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
<?php include_file('desktop', 'sncbnmbs', 'js', 'sncbnmbs'); ?>
