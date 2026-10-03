/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/*
 * Requête AJAX vers le contrôleur du plugin. Même pattern que les autres
 * plugins maison : bouton désactivé pendant l'appel, filet de sécurité à 60 s,
 * failure/silent pour les cas où l'appelant veut gérer lui-même.
 */
function oresbeAjax(_action, _data, _success, _options) {
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
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/oresbe/core/ajax/oresbe.ajax.php',
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

function oresbeCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord l\'équipement.}}', level: 'warning' })
    return null
  }
  return input.value
}

function oresbeIsDisplayed(_id) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (input !== null && String(input.value) === String(_id))
}

/* ========================================================= ALERTES — RENDU */

/*
 * Vrai pendant la reconstruction de l'onglet Alertes : poser une valeur dans
 * un champ émet « change » exactement comme une saisie, et sans ce drapeau,
 * ouvrir un équipement suffirait à le déclarer modifié, l'avertissement
 * « quitter sans enregistrer ? » tomberait sans que rien n'ait été touché.
 */
var oresbeRendering = false

/*
 * Jeedom appelle une fonction nommée saveEqLogic juste avant d'envoyer le
 * formulaire : le hook magique de plugin.template.js nous laisse injecter
 * la liste d'alertes, qu'un data-l1key ne saurait pas sérialiser (quatre
 * niveaux de nesting). Sans ce hook, les alertes resteraient perdues au
 * premier Sauvegarder.
 */
function saveEqLogic(_eqLogic) {
  _eqLogic.configuration = _eqLogic.configuration || {}
  _eqLogic.configuration.notifications = oresbeCollectNotifications()
  return _eqLogic
}

/* Appelée par le cœur après printEqLogic : les .eqLogicAttr sont peuplés
 * mais pas nos .notificationAttr. On rejoue le rendu à chaque ouverture. */
function printEqLogic(_eqLogic) {
  oresbeRenderNotifications(_eqLogic)
}

function oresbeRenderNotifications(_eqLogic) {
  var container = document.getElementById('div_oresbeNotifications')
  if (container === null) { return }
  oresbeRendering = true
  try {
    while (container.firstChild) { container.removeChild(container.firstChild) }
    var list = (_eqLogic && _eqLogic.configuration && _eqLogic.configuration.notifications)
      ? _eqLogic.configuration.notifications : []
    if (!Array.isArray(list)) { list = [] }
    list.forEach(function (n) { oresbeAppendNotification(n) })
  } finally {
    oresbeRendering = false
  }
}

function oresbeNewId() {
  return (Math.random().toString(36).slice(2, 8) + Math.random().toString(36).slice(2, 8)).slice(0, 12)
}

/*
 * Un bloc d'alerte dans le DOM. On s'appuie sur les classes Bootstrap/Jeedom
 * (panel, well) : elles portent les bons contrastes clair/sombre selon le
 * thème actif. Pas de couleurs en dur : un #f9f9f9 à la main se transforme
 * en bande blanche aveuglante sur un dashboard sombre.
 */
