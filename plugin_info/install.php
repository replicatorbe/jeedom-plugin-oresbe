<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';

function oresbe_install() {
}

function oresbe_update() {
    oresbe_migrateCommands();
}

/*
 * Donne aux équipements existants les commandes apparues depuis leur
 * création. Le plugin ne les crée qu'à l'enregistrement : sans ce passage,
 * elles n'apparaîtraient qu'au jour où quelqu'un rouvre l'adresse et
 * clique sur Sauvegarder. update(false) : on recalcule depuis le cache
 * sans taper l'API, le cron15 fera la vraie lecture.
 */
function oresbe_migrateCommands() {
    foreach (eqLogic::byType('oresbe') as $eqLogic) {
        try {
            $eqLogic->ensureCommands();
            if ($eqLogic->getIsEnable() == 1 && $eqLogic->isConfigured()) {
                $eqLogic->update(false);
            }
        } catch (Throwable $e) {
            log::add('oresbe', 'error', 'migration ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function oresbe_remove() {
    /*
     * Même raison que swdebe : les caches propres à chaque équipement sont
     * déjà nettoyés par preRemove() au moment où Jeedom supprime l'eqLogic.
     * Ce filet rattrape un plugin désactivé puis réinstallé sans que les
     * eqLogic soient passés par preRemove.
     */
    foreach (eqLogic::byType('oresbe') as $eqLogic) {
        try {
            $eqLogic->preRemove();
        } catch (Throwable $e) {
            log::add('oresbe', 'debug', 'nettoyage ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}
