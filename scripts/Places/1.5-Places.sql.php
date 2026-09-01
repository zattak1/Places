<?php

function Places_1_4_Places()
{
	$countriesFile = PLACES_PLUGIN_TEXT_DIR . DS . 'Places' . DS . 'countries' . DS . 'localize.json';
	$countries = json_decode(file_get_contents($countriesFile), true);
	if (!$countries) {
		throw new RuntimeException("Could not load countries.json");
	}

	$chunkSize = 10;
	$total     = 0;

	foreach (array_keys($countries) as $countryCode) {
		$lastPostcode = null;

		do {
			// Keyset pagination: fetch next chunk for this country
			$select = Places_Postcode::select()
				->where(array('countryCode' => $countryCode))
				->orderBy('postcode')
                ->caching(false)
				->limit($chunkSize);

			if ($lastPostcode !== null) {
				$select->where(array(
                    'postcode' => new Db_Range($lastPostcode, false, true, null)
                ));
			}

			$rows = $select->fetchAll(PDO::FETCH_ASSOC);
			if (!$rows) {
				break;
			}

			$updates = [];
            $postcodes = [];
			foreach ($rows as $row) {
				if (!isset($row['latitude']) || !isset($row['longitude'])) {
					continue;
				}
				$geohash = Places_Geohash::encode($row['latitude'], $row['longitude'], 12);
				$updates[$row['postcode']] = $geohash;
                $postcodes[] = $row['postcode'];
			}

			if ($updates) {
				Places_Postcode::update()
					->basedOn(['geohash' => 'postcode'])
					->set(['geohash' => $updates])
					->where([
						'countryCode' => $countryCode,
						'postcode'    => $postcodes
					])
					->execute();
			}

			// advance cursor
            $row = end($rows);
			$lastPostcode = $row['postcode'];
			$total += count($rows);

			echo "Processed $total rows so far (up to $countryCode $lastPostcode)\n";

		} while (true);
	}

	echo "Finished updating geohash for $total postcodes\n";
}

Places_1_4_Places();