function oresbeAppendNotification(_notification) {
  var container = document.getElementById('div_oresbeNotifications')
  if (container === null) { return }
  var n = _notification || {}
  var id = n.id || oresbeNewId()

  var block = document.createElement('div')
  block.className = 'panel panel-default oresbeNotification'
  block.setAttribute('data-id', id)

  block.innerHTML =
    '<div class="panel-heading">' +
      '<i class="fas fa-bell"></i> {{Alerte}} <small class="text-muted">#' + id + '</small>' +
      '<div class="pull-right">' +
        '<a class="btn btn-danger btn-xs bt_oresbeRemoveNotification" title="{{Supprimer cette alerte}}"><i class="fas fa-minus-circle"></i></a>' +
      '</div>' +
      '<div class="clearfix"></div>' +
    '</div>' +
    '<div class="panel-body">' +
      '<div class="checkbox" style="margin-top:0;">' +
        '<label>' +
          '<input type="checkbox" class="notificationAttr" data-l1key="enable"' + (n.enable == 1 ? ' checked' : '') + '> ' +
          '{{Alerte activée}}' +
        '</label>' +
      '</div>' +
      '<input type="hidden" class="notificationAttr" data-l1key="id" value="' + id + '">' +

      '<fieldset style="margin-top:10px;">' +
        '<legend style="font-size:13px;">' +
          '<i class="fas fa-play-circle"></i> {{Actions à l\'apparition d\'un nouvel incident}} ' +
          '<a class="btn btn-info btn-xs bt_oresbeTestNotification" data-which="actions" style="margin-left:10px;">' +
            '<i class="fas fa-vial"></i> {{Tester}}' +
          '</a>' +
        '</legend>' +
        '<div class="oresbeNotificationActions"></div>' +
        '<a class="btn btn-default btn-xs bt_oresbeAddAction" data-target="start" style="margin-top:5px;">' +
          '<i class="fas fa-plus"></i> {{Ajouter une action}}' +
        '</a>' +
      '</fieldset>' +

      '<fieldset style="margin-top:15px;">' +
        '<legend style="font-size:13px;">' +
          '<i class="fas fa-stop-circle"></i> {{Actions à la fin de l\'incident}} <small class="text-muted">({{optionnel : jouées quand l\'incident disparaît du site SWDE, ex. effacer un affichage matrix}})</small> ' +
          '<a class="btn btn-info btn-xs bt_oresbeTestNotification" data-which="endActions" style="margin-left:10px;">' +
            '<i class="fas fa-vial"></i> {{Tester}}' +
          '</a>' +
        '</legend>' +
        '<div class="oresbeNotificationEndActions"></div>' +
        '<a class="btn btn-default btn-xs bt_oresbeAddAction" data-target="end" style="margin-top:5px;">' +
          '<i class="fas fa-plus"></i> {{Ajouter une action}}' +
        '</a>' +
      '</fieldset>' +
    '</div>'

  container.appendChild(block)

  var actionsWrap = block.querySelector('.oresbeNotificationActions')
  var actions = (Array.isArray(n.actions) ? n.actions : [])
  actions.forEach(function (a) { oresbeAppendAction(actionsWrap, a) })

  var endWrap = block.querySelector('.oresbeNotificationEndActions')
  var endActions = (Array.isArray(n.endActions) ? n.endActions : [])
  endActions.forEach(function (a) { oresbeAppendAction(endWrap, a) })
}

/*
 * Une ligne d'action. Classes `well well-sm` plutôt que fond custom : le
 * well suit le thème (clair/sombre) sans jurer. Le div options est rempli
 * par le cœur Jeedom via displayActionOption.
 */
function oresbeAppendAction(_wrap, _action) {
  var a = _action || {}
  var opts = (a.options && typeof a.options === 'object') ? a.options : {}

  var row = document.createElement('div')
  row.className = 'well well-sm oresbeNotificationAction'
  row.style.marginBottom = '10px'

  row.innerHTML =
    '<div class="row" style="margin:0 0 6px 0;">' +
      '<div class="col-sm-9" style="padding-left:0;">' +
        '<label class="checkbox-inline" title="{{Décocher pour laisser la ligne en place sans la jouer.}}">' +
          '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable"' + (opts.enable == 0 ? '' : ' checked') + '> {{activée}}' +
        '</label>' +
        '<label class="checkbox-inline" title="{{Jouer la commande dans un processus séparé.}}">' +
          '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background"' + (opts.background == 1 ? ' checked' : '') + '> {{en tâche de fond}}' +
        '</label>' +
      '</div>' +
      '<div class="col-sm-3 text-right" style="padding-right:0;">' +
        '<a class="btn btn-danger btn-xs bt_oresbeRemoveAction" title="{{Supprimer cette action}}"><i class="fas fa-minus"></i></a>' +
      '</div>' +
    '</div>' +
    '<div class="input-group">' +
      '<input type="text" class="expressionAttr form-control" data-l1key="cmd" placeholder="{{Choisissez une action ou un bloc}}" readonly>' +
      '<span class="input-group-btn">' +
        '<a class="btn btn-default bt_oresbeChooseCmd" title="{{Choisir une commande d\'action}}"><i class="fas fa-list-alt"></i></a>' +
        '<a class="btn btn-default bt_oresbeChooseBlock" title="{{Choisir un bloc (message, variable…)}}"><i class="fas fa-tasks"></i></a>' +
      '</span>' +
    '</div>' +
    '<div class="oresbeActionOptions" style="margin-top:8px;"></div>'

  _wrap.appendChild(row)

  // 1. Pose les valeurs sur les champs déjà présents (cmd, enable, background).
  //    setJeeValues est une méthode AJOUTÉE PAR JEEDOM aux prototypes Node /
  //    NodeList : `row.setJeeValues(a, '.expressionAttr')`, pas un
  //    `setJeeValues(row, ...)` global — qui existe sous ce nom seulement
  //    dans certains contextes et NE POSE PAS les valeurs (le champ cmd
  //    restait vide à la réouverture).
  if (typeof row.setJeeValues === 'function') {
    row.setJeeValues(a, '.expressionAttr')
  }
  // 2. Rendu des options (title/message/…) par le cœur, en passant les
  //    valeurs sauvegardées : sans ça, displayActionOption nous rend un
  //    gabarit vide et title/message restent blancs à la réouverture.
  oresbeRefreshActionOptions(row, opts)
}

