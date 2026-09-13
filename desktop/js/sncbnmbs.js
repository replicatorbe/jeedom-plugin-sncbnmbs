/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================ OUTILS */

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>,
                failure: <fonction recevant le message d'erreur>,
                silent: true pour ne rien afficher } */
function sncbnmbsAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/sncbnmbs/core/ajax/sncbnmbs.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (isset(options.failure)) {
        options.failure('{{Jeedom n\'a pas répondu.}}')
        return
      }
      if (options.silent === true) { return }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (isset(options.failure)) {
          options.failure(data.result)
          return
        }
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Un élément de la page, ou null. Tout le fichier passe par ici : le panneau
   d'un trajet n'existe pas tant qu'aucun n'est ouvert, et une exception sur un
   getElementById nul ferait taire tous les écouteurs déjà posés. */
function sncbnmbsEl(_id) {
  return document.getElementById(_id)
}

/* Identifiant de l'équipement actuellement ouvert, ou null s'il n'est pas encore enregistré. */
function sncbnmbsCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord le trajet.}}', level: 'warning' })
    return null
  }
  return input.value
}

/* L'équipement affiché est-il toujours celui dont on attendait la réponse ?
   Comparaison en chaînes des deux côtés : le coeur transmet l'identifiant en
   entier dans printEqLogic, alors qu'un champ de formulaire rend toujours une
   chaîne. Une comparaison stricte rejetait donc toutes les réponses. */
function sncbnmbsIsDisplayed(_id) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (input !== null && String(input.value) === String(_id))
}

/* Les actions serveur travaillent sur les valeurs en base : refuse de partir
   si l'écran contient des modifications non enregistrées. */
function sncbnmbsCheckSaved() {
  var modified = (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave === true)
    || window.modifyWithoutSave === true
  if (modified) {
    jeedomUtils.showAlert({ message: '{{Enregistrez vos modifications avant de continuer}}', level: 'warning' })
    return false
  }
  return true
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function sncbnmbsMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Valeur d'un champ caché de configuration. */
function sncbnmbsConfig(_key) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + _key + '"]')
  return (input === null) ? '' : input.value
}

function sncbnmbsSetConfig(_key, _value) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + _key + '"]')
  if (input !== null) { input.value = _value }
}

/* Récapitule le trajet tel qu'il sera enregistré. Recalculé à chaque choix de
   gare : affiché seulement au chargement, il resterait à « - » pendant toute la
   configuration, c'est-à-dire au seul moment où on le regarde. */
function sncbnmbsShowRoute() {
  var span = sncbnmbsEl('span_sncbnmbsRoute')
  if (span === null) { return }

  var from = sncbnmbsConfig('from_label')
  var to = sncbnmbsConfig('to_label')
  if (from === '' && to === '') {
    span.textContent = '-'
    return
  }
  /* Le point d'interrogation montre laquelle des deux gares manque encore :
     un trajet à moitié choisi ne rend aucun horaire, et rien ne le dirait. */
  span.textContent = ((from === '') ? '?' : from) + ' → ' + ((to === '') ? '?' : to)
}

/* Message affiché sous les boutons d'action. */
function sncbnmbsShowResult(_message, _level) {
  var container = sncbnmbsEl('span_sncbnmbsResult')
  if (container === null) { return }
  container.innerHTML = ''
  if (_message === '') { return }
  var box = document.createElement('div')
  box.className = 'alert alert-' + _level
  box.style.margin = '0'
  /* textContent et non innerHTML : le message peut reprendre un libellé venu
     d'un service extérieur. */
  box.textContent = _message
  container.appendChild(box)
}

/* Minutes rendues comme un voyageur les lit : 95 minutes, c'est 1 h 35. */
function sncbnmbsDuration(_minutes) {
  var total = parseInt(_minutes, 10)
  if (isNaN(total) || total <= 0) { return '' }
  if (total < 60) { return total + ' {{min}}' }
  var rest = total % 60
  return Math.floor(total / 60) + ' h ' + ((rest < 10) ? '0' : '') + rest
}

