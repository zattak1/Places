<?php

/**
 * Places/address handler — autocomplete, drill-down, and unit lookup
 * 
 * GET ?text=350+w+42   → autocomplete results
 * GET ?address=...     → unit list for a building
 * POST ?address=...&unit=...  → user correction (unit not listed)
 */
function Places_address_response()
{
	if (Q_Request::method() === 'POST') {
		// User correction — "my apartment isn't listed"
		$user = Users::loggedInUser(true);
		$address = Q::ifset($_REQUEST, 'address', null);
		$unit = Q::ifset($_REQUEST, 'unit', null);
		$bbl = Q::ifset($_REQUEST, 'bbl', null);

		if (!$address || !$unit) {
			throw new Q_Exception_RequiredField(array('field' => 'address and unit'));
		}

		$row = Places_AddressUnit::correct($address, $unit, $bbl);
		Q_Response::setSlot('unit', $row->toArray());
		return;
	}

	// GET — autocomplete or unit lookup
	$text = Q::ifset($_REQUEST, 'text', null);
	$address = Q::ifset($_REQUEST, 'address', null);
	$container = Q::ifset($_REQUEST, 'container', null);

	if ($text) {
		// Autocomplete
		$provider = Q_Config::get('Places', 'address', 'provider', 'loqate');
		
		if ($provider === 'nyc' || ($provider === 'auto' && !empty($_REQUEST['nyc']))) {
			// NYC GeoSearch
			$results = Places_NYC::autocomplete($text);
		} else {
			// Loqate (default)
			$results = Places_Address::find($text, array(
				'container' => $container
			));
		}
		Q_Response::setSlot('results', $results);
		return;
	}

	if ($address) {
		// Unit lookup for a building
		$result = Places_Address::units($address, array(
			'loqateContainerId' => $container
		));
		Q_Response::setSlot('building', $result);
		return;
	}

	throw new Q_Exception_RequiredField(array('field' => 'text or address'));
}
