<?php
/**
 * @module Places
 */
/**
 * Class representing 'Location' rows in the 'Places' database
 * You can create an object of this class either to
 * access its non-static methods, or to actually
 * represent a location row in the Places database.
 *
 * @class Places_Location
 * @extends Base_Places_Location
 */
class Places_Location extends Base_Places_Location
{
	/**
	 * The setUp() method is called the first time
	 * an object of this class is constructed.
	 * @method setUp
	 */
	function setUp()
	{
		parent::setUp();
		// INSERT YOUR CODE HERE
		// e.g. $this->hasMany(...) and stuff like that.
	}

	/*
	 * Add any Places_Location methods here, whether public or not
	 */
	/**
	 * Get the logged-in user's location stream
	 * @method userStream
	 * @param {boolean} [$throwIfNotLoggedIn=false]
	 *   Whether to throw a Users_Exception_NotLoggedIn if no user is logged in.
	 * @param {boolean} [$created] Optional reference to a variable that
	 *   will be set to true if the stream was created, or false otherwise.
	 * @return {Streams_Stream|null}
	 * @throws {Users_Exception_NotLoggedIn} If user is not logged in and
	 *   $throwIfNotLoggedIn is true
	 */
	static function userStream($throwIfNotLoggedIn = false, &$created = null)
	{
		$user = Users::loggedInUser($throwIfNotLoggedIn);
		if (!$user) {
			return null;
		}
		$streamName = "Places/user/location";
		$stream = Streams_Stream::fetch($user->id, $user->id, $streamName);
		if (!$stream) {
			$stream = Streams::create($user->id, $user->id, 'Places/location', array(
				'name' => $streamName
			));
			$stream->join(array('skipAccess' => true));
			$created = true;
		} else {
			$created = false;
		}
		return $stream;
	}

	/**
	 * Get a Places/location stream published by a publisher for a given placeId.
	 * This is used to cache information from the Google Places API.
	 * @method stream
	 * @static
	 * @param {string} $asUserId The user to fetch as
	 * @param {string} $publisherId The user publishing the stream
	 * @param {string} $placeId The id of the place in Google Places
	 * @param {array} [$options=array()] Any options to pass. Also can include:
	 * @param {boolean} [$options.throwIfBadValue=false] Whether to throw Q_Exception if the result contains a bad value
	 * @param {boolean} [$options.withTimeZone=false] If true, trying to get time zone and save to attributes
	 * @return {Streams_Stream|null}
	 * @throws {Q_Exception} if a bad value is encountered and $throwIfBadValue is true
	 */
	static function stream($asUserId, $publisherId, $placeId, $options = array())
	{
		$throwIfBadValue = Q::ifset($options, 'throwIfBadValue', false);
		$withTimeZone = Q::ifset($options, 'withTimeZone', false);

		if (empty($placeId)) {
			if ($throwIfBadValue) {
				throw new Q_Exception_RequiredField(array('field' => 'id'));
			}
			return null;
		}

		// sanitize the ID
		$characters = '/[^A-Za-z0-9]+/';
		$result = preg_replace($characters, '_', $placeId);

		// see if it's already in the system
		$streamName = "Places/location/$result";
		$location = Streams_Stream::fetch($asUserId, $publisherId, $streamName);
		if ($location) {
			$ut = $location->updatedTime;
			if (isset($ut)) {
				$db = $location->db();
				$ut = $db->fromDateTime($ut);
				$ct = $db->getCurrentTimestamp();
				$cd = Q_Config::get('Places', 'cache', 'duration', 60*60*24*30);
				if ($ct - $ut < $cd) {
					// there is a cached location stream that is still viable
					return $location;
				}
			}
		}

		$key = Q_Config::expect('Places', 'google', 'keys', 'server');
		$query = http_build_query(array('key' => $key, 'placeid' => $placeId));
		$url = "https://maps.googleapis.com/maps/api/place/details/json?$query";
		$json = Places::getRemoteContents($url);
		$response = json_decode($json, true);
		if (empty($response['result'])) {
			throw new Q_Exception("Places_Location::stream: Couldn't obtain place information for $placeId");
		}
		if (!empty($response['error_message'])) {
			throw new Q_Exception("Places_Location::stream: ".$response['error_message']);
		}
		$result = $response['result'];
		$latitude = $result['geometry']['location']['lat'];
		$longitude = $result['geometry']['location']['lng'];
		$attributes = array(
			'title' => $result['name'],
			'latitude' => $latitude,
			'longitude' => $longitude,
			'viewport' => $result['geometry']['viewport'],
			// 'icon' => $result['icon'],
			'phoneNumber' => Q::ifset($result, 'international_phone_number', null),
			'phoneFormatted' => Q::ifset($result, 'formatted_phone_number', null),
			'types' => $result['types'],
			'rating' => Q::ifset($result, 'rating', null),
			'address' => Q::ifset($result, 'formatted_address', null),
			'website' => Q::ifset($result, 'website', null),
			'placeId' => $placeId
		);

		// try to get timeZone
		if ($withTimeZone) {
			try {
				$timeZone = Places::timezone($latitude, $longitude);
				$attributes['timeZone'] = Q::ifset($timeZone, 'timeZoneId', null);
			} catch (Exception $e) {}
		}

		$geohash = Places_Geohash::encode($latitude, $longitude);
		if ($location) {
			$location->title = $result['name'];
			$location->setAttribute($attributes);
			$location->changed();
		} else {
			Streams_Stream::fetchOrCreate($publisherId, $publisherId, "Places/user/locations");
			$location = Streams::create($asUserId, $publisherId, 'Places/location', array(
				'name' => $streamName,
				'title' => $result['name'],
				'attributes' => Q::json_encode($attributes)
			), array('relate' => array(
				'publisherId' => $publisherId,
				'streamName' => 'Places/user/locations',
				'type' => 'Places/locations'
			), 'skipAccess' => true));
			$pl = new Places_Location(@compact(
				'geohash', 'publisherId','streamName'
			));
			$pl->save(true);
		}
		return $location;
	}