/*
 * Demande au cœur de dessiner le titre/message/options pour l'expression
 * courante. `_savedOptions` est le dictionnaire des valeurs sauvegardées
 * (title, message, …) : on les passe à displayActionOption pour qu'il
 * initialise ses champs, et on les repose via setJeeValues après que le
 * HTML est injecté — displayActionOption rend parfois un gabarit nu.
 */
function oresbeRefreshActionOptions(_row, _savedOptions) {
  var input = _row.querySelector('.expressionAttr[data-l1key="cmd"]')
  var optsDiv = _row.querySelector('.oresbeActionOptions')
  if (input === null || optsDiv === null) { return }
  var expression = input.value || ''
  if (expression === '') {
    optsDiv.innerHTML = ''
    return
  }
  if (typeof jeedom === 'undefined' || !jeedom.cmd || typeof jeedom.cmd.displayActionOption !== 'function') {
    return
  }

  // Options à transmettre : celles passées explicitement (rendu initial), ou
  // à défaut celles ramassées du DOM (après une re-sélection utilisateur).
  var current = _savedOptions
  if (typeof current !== 'object' || current === null) {
    current = {}
    _row.querySelectorAll('.expressionAttr[data-l1key="options"][data-l2key]').forEach(function (el) {
      var key = el.getAttribute('data-l2key')
      current[key] = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value
    })
  }

  jeedom.cmd.displayActionOption(expression, current, function (html) {
    if (html === 'Unsupported') {
      optsDiv.innerHTML = '<span class="label label-warning">{{Ce bloc n\'est pas utilisable dans une alerte.}}</span>'
      return
    }
    optsDiv.innerHTML = html
    // Pose les valeurs : displayActionOption n'injecte pas toujours title /
    // message dans son HTML, même quand on les lui passe. setJeeValues est
    // le filet de rattrapage qui garantit un rendu fidèle.
    if (typeof optsDiv.setJeeValues === 'function') {
      optsDiv.setJeeValues({ options: current }, '.expressionAttr')
    }
  })
}

/* Récupère toutes les alertes depuis le DOM, sous la forme attendue par
 * cleanNotifications côté PHP. */
function oresbeCollectNotifications() {
  var out = []

  var collect = function (wrap) {
    var list = []
    if (wrap === null) { return list }
    wrap.querySelectorAll(':scope > .oresbeNotificationAction').forEach(function (row) {
      var cmdInput = row.querySelector('.expressionAttr[data-l1key="cmd"]')
      if (cmdInput === null || cmdInput.value === '') { return }
      var action = { cmd: cmdInput.value, options: {} }
      row.querySelectorAll('.expressionAttr[data-l1key="options"][data-l2key]').forEach(function (el) {
        var key = el.getAttribute('data-l2key')
        action.options[key] = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value
      })
      list.push(action)
    })
    return list
  }

  document.querySelectorAll('.oresbeNotification').forEach(function (block) {
    var n = {
      id: block.getAttribute('data-id') || oresbeNewId(),
      enable: 0,
      actions: collect(block.querySelector('.oresbeNotificationActions')),
      endActions: collect(block.querySelector('.oresbeNotificationEndActions')),
    }
    var enableBox = block.querySelector('.notificationAttr[data-l1key="enable"]')
    if (enableBox !== null && enableBox.checked) { n.enable = 1 }
    var hiddenId = block.querySelector('.notificationAttr[data-l1key="id"]')
    if (hiddenId !== null && hiddenId.value) { n.id = hiddenId.value }
    out.push(n)
  })
  return out
}

function oresbeFormatDate(_iso) {
  if (!_iso) { return '-' }
  try {
    var d = new Date(_iso)
    if (isNaN(d.getTime())) { return _iso }
    var pad = function (n) { return (n < 10 ? '0' : '') + n }
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() +
           ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
  } catch (e) {
    return _iso
  }
}

