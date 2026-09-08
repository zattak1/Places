<?php

/**
 * @module Places
 */

/**
 * NYC-specific building and unit data from free open APIs.
 * Uses GeoSearch (address → BBL), PLUTO (building shape),
 * and the Property Assessment Roll (real condo unit numbers).
 * 
 * Falls back to unit generation from building metadata when
 * real unit numbers aren't available.
 * 
 * @class Places_NYC
 */
class Places_NYC
{
	/**
	 * GeoSearch autocomplete — free, no API key needed
	 * 
	 * @method autocomplete
	 * @static
	 * @param {string} $text Address text
	 * @return {array} Array of results with text, bbl, bin, coordinates
	 */
	static function autocomplete($text)
	{
		// Endpoint is configurable so it can be pointed at a mock or a mirror.
		$base = Q_Config::get('Places', 'nyc', 'geosearchUrl',
			'https://geosearch.planninglabs.nyc/v2/autocomplete');
		$url = $base . '?' . http_build_query(array('text' => $text));
		$response = Q_Utils::get($url);
		$data = Q::json_decode($response, true);

		$results = array();
		foreach (Q::ifset($data, 'features', array()) as $feature) {
			$props = Q::ifset($feature, 'properties', array());
			$pad = Q::ifset($props, 'addendum', 'pad', array());
			$coords = Q::ifset($feature, 'geometry', 'coordinates', array());
			$results[] = array(
				'text' => Q::ifset($props, 'label', ''),
				'bbl' => Q::ifset($pad, 'bbl', ''),
				'bin' => Q::ifset($pad, 'bin', ''),
				'borough' => Q::ifset($props, 'borough', ''),
				'latitude' => isset($coords[1]) ? $coords[1] : null,
				'longitude' => isset($coords[0]) ? $coords[0] : null
			);
		}
		return $results;
	}

	/**
	 * Get building metadata from PLUTO
	 * 
	 * @method building
	 * @static
	 * @param {string} $bbl The 10-digit BBL (boro+block+lot)
	 * @return {array|null} Building metadata
	 */
	static function building($bbl)
	{
		$base = Q_Config::get('Places', 'nyc', 'plutoUrl',
			'https://data.cityofnewyork.us/resource/64uk-42ks.json');
		$url = $base . '?'
			. http_build_query(array(
				'$select' => 'bbl,numfloors,unitsres,unitstotal,numbldgs,bldgclass,yearbuilt,address,zipcode,latitude,longitude',
				'$where' => "bbl='$bbl'",
				'$limit' => 1
			));
		
		// Add app token if configured (avoids throttling)
		$token = Q_Config::get('Places', 'nyc', 'appToken', null);
		if ($token) {
			$url .= '&$$app_token=' . urlencode($token);
		}

		$response = Q_Utils::get($url);
		$data = Q::json_decode($response, true);

		if (empty($data[0])) return null;

		$row = $data[0];
		return array(
			'bbl' => $row['bbl'],
			'floors' => intval(Q::ifset($row, 'numfloors', 0)),
			'unitsRes' => intval(Q::ifset($row, 'unitsres', 0)),
			'unitsTotal' => intval(Q::ifset($row, 'unitstotal', 0)),
			'numBldgs' => intval(Q::ifset($row, 'numbldgs', 1)),
			'bldgClass' => Q::ifset($row, 'bldgclass', ''),
			'yearBuilt' => intval(Q::ifset($row, 'yearbuilt', 0)),
			'address' => Q::ifset($row, 'address', ''),
			'zipcode' => Q::ifset($row, 'zipcode', ''),
			'latitude' => Q::ifset($row, 'latitude', null),
			'longitude' => Q::ifset($row, 'longitude', null)
		);
	}