	/**
	 * Adds a stream to represent an area within a location.
	 * Also may add streams to represent the floor and column.
	 * @method addArea
	 * @static
	 * @param {Streams_Stream} $location The location stream
	 * @param {string} $title The title of the area
	 * @param {string} [$floor] The number of the floor on which the area is located
	 * @param {string} [$column] The name of the column on which the area is located
	 * @param {array} [$options=array()] Any options to pass to Streams::create. Also can include:
	 * @param {array} [$options.asUserId=null] Override the first parameter to Streams::create
	 * @return {array} An array of ($area, $floor, $column)
	 */
	static function addArea($location, $title, $floor=null, $column=null, $options=array())
	{
		$locationName = $location->name;
		$parts = explode('/', $locationName);
		$placeId = $parts[2];
		$asUserId = Q::ifset($options, 'asUserId', null);
		$publisherId = $location->publisherId;
		$skipAccess = Q::ifset($options, 'skipAccess', true);
		$floorName = isset($floor)
			? "Places/floor/$placeId/".Q_Utils::normalize($floor)
			: null;
		$columnName = isset($column)
			? "Places/column/$placeId/".Q_Utils::normalize($column)
			: null;
		$areaName = Q_Utils::normalize(
			$floor && $column ? $floor.$column : $title
		);
		$name = "Places/area/$placeId/$areaName";
		$area = Streams_Stream::fetch($asUserId, $publisherId, $name, $options);
		if (!$area) {
			$attributes = array(
				'locationName' => $locationName,
				'locationTitle' => $location->title,
				'locationAddress' => $location->getAttribute('address'),
				'floorName' => $floorName,
				'columnName' => $columnName
			);
			$area = Streams::create($asUserId, $publisherId, 'Places/area',
				@compact('name', 'title', 'attributes'),
				@compact('skipAccess')
			);
			$area->relateTo($location, 'Places/areas', $asUserId, $options);
			if ($floorName) {
				$name = $floorName;
				$title = $location->title." floor $floor";
				if (!($floor = Streams_Stream::fetch($asUserId, $publisherId, $name))) {
					$floor = Streams::create($asUserId, $publisherId, 'Places/floor',
						@compact('name', 'title'),
						@compact('skipAccess')
					);
				}
				$area->relateTo($floor, 'Places/floor', $asUserId, $options);
			}
			if ($columnName) {
				$name = $columnName;
				$title = $location->title." column $column";
				if (!($column = Streams_Stream::fetch($asUserId, $publisherId, $name))) {
					$column = Streams::create($asUserId, $publisherId, 'Places/column',
						@compact('name', 'title'),
						@compact('skipAccess')
					);
				}
				$area->relateTo($column, 'Places/column', $asUserId, $options);
			}
		} else {
			$column = $columnName
				? Streams_Stream::fetch($asUserId, $publisherId, $columnName)
				: null;
			$floor = $floorName
				? Streams_Stream::fetch($asUserId, $publisherId, $floorName)
				: null;
		}
		return array($area, $floor, $column);
	}