/* ========================================================== LISTES DÉROULANTES */

/*
 * Remplit un select à partir d'une liste [{id, name}].
 *
 * La première option est toujours un repère vide : sans elle, le navigateur
 * sélectionne d'office la première entrée réelle sans émettre « change », et
 * l'utilisateur qui clique sur cette entrée-là ne déclencherait rien — son
 * choix ne serait jamais enregistré.
 */
function sncbnmbsFillSelect(_select, _items, _selectedId, _placeholder) {
  if (_select === null) { return }
  _select.innerHTML = ''

  var placeholder = document.createElement('option')
  placeholder.value = ''
  placeholder.textContent = (_items.length === 0) ? '{{Aucun résultat}}' : _placeholder
  _select.appendChild(placeholder)

  for (var i = 0; i < _items.length; i++) {
    /* textContent et non innerHTML : les libellés viennent d'un service extérieur. */
    var option = document.createElement('option')
    option.value = _items[i].id
    option.textContent = _items[i].name
    if (_items[i].id === _selectedId) { option.selected = true }
    _select.appendChild(option)
  }
}

/* Le suffixe des identifiants DOM d'une gare. Les deux gares se configurent
   exactement de la même manière : seul ce suffixe et le préfixe des clés de
   configuration changent, tout dupliquer laisserait les deux moitiés diverger. */
function sncbnmbsSuffix(_which) {
  return (_which === 'from') ? 'From' : 'To'
}

/*
 * Recopie le choix de gare dans les champs enregistrés. _which vaut 'from' ou
 * 'to'. Appelée aussi bien sur « change » qu'après chaque remplissage de la
 * liste, pour que l'affichage et la valeur en base ne puissent jamais diverger.
 */
function sncbnmbsCommitStation(_which, _select) {
  if (_select === null) { return }
  var id = _select.value
  if (sncbnmbsConfig(_which + '_id') === id) { return }

  sncbnmbsSetConfig(_which + '_id', id)
  sncbnmbsSetConfig(_which + '_label', (id === '') ? '' : _select.options[_select.selectedIndex].textContent)

  sncbnmbsShowRoute()
  sncbnmbsMarkModified()
}

/* Remet la liste d'une gare dans l'état « une seule entrée, celle enregistrée ».
   Sans cela, le select garderait les résultats de la recherche précédente et
   proposerait, à l'ouverture d'un autre trajet, des gares qui ne sont pas les
   siennes. */
function sncbnmbsResetStation(_which) {
  var id = sncbnmbsConfig(_which + '_id')
  var label = sncbnmbsConfig(_which + '_label')

  var input = sncbnmbsEl('in_sncbnmbs' + sncbnmbsSuffix(_which))
  if (input !== null) { input.value = label }

  sncbnmbsFillSelect(sncbnmbsEl('sel_sncbnmbs' + sncbnmbsSuffix(_which)),
    (id === '') ? [] : [{ id: id, name: label }], id, '{{Choisissez votre gare}}')
}

/* ====================================================== COMMANDE D'ALERTE */

/*
 * Le champ enregistré ne contient qu'un identifiant numérique, illisible. Le
 * nom humain est demandé au coeur, seul à savoir de quel objet et de quel
 * équipement la commande dépend.
 */
function sncbnmbsShowAlertCmd() {
  var display = sncbnmbsEl('in_sncbnmbsAlertCmd')
  if (display === null) { return }

  var id = sncbnmbsConfig('alert_cmd')
  if (id === '') {
    display.value = ''
    return
  }

  /* Repli écrit tout de suite : si la commande a été supprimée depuis, le
     coeur ne répondra rien et le champ resterait vide, laissant croire
     qu'aucune commande n'est configurée alors qu'un identifiant mort traîne
     et sera exécuté à la prochaine alerte. */
  display.value = '#' + id + '#'
  if (typeof jeedom === 'undefined' || !isset(jeedom.cmd) || !isset(jeedom.cmd.getHumanCmdName)) { return }

  jeedom.cmd.getHumanCmdName({
    id: id,
    error: function () { },
    success: function (result) {
      /* La réponse peut arriver après un changement de trajet : ne l'écrire
         que si l'identifiant affiché est toujours celui demandé. */
      if (sncbnmbsConfig('alert_cmd') !== String(id)) { return }
      if (typeof result === 'string' && result !== '') { display.value = result }
    }
  })
}

