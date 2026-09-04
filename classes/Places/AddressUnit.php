<?php

/**
 * @module Places
 */

/**
 * Class representing 'Address_Unit' rows in the 'Places' database.
 * Caches unit/apartment numbers for building addresses.
 * 
 * @class Places_AddressUnit
 * @extends Base_Places_AddressUnit
 */
class Places_AddressUnit extends Base_Places_AddressUnit
{
	/**
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
	}

	/**
	 * Get all cached units for a building address
	 * @method forAddress
	 * @static
	 * @param {string} $address
	 * @return {array} Array of unit strings, naturally sorted
	 */
	static function forAddress($address)
	{
		$rows = self::select('unit, floor, source')
			->where(array('address' => substr($address, 0, 500)))
			->orderBy('floor')
			->orderBy('unit')
			->fetchDbRows();

		$units = array();
		foreach ($rows as $row) {
			$units[] = $row->unit;
		}
		return $units;
	}

	/**
	 * Add a user correction — a unit that wasn't in the generated list
	 * @method correct
	 * @static
	 * @param {string} $address
	 * @param {string} $unit
	 * @param {string} [$bbl]
	 * @return {Places_AddressUnit}
	 */
	static function correct($address, $unit, $bbl = null)
	{
		$row = new self();
		$row->address = substr($address, 0, 500);
		$row->unit = substr(trim($unit), 0, 31);
		if (!$row->retrieve()) {
			$row->floor = Places_Address::parseFloor($unit);
			$row->source = 'user_correction';
			$row->bbl = $bbl;
			$row->save();
		}
		return $row;
	}
}
