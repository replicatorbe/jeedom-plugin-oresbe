<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class oresbe extends eqLogic {

    /*
     * ORES expose une API Azure non documentée mais publique, utilisée par le
     * site Nuxt du distributeur pour peupler sa carte des pannes. La racine
     * répond « Running... » et seul /Breakdown renvoie un JSON structuré ;
     * les paramètres de requête (?postalCode=, ?energyType=…) sont acceptés
     * mais ignorés par le serveur, qui renvoie TOUJOURS la liste complète.
     * Le filtrage par adresse se fait donc exclusivement côté plugin.
     *
     * L'API ne couvre que l'électricité. Pour le gaz, ORES ne publie pas de
     * carte temps réel ni d'API : le numéro 0800/87.087 est la seule voie,
     * ce que la documentation du plugin explique sans détour.
     */
    const API_URL  = 'https://ores-breakdownmapapi-prd.azurewebsites.net/Breakdown';
    const BASE_URL = 'https://www.ores.be';
    const WORKS_URL = 'https://www.ores.be/pannes-et-interruptions/pannes-et-interruptions-en-cours';

    const DEFAULT_TIMEOUT  = 10;
    const USER_AGENT       = 'Jeedom-ORES-Plugin/0.1 (+https://github.com/replicatorbe/jeedom-plugin-oresbe)';

    const DEFAULT_REFRESH_MINUTES = 15;
    const MIN_REFRESH_MINUTES     = 15;
    const FORCE_MIN_INTERVAL      = 300;
    const RETRY_DELAY             = 3600;

    const SENT_MEMORY = 604800;  // 7 jours : couvre les plus longues interventions planifiées

    /*
     * Mêmes blocs refusés que swdebe : les actions d'alerte sont jouées dans
     * le cron partagé par tous les plugins, qui serait tué au bout de cinq
     * minutes par un bloc qui attend ou pose une question.
     */
    const NOTIFICATION_REFUSED = array(
        'wait', 'sleep', 'ask', 'report', 'exportHistory',
        'stop', 'log', 'scenario_return', 'icon', 'tag',
    );

    private $_refreshError = '';
    private $_refreshInfo  = '';

    /* ======================================================================= CRON */

    public static function cron15() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->update();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /* Code postal belge : quatre chiffres ; les autres formats sont
         * effacés sans refus pour que le bouton « Ajouter » ne meure jamais. */
        $zipcode = trim((string) $this->getConfiguration('zipcode'));
        if (!preg_match('/^\d{4}$/', $zipcode)) {
            $zipcode = '';
        }
        $this->setConfiguration('zipcode', $zipcode);

        /* Rue : stockée telle que saisie pour l'affichage ; la normalisation
         * (NFKD + uppercase + sans accents) se fait au moment du matching. */
        $this->setConfiguration('street', trim((string) $this->getConfiguration('street')));

        /* Numéro de maison : on garde une forme texte (ex. "3A") pour
         * l'affichage, mais on en extrait le nombre à part pour le matching
         * dans les streetNumberRanges. "3A", "3/RT", "3bis" → 3. */
        $raw = trim((string) $this->getConfiguration('houseNumber'));
        $this->setConfiguration('houseNumber', $raw);
        if ($raw !== '' && preg_match('/\d+/', $raw, $m)) {
            $this->setConfiguration('houseNumberInt', (int) $m[0]);
        } else {
            $this->setConfiguration('houseNumberInt', '');
        }

        /* Alertes au format du formulaire : mise en forme ici, pas à l'envoi. */
        $this->setConfiguration('notifications', self::cleanNotifications($this->getConfiguration('notifications')));

        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '320px');
        }
    }

    public function postSave() {
        $this->createCommands();

        if ($this->isConfigured()) {
            try {
                /* Pas d'appel réseau à l'enregistrement : si l'utilisateur
                 * modifie son adresse, le cache précédent est jeté — pas la
                 * peine de bloquer la page jusqu'à ce qu'ORES réponde. Le
                 * cron15 fera la lecture. */
                $state   = $this->getState();
                $changed = isset($state['address']) && $state['address'] !== $this->addressSignature();
                if ($changed) {
                    $this->clearState();
                }
                $this->update(false);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public function preRemove() {
        $this->clearState();
        cache::delete($this->seenKey());
        return true;
    }

    public function isConfigured() {
        return preg_match('/^\d{4}$/', (string) $this->getConfiguration('zipcode')) === 1
            && trim((string) $this->getConfiguration('street')) !== ''
            && $this->getConfiguration('houseNumberInt') !== '';
    }

    private function addressSignature() {
        /*
         * On inclut la forme TEXTE du numéro (3A, 3B) et pas seulement
         * l'entier dérivé : passer de « 3A » à « 3B » doit invalider le
         * cache alors que l'entier reste 3. Les ranges ORES font parfois la
         * distinction via leurs suffixes (3/RT, 3/A), donc deux adresses
         * littéralement différentes peuvent légitimement avoir des
         * incidents distincts.
         */
        return $this->getConfiguration('zipcode')
             . '|' . self::normalizeStreet($this->getConfiguration('street'))
             . '|' . $this->getConfiguration('houseNumber');
    }

    /* ======================================================================= CACHE */

    private function stateKey() { return 'oresbe::state::' . $this->getId(); }
    private function retryKey() { return 'oresbe::retry::' . $this->getId(); }
    private function seenKey()  { return 'oresbe::seen::'  . $this->getId(); }

    private function clearState() {
        cache::delete($this->stateKey());
        cache::delete($this->retryKey());
    }

    public function getState() {
        $value = cache::byKey($this->stateKey())->getValue('');
        if ($value === '') return array();
        $state = json_decode($value, true);
        return is_array($state) ? $state : array();
    }

    /* ================================================================ COMMANDES */

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) return $cmd;
        $cmd = new oresbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order'])) $cmd->setOrder($_options['order']);
        if (isset($_options['unite'])) $cmd->setUnite($_options['unite']);
        if (isset($_options['icon'])) $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>');
        $cmd->save();
        return $cmd;
    }

    private function createCommands() {
        $o = 0;
        $this->addCmdIfMissing('etat', 'Panne en cours', 'info', 'binary', array(
            'order' => $o++, 'isHistorized' => 1, 'icon' => 'fas fa-bolt',
        ));
        $this->addCmdIfMissing('nbIncidents', 'Nombre de pannes', 'info', 'numeric', array(
            'order' => $o++, 'isHistorized' => 1, 'icon' => 'fas fa-list',
        ));
        $this->addCmdIfMissing('titre', 'Titre', 'info', 'string', array('order' => $o++));
        $this->addCmdIfMissing('type', 'Type', 'info', 'string', array(
            'order' => $o++, 'icon' => 'fas fa-tag',
        ));
        $this->addCmdIfMissing('dateDebut', 'Date de début', 'info', 'string', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('dateFin', 'Date de fin prévue', 'info', 'string', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('enRetard', 'En retard', 'info', 'binary', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('clientsImpactes', 'Clients impactés', 'info', 'numeric', array(
            'order' => $o++, 'unite' => '', 'icon' => 'fas fa-users',
        ));
        $this->addCmdIfMissing('rue', 'Rue impactée', 'info', 'string', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('generateur', 'Groupe électrogène prévu', 'info', 'binary', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('url', 'Page ORES', 'info', 'string', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('details', 'Détails (JSON)', 'info', 'string', array(
            'order' => $o++, 'isVisible' => 0,
        ));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array(
            'order' => $o++, 'icon' => 'fas fa-sync',
        ));
    }

    public function ensureCommands() {
        $this->createCommands();
    }

    private function publishCmd($_logicalId, $_value) {
        /* Même précaution que swdebe : ne pas réécrire une commande déjà vide
         * sinon le cœur émet un évènement de changement à chaque cron. Et
         * JAMAIS appeler cette méthode setCmd() : le cœur appelle set + clé
         * de formulaire à l'enregistrement et casserait tout. */
        if ($_value === '') {
            $cmd = $this->getCmd(null, $_logicalId);
            if (is_object($cmd) && $cmd->execCmd() === '') {
                return;
            }
        }
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* ============================================================== RAFRAICHISSEMENT */

    public function update($_force = false) {
        $this->_refreshError = '';
        $this->_refreshInfo  = '';

        if (!$this->isConfigured()) {
            $this->_refreshError = __('Adresse incomplète : renseignez code postal, rue et numéro.', __FILE__);
            return array();
        }

        $state = $this->getState();
        $age   = isset($state['fetchedAt']) ? (time() - (int) $state['fetchedAt']) : PHP_INT_MAX;

        if ($_force && $age <= self::FORCE_MIN_INTERVAL) {
            $_force = false;
            $this->_refreshInfo = __('Lecture ignorée : état mis à jour il y a moins de 5 minutes.', __FILE__);
        }

        $minutes = (int) config::byKey('refresh_minutes', __CLASS__, self::DEFAULT_REFRESH_MINUTES);
        if ($minutes < self::MIN_REFRESH_MINUTES) {
            $minutes = self::MIN_REFRESH_MINUTES;
        }
        $ttl     = $minutes * 60;
        $waiting = (cache::byKey($this->retryKey())->getValue('') !== '');
        if ($waiting && $_force) {
            $_force = false;
            $this->_refreshInfo = __('Lecture ignorée : le service a échoué récemment, nouvelle tentative dans un instant.', __FILE__);
        }

        if ($_force || ($age > $ttl && !$waiting)) {
            try {
                $fresh = $this->fetchIncidents();
                $state = $fresh;
                cache::set($this->stateKey(), json_encode($state), 0);
                cache::delete($this->retryKey());

                try {
                    $this->checkNewIncidents(isset($state['incidents']) ? $state['incidents'] : array());
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'error', $this->getHumanName() . ' alertes : ' . $e->getMessage());
                }
            } catch (Throwable $e) {
                $this->_refreshError = $e->getMessage();
                cache::set($this->retryKey(), time(), self::RETRY_DELAY);
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
                if (empty($state)) {
                    throw $e;
                }
            }
        }

        $this->refreshCommands($state);
        return $state;
    }

    public function getRefreshError() { return $this->_refreshError; }
    public function getRefreshInfo()  { return $this->_refreshInfo; }

    /* ======================================================================= API ORES */

    private function fetchIncidents() {
        $breakdowns = self::fetchAllBreakdowns();
        $incidents  = self::filterByAddress(
            $breakdowns,
            $this->getConfiguration('zipcode'),
            $this->getConfiguration('street'),
            (int) $this->getConfiguration('houseNumberInt')
        );

        return array(
            'fetchedAt' => time(),
            'address'   => $this->addressSignature(),
            'incidents' => $incidents,
        );
    }

    /*
     * Récupère toutes les pannes actives. L'API ne filtre pas côté serveur :
     * on prend le payload complet (~300 Ko, ~350 pannes en Wallonie).
     *
     * Cache partagé entre tous les équipements du plugin : sans ça, N
     * adresses surveillées = N × 300 Ko à chaque cron (maison, grand-mère,
     * appart'…). 300 s suffisent largement : le cron15 déclenche
     * l'ensemble en l'espace de quelques secondes, c'est le seul moment
     * où plusieurs équipements fetchent au même instant.
     */
    const RAW_CACHE_TTL = 300;

    public static function fetchAllBreakdowns() {
        $cached = cache::byKey('oresbe::rawBreakdowns')->getValue('');
        if ($cached !== '') {
            $wrap = json_decode($cached, true);
            if (is_array($wrap) && isset($wrap['data']) && is_array($wrap['data'])) {
                return $wrap['data'];
            }
        }

        $json = self::httpGet(self::API_URL);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception(__('Réponse inattendue de l\'API ORES : JSON invalide.', __FILE__));
        }
        cache::set('oresbe::rawBreakdowns', json_encode(array('fetchedAt' => time(), 'data' => $data)), self::RAW_CACHE_TTL);
        return $data;
    }

    /*
     * Filtre la liste des pannes pour une adresse exacte. Public pour que le
     * jeu d'essai puisse valider le matcher sans HTTP.
     */
    public static function filterByAddress($_breakdowns, $_zipcode, $_street, $_houseNumber) {
        $zipcode = (string) $_zipcode;
        $target  = self::normalizeStreet($_street);
        $num     = (int) $_houseNumber;

        $out = array();
        foreach ($_breakdowns as $b) {
            if (!isset($b['impactedCities']) || !is_array($b['impactedCities'])) continue;
            foreach ($b['impactedCities'] as $city) {
                if (!isset($city['zipCode']) || (string) $city['zipCode'] !== $zipcode) continue;
                if (!isset($city['streets']) || !is_array($city['streets'])) continue;
                foreach ($city['streets'] as $street) {
                    $streetNorm = self::normalizeStreet(isset($street['streetName']) ? $street['streetName'] : '');
                    if (!self::streetMatches($streetNorm, $target)) continue;
                    if (!self::numberInRanges($num, isset($street['streetNumberRanges']) ? $street['streetNumberRanges'] : array())) continue;

                    $out[] = self::describeBreakdown($b, $city, $street);
                    break 2;  // un incident ne compte qu'une fois pour cette adresse
                }
            }
        }
        return $out;
    }

    /*
     * Projette une panne brute ORES vers un tableau plat utile aux commandes
     * et aux jetons des alertes. On garde l'essentiel ; les détails complets
     * restent sous la clé 'raw' pour les scénarios avancés.
     */
    public static function describeBreakdown($_breakdown, $_city, $_street) {
        $isFailure      = !empty($_breakdown['failure']);
        $isInterruption = !empty($_breakdown['interruption']);
        $type           = $isFailure ? 'panne' : ($isInterruption ? 'interruption' : 'inconnu');

        $end = '';
        if (isset($_breakdown['endDateTime']) && $_breakdown['endDateTime']) {
            $end = $_breakdown['endDateTime'];
        } elseif ($isFailure && isset($_breakdown['failure']['plannedEndDateTime'])) {
            $end = $_breakdown['failure']['plannedEndDateTime'];
        } elseif ($isInterruption && isset($_breakdown['interruption']['plannedTo'])) {
            $end = $_breakdown['interruption']['plannedTo'];
        } elseif (isset($_breakdown['originalEndDate'])) {
            $end = $_breakdown['originalEndDate'];
        }

        $start = '';
        if ($isInterruption && isset($_breakdown['interruption']['plannedFrom'])) {
            $start = $_breakdown['interruption']['plannedFrom'];
        } elseif (isset($_breakdown['creationAt'])) {
            $start = $_breakdown['creationAt'];
        }

        $titre = ($type === 'panne')
            ? __('Panne électrique', __FILE__)
            : (($type === 'interruption') ? __('Interruption planifiée', __FILE__) : __('Incident ORES', __FILE__));

        return array(
            'businessId'       => isset($_breakdown['businessId']) ? (string) $_breakdown['businessId'] : '',
            'id'               => isset($_breakdown['id']) ? (string) $_breakdown['id'] : '',
            'type'             => $type,
            'titre'            => $titre,
            'dateDebut'        => $start,
            'dateFin'          => $end,
            'enRetard'         => !empty($_breakdown['isDelayed']) ? 1 : 0,
            'clientsImpactes'  => isset($_breakdown['impactedCustomerCount']) ? (int) $_breakdown['impactedCustomerCount'] : 0,
            'generateur'       => isset($_breakdown['generator']) && $_breakdown['generator'] !== null ? 1 : 0,
            'lat'              => isset($_breakdown['coordinates']['latitude']) ? (float) $_breakdown['coordinates']['latitude'] : null,
            'lon'              => isset($_breakdown['coordinates']['longitude']) ? (float) $_breakdown['coordinates']['longitude'] : null,
            'cityName'         => isset($_city['cityName']) ? (string) $_city['cityName'] : '',
            'rue'              => isset($_street['streetName']) ? (string) $_street['streetName'] : '',
            'url'              => self::WORKS_URL,
        );
    }

    /* ============================================================= NORMALISATION */

    /*
     * Normalise un nom de rue pour comparaison robuste : sans accents,
     * majuscules, sans ponctuation. L'API renvoie « RUE DES PRES MERCQ »
     * en majuscules sans accents, mais l'utilisateur saisit souvent avec
     * accents et casse mixte — les deux doivent matcher.
     *
     * On n'utilise PAS ext-intl (Normalizer) : c'est une dépendance système
     * absente sur beaucoup d'installations Jeedom (surtout en Docker). La
     * translittération manuelle couvre tout ce qu'on peut rencontrer dans
     * des noms de rue wallons.
     */
    public static function normalizeStreet($_name) {
        $s = (string) $_name;
        if ($s === '') return '';

        // Translittération ASCII des caractères accentués fréquents.
        static $tr = array(
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
            'à'=>'A','á'=>'A','â'=>'A','ã'=>'A','ä'=>'A','å'=>'A','æ'=>'AE',
            'Ç'=>'C','ç'=>'C',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','è'=>'E','é'=>'E','ê'=>'E','ë'=>'E',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'I','í'=>'I','î'=>'I','ï'=>'I',
            'Ñ'=>'N','ñ'=>'N',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Œ'=>'OE',
            'ò'=>'O','ó'=>'O','ô'=>'O','õ'=>'O','ö'=>'O','ø'=>'O','œ'=>'OE',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','ù'=>'U','ú'=>'U','û'=>'U','ü'=>'U',
            'Ý'=>'Y','ÿ'=>'Y','ý'=>'Y','Ÿ'=>'Y',
            'ß'=>'SS',
        );
        $s = strtr($s, $tr);
        $s = mb_strtoupper($s, 'UTF-8');
        // Tout caractère non alphanumérique devient un espace, puis on compacte.
        $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /*
     * Matcher deux rues : égalité stricte APRÈS normalisation. On n'accepte
     * pas de match partiel (« RUE DES PRES » vs « RUE DES PRES MERCQ ») : un
     * voisin tirerait des alertes qui ne le concernent pas. Si l'utilisateur
     * s'est trompé de nom, le bouton « Tester l'adresse » lui dira que rien
     * ne matche.
     */
    public static function streetMatches($_a, $_b) {
        return $_a !== '' && $_a === $_b;
    }

    /*
     * Un numéro appartient-il à l'un des `streetNumberRanges` ? Les ranges
     * ORES ont une forme :
     *   { "from": "1", "to": "11", "rangeType": "odd" }
     * `from` et `to` peuvent contenir des suffixes ("128/RD", "45/RT") qu'on
     * ignore pour comparer en entiers. `rangeType` vaut "odd" / "even" /
     * absent ou autre valeur = tous.
     */
    public static function numberInRanges($_num, $_ranges) {
        $num = (int) $_num;
        if ($num <= 0 || !is_array($_ranges) || empty($_ranges)) {
            // Pas de ranges connus = l'API n'a pas précisé → on considère que
            // toute la rue est concernée. C'est le comportement observé pour
            // certaines pannes et c'est l'intention la plus prudente côté
            // utilisateur (prévenir plutôt que taire).
            return !is_array($_ranges) || empty($_ranges);
        }
        $parity = ($num % 2 === 0) ? 'even' : 'odd';
        foreach ($_ranges as $r) {
            if (!is_array($r)) continue;
            $from = self::intFromRangeBound(isset($r['from']) ? $r['from'] : '');
            $to   = self::intFromRangeBound(isset($r['to'])   ? $r['to']   : '');
            if ($from === null || $to === null) continue;
            if ($num < $from || $num > $to) continue;
            $rt = isset($r['rangeType']) ? strtolower((string) $r['rangeType']) : '';
            if ($rt === 'odd' || $rt === 'even') {
                if ($rt !== $parity) continue;
            }
            return true;
        }
        return false;
    }

    private static function intFromRangeBound($_v) {
        if ($_v === '' || $_v === null) return null;
        if (preg_match('/^\d+/', (string) $_v, $m)) return (int) $m[0];
        return null;
    }

    /* ====================================================== MISE À JOUR DES CMD */

    private function refreshCommands($_state) {
        $incidents = isset($_state['incidents']) ? $_state['incidents'] : array();
        $n = count($incidents);

        $this->publishCmd('etat', $n > 0 ? 1 : 0);
        $this->publishCmd('nbIncidents', $n);

        if ($n > 0) {
            $i = $incidents[0];
            $this->publishCmd('titre',           isset($i['titre']) ? $i['titre'] : '');
            $this->publishCmd('type',            isset($i['type']) ? $i['type'] : '');
            $this->publishCmd('dateDebut',       isset($i['dateDebut']) ? $i['dateDebut'] : '');
            $this->publishCmd('dateFin',         isset($i['dateFin']) ? $i['dateFin'] : '');
            $this->publishCmd('enRetard',        !empty($i['enRetard']) ? 1 : 0);
            $this->publishCmd('clientsImpactes', isset($i['clientsImpactes']) ? (int) $i['clientsImpactes'] : 0);
            $this->publishCmd('rue',             isset($i['rue']) ? $i['rue'] : '');
            $this->publishCmd('generateur',      !empty($i['generateur']) ? 1 : 0);
            $this->publishCmd('url',             isset($i['url']) ? $i['url'] : '');
        } else {
            $this->publishCmd('titre', '');
            $this->publishCmd('type', '');
            $this->publishCmd('dateDebut', '');
            $this->publishCmd('dateFin', '');
            $this->publishCmd('enRetard', 0);
            $this->publishCmd('clientsImpactes', 0);
            $this->publishCmd('rue', '');
            $this->publishCmd('generateur', 0);
            $this->publishCmd('url', '');
        }

        $this->publishCmd('details', json_encode($incidents));
    }

    /* ================================================================== ALERTES */

    /*
     * Repris mot pour mot du plugin swdebe : mêmes règles, mêmes garanties,
     * un seul endroit à maintenir si quelque chose change plus tard. La
     * seule différence est l'identifiant « d'incident » qu'on utilise comme
     * clé anti-doublon — ici businessId (préfixe M/P signifiant, stable d'un
     * cycle à l'autre) plutôt qu'un nodeId numérique.
     */

    public static function cleanNotifications($_notifications) {
        if (!is_array($_notifications)) return array();
        $clean = array();
        foreach ($_notifications as $n) {
            if (!is_array($n)) continue;
            $id = isset($n['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $n['id']) : '';
            if ($id === '') $id = substr(md5(uniqid('oresbe', true)), 0, 12);
            $clean[] = array(
                'id'         => $id,
                'enable'     => (isset($n['enable']) && $n['enable'] == 1) ? 1 : 0,
                'actions'    => self::cleanActions(isset($n['actions'])    ? $n['actions']    : array()),
                'endActions' => self::cleanActions(isset($n['endActions']) ? $n['endActions'] : array()),
            );
        }
        return $clean;
    }

    public static function cleanActions($_actions) {
        $out = array();
        if (!is_array($_actions)) return $out;
        foreach ($_actions as $a) {
            if (!is_array($a) || trim((string) (isset($a['cmd']) ? $a['cmd'] : '')) === '') continue;
            $out[] = array(
                'cmd'     => trim((string) $a['cmd']),
                'options' => (isset($a['options']) && is_array($a['options'])) ? $a['options'] : array(),
            );
        }
        return $out;
    }

    public function notifications() {
        return self::cleanNotifications($this->getConfiguration('notifications'));
    }

    private function sentKey($_notificationId, $_businessId) {
        return 'oresbe::sent::' . $this->getId() . '::' . $_notificationId . '::' . $_businessId;
    }

    private function getSeenBusinessIds() {
        $raw = cache::byKey($this->seenKey())->getValue('');
        if ($raw === '') return null;  // null = jamais initialisé
        $ids = json_decode($raw, true);
        return is_array($ids) ? $ids : array();
    }

    private function saveSeenBusinessIds($_ids) {
        cache::set($this->seenKey(), json_encode(array_values(array_unique(array_map('strval', $_ids)))), 0);
    }

    private function checkNewIncidents($_incidents) {
        $valid = array();
        foreach ($_incidents as $incident) {
            $bid = isset($incident['businessId']) ? (string) $incident['businessId'] : '';
            if ($bid !== '') $valid[$bid] = $incident;
        }
        $currentIds = array_keys($valid);

        $lastIds = $this->getSeenBusinessIds();
        if ($lastIds === null) {
            $this->saveSeenBusinessIds($currentIds);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('mémoire des alertes initialisée', __FILE__)
                   . ' (' . count($currentIds) . ' ' . __('panne(s)', __FILE__) . ')');
            return;
        }

        $newIds        = array_diff($currentIds, $lastIds);
        $disappearedIds = array_diff($lastIds, $currentIds);

        $this->saveSeenBusinessIds($currentIds);

        if (count($newIds) === 0 && count($disappearedIds) === 0) return;

        $notifications = $this->notifications();
        $active = array_values(array_filter($notifications, function ($_n) {
            return $_n['enable'] == 1 && (count($_n['actions']) > 0 || count($_n['endActions']) > 0);
        }));
        if (count($active) === 0) return;

        foreach ($newIds as $bid) {
            $incident = $valid[$bid];
            foreach ($active as $notification) {
                /* Flag `sent` posé AVANT le filtre `count($actions) === 0` : une
                 * alerte configurée UNIQUEMENT avec des endActions (« juste
                 * effacer la matrix à la fin, pas de push ») doit voir son
                 * flag posé pour que son end se déclenche à la disparition. */
                cache::set($this->sentKey($notification['id'], $bid), json_encode($incident), self::SENT_MEMORY);
                if (count($notification['actions']) === 0) continue;
                $this->runNotification($notification, $incident, 'actions');
            }
        }

        foreach ($disappearedIds as $bid) {
            foreach ($active as $notification) {
                if (count($notification['endActions']) === 0) continue;
                $key = $this->sentKey($notification['id'], $bid);
                $cached = cache::byKey($key)->getValue('');
                if ($cached === '') continue;  // jamais annoncé par cette alerte
                $incident = json_decode($cached, true);
                if (!is_array($incident)) $incident = array('businessId' => $bid);
                $this->runNotification($notification, $incident, 'endActions');
                cache::delete($key);
            }
        }
    }

    private function runNotification($_notification, $_incident, $_which = 'actions') {
        $actions = isset($_notification[$_which]) ? $_notification[$_which] : array();
        if (count($actions) === 0) return array();
        $tags   = $this->notificationTags($_incident);
        $errors = $this->runActions($actions, $tags);

        $key = 'oresbe::notified::' . $this->getId() . '::' . $_notification['id']
             . '::' . $_which . '::' . (isset($_incident['businessId']) ? $_incident['businessId'] : '');
        message::removeAll(__CLASS__, $key);
        if (count($errors) > 0) {
            message::add(__CLASS__, $this->getHumanName() . ' '
                       . __('alerte en échec :', __FILE__) . ' ' . implode(' ; ', $errors), '', $key);
        }
        return $errors;
    }

    private function runActions($_actions, $_tags) {
        $errors = array();
        foreach ($_actions as $action) {
            $options = is_array($action['options']) ? $action['options'] : array();
            if (isset($options['enable']) && $options['enable'] == 0) continue;
            $background = (isset($options['background']) && $options['background'] == 1);
            unset($options['enable'], $options['background']);

            foreach ($options as $k => $v) {
                if (is_string($v)) {
                    $v = scenarioExpression::setTags($v);
                    $options[$k] = str_replace(array_keys($_tags), array_values($_tags), $v);
                }
            }

            $expression = (string) $action['cmd'];
            if (in_array($expression, self::NOTIFICATION_REFUSED, true)) {
                $errors[] = __('bloc inutilisable dans une alerte :', __FILE__) . ' ' . $expression;
                continue;
            }

            try {
                if (preg_match('/^#(\d+)#$/', $expression, $m)) {
                    $cmd = cmd::byId($m[1]);
                    if (!is_object($cmd)) {
                        throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
                    }
                    if ($cmd->getType() !== 'action') {
                        throw new Exception(__("ce n'est pas une commande d'action :", __FILE__) . ' ' . $cmd->getHumanName());
                    }
                    $cmd->execCmd($options);
                } else {
                    $expression = scenarioExpression::setTags($expression);
                    $expression = str_replace(array_keys($_tags), array_values($_tags), $expression);
                    $opts = $options;
                    $opts['source'] = $this->getHumanName();
                    scenarioExpression::createAndExec($background ? 'actionBackground' : 'action', $expression, $opts);
                }
            } catch (Throwable $e) {
                $errors[] = $expression . ' : ' . $e->getMessage();
                log::add(__CLASS__, 'error', __('action en échec :', __FILE__) . ' ' . $expression . ' — ' . $e->getMessage());
            }
        }
        return $errors;
    }

    private function notificationTags($_incident) {
        return array(
            '#titre#'       => isset($_incident['titre']) ? (string) $_incident['titre'] : '',
            '#type#'        => isset($_incident['type']) ? (string) $_incident['type'] : '',
            '#debut#'       => isset($_incident['dateDebut']) ? self::humanDate($_incident['dateDebut']) : '',
            '#fin#'         => isset($_incident['dateFin']) ? self::humanDate($_incident['dateFin']) : '',
            '#retard#'      => !empty($_incident['enRetard']) ? __('oui', __FILE__) : __('non', __FILE__),
            '#clients#'     => isset($_incident['clientsImpactes']) ? (string) $_incident['clientsImpactes'] : '0',
            '#rue#'         => isset($_incident['rue']) ? (string) $_incident['rue'] : '',
            '#url#'         => isset($_incident['url']) ? (string) $_incident['url'] : '',
            '#equipement#'  => $this->getName(),
            '#cp#'          => (string) $this->getConfiguration('zipcode'),
            '#generateur#'  => !empty($_incident['generateur']) ? __('oui', __FILE__) : __('non', __FILE__),
        );
    }

    public static function humanDate($_iso) {
        $iso = (string) $_iso;
        if ($iso === '') return '';
        try {
            $tz = new DateTimeZone('Europe/Brussels');
            $date = new DateTimeImmutable($iso);
            $date = $date->setTimezone($tz);
            $days = array('dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam');
            return $days[(int) $date->format('w')] . ' ' . $date->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return $iso;
        }
    }

    public function testNotification($_notificationId, $_which = 'actions') {
        if ($_which !== 'actions' && $_which !== 'endActions') $_which = 'actions';
        $target = null;
        foreach ($this->notifications() as $n) {
            if ($n['id'] === $_notificationId) { $target = $n; break; }
        }
        if ($target === null) {
            throw new Exception(__("Alerte introuvable : enregistrez l'équipement avant de la tester.", __FILE__));
        }
        if (!isset($target[$_which]) || count($target[$_which]) === 0) {
            throw new Exception(__('Cette alerte ne déclenche aucune action pour ce test.', __FILE__));
        }

        $state = $this->getState();
        $incident = null;
        if (isset($state['incidents']) && is_array($state['incidents']) && count($state['incidents']) > 0) {
            $incident = $state['incidents'][0];
        }
        if ($incident === null) {
            $incident = array(
                'businessId'      => 'TEST000000',
                'id'              => '00000000-0000-0000-0000-000000000000',
                'type'            => 'interruption',
                'titre'           => __('Test — interruption planifiée ORES', __FILE__),
                'dateDebut'       => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'dateFin'         => (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'enRetard'        => 0,
                'clientsImpactes' => 42,
                'generateur'      => 1,
                'rue'             => (string) $this->getConfiguration('street'),
                'url'             => self::WORKS_URL,
            );
        }

        $errors = $this->runActions($target[$_which], $this->notificationTags($incident));
        return array('incident' => $incident, 'errors' => $errors, 'which' => $_which);
    }

    /* =============================================================== BOUTON TEST */

    /*
     * Vérifie l'adresse en interrogeant l'API et en comptant les pannes qui
     * y correspondent — sans sauvegarder l'équipement. Permet à l'utilisateur
     * de confirmer que son match adresse marche avant de cliquer sur
     * Sauvegarder.
     */
    public static function testAddress($_zipcode, $_street, $_houseNumber) {
        $zipcode = trim((string) $_zipcode);
        $street  = trim((string) $_street);
        $num     = 0;
        if (preg_match('/\d+/', (string) $_houseNumber, $m)) $num = (int) $m[0];
        if (!preg_match('/^\d{4}$/', $zipcode)) {
            throw new Exception(__('Code postal invalide : quatre chiffres attendus.', __FILE__));
        }
        if ($street === '') {
            throw new Exception(__('Rue manquante.', __FILE__));
        }
        if ($num === 0) {
            throw new Exception(__('Numéro manquant ou illisible.', __FILE__));
        }

        $all     = self::fetchAllBreakdowns();
        $matched = self::filterByAddress($all, $zipcode, $street, $num);

        /* Nombre de rues du CP connues de l'API, à titre indicatif, pour que
         * l'utilisateur sache si son adresse est reconnue par ORES même en
         * l'absence d'incident. */
        $knownStreets = array();
        foreach ($all as $b) {
            foreach ($b['impactedCities'] ?? array() as $c) {
                if (($c['zipCode'] ?? '') !== $zipcode) continue;
                foreach ($c['streets'] ?? array() as $s) {
                    $knownStreets[self::normalizeStreet($s['streetName'] ?? '')] = true;
                }
            }
        }

        return array(
            'zipcode'           => $zipcode,
            'street'            => $street,
            'streetNormalized'  => self::normalizeStreet($street),
            'houseNumber'       => $num,
            'totalBreakdowns'   => count($all),
            'matched'           => count($matched),
            'incidents'         => $matched,
            'knownStreetsInZip' => count($knownStreets),
        );
    }

    /* ======================================================================= HTTP */

    private static function httpGet($_url) {
        $timeout = (int) config::byKey('http_timeout', __CLASS__, self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        $ch = curl_init($_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array(
                'Accept: application/json',
                'Accept-Language: fr,fr-BE;q=0.9,en;q=0.5',
            ),
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception(__('Erreur réseau ORES : ', __FILE__) . $err);
        }
        if ($code >= 400) {
            throw new Exception(__('HTTP ', __FILE__) . $code . ' ' . __('sur', __FILE__) . ' ' . $_url);
        }
        return (string) $body;
    }
}

class oresbeCmd extends cmd {

    /*
     * Classe cmd obligatoire, même limitée à l'action « Rafraîchir » : sans
     * elle, l'enregistrement d'un équipement échoue (le cœur l'instancie
     * par réflexion dès qu'une commande est présente).
     */
    public function execute($_options = null) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() === 'refresh') {
            $eqLogic->update(true);
        }
    }
}