/* ============================================================ ÉQUIPEMENT */

function printEqLogic(_eqLogic) {
  /* Le coeur ne réinitialise que les .eqLogicAttr : tout le reste de l'écran
     garderait sinon l'état du trajet précédemment ouvert. */
  sncbnmbsShowResult('', 'info')
  sncbnmbsClearBoard()
  sncbnmbsResetStation('from')
  sncbnmbsResetStation('to')
  sncbnmbsShowRoute()
  sncbnmbsShowAlertCmd()

  /* Les perturbations décrivent le réseau et non le trajet, mais les vider
     force leur relecture : celles affichées peuvent dater de la veille si
     l'écran est resté ouvert toute la nuit. */
  var network = sncbnmbsEl('div_sncbnmbsNetwork')
  if (network !== null) { network.innerHTML = '' }

  if (isset(_eqLogic.id) && _eqLogic.id != '') {
    sncbnmbsLoadBoard(_eqLogic.id)
  }
}

/* Remet l'onglet « Trains » à vide, en-têtes d'état compris. */
function sncbnmbsClearBoard() {
  var table = sncbnmbsEl('table_sncbnmbsTrains')
  if (table !== null) { table.querySelector('tbody').innerHTML = '' }

  var alerts = sncbnmbsEl('div_sncbnmbsTrainAlerts')
  if (alerts !== null) { alerts.innerHTML = '' }

  var lastUpdate = sncbnmbsEl('span_sncbnmbsLastUpdate')
  if (lastUpdate !== null) { lastUpdate.textContent = '-' }

  var watching = sncbnmbsEl('span_sncbnmbsWatching')
  if (watching !== null) { watching.textContent = '-' }
}

/* Une ligne seule dans le tableau, en remplacement des trains : le tableau vide
   sans un mot laisse croire à un écran cassé. */
function sncbnmbsBoardMessage(_text) {
  var table = sncbnmbsEl('table_sncbnmbsTrains')
  if (table === null) { return }
  var tbody = table.querySelector('tbody')
  tbody.innerHTML = ''

  var row = document.createElement('tr')
  var cell = document.createElement('td')
  cell.setAttribute('colspan', '10')
  cell.textContent = _text
  row.appendChild(cell)
  tbody.appendChild(row)
}

/* Remplit l'onglet « Trains » depuis le cache serveur, sans rappeler iRail. */
function sncbnmbsLoadBoard(_id) {
  sncbnmbsAjax('board', { id: _id }, function (result) {
    /* La réponse d'un trajet qu'on a quitté entre-temps remplirait le tableau
       de celui qu'on regarde maintenant. */
    if (!sncbnmbsIsDisplayed(_id)) { return }
    sncbnmbsRenderBoard(result)
  }, {
    failure: function () {
      if (!sncbnmbsIsDisplayed(_id)) { return }
      sncbnmbsBoardMessage('{{Horaires indisponibles.}}')
    }
  })
}

/*
 * Affiche un tableau de trains complet : date de lecture, état de la
 * surveillance, perturbations du trajet et liste des départs. Séparé de la
 * requête, parce que « Rafraîchir maintenant » rend déjà ce tableau dans sa
 * réponse : le redemander ferait un aller-retour pour des données en main.
 */