	/**
	 * Get units for a building address.
	 * Tries assessment roll first (real condo units), then generates.
	 * 
	 * @method units
	 * @static
	 * @param {string} $address
	 * @param {array} [$options]
	 * @return {array} Array with 'units', 'source', 'building', 'scheme'
	 */
	static function units($address, $options = array())
	{
		// Get BBL from GeoSearch
		$results = self::autocomplete($address);
		if (empty($results)) {
			return array('units' => array(), 'source' => 'none', 'building' => null);
		}

		$bbl = $results[0]['bbl'];
		if (!$bbl) {
			return array('units' => array(), 'source' => 'none', 'building' => null);
		}

		// Get building metadata from PLUTO
		$building = self::building($bbl);
		if (!$building) {
			return array('units' => array(), 'source' => 'none', 'building' => null);
		}

		// Parse BBL components: boro(1) + block(5) + lot(4)
		$boro = substr($bbl, 0, 1);
		$block = substr($bbl, 1, 5);

		// Parse street name from address for assessment roll lookup
		$streetName = self::_extractStreetName($address);

		// 1. Try assessment roll (real condo unit numbers)
		$realUnits = self::_getAssessmentUnits($boro, $block, $streetName);
		if (!empty($realUnits)) {
			return array(
				'units' => $realUnits,
				'source' => 'assessment_roll',
				'building' => $building,
				'scheme' => 'real'
			);
		}

		// 2. Generate units from building metadata
		$generated = self::generateUnits($building);
		return array(
			'units' => $generated['units'],
			'source' => 'generated',
			'building' => $building,
			'scheme' => $generated['scheme']
		);
	}

	/**
	 * Query the Property Assessment Roll for real unit numbers.
	 * Dataset 8y4t-faws — free, no key needed.
	 * 
	 * @method _getAssessmentUnits
	 * @static
	 * @private
	 */
	private static function _getAssessmentUnits($boro, $block, $streetName)
	{
		if (!$streetName) return array();

		$base = Q_Config::get('Places', 'nyc', 'assessmentUrl',
			'https://data.cityofnewyork.us/resource/8y4t-faws.json');
		$url = $base . '?'
			. http_build_query(array(
				'$select' => 'aptno',
				'$where' => "boro='$boro' AND block='$block' AND upper(street_name) LIKE upper('%$streetName%') AND aptno IS NOT NULL",
				'$order' => 'aptno',
				'$limit' => 1000
			));

		$token = Q_Config::get('Places', 'nyc', 'appToken', null);
		if ($token) {
			$url .= '&$$app_token=' . urlencode($token);
		}

		$response = Q_Utils::get($url);
		$data = Q::json_decode($response, true);

		$units = array();
		foreach ($data as $row) {
			$apt = trim(Q::ifset($row, 'aptno', ''));
			if ($apt && !in_array($apt, $units)) {
				$units[] = $apt;
			}
		}

		// Natural sort: 1A, 1B, 2A, 2B, ..., 10A, 10B, PH1
		usort($units, function ($a, $b) {
			return strnatcasecmp($a, $b);
		});

		return $units;
	}

	/**
	 * Generate unit numbers from building metadata.
	 * Uses BldgClass + YearBuilt to pick the numbering scheme.
	 * 
	 * @method generateUnits
	 * @static
	 * @param {array} $building Building metadata from PLUTO
	 * @return {array} Array with 'units' and 'scheme'
	 */
	static function generateUnits($building)
	{
		$floors = intval($building['floors']);
		$unitsRes = intval($building['unitsRes']);
		$unitsTotal = intval($building['unitsTotal']);
		$numBldgs = intval($building['numBldgs']);
		$bldgClass = $building['bldgClass'];
		$yearBuilt = intval($building['yearBuilt']);

		if ($floors <= 0 || $unitsRes <= 0) {
			return array('units' => array(), 'scheme' => 'unknown');
		}

		// If multiple buildings on the lot, we can't distribute reliably
		if ($numBldgs > 1) {
			// Just generate flat sequential
			$units = array();
			for ($i = 1; $i <= min($unitsRes, 500); $i++) {
				$units[] = (string)$i;
			}
			return array('units' => $units, 'scheme' => 'sequential');
		}

		// Determine scheme from BldgClass + YearBuilt
		$scheme = self::_pickScheme($bldgClass, $yearBuilt, $floors, $unitsRes);

		// Calculate residential floor range
		$commercialUnits = max(0, $unitsTotal - $unitsRes);
		$startFloor = ($commercialUnits > 0) ? 2 : 1; // skip ground floor if retail
		$resFloors = $floors - ($startFloor - 1);

		// Floor 13 is skipped below, so it must not count toward the divisor —
		// otherwise the last few units are never generated and the building
		// comes up short of unitsRes.
		if ($floors >= 13 and $startFloor <= 13) {
			--$resFloors;
		}

		if ($resFloors <= 0) {
			return array('units' => array(), 'scheme' => $scheme);
		}

		$unitsPerFloor = ceil($unitsRes / $resFloors);

		$units = array();
		$generated = 0;

		for ($f = $startFloor; $f <= $floors && $generated < $unitsRes; $f++) {
			// Skip floor 13
			if ($f == 13) continue;

			// Determine if this is the top floor (penthouse)
			$isTop = ($f == $floors);
			$floorLabel = $isTop && $floors >= 10 ? 'PH' : (string)$f;

			$unitsOnFloor = min($unitsPerFloor, $unitsRes - $generated);

			for ($u = 0; $u < $unitsOnFloor && $generated < $unitsRes; $u++) {
				switch ($scheme) {
					case 'front_rear':
						// Walk-ups: 2F, 2R or 2FE, 2FW, 2RE, 2RW
						if ($unitsOnFloor <= 2) {
							$suffix = ($u == 0) ? 'F' : 'R';
						} else {
							$suffixes = array('FE', 'FW', 'RE', 'RW', 'F', 'R');
							$suffix = $suffixes[$u % count($suffixes)];
						}
						$units[] = $floorLabel . $suffix;
						break;

					case 'letters':
						// Elevator pre-1990: 12A, 12B, 12C (skip I)
						$letter = $u;
						if ($letter >= 8) $letter++; // skip I (index 8)
						$units[] = $floorLabel . chr(65 + $letter);
						break;

					case 'four_digit':
						// Post-1990 tall: 1201, 1202
						$floorNum = ($floorLabel === 'PH') ? $floors : $f;
						$units[] = sprintf('%d%02d', $floorNum, $u + 1);
						break;

					case 'sequential':
					default:
						$units[] = (string)($generated + 1);
						break;
				}
				$generated++;
			}
		}

		// Imperfect division can still leave a remainder once every floor has
		// had its share. The README specifies these become PH1..PHn.
		$leftover = $unitsRes - $generated;
		for ($i = 1; $i <= $leftover; ++$i) {
			$units[] = 'PH' . $i;
		}

		return array('units' => $units, 'scheme' => $scheme);
	}

