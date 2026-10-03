<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    $getEqLogic = function ($_id) {
        $eqLogic = oresbe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'oresbe') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

    if (init('action') == 'testAddress') {
        unautorizedInDemo();
        ajax::success(oresbe::testAddress(init('zipcode'), init('street'), init('houseNumber')));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        if (!$eqLogic->isConfigured()) {
            throw new Exception(__('Adresse incomplète : renseignez code postal, rue et numéro.', __FILE__));
        }

        $state = $eqLogic->update(true);
        if ($eqLogic->getRefreshError() != '') {
            throw new Exception($eqLogic->getRefreshError());
        }

        $count   = isset($state['incidents']) ? count($state['incidents']) : 0;
        $info    = $eqLogic->getRefreshInfo();
        $summary = ($info !== '')
            ? $info
            : ($count . ' ' . __('panne(s) active(s) à cette adresse.', __FILE__));

        ajax::success(array(
            'count'     => $count,
            'summary'   => $summary,
            'skipped'   => ($info !== '') ? 1 : 0,
            'incidents' => isset($state['incidents']) ? $state['incidents'] : array(),
            'fetchedAt' => isset($state['fetchedAt']) ? $state['fetchedAt'] : 0,
        ));
    }

    if (init('action') == 'incidents') {
        $eqLogic = $getEqLogic(init('id'));
        $state = $eqLogic->getState();
        ajax::success(array(
            'incidents' => isset($state['incidents']) ? $state['incidents'] : array(),
            'fetchedAt' => isset($state['fetchedAt']) ? $state['fetchedAt'] : 0,
            'address'   => isset($state['address']) ? $state['address'] : '',
        ));
    }

    if (init('action') == 'testNotification') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        $which   = init('which', 'actions');
        $result  = $eqLogic->testNotification(init('notificationId'), $which);
        if (count($result['errors']) > 0) {
            throw new Exception(implode(' ; ', $result['errors']));
        }
        $label = ($result['which'] === 'endActions')
            ? __('Actions de fin jouées (incident fictif) :', __FILE__)
            : __('Alerte jouée sur la panne :', __FILE__);
        ajax::success(array(
            'incident' => $result['incident'],
            'which'    => $result['which'],
            'summary'  => $label . ' ' . (isset($result['incident']['titre']) ? $result['incident']['titre'] : ''),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