function sncbnmbsRenderBoard(_board) {
  if (!isset(_board)) { return }

  var lastUpdate = sncbnmbsEl('span_sncbnmbsLastUpdate')
  if (lastUpdate !== null) {
    lastUpdate.textContent = (_board.lastUpdate === '') ? '{{jamais}}' : _board.lastUpdate
  }

  var watching = sncbnmbsEl('span_sncbnmbsWatching')
  if (watching !== null) {
    /* Dire ce que l'état implique, et non seulement son nom : « en veille »
       seul laisserait croire à une panne alors que c'est le comportement
       attendu hors créneau. */
    watching.textContent = (_board.watching === true)
      ? '{{active, horaires relus chaque minute}}'
      : '{{en veille, relecture au quart d\'heure}}'
  }

  var route = sncbnmbsEl('span_sncbnmbsRoute')
  if (route !== null && isset(_board.route) && _board.route !== '') {
    route.textContent = _board.route
  }

  var alerts = sncbnmbsEl('div_sncbnmbsTrainAlerts')
  if (alerts !== null) {
    alerts.innerHTML = ''
    var disturbances = isset(_board.disturbances) ? _board.disturbances : []
    for (var d = 0; d < disturbances.length; d++) {
      var box = document.createElement('div')
      box.className = 'alert alert-warning'
      box.style.margin = '0 0 5px 0'
      /* textContent et non innerHTML : le titre vient du flux de la SNCB. */
      box.textContent = disturbances[d]
      alerts.appendChild(box)
    }
  }

  var table = sncbnmbsEl('table_sncbnmbsTrains')
  if (table === null) { return }

  var trains = isset(_board.trains) ? _board.trains : []
  if (trains.length === 0) {
    sncbnmbsBoardMessage('{{Aucun train connu. Vérifiez les deux gares et le créneau, puis cliquez sur « Rafraîchir maintenant ».}}')
    return
  }

  var tbody = table.querySelector('tbody')
  tbody.innerHTML = ''
  for (var i = 0; i < trains.length; i++) {
    tbody.appendChild(sncbnmbsTrainRow(trains[i]))
  }
}

/* Une ligne du tableau des trains. Construite en DOM : insertAdjacentHTML sur
   une table crée un <tbody> par insertion et empile toutes les lignes au même
   endroit. */
function sncbnmbsTrainRow(_train) {
  var row = document.createElement('tr')

  /* Classes contextuelles du coeur plutôt que des couleurs en dur : elles
     restent lisibles sur les thèmes clairs comme sur les thèmes sombres. */
  if (_train.status === 'canceled' || _train.status === 'delayed') {
    row.className = 'danger'
  } else if (_train.status === 'slight') {
    row.className = 'warning'
  } else {
    row.className = 'success'
  }

  /* Un train déjà parti reste affiché — il explique souvent le retard de celui
     qui suit — mais en retrait, pour qu'on ne le prenne pas pour une option. */
  if (_train.left === true) { row.style.opacity = '0.55' }

  /* Les alertes d'iRail sont souvent longues : en infobulle plutôt qu'en
     colonne, où elles écraseraient tout le reste du tableau. */
  if (isset(_train.alerts) && _train.alerts.length > 0) {
    row.setAttribute('title', _train.alerts.join('\n'))
  }

  var cell = function (_text) {
    var td = document.createElement('td')
    td.textContent = _text
    row.appendChild(td)
    return td
  }

  var dayCell = cell(_train.day)
  if (_train.isNext === true) {
    /* Le prochain train est ce qu'on vient chercher en ouvrant l'onglet : il
       doit se repérer sans lire toute la colonne des heures. */
    row.style.fontWeight = 'bold'
    var marker = document.createElement('i')
    marker.className = 'fas fa-arrow-right'
    marker.style.marginRight = '5px'
    marker.setAttribute('title', '{{Prochain train}}')
    dayCell.insertBefore(marker, dayCell.firstChild)
  }

  /* L'heure théorique barrée dès qu'elle n'est plus celle du départ : deux
     heures côte à côte sans distinction ne disent pas laquelle est la bonne. */
  var timeCell = cell(_train.time)
  if (_train.canceled === true || _train.delay > 0) { timeCell.style.textDecoration = 'line-through' }

  var delayCell = cell('')
  if (_train.canceled === true) {
    delayCell.textContent = '{{Supprimé}}'
    delayCell.style.fontWeight = 'bold'
  } else if (_train.delay > 0) {
    delayCell.textContent = '+' + _train.delay + ' {{min}} → ' + _train.real
  } else {
    delayCell.textContent = '{{à l\'heure}}'
  }

  cell(_train.vehicle)
  cell(_train.direction)

  var platformCell = cell(_train.platform)
  if (_train.platformChanged === true) {
    /* Une voie changée fait rater le train à qui attend sur l'ancienne :
       c'est la seule information du tableau qui mérite de crier. */
    platformCell.style.fontWeight = 'bold'
    platformCell.setAttribute('title', '{{Voie modifiée}}')
    var changed = document.createElement('i')
    changed.className = 'fas fa-exclamation-triangle'
    changed.style.marginLeft = '5px'
    platformCell.appendChild(changed)
  }

  cell(_train.arrival)
  cell(sncbnmbsDuration(_train.duration))
  /* « direct » plutôt qu'un zéro : c'est l'argument qui fait choisir un train
     plutôt qu'un autre, pas une quantité à comparer. */
  cell((_train.transfers > 0) ? String(_train.transfers) : '{{direct}}')
  cell(_train.occupancy)

  return row
}

/* =============================================================== RÉSEAU */

/* Les perturbations de tout le réseau, indépendantes du trajet ouvert. */
function sncbnmbsLoadNetwork(_button) {
  var container = sncbnmbsEl('div_sncbnmbsNetwork')
  if (container === null) { return }

  var message = function (_text, _level) {
    container.innerHTML = ''
    var box = document.createElement('div')
    box.className = 'alert alert-' + _level
    box.textContent = _text
    container.appendChild(box)
  }

  sncbnmbsAjax('disturbances', {}, function (result) {
    container.innerHTML = ''
    if (result.length === 0) {
      message('{{Aucune perturbation annoncée sur le réseau.}}', 'success')
      return
    }
    for (var i = 0; i < result.length; i++) {
      container.appendChild(sncbnmbsDisturbancePanel(result[i]))
    }
  }, {
    button: _button,
    failure: function (_message) { message(_message, 'danger') }
  })
}

function sncbnmbsDisturbancePanel(_disturbance) {
  var panel = document.createElement('div')
  panel.className = 'panel panel-default'

  var heading = document.createElement('div')
  heading.className = 'panel-heading'
  heading.style.fontWeight = 'bold'
  /* textContent partout : titres et descriptions sont du texte libre publié
     par la SNCB, et arrivent parfois avec des balises. */
  heading.textContent = _disturbance.title
  if (isset(_disturbance.date) && _disturbance.date !== '') {
    var date = document.createElement('span')
    date.className = 'pull-right'
    date.style.fontWeight = 'normal'
    date.textContent = _disturbance.date
    heading.appendChild(date)
  }
  panel.appendChild(heading)

  var body = document.createElement('div')
  body.className = 'panel-body'
  body.textContent = _disturbance.description
  /* Seuls les liens http(s) deviennent cliquables : un href reçu d'un flux
     extérieur pourrait porter un javascript: qui s'exécuterait dans la page
     d'administration, avec les droits de l'administrateur. */
  if (isset(_disturbance.link) && /^https?:\/\//.test(_disturbance.link)) {
    var link = document.createElement('a')
    link.href = _disturbance.link
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
    link.style.display = 'block'
    link.style.marginTop = '5px'
    link.textContent = '{{Détail sur le site de la SNCB}}'
    body.appendChild(link)
  }
  panel.appendChild(body)

  return panel
}

/* ============================================================== COMMANDES */

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== RECHERCHES */

