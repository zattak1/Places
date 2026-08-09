<?php

/**
 * @module Places
 */

/**
 * Create or fetch a Places/location stream from a Google placeId and relate it to a category.
 * @class HTTP Places location
 * @method post
 * @param {array} $_REQUEST
 * @param {string} $_REQUEST.placeId Required. Google Places place id.
 * @param {string} $_REQUEST.publisherId Required. Publisher of the location and category streams.
 * @param {string} $_REQUEST.streamName Required. Category stream name to relate to.
 * @param {string} $_REQUEST.type Required. Relation type.
 */
function Places_location_post()
{
	$user = Users::loggedInUser(true);
	Q_Valid::requireFields(array('placeId', 'publisherId', 'streamName', 'type'), $_REQUEST, true);

	$placeId = $_REQUEST['placeId'];
	$publisherId = $_REQUEST['publisherId'];
	$streamName = $_REQUEST['streamName'];
	$type = $_REQUEST['type'];

	$stream = Places_Location::stream($user->id, $publisherId, $placeId, array(
		'throwIfBadValue' => true
	));

	Streams::relate(
		$user->id,
		$publisherId,
		$streamName,
		$type,
		$stream->publisherId,
		$stream->name
	);

	Q_Response::setSlot('stream', array("publisherId" => $stream->publisherId, "streamName" => $stream->name));
}
