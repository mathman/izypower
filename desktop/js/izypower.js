'use strict';

/**
 * Bouton "Synchroniser les centrales" sur la page d'accueil du plugin.
 */
$('#bt_syncStationsHome').off('click').on('click', function () {
	$(this).addClass('disabled');
	$.ajax({
		type: 'POST',
		url: 'plugins/izypower/core/ajax/izypower.ajax.php',
		data: { action: 'synchronizeStations' },
		dataType: 'json',
		error: function (request, status, error) {
			handleAjaxError(request, status, error);
			$('#bt_syncStationsHome').removeClass('disabled');
		},
		success: function (data) {
			$('#bt_syncStationsHome').removeClass('disabled');
			if (data.state != 'ok') {
				return $.error('{{Erreur lors de la synchronisation}} : ' + data.result);
			}
			$('#div_alert').showAlert({ message: '{{Synchronisation réussie}} : ' + data.result + ' {{centrale(s) trouvée(s)}}', level: 'success' });
			$.ajax({
				type: 'GET',
				url: 'index.php',
				data: { v: 'd&p=izypower' },
				success: function () {
					location.reload();
				}
			});
		}
	});
});

/**
 * Bouton "Rafraîchir maintenant" sur la fiche d'une centrale.
 * Force une mise à jour immédiate des commandes de cette centrale,
 * sans attendre le prochain passage du cron (toutes les 3 minutes).
 */
$('#bt_syncStationEq').off('click').on('click', function () {
	var eqLogic_id = $('.eqLogic').getEqLogicAttr('id');
	if (!eqLogic_id) {
		return;
	}
	$(this).addClass('disabled');
	$.ajax({
		type: 'POST',
		url: 'plugins/izypower/core/ajax/izypower.ajax.php',
		data: {
			action: 'pullStation',
			id: eqLogic_id
		},
		dataType: 'json',
		error: function (request, status, error) {
			handleAjaxError(request, status, error);
			$('#bt_syncStationEq').removeClass('disabled');
		},
		success: function (data) {
			$('#bt_syncStationEq').removeClass('disabled');
			if (data.state != 'ok') {
				return $.error('{{Erreur lors du rafraîchissement}} : ' + data.result);
			}
			$('#div_alert').showAlert({ message: '{{Centrale rafraîchie}}', level: 'success' });
			$('.eqLogicAction[data-action="save"]').trigger('click');
		}
	});
});