function oresbeRenderIncidents(_result) {
  var tbody = document.querySelector('#table_oresbeIncidents tbody')
  if (tbody === null) { return }
  while (tbody.firstChild) { tbody.removeChild(tbody.firstChild) }

  if (isset(_result.fetchedAt) && _result.fetchedAt > 0) {
    var when = new Date(_result.fetchedAt * 1000)
    document.getElementById('span_oresbeLastUpdate').textContent = when.toLocaleString()
  }
  if (isset(_result.address) && _result.address) {
    document.getElementById('span_oresbeAddress').textContent = _result.address
  }

  var incidents = isset(_result.incidents) ? _result.incidents : []
  if (incidents.length === 0) {
    var tr = document.createElement('tr')
    var td = document.createElement('td')
    td.colSpan = 6
    td.style.textAlign = 'center'
    td.style.fontStyle = 'italic'
    td.textContent = '{{Aucune panne active à cette adresse.}}'
    tr.appendChild(td)
    tbody.appendChild(tr)
    return
  }

  incidents.forEach(function (i) {
    var tr = document.createElement('tr')

    var tdTitre = document.createElement('td')
    tdTitre.textContent = isset(i.titre) ? i.titre : (isset(i.businessId) ? i.businessId : '-')
    tr.appendChild(tdTitre)

    var tdType = document.createElement('td')
    // Petit badge coloré selon le type (panne vs interruption).
    if (isset(i.type) && i.type === 'panne') {
      tdType.innerHTML = '<span class="label label-danger">{{Panne}}</span>'
    } else if (isset(i.type) && i.type === 'interruption') {
      tdType.innerHTML = '<span class="label label-warning">{{Planifiée}}</span>'
    } else {
      tdType.textContent = '-'
    }
    tr.appendChild(tdType)

    var tdDeb = document.createElement('td')
    tdDeb.textContent = isset(i.dateDebut) ? oresbeFormatDate(i.dateDebut) : '-'
    tr.appendChild(tdDeb)

    var tdFin = document.createElement('td')
    var finTxt = isset(i.dateFin) ? oresbeFormatDate(i.dateFin) : '-'
    if (isset(i.enRetard) && i.enRetard == 1) {
      tdFin.innerHTML = finTxt + ' <small class="text-danger">({{retard}})</small>'
    } else {
      tdFin.textContent = finTxt
    }
    tr.appendChild(tdFin)

    var tdClients = document.createElement('td')
    tdClients.textContent = isset(i.clientsImpactes) ? i.clientsImpactes : '-'
    tr.appendChild(tdClients)

    var tdMap = document.createElement('td')
    if (isset(i.lat) && isset(i.lon) && i.lat !== null && i.lon !== null) {
      // Lien OpenStreetMap : zoom 17 sur le point exact de la panne.
      var mapA = document.createElement('a')
      mapA.href = 'https://www.openstreetmap.org/?mlat=' + i.lat + '&mlon=' + i.lon + '#map=17/' + i.lat + '/' + i.lon
      mapA.target = '_blank'
      mapA.rel = 'noopener'
      mapA.title = '{{Ouvrir sur OpenStreetMap}}'
      var ic = document.createElement('i')
      ic.className = 'fas fa-map-marker-alt'
      mapA.appendChild(ic)
      tdMap.appendChild(mapA)
    } else if (isset(i.url) && i.url) {
      var a = document.createElement('a')
      a.href = i.url
      a.target = '_blank'
      a.rel = 'noopener'
      a.title = '{{Ouvrir sur ores.be}}'
      var icon = document.createElement('i')
      icon.className = 'fas fa-external-link-alt'
      a.appendChild(icon)
      tdMap.appendChild(a)
    } else {
      tdMap.textContent = '-'
    }
    tr.appendChild(tdMap)

    tbody.appendChild(tr)
  })
}

/* ============================================================= HANDLERS */

/*
 * Jeedom charge les pages en AJAX et ré-évalue oresbe.js à chaque entrée
 * sur la page du plugin. Attacher un listener à `document` à chaque passage
 * les accumulerait : un clic sur « Rafraîchir » émettrait N requêtes HTTP
 * après N navigations. Le drapeau garantit un attachement unique pour la
 * durée de vie de la session.
 */