/* Cherche une gare et propose les résultats. _which vaut 'from' ou 'to'. */
function sncbnmbsSearchStation(_which, _button) {
  var input = sncbnmbsEl('in_sncbnmbs' + sncbnmbsSuffix(_which))
  if (input === null) { return }

  var query = input.value.trim()
  /* Le serveur rend une liste vide en dessous de deux caractères : le dire ici
     évite un « Aucun résultat » qui ferait croire la gare inconnue. */
  if (query.length < 2) {
    jeedomUtils.showAlert({ message: '{{Saisissez au moins deux lettres du nom de la gare.}}', level: 'warning' })
    return
  }

  sncbnmbsAjax('searchStation', { q: query }, function (result) {
    var select = sncbnmbsEl('sel_sncbnmbs' + sncbnmbsSuffix(_which))
    sncbnmbsFillSelect(select, result, (result.length === 1) ? result[0].id : sncbnmbsConfig(_which + '_id'),
      '{{Choisissez votre gare}}')
    sncbnmbsCommitStation(_which, select)
    if (result.length > 1) {
      jeedomUtils.showAlert({ message: '{{Plusieurs gares correspondent, choisissez la vôtre.}}', level: 'info', timeOut: 6000 })
    }
  }, { button: _button })
}

/* Échange les deux gares : le retour du soir est l'aller du matin à l'envers,
   et les ressaisir toutes les deux serait la première corvée du navetteur. */
function sncbnmbsSwapStations() {
  var fromId = sncbnmbsConfig('from_id')
  var fromLabel = sncbnmbsConfig('from_label')
  var toId = sncbnmbsConfig('to_id')
  var toLabel = sncbnmbsConfig('to_label')

  if (fromId === '' && toId === '') {
    jeedomUtils.showAlert({ message: '{{Choisissez d\'abord au moins une gare.}}', level: 'warning' })
    return
  }

  sncbnmbsSetConfig('from_id', toId)
  sncbnmbsSetConfig('from_label', toLabel)
  sncbnmbsSetConfig('to_id', fromId)
  sncbnmbsSetConfig('to_label', fromLabel)

  /* Les deux listes sont reconstruites et non simplement resélectionnées :
     elles peuvent encore contenir les résultats d'une recherche, où la gare
     qui vient d'arriver de l'autre côté ne figure pas. */
  sncbnmbsResetStation('from')
  sncbnmbsResetStation('to')
  sncbnmbsShowRoute()
  sncbnmbsMarkModified()
}

/* =============================================================== ÉCOUTEURS */

/* Les écouteurs sont posés à la racine du script : les pages sont chargées en
   AJAX par jeedomUtils.loadPage, l'évènement DOMContentLoaded a déjà eu lieu.
   La garde évite qu'une absence du conteneur ne casse tout le fichier. */
var sncbnmbsContainer = document.getElementById('div_pageContainer') || document.body

sncbnmbsContainer.addEventListener('change', function (event) {
  if (event.target.closest('#sel_sncbnmbsFrom')) {
    sncbnmbsCommitStation('from', event.target)
    return
  }
  if (event.target.closest('#sel_sncbnmbsTo')) {
    sncbnmbsCommitStation('to', event.target)
    return
  }
})

/* Entrée dans un champ de recherche vaut clic sur la loupe : le formulaire
   compte plusieurs champs, la touche n'y déclencherait rien d'autre. */
sncbnmbsContainer.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter') { return }

  if (event.target.closest('#in_sncbnmbsFrom')) {
    event.preventDefault()
    sncbnmbsSearchStation('from', sncbnmbsEl('bt_sncbnmbsSearchFrom'))
    return
  }
  if (event.target.closest('#in_sncbnmbsTo')) {
    event.preventDefault()
    sncbnmbsSearchStation('to', sncbnmbsEl('bt_sncbnmbsSearchTo'))
    return
  }
})

