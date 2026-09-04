<?php

/**
 * @module Places
 */

/**
 * Address and sub-premise (unit/apartment) lookup via Loqate and local caches.
 * 
 * Uses Loqate Find (free) for autocomplete and container drill-down,
 * Loqate Retrieve (1 credit) only when user selects a specific unit.
 * Results are cached in the database so repeated lookups cost nothing.
 * 
 * For NYC addresses, can defer to Places_NYC for free open data before
 * falling back to Loqate.
 * 
 * @class Places_Address
 */
class Places_Address
{
	/**
	 * Loqate Find — autocomplete and container drill-down.
	 * This is FREE — no credits consumed.
	 * 
	 * @method find
	 * @static
	 * @param {string} $text The search text (address typed by user)
	 * @param {array} [$options]
	 * @param {string} [$options.container] Loqate container ID for drill-down
	 * @param {string} [$options.countries="USA"] Country filter
	 * @param {int} [$options.limit=10] Max results
	 * @return {array} Array of items with Id, Type, Text, Description
	 */
	static function find($text, $options = array())
	{
		$key = Q_Config::get('Places', 'loqate', 'key', null);
		if (!$key) {
			throw new Q_Exception("Places loqate key is not configured");
		}

		$endpoint = Q_Config::get(
			'Places', 'loqate', 'endpoint',
			'https://api.addressy.com/Capture/Interactive/Find/v1.10/json3.ws'
		);

		$params = array(
			'Key' => $key,
			'Text' => $text ?: '',
			'Countries' => Q::ifset($options, 'countries', 'USA'),
			'Limit' => Q::ifset($options, 'limit', 10)
		);
		if (!empty($options['container'])) {
			$params['Container'] = $options['container'];
			$params['Text'] = '';  // must be empty for container drill-down
		}

		$url = $endpoint . '?' . http_build_query($params);
		$response = Q_Utils::get($url);
		$data = Q::json_decode($response, true);

		if (!empty($data['Items'][0]['Error'])) {
			$error = $data['Items'][0];
			Q::log("Loqate Find error: " . $error['Description'], 'places');
			return array();
		}

		return Q::ifset($data, 'Items', array());
	}

	/**
	 * Loqate Retrieve — get full address details.
	 * COSTS 1 CREDIT per call. Only use when user selects.
	 * 
	 * @method retrieve
	 * @static
	 * @param {string} $id The Loqate address ID (from Find results where Type="Address")
	 * @param {array} [$options]
	 * @return {array} Full address with SubBuilding, BuildingNumber, Street, City, PostalCode
	 */
	static function retrieve($id, $options = array())
	{
		$key = Q_Config::get('Places', 'loqate', 'key', null);
		if (!$key) {
			throw new Q_Exception("Places loqate key is not configured");
		}

		$endpoint = Q_Config::get(
			'Places', 'loqate', 'retrieveEndpoint',
			'https://api.addressy.com/Capture/Interactive/Retrieve/v1.2/json3.ws'
		);

		$params = array('Key' => $key, 'Id' => $id);
		$url = $endpoint . '?' . http_build_query($params);
		$response = Q_Utils::get($url);
		$data = Q::json_decode($response, true);

		if (!empty($data['Items'][0]['Error'])) {
			$error = $data['Items'][0];
			Q::log("Loqate Retrieve error: " . $error['Description'], 'places');
			return null;
		}

		$item = Q::ifset($data, 'Items', 0, null);
		if ($item) {
			// Cache the result
			self::_cacheUnit($item);
		}

		return $item;
	}

	/**
	 * Get all units for a building address.
	 * Uses local cache first, then NYC open data (if applicable),
	 * then Loqate container drill-down (free).
	 * 
	 * @method units
	 * @static
	 * @param {string} $address Full building address
	 * @param {array} [$options]
	 * @param {boolean} [$options.forceRefresh=false] Skip cache
	 * @param {string} [$options.loqateContainerId] If you already have the Loqate container ID
	 * @return {array} Array with 'units' (list), 'source' (cache|nyc|loqate|generated), 'building' (metadata)
	 */
	static function units($address, $options = array())
	{
		$forceRefresh = Q::ifset($options, 'forceRefresh', false);

		// 1. Check local cache
		if (!$forceRefresh) {
			$cached = self::_getCachedUnits($address);
			if ($cached) {
				return array(
					'units' => $cached,
					'source' => 'cache',
					'building' => null
				);
			}
		}

		// 2. Is this a NYC address? Try free NYC data first
		if (class_exists('Places_NYC') && Places_NYC::isNYCAddress($address)) {
			$nycResult = Places_NYC::units($address);
			if (!empty($nycResult['units'])) {
				// Cache the results
				foreach ($nycResult['units'] as $unit) {
					self::_cacheUnitSimple($address, $unit);
				}
				return $nycResult;
			}
		}

		// 3. Try Loqate container drill-down (FREE)
		$containerId = Q::ifset($options, 'loqateContainerId', null);
		if (!$containerId) {
			// Find the building first
			$results = self::find($address);
			foreach ($results as $item) {
				if ($item['Type'] !== 'Address') {
					// It's a container — we can drill down
					$containerId = $item['Id'];
					break;
				}
			}
		}

		if ($containerId) {
			$units = self::_drillDownAll($containerId);
			if (!empty($units)) {
				foreach ($units as $unit) {
					self::_cacheUnitSimple($address, $unit);
				}
				return array(
					'units' => $units,
					'source' => 'loqate',
					'building' => null
				);
			}
		}

		// 4. No sub-premise data available
		return array(
			'units' => array(),
			'source' => 'none',
			'building' => null
		);
	}

