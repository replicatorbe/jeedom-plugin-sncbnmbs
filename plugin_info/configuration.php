<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cloud"></i> {{Service iRail}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="api_timeout" placeholder="8">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à iRail. Le trajet est relu chaque minute pendant la surveillance : au-delà de 8 secondes, un service lent retarderait le cron de toute la box pour un horaire qui sera de toute façon repris une minute plus tard.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Langue des libellés}}</label>
			<div class="col-md-2">
				<select class="configKey form-control" data-l1key="lang">
					<option value="">{{Langue de Jeedom}}</option>
					<option value="fr">{{Français}}</option>
					<option value="nl">{{Néerlandais}}</option>
					<option value="de">{{Allemand}}</option>
					<option value="en">{{Anglais}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Langue dans laquelle iRail rend le nom des gares, les directions et les perturbations. Vide suit la langue de Jeedom, ce qui convient presque toujours ; l'imposer sert sur un trajet flamand annoncé en néerlandais sur les quais.}}</span>
			</div>
		</div>
	</fieldset>
</form>