sncbnmbsContainer.addEventListener('click', function (event) {
  var target = null

  /* L'onglet « Réseau » se charge à sa première ouverture, et pas avant : les
     perturbations sont un appel au réseau que personne ne regarde tant que
     l'onglet reste fermé. Le clic sur l'onglet plutôt qu'un évènement de
     Bootstrap, qui n'est pas garanti d'une version de Jeedom à l'autre. */
  if (target = event.target.closest('a[href="#networktab"]')) {
    var pending = sncbnmbsEl('div_sncbnmbsNetwork')
    if (pending !== null && pending.innerHTML === '') {
      sncbnmbsLoadNetwork(sncbnmbsEl('bt_sncbnmbsLoadNetwork'))
    }
    return
  }

  if (target = event.target.closest('#bt_sncbnmbsSearchFrom')) {
    if (target.classList.contains('disabled')) { return }
    sncbnmbsSearchStation('from', target)
    return
  }

  if (target = event.target.closest('#bt_sncbnmbsSearchTo')) {
    if (target.classList.contains('disabled')) { return }
    sncbnmbsSearchStation('to', target)
    return
  }

  if (event.target.closest('#bt_sncbnmbsSwap')) {
    sncbnmbsSwapStations()
    return
  }

  if (event.target.closest('#bt_sncbnmbsSelectAlertCmd')) {
    if (typeof jeedom === 'undefined' || !isset(jeedom.cmd) || !isset(jeedom.cmd.getSelectModal)) { return }
    /* Le sélecteur du coeur rappelle avec { human: '#<id>#', cmd: { id, type,
       subType } }. C'est l'identifiant qui est enregistré : le texte humain
       n'est qu'un affichage, faux dès le premier renommage de la commande. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      if (!isset(result.cmd) || !isset(result.cmd.id)) { return }
      sncbnmbsSetConfig('alert_cmd', result.cmd.id)
      var chosen = sncbnmbsEl('in_sncbnmbsAlertCmd')
      if (chosen !== null) { chosen.value = result.human }
      sncbnmbsMarkModified()
    })
    return
  }

  if (event.target.closest('#bt_sncbnmbsClearAlertCmd')) {
    sncbnmbsSetConfig('alert_cmd', '')
    var cleared = sncbnmbsEl('in_sncbnmbsAlertCmd')
    if (cleared !== null) { cleared.value = '' }
    sncbnmbsMarkModified()
    return
  }

  if (target = event.target.closest('#bt_sncbnmbsLoadNetwork')) {
    if (target.classList.contains('disabled')) { return }
    sncbnmbsLoadNetwork(target)
    return
  }

  if (target = event.target.closest('#bt_sncbnmbsRefresh')) {
    if (target.classList.contains('disabled')) { return }
    /* Le serveur relit les gares et le créneau en base : partir avec des
       modifications à l'écran rendrait les horaires de l'ancien trajet. */
    if (!sncbnmbsCheckSaved()) { return }
    var refreshId = sncbnmbsCurrentId()
    if (refreshId === null) { return }
    sncbnmbsShowResult('', 'info')
    sncbnmbsAjax('refresh', { id: refreshId }, function (data) {
      sncbnmbsShowResult(data.summary, 'success')
      if (!sncbnmbsIsDisplayed(refreshId)) { return }
      sncbnmbsRenderBoard(data.board)
    }, {
      button: target,
      failure: function (message) { sncbnmbsShowResult(message, 'danger') }
    })
    return
  }

  if (target = event.target.closest('#bt_sncbnmbsAcknowledge')) {
    if (target.classList.contains('disabled')) { return }
    var ackId = sncbnmbsCurrentId()
    if (ackId === null) { return }
    sncbnmbsShowResult('', 'info')
    sncbnmbsAjax('acknowledge', { id: ackId }, function (data) {
      sncbnmbsShowResult(data.summary, 'success')
    }, {
      button: target,
      failure: function (message) { sncbnmbsShowResult(message, 'danger') }
    })
    return
  }
})