	/**
	 * Pick the numbering scheme based on building classification
	 * 
	 * @method _pickScheme
	 * @static
	 * @private
	 */
	private static function _pickScheme($bldgClass, $yearBuilt, $floors, $unitsRes)
	{
		$bldgClass = strtoupper($bldgClass);

		// Small walk-up, ≤3 floors → flat sequential
		if ($floors <= 3 && $unitsRes <= 12) {
			return 'sequential';
		}

		// Walk-up (C1-C7), ≤6 units/floor → front/rear
		if (preg_match('/^C[1-7]/', $bldgClass) && $floors <= 6) {
			return 'front_rear';
		}

		// Elevator, post-1990, tall → four-digit
		if (preg_match('/^D[1-9]/', $bldgClass) && $yearBuilt >= 1990 && $floors >= 10) {
			return 'four_digit';
		}

		// Elevator, pre-1990 → letters
		if (preg_match('/^D[1-9]/', $bldgClass)) {
			return 'letters';
		}

		// Mixed-use (S*) → letters
		if (strpos($bldgClass, 'S') === 0) {
			return 'letters';
		}

		// Default: letters for taller, sequential for shorter
		return ($floors >= 5) ? 'letters' : 'sequential';
	}

	/**
	 * Check whether an address is in NYC
	 * 
	 * @method isNYCAddress
	 * @static
	 * @param {string} $address
	 * @return {boolean}
	 */
	static function isNYCAddress($address)
	{
		$address = strtolower($address);
		$nycIndicators = array(
			'new york', 'ny ', 'nyc', 'manhattan', 'brooklyn',
			'queens', 'bronx', 'staten island',
			// NYC zip code ranges
			'100', '101', '102', '103', '104',  // Manhattan
			'112',  // Brooklyn
			'111', '113', '114', '116',  // Queens
			'104',  // Bronx
			'103'   // Staten Island
		);
		foreach ($nycIndicators as $indicator) {
			if (strpos($address, $indicator) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract street name from a full address
	 * "350 W 42nd St, New York, NY 10036" → "42 ST"
	 * 
	 * @method _extractStreetName
	 * @static
	 * @private
	 */
	private static function _extractStreetName($address)
	{
		// Remove city, state, zip
		$address = preg_replace('/,.*$/', '', $address);
		// Remove building number
		$address = preg_replace('/^\d+[-\d]*\s+/', '', $address);
		// Normalize
		$address = strtoupper(trim($address));
		// Remove directional prefixes for matching
		$address = preg_replace('/^(EAST|WEST|NORTH|SOUTH|E|W|N|S)\s+/', '', $address);
		return $address;
	}
}
