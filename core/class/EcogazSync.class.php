<?php
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

/* * ***************************Includes********************************* */
require_once dirname(__FILE__).'/../../../../core/php/core.inc.php';

class EcogazSync extends eqLogic {
    /*
     * Fonction exécutée automatiquement par Jeedom
     */
    public static function cronHourly()
    {
      log::add('EcogazSync', 'debug', 'Il est '.date('H').'h');
      if (date('H')=='1')
      {
        self::refreshEcogaz();
      }
      else
      {
        log::add('EcogazSync', 'debug', 'Pas de synchronisation à cette heure');
      }
    }

    public function postInsert()
    {
      $this->addMissingCmdEcogaz();
    }

    /**
     * Sync all Ecogaz
     * @return none
     */
    public static function refreshEcogaz()
    {
        log::add('EcogazSync', 'info', 'Synchronisation des API Ecogaz');

        foreach (self::byType('EcogazSync') as $eqLogic)
        {
          log::add('EcogazSync', 'debug', 'ID '.$eqLogic->getLogicalId().' - '.$eqLogic->getEqType_name().' - '.$eqLogic->getName());
          self::syncOneEcogaz($eqLogic);
        }
        log::add('EcogazSync', 'info', 'EcogazSync: synchronisation terminée.');
    }

    /**
     * Sync one meross devices.
     * @return none
     */

    public static function syncOneEcogaz($eqLogic)
    {
        log::add('EcogazSync', 'info', 'SyncOne API Ecogaz : Mise à jour de ' . $eqLogic->getName());
        $urlEcogaz = 'https://odre.opendatasoft.com/api/records/1.0/search/?dataset=signal-ecogaz&q=&start=0&sort=gas_day&facet=gas_day';
        $today= new DateTime("now");

        set_error_handler(
          function ($severity, $message, $file, $line) {
            throw new Exception($message);
          }
        );
        try
        {
          log::add('EcogazSync', 'debug', 'Appel API Ecogaz');
          $options = array(
            'http' => array(
              'method'  => 'GET'
            )
          );
          $context  = stream_context_create($options);
          $result = file_get_contents($urlEcogaz, false, $context);
          if (($result === FALSE) || (!is_json($result)))
          {
            log::add('EcogazSync', 'error', 'Une erreur est survenue à l\'appel API Ecogaz.');
          }
          else
          {
            $eqLogic->addMissingCmdEcogaz();
            foreach (json_decode($result)->records as $record)
            {
              log::add('EcogazSync', 'info', 'Voici le résultat '.$record->fields->gas_day.' : '.$record->fields->indice_de_couleur);
              $jour=DateTime::createFromFormat('Y-m-d', $record->fields->gas_day);
              if ($jour==false)
              {
                log::add('EcogazSync', 'error', 'impossible de récupérer les informations de date à partir de '.$record->fields->gas_day);
              }
              else
              {
                $jourJ=$today->diff($jour)->format("%a");
                if ($jour >= $today)
                {
                  log::add('EcogazSync', 'debug', 'Jour J+'.$jourJ);
                  $eqLogic->checkAndUpdateCmd('date J+'.$jourJ, $record->fields->gas_day);
                  $eqLogic->checkAndUpdateCmd('valeur J+'.$jourJ, $record->fields->indice_de_couleur);
                  $eqLogic->save();
                }
              }
            }
          }
        } catch (Exception $e)
        {
          log::add('EcogazSync', 'error', 'Exception : '.$e->getMessage());
        }
        restore_error_handler();

        //$eqLogic->save();
        log::add('EcogazSync', 'info', 'Synchronisation de ' . $eqLogic->getName().' terminée !');
    }

    public function addMissingCmdEcogaz()
    {
      $cmd = $this->getCmd(null, 'refresh');
      if (!is_object($cmd))
      {
        $cmd = new EcogazSyncCmd();
        $cmd->setName('Refresh');
        $cmd->setType('action');
        $cmd->setSubType('other');
        $cmd->setTemplate('dashboard', 'default');
        $cmd->setTemplate('mobile', 'default');
        $cmd->setIsVisible(1);
        $cmd->setLogicalId('refresh');
        $cmd->setEqLogic_id($this->getId());
        $cmd->setOrder(0);
        $cmd->save();
      }
      $order=0;
      for ($i = 0; $i <= 4; $i++)
      {
        $order++;
        $cmd = $this->getCmd(null, 'date J+'.$i);
        if (!is_object($cmd))
        {
          $cmd = new EcogazSyncCmd();
          $cmd->setType('info');
          $cmd->setName('date J+'.$i);
          $cmd->setSubType('string');
          $cmd->setIsVisible(1);
          $cmd->setIsHistorized(0);
          $cmd->setLogicalId('date J+'.$i);
          $cmd->setEqLogic_id($this->getId());
          $cmd->setOrder($order);
          $cmd->save();
        }

        $order++;
        $cmd = $this->getCmd(null, 'valeur J+'.$i);
        if (!is_object($cmd))
        {
          $cmd = new EcogazSyncCmd();
          $cmd->setType('info');
          $cmd->setName('valeur J+'.$i);
          $cmd->setSubType('numeric');
          $cmd->setIsVisible(1);
          if ($i==0)
          {
            $cmd->setIsHistorized(1);
          }
          else
          {
            $cmd->setIsHistorized(0);
          }
          $cmd->setLogicalId('valeur J+'.$i);
          $cmd->setEqLogic_id($this->getId());
          $cmd->setOrder($order);
          $cmd->save();
        }
      }
    }