if (!window.oresbeHandlersInstalled) {
  window.oresbeHandlersInstalled = true

document.addEventListener('click', function (e) {
  var test = e.target.closest('#bt_oresbeTest')
  if (test !== null) {
    e.preventDefault()
    var zipInput    = document.querySelector('.eqLogicAttr[data-l2key="zipcode"]')
    var streetInput = document.querySelector('.eqLogicAttr[data-l2key="street"]')
    var numInput    = document.querySelector('.eqLogicAttr[data-l2key="houseNumber"]')
    var span = document.getElementById('span_oresbeTestResult')
    span.innerHTML = '<i class="fas fa-spinner fa-spin"></i> {{Interrogation de l\'API ORES…}}'
    oresbeAjax('testAddress', {
      zipcode: (zipInput === null) ? '' : zipInput.value,
      street: (streetInput === null) ? '' : streetInput.value,
      houseNumber: (numInput === null) ? '' : numInput.value,
    }, function (data) {
      /*
       * Trois cas, dans l'ordre de clarté pour l'utilisateur :
       *  - panne active trouvée à son adresse exacte,
       *  - adresse comprise par ORES mais aucune panne en cours,
       *  - rue inconnue d'ORES (saisie à relire).
       */
      var msg
      if (data.matched > 0) {
        msg = '<i class="fas fa-exclamation-triangle" style="color:#f0ad4e"></i> ' +
              data.matched + ' {{panne(s) active(s) à cette adresse (sur}} ' +
              data.totalBreakdowns + ' {{en Wallonie)}}'
        if (data.incidents && data.incidents.length > 0) {
          msg += '<ul style="margin:5px 0 0 0;">'
          data.incidents.forEach(function (inc) {
            msg += '<li>' + (inc.titre || inc.businessId || '?') +
                   (inc.dateFin ? ' — {{fin prévue}} ' + oresbeFormatDate(inc.dateFin) : '') +
                   '</li>'
          })
          msg += '</ul>'
        }
      } else if (data.knownStreetsInZip > 0) {
        msg = '<i class="fas fa-check-circle" style="color:green"></i> ' +
              '{{Code postal reconnu par ORES, aucune panne en cours à cette adresse.}} ' +
              '<br><small class="text-muted">{{Rues du code postal connues de l\'API aujourd\'hui :}} ' +
              data.knownStreetsInZip + '</small>'
      } else {
        msg = '<i class="fas fa-info-circle" style="color:#5bc0de"></i> ' +
              '{{Aucune panne et aucune rue du code postal dans la base ORES à l\'instant. ' +
              'C\'est normal si rien ne se passe sur ce CP ; en cas de doute, vérifiez l\'orthographe de la rue.}}'
      }
      span.innerHTML = msg
    }, {
      button: test,
      failure: function (message) {
        span.innerHTML = '<i class="fas fa-times-circle" style="color:red"></i> ' + message
      }
    })
    return
  }

  var refresh = e.target.closest('#bt_oresbeRefresh')
  if (refresh !== null) {
    e.preventDefault()
    var currentId = oresbeCurrentId()
    if (currentId === null) { return }
    var span = document.getElementById('span_oresbeTestResult')
    span.innerHTML = '<i class="fas fa-spinner fa-spin"></i> {{Rafraîchissement en cours…}}'
    oresbeAjax('refresh', { id: currentId }, function (data) {
      /* skipped == 1 : la lecture a été volontairement sautée (anti-spam,
         backoff). Ce n'est pas une erreur mais pas non plus une victoire ;
         on peint en orange pour que l'utilisateur comprenne la nuance. */
      var icon = (data.skipped == 1)
        ? '<i class="fas fa-info-circle" style="color:#f0ad4e"></i>'
        : '<i class="fas fa-check-circle" style="color:green"></i>'
      span.innerHTML = icon + ' ' + data.summary
      if (oresbeIsDisplayed(currentId)) {
        oresbeRenderIncidents(data)
      }
    }, {
      button: refresh,
      failure: function (message) {
        span.innerHTML = '<i class="fas fa-times-circle" style="color:red"></i> ' + message
      }
    })
    return
  }

  /*
   * Clic sur l'onglet Incidents : on charge les incidents depuis le cache sans
   * taper le site. Jeedom charge les pages en AJAX ; on écoute au niveau
   * document pour attraper le data-toggle=tab, qui a déjà basculé l'onglet
   * actif lorsqu'on reçoit le clic en bulle.
   */
  var tab = e.target.closest('a[href="#incidenttab"]')
  if (tab !== null) {
    var tabId = oresbeCurrentId()
    if (tabId === null) { return }
    oresbeAjax('incidents', { id: tabId }, function (data) {
      if (oresbeIsDisplayed(tabId)) {
        oresbeRenderIncidents(data)
      }
    }, { silent: true })
  }

  /* ================================================= HANDLERS ALERTES */

  if (e.target.closest('#bt_oresbeAddNotification') !== null) {
    e.preventDefault()
    oresbeAppendNotification({ id: oresbeNewId(), enable: 1, actions: [] })
    return
  }

  var removeNotif = e.target.closest('.bt_oresbeRemoveNotification')
  if (removeNotif !== null) {
    e.preventDefault()
    var block = removeNotif.closest('.oresbeNotification')
    if (block !== null) {
      block.parentNode.removeChild(block)
    }
    return
  }

  var addAction = e.target.closest('.bt_oresbeAddAction')
  if (addAction !== null) {
    e.preventDefault()
    var block = addAction.closest('.oresbeNotification')
    if (block === null) { return }
    var target = addAction.getAttribute('data-target') === 'end'
      ? '.oresbeNotificationEndActions'
      : '.oresbeNotificationActions'
    var wrap = block.querySelector(target)
    if (wrap !== null) {
      oresbeAppendAction(wrap, { options: { enable: 1 } })
    }
    return
  }

  var removeAction = e.target.closest('.bt_oresbeRemoveAction')
  if (removeAction !== null) {
    e.preventDefault()
    var row = removeAction.closest('.oresbeNotificationAction')
    if (row !== null) {
      row.parentNode.removeChild(row)
    }
    return
  }

  var chooseCmd = e.target.closest('.bt_oresbeChooseCmd')
  if (chooseCmd !== null) {
    e.preventDefault()
    var row = chooseCmd.closest('.oresbeNotificationAction')
    if (row === null) { return }
    if (typeof jeedom === 'undefined' || !jeedom.cmd || typeof jeedom.cmd.getSelectModal !== 'function') {
      jeedomUtils.showAlert({ message: '{{Sélecteur de commandes indisponible.}}', level: 'danger' })
      return
    }
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      if (!result || !result.human) { return }
      var input = row.querySelector('.expressionAttr[data-l1key="cmd"]')
      if (input !== null) {
        input.value = result.human
        /* {} explicite plutôt que de laisser oresbeRefreshActionOptions
         * aller lire les options depuis le DOM : celles qui s'y trouvent
         * appartiennent à l'ancienne commande (title/message d'un push
         * mobile ré-injectés sur un TTS n'a aucun sens). On repart propre
         * et displayActionOption initialise les options par défaut de la
         * nouvelle commande. */
        oresbeRefreshActionOptions(row, {})
      }
    })
    return
  }

  var chooseBlock = e.target.closest('.bt_oresbeChooseBlock')
  if (chooseBlock !== null) {
    e.preventDefault()
    var row = chooseBlock.closest('.oresbeNotificationAction')
    if (row === null) { return }
    if (typeof jeedom === 'undefined' || typeof jeedom.getSelectActionModal !== 'function') {
      jeedomUtils.showAlert({ message: '{{Sélecteur de blocs indisponible.}}', level: 'danger' })
      return
    }
    jeedom.getSelectActionModal({}, function (result) {
      if (!result || !result.human) { return }
      var input = row.querySelector('.expressionAttr[data-l1key="cmd"]')
      if (input !== null) {
        input.value = result.human
        // Même raison : repartir d'options vides sur un changement de bloc.
        oresbeRefreshActionOptions(row, {})
      }
    })
    return
  }

  var testNotif = e.target.closest('.bt_oresbeTestNotification')
  if (testNotif !== null) {
    e.preventDefault()
    var eqId = oresbeCurrentId()
    if (eqId === null) { return }
    var block = testNotif.closest('.oresbeNotification')
    if (block === null) { return }
    var notificationId = block.getAttribute('data-id') || ''
    var which = testNotif.getAttribute('data-which') || 'actions'
    /*
     * Le test rejoue l'alerte TELLE QU'ELLE EST EN BASE. Si l'utilisateur
     * vient de modifier le formulaire sans Sauvegarder, les changements
     * récents ne sont pas testés : on l'avertit.
     */
    if (!confirm('{{Le test joue l\'alerte telle qu\'elle est enregistrée. Toute modification non sauvegardée est ignorée. Continuer ?}}')) {
      return
    }
    oresbeAjax('testNotification', { id: eqId, notificationId: notificationId, which: which }, function (data) {
      jeedomUtils.showAlert({ message: data.summary, level: 'success' })
    }, {
      button: testNotif,
      failure: function (message) {
        jeedomUtils.showAlert({ message: message, level: 'danger' })
      }
    })
    return
  }
})

}