	/**
	 * Recursively drill down through Loqate containers to get all units.
	 * All Find calls are FREE — no credits consumed.
	 * 
	 * @method _drillDownAll
	 * @static
	 * @private
	 * @param {string} $containerId
	 * @param {int} [$depth=0] Recursion depth safety
	 * @return {array} Flat list of unit strings
	 */
	private static function _drillDownAll($containerId, $depth = 0)
	{
		if ($depth > 5) return array(); // safety

		$items = self::find('', array(
			'container' => $containerId,
			'limit' => 200
		));

		$units = array();
		foreach ($items as $item) {
			if ($item['Type'] === 'Address') {
				// Terminal node — extract unit number from Text
				$unitNumber = self::_parseUnitFromText($item['Text']);
				if ($unitNumber) {
					$units[] = $unitNumber;
				}
			} else {
				// Nested container — drill deeper
				$nested = self::_drillDownAll($item['Id'], $depth + 1);
				$units = array_merge($units, $nested);
			}
		}

		return $units;
	}

	/**
	 * Parse a unit/apartment number from Loqate's Text field
	 * e.g. "Apt 12B" → "12B", "Suite 200" → "200", "Floor 3" → "3"
	 * 
	 * @method _parseUnitFromText
	 * @static
	 * @private
	 */
	private static function _parseUnitFromText($text)
	{
		$text = trim($text);
		// Common patterns: "Apt 12B", "Suite 200", "Unit 3A", "PH1", "Floor 2"
		if (preg_match('/^(?:Apt|Apartment|Suite|Unit|Ste|#|Fl|Floor)\s*\.?\s*(.+)$/i', $text, $m)) {
			return trim($m[1]);
		}
		// If it's just a number/letter combo, use as-is
		if (preg_match('/^[A-Za-z0-9\-\/]+$/', $text)) {
			return $text;
		}
		return $text;
	}

	/**
	 * Parse floor number from a unit string
	 * "12B" → 12, "PH1" → null (penthouse), "2F" → 2
	 * 
	 * @method parseFloor
	 * @static
	 * @param {string} $unit
	 * @return {int|null}
	 */
	static function parseFloor($unit)
	{
		$unit = strtoupper(trim($unit));
		if (strpos($unit, 'PH') === 0) return null; // penthouse
		if (strpos($unit, 'B') === 0 && strlen($unit) <= 3) return 0; // basement
		if (preg_match('/^(\d+)/', $unit, $m)) {
			$floor = intval($m[1]);
			// Four-digit scheme: 1201 → floor 12
			if ($floor >= 100 && strlen($m[1]) >= 3) {
				return intval(substr($m[1], 0, -2));
			}
			return $floor;
		}
		return null;
	}

	/**
	 * Cache a unit from Loqate Retrieve response
	 * @method _cacheUnit
	 * @static
	 * @private
	 */
	private static function _cacheUnit($item)
	{
		$address = trim(
			Q::ifset($item, 'BuildingNumber', '') . ' ' .
			Q::ifset($item, 'Street', '') . ', ' .
			Q::ifset($item, 'City', '') . ' ' .
			Q::ifset($item, 'PostalCode', '')
		);
		$unit = Q::ifset($item, 'SubBuilding', '');
		if ($address && $unit) {
			self::_cacheUnitSimple($address, $unit, $item);
		}
	}

	/**
	 * Cache a unit string against a building address
	 * @method _cacheUnitSimple
	 * @static
	 * @private
	 */
	private static function _cacheUnitSimple($address, $unit, $fullData = null)
	{
		// Store in places_address_unit table
		try {
			$row = new Places_AddressUnit();
			$row->address = substr($address, 0, 500);
			$row->unit = substr($unit, 0, 31);
			if (!$row->retrieve()) {
				$row->floor = self::parseFloor($unit);
				$row->fullData = $fullData ? Q::json_encode($fullData) : null;
				$row->source = $fullData ? 'loqate' : 'generated';
				$row->save();
			}
		} catch (Exception $e) {
			Q::log("Places_Address cache error: " . $e->getMessage(), 'places');
		}
	}

	/**
	 * Get cached units for an address
	 * @method _getCachedUnits
	 * @static
	 * @private
	 */
	private static function _getCachedUnits($address)
	{
		try {
			$rows = Places_AddressUnit::select()
				->where(array('address' => substr($address, 0, 500)))
				->orderBy('floor')
				->orderBy('unit')
				->fetchDbRows();

			if (empty($rows)) return null;

			$units = array();
			foreach ($rows as $row) {
				$units[] = $row->unit;
			}
			return $units;
		} catch (Exception $e) {
			return null;
		}
	}
}