    public function toHtml($version = 'dashboard')
    {
      $replace = $this->preToHtml($version);
      if (!is_array($replace))
      {
        log::add('EcogazSync','debug','Widget en cache');
        return $replace;
      }

      $replace['#id#'] = $this->getId();
      $replace['#eqLogic_name#'] = $this->getName();
      $replace['#object_name#'] = $this->getObject()->getName();

      // Vérification que les données sont OK sinon force synchro et au final affichage page dédiée si toujours KO.
      $cmd = $this->getCmd(null, 'date J+0');
      $jour=DateTime::createFromFormat('Y-m-d', $cmd->execCmd());
      if ($jour == false)
      {
        log::add('EcogazSync','debug','On lance une synchro... A cause de J+0 : '.$cmd->execCmd());
        self::syncOneEcogaz($this);
        $cmd = $this->getCmd(null, 'date J+0');
        $jour=DateTime::createFromFormat('Y-m-d', $cmd->execCmd());
        if ($jour == false)
        {
          $template=getTemplate('core', $version, 'Ecogazsync_nodata', 'EcogazSync');
          return $this->postToHtml($version, template_replace($replace, $template));;
        }
      }

      log::add('EcogazSync','debug','Récupérations des valeurs');
      $jourFR=array('1'=>'Lundi','2'=>'Mardi','3'=>'Mercredi','4'=>'Jeudi','5'=>'Vendredi','6'=>'Samedi','7'=>'Dimanche');
      $moisFR=array('1'=>'Jan.','2'=>'Fev.','3'=>'Mars','4'=>'Avril','5'=>'Mai','6'=>'Juin','7'=>'Juil.','8'=>'Août','9'=>'Sept.','10'=>'Oct.','11'=>'Nov.','12'=>'Déc.');
      for ($i = 0; $i <= 4; $i++)
      {
        $cmd = $this->getCmd(null, 'date J+'.$i);
        $replace['#dateJ'.$i.'#'] = is_object($cmd) ? $cmd->execCmd() : '';
        if (is_object($cmd))
        {
          $jour=DateTime::createFromFormat('Y-m-d', $cmd->execCmd());
          if ($jour == false)
          {
            log::add('EcogazSync','debug','Impossible de convertir '.$cmd->execCmd().' J+'.$i);
          }
          else
          {
            $replace['#jourJ'.$i.'#']=$jourFR[$jour->format('N')];
            $replace['#dateJ'.$i.'#']=$jour->format('j').' '.$moisFR[$jour->format('n')];
          }
        }


        $cmd = $this->getCmd(null, 'valeur J+'.$i);
        if (is_object($cmd))
        {
          $value=$cmd->execCmd();
          $replace['#valeurJ'.$i.'#'] = $value;
          if ($value=='1')
          {
            $replace['#iconJ'.$i.'#'] = 'fas fa-check-circle';
            $replace['#colorJ'.$i.'#'] = 'green';
          }
          else if ($value=='2')
          {
            $replace['#iconJ'.$i.'#'] = 'fas fa-info-circle';
            $replace['#colorJ'.$i.'#'] = 'yellow';
          }
          else if ($value=='3')
          {
            $replace['#iconJ'.$i.'#'] = 'fas fa-exclamation-circle';
            $replace['#colorJ'.$i.'#'] = 'orange';
          }
          else if ($value=='4')
          {
            $replace['#iconJ'.$i.'#'] = 'fas fa-times-circle';
            $replace['#colorJ'.$i.'#'] = 'red';
          }
        }
        else
        {
          $replace['#valeurJ'.$i.'#'] ='';
          $replace['#imageJ'.$i.'#'] = '';
        }
      }

      $cmd = $this->getCmd(null, 'refresh');
      $replace['#refresh_id#'] = is_object($cmd) ? $cmd->getId() : '';

      $parameters = $this->getDisplay('parameters');
      if (is_array($parameters))
      {
          foreach ($parameters as $key => $value)
          {
              $replace['#' . $key . '#'] = $value;
          }
      }
      log::add('EcogazSync','debug','Récupérations template');
      $template=getTemplate('core', $version, 'EcogazSync', 'EcogazSync');
      return $this->postToHtml($version, template_replace($replace, $template));;
    }

    /**
     * Effacer tous les EqLogic
     * @return none
     */
    public static function deleteAll()
    {
        log::add('EcogazSync','debug','***** DELETE ALL *****');
        $eqLogics = eqLogic::byType('EcogazSync');
        foreach ($eqLogics as $eqLogic)
        {
            $eqLogic->remove();
        }
        return array(true, 'OK');
    }

}

class EcogazSyncCmd extends cmd
{
    public function dontRemoveCmd() {
        return true;
    }

    public function execute($_options = array()) {

        $eqLogic = $this->getEqLogic();
        $action = $this->getLogicalId();
        log::add('EcogazSync', 'debug', $eqLogic->getLogicalId().' = action: '. $action.' - params '.json_encode($_options) );
        $execute = false;
        // Handle actions like on_x off_x
        $splitAction = explode("_", $action);
        $action = $splitAction[0];
        $channel = $splitAction[1];
        switch ($action) {
            case "refresh":
              log::add('EcogazSync', 'debug', 'refresh');
              EcogazSync::syncOneEcogaz($eqLogic);
              break;
            default:
              log::add('EcogazSync','debug','action: Action='.$action.' '.__('non implementée.', __FILE__));
              break;
        }

    }
}
