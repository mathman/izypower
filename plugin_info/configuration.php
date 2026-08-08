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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-solar-panel"></i> {{Compte Izypower}}</legend>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Identifiant (email)}}</label>
      <div class="col-sm-4">
        <input class="configKey form-control" data-l1key="username" placeholder="vous@exemple.fr" />
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Mot de passe}}</label>
      <div class="col-sm-4">
        <input type="password" class="configKey form-control" data-l1key="password" placeholder="••••••••" />
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label"></label>
      <div class="col-sm-6">
        <a class="btn btn-primary" id="bt_syncStations">
          <i class="fas fa-sync"></i> {{Synchroniser les centrales}}
        </a>
        <span class="help-block">
          {{Crée ou met à jour un équipement Jeedom pour chaque centrale photovoltaïque de votre compte Izypower. Les capteurs (puissance, énergie, batterie) sont ensuite rafraîchis automatiquement toutes les 3 minutes.}}
        </span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label"></label>
      <div class="col-sm-6">
        <div class="alert alert-info">
          {{Ce plugin n'est pas développé ni affilié à Materfrance / Izypower. Toutes les données transitent par le cloud Izypower (pas d'accès local) ; le rafraîchissement n'est donc pas plus rapide que 3 minutes, comme dans l'application officielle.}}
        </div>
      </div>
    </div>

  </fieldset>
</form>

<script>
$('#bt_syncStations').off('click').on('click', function () {
  $(this).addClass('disabled');
  $.ajax({
    type: 'POST',
    url: 'plugins/izypower/core/ajax/izypower.ajax.php',
    data: { action: 'synchronizeStations' },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error);
      $('#bt_syncStations').removeClass('disabled');
    },
    success: function (data) {
      $('#bt_syncStations').removeClass('disabled');
      if (data.state !== 'ok') {
        return $.error('{{Erreur lors de la synchronisation}} : ' + data.result);
      }
      $('#div_alert').showAlert({ message: '{{Synchronisation réussie}} : ' + data.result + ' {{centrale(s) trouvée(s)}}', level: 'success' });
    }
  });
});
</script>