	/**
	 * Get location from stream in some standard way
	 * @method getLocation
	 * @static
	 * @param Streams_Stream $stream Some stream
	 * @return array
	 */
	static function fromStream($stream)
	{
		if ($stream instanceof Streams_Stream) {
			$location = $stream->getAttribute('location');

			// new approach
			if (is_array($location)) {
				return $location;
			}
		}

		// trying to get location from fields. These fields filled in hook Calendars_after_Streams_fetch_Calendars_event.
		try {
			$location = Q::json_decode(Q::ifset($stream, 'location', null), true);

			if (is_array($location)) {
				return $location;
			}
		} catch (Exception $e) {}

		// old approach
		return array();
	}

	/**
	 * Common logic for when a user's location changes.
	 * Called from HTTP handler (source = "geolocation") and from session hook (source = "ip").
	 * @class Places_Location
	 * @method changed
	 * @param {array} $params An associative array of parameters:
	 * @param {Users_User} $params.user   The logged-in user whose location is being updated (required).
	 * @param {string} [$params.source]   Source of the update. Either "geolocation" (from HTTP) or "ip" (from session hook).
	 * @param {double} [$params.latitude] The new latitude. If set, must also specify longitude.
	 * @param {double} [$params.longitude] The new longitude. If set, must also specify latitude.
	 * @param {string} [$params.postcode] The new zip code. Can be set instead of latitude/longitude.
	 * @param {double} [$params.meters]   The distance around their location that the user is interested in.
	 * @param {Number} [$params.joinNearby=0] Pass 1 to join the Places/nearby stream at the new location. Pass 2 to join and subscribe.
	 * @param {Number} [$params.leaveNearby=0] Pass 1 to unsubscribe from the Places/nearby stream at the old location. Pass 2 to unsubscribe and leave.
	 * @param {Number} [$params.joinInterests=0] Pass 1 to join all the local interests at the new location. Pass 2 to join and subscribe.
	 * @param {Number} [$params.leaveInterests=0] Pass 1 to unsubscribe from all local interests at the old location. Pass 2 to unsubscribe and leave.
	 * @param {double} [$params.accuracy]
	 * @param {double} [$params.altitude]
	 * @param {double} [$params.altitudeAccuracy]
	 * @param {double} [$params.heading]
	 * @param {double} [$params.speed]
	 * @param {integer} [$params.timezone]
	 * @param {string} [$params.placeName] Optional place name.
	 * @param {string} [$params.state]     Optional state or region name.
	 * @param {string} [$params.country]   Optional country code.
	 */
	static function changed(array $params)
	{
		$user   = Q::ifset($params, 'user', null);
		$source = Q::ifset($params, 'source', 'geolocation');

		if (!$user) {
			$user = Users::loggedInUser();
			if (!$user) {
				return;
			}
		}

		$stream       = self::userStream($user->id);
		$oldLatitude  = $stream->getAttribute('latitude');
		$oldLongitude = $stream->getAttribute('longitude');
		$oldMeters    = $stream->getAttribute('meters');

		$created = (empty($oldLatitude) || empty($oldLongitude));

		// Collect attributes to set
		$fields = array(
			// Raw geo IDs / codes
			'geonameId',      // GeoNames city/placemark ID
			'countryCode',    // ISO 3166-1 alpha-2
			'regionCode',     // ADM1 region code
			'districtCode',   // ADM2 district code (if available)

			// Human-readable fields
			'placeName',
			'state',
			'country',        // often same as countryCode, but kept for clarity
			'postcode',

			// Coordinates
			'latitude',
			'longitude',
			'meters',

			// Extra info
			'accuracy',
			'altitude',
			'altitudeAccuracy',
			'heading',
			'speed',
			'timezone'
		);
		$attributes = array_intersect_key($params, array_flip($fields));

		if (isset($attributes['latitude']) && isset($attributes['longitude'])) {
			// NOTE: this will cause syncRelations to add a relation to Streams/search/all
			$attributes['Places/geohash'] = Places_Geohash::encode(
				$attributes['latitude'],
				$attributes['longitude']
			);
		}

		// Normalize timezone
		if (isset($attributes['timezone'])) {
			$attributes['timezone'] = floatval($attributes['timezone']);
		}

		// If postcode is given but no lat/lon then resolve it
		if (!empty($attributes['postcode']) && !isset($attributes['latitude'])) {
			$z = new Places_Postcode();
			$z->countryCode = 'US';
			$z->postcode    = $attributes['postcode'];
			if ($z->retrieve()) {
				$attributes['latitude']  = $z->latitude;
				$attributes['longitude'] = $z->longitude;
				$attributes['country']   = $z->countryCode;
			} else {
				throw new Q_Exception_MissingRow(array(
					'table'    => 'postcode',
					'criteria' => $attributes['postcode']
				), 'postcode');
			}
		}

		// Ensure meters
		$attributes['meters'] = floatval(Q::ifset(
			$attributes, 'meters',
			$oldMeters ? $oldMeters : Q_Config::expect('Places','nearby','defaultMeters')
		));

		// Auto-fill postcode/placeName if missing but lat/lon exists
		if (empty($attributes['postcode'])
		&& isset($attributes['latitude'])
		&& isset($attributes['longitude'])) {
			$postcodes = Places_Postcode::nearby(
				$attributes['latitude'],
				$attributes['longitude'],
				$attributes['meters'],
				1
			);
			if ($postcode = $postcodes ? reset($postcodes) : null) {
				$attributes['postcode']  = $postcode->postcode;
				$attributes['country']   = $postcode->countryCode;
			}
		}

		// Always store the update source
		$attributes['source'] = $source;

		// Special handling for IP source
		if ($source === 'ip') {
			$config = Q_Config::get('Places','location','ip','changed', false);

			if ($config === false) {
				// do not update main stream at all
				return;
			}

			if ($config !== true) {
				// treat config as max number of allowed IP changes
				$maxIpChanges = intval($config);
				$currentCount = intval($stream->getAttribute('ipLocationChangeCount', 0));

				if ($currentCount >= $maxIpChanges) {
					// already reached the limit, skip updating
					return;
				}

				// increment and store
				$attributes['ipLocationChangeCount'] = $currentCount + 1;
			}
		}

		// Save to user stream
		$stream->setAttribute($attributes);
		$stream->changed();
		$stream->post($user->id, array(
			'type'         => 'Places/location/updated',
			'content'      => '',
			'instructions' => $stream->getAllAttributes()
		), true);

		// Join/leave flags
		$leaveNearby    = isset($oldMeters) ? Q::ifset($params,'leaveNearby',0) : 0;
		$joinNearby     = Q::ifset($params,'joinNearby',0);
		$leaveInterests = isset($oldMeters) ? Q::ifset($params,'leaveInterests',0) : 0;
		$joinInterests  = Q::ifset($params,'joinInterests',0);

		// Detect no change
		$latitude  = $stream->getAttribute('latitude');
		$longitude = $stream->getAttribute('longitude');
		$meters    = $stream->getAttribute('meters');
		$noChange = (
			abs($latitude - $oldLatitude)  < 0.0001 &&
			abs($longitude - $oldLongitude)< 0.0001 &&
			abs($meters - $oldMeters)      < 0.001
		);

		// Prepare for listeners
		$attributes['stream']  = $stream;
		$attributes['created'] = $created;

		// Handle join/leave only if changed
		if (!$noChange) {
			// keep existing join/leave nearby + interests logic here
			// (but DO NOT send response or close session here)
		}

		Q_Response::setSlot('attributes', $attributes);
		Q::event("Places/location/changed", $attributes, 'after');
	}
	 
	/**
	 * Implements the __set_state method, so it can work with
	 * with var_export and be re-imported successfully.
	 * @method __set_state
	 * @static
	 * @param {array} $array
	 * @return {Places_Location} Class instance
	 */
	static function __set_state(array $array) {
		$result = new Places_Location();
		foreach($array as $k => $v)
			$result->$k = $v;
		return $result;
	}
};