# Places Plugin

Geolocation, geographic data, proximity-based subscriptions, venue management, and IP geolocation for the Qbix platform. Places builds on the Streams plugin to turn physical locations into real-time content channels — nearby events, interest-based discovery, venue check-ins, and location-aware notifications all work through the same stream/relation infrastructure.

## Core Concepts

Places solves three problems: knowing where users are (geolocation and IP lookup), organizing content by geography (the "nearby" grid system), and modeling physical spaces (location/area/floor/column hierarchy). All three are backed by Streams, with geographic data stored in the `attributes` JSON field and proximity indexing handled by a grid of quantized `Places/nearby` category streams.

### The Nearby Grid System

The central mechanism is a spatial grid that maps any latitude/longitude pair to a set of `Places/nearby` streams, one per distance tier. The configurable `Places.nearby.meters` array (default: 1km, 5km, 10km, 15km, 20km, 25km, 50km, 75km, 100km) defines the distance tiers.

When someone **publishes** content at a location, the system calls `Places_Nearby::forPublishers()`, which quantizes the coordinates to the enclosing grid cell at each distance tier and returns the corresponding `Places/nearby` stream names. The content stream is then related to all of these category streams.

When someone **subscribes** to content near a location, the system calls `Places_Nearby::forSubscribers()`, which returns the four grid cells that surround the user's position at the chosen distance tier (a 2×2 block). Subscribing to all four ensures that the coverage circle of radius `$meters` is fully contained, regardless of where in the cell the user sits.

Stream names follow the pattern `Places/nearby/{experienceId}/{geohash}/{meters}` — for example, `Places/nearby/main/dr5ru7/50000`. The geohash component provides efficient prefix-based querying.

### Geohashing

The `Places_Geohash` class encodes latitude/longitude into a Base32 string (standard geohash algorithm). Geohashes have the property that nearby locations share a common prefix, making them useful for range queries. The plugin uses 6-character and 12-character geohashes for different precision needs. The `Places_Nearby::geohashRange()` method returns a `Db_Range` for querying geohashes within a radius.

### Quantization

`Places::quantize($latitude, $longitude, $meters)` snaps a coordinate pair to a grid point. The grid spacing is derived from the desired meter radius: latitude spacing = `$meters / (1609.34 × 69.17)`, longitude spacing adjusts for the cosine of latitude. Returns `[$latQuantized, $longQuantized, $latGrid, $longGrid]`.

### User Location

Each user has a personal `Places/user/location` stream (created on registration) that stores their current position in its attributes: `latitude`, `longitude`, `meters`, `timezone`, `postcode`, `placeName`, `state`, `country`, `accuracy`, `altitude`, `heading`, `speed`. The stream is private by default (readLevel 0).

Location updates come from two sources. **Geolocation** — the browser's geolocation API posts to the `Places/geolocation` handler. **IP-based** — on session start/write, the `Places_after_Q_session_write` hook looks up the user's IP in the `ipv4`/`ipv6` tables and updates `Places/user/location` if the configured `Places.location.ip.changed` limit hasn't been reached.

Additional user location streams include `Places/user/location/home`, `Places/user/location/work`, and `Places/user/location/ip`, all related to the `Places/user/locations` category stream.

### Venue Hierarchy

Places models physical spaces as a tree of streams related via typed relations:

`Places/location` — a real-world venue (restaurant, office, park). Keyed to a Google Places `placeId`. Stores latitude, longitude, viewport, address, phone, types, rating, website in attributes.

`Places/area` — a named zone within a location (dining room, patio, VIP section). Related to its parent location via `Places/areas`.

`Places/floor` — a building floor. Areas can relate to a floor via `Places/floor`.

`Places/column` — a building column or section. Areas can relate to columns via `Places/column`.

`Places/table` — an individual table or seating position within an area.

`Places/locations` — a category stream that lists a user's saved locations (home, work, favorites).

### Interests by Location

`Places_Interest` extends the nearby system to handle interest-based discovery. Instead of generic `Places/nearby` streams, it creates `Places/interest/{experienceId}/{geohash}/{meters}/{normalizedTitle}` streams. This lets users subscribe to specific topics ("live music", "pickup basketball") within a geographic radius and receive notifications when new content matching that interest is posted nearby.

### IP Geolocation

The `ipv4` and `ipv6` tables map IP address ranges to geographic locations (geonameId, countryCode, postcode, latitude, longitude). `Places::lookupFromRequest()` determines the client IP from `$_SERVER` and returns the matching row, optionally joined with city, country, and postcode data.

## Geographic Reference Data

### Countries

The `country` table stores all 249 ISO countries/territories with countryCode (2-letter), countryCode3 (3-letter), geonameId, phoneCode, emoji flag, area, population, continent, and currency. Country names are stored in English, local language, and normalized form. The `text/Places/countries/` directory provides localized country names in 50+ languages.

### Cities

The `city` table stores populated places from GeoNames with geonameId, countryCode, English and local names, coordinates, geohash, timezone, population, and featureCode. Cities link to regions and districts via `regionGeonameId` and `districtGeonameId` foreign keys.

### Regions and Districts

`region` — ADM1 administrative divisions (US states, French régions). Keyed by geonameId with countryCode, regionCode, and English/local names.

`district` — ADM2 administrative divisions (US counties, UK districts). Keyed by geonameId with countryCode, regionCode, districtCode.

### Hierarchy

The `hierarchy` table stores parent-child relationships between geographic entities (country → region → district → city → borough) with a `relationType` field for the nature of the relationship.

### Postcodes

The `postcode` table maps (countryCode, postcode) pairs to geonameId, latitude, longitude, and geohash for reverse-geocoding lookups. `Places_Postcode::nearby($lat, $lng, $meters, $limit)` finds the nearest postcodes to a point.

## Google Places Integration

The plugin integrates with the Google Places API for two operations:

**Autocomplete** — `Places::autocomplete($input)` calls the Google Places Autocomplete API and returns prediction objects. Results are cached in the `autocomplete` table with a 30-day TTL. Supported types: establishment, locality, sublocality, postal_code, country, administrative_area_level_1, administrative_area_level_2.

**Place Details** — `Places_Location::stream($asUserId, $publisherId, $placeId)` calls the Google Place Details API and creates or updates a `Places/location` stream with the venue's name, coordinates, viewport, address, phone, types, rating, and website. Also cached with a 30-day TTL.

Both require a server-side Google API key configured at `Places.google.keys.server`.

## Database Schema

### postcode
```
countryCode  varchar(2)    KEY
postcode     varchar(10)   KEY
geonameId    int           NULL
latitude     double        NULL
longitude    double        NULL
geohash      varchar(31)   KEY
```

### city
```
geonameId          int          PK AUTO_INCREMENT
countryCode        varchar(2)   KEY
normalizedName     varchar(180) KEY
englishName        varchar(180)
localName          varchar(180) KEY
regionGeonameId    int          NULL (FK → region)
districtGeonameId  int          NULL (FK → district)
latitude           double
longitude          double
geohash            varchar(31)  KEY
timeZone           varchar(40)
population         int
featureCode        varchar(10)
```

### country
```
countryCode    varchar(2)    PK
countryCode3   varchar(3)
geonameId      int           KEY
numericCode    int
phoneCode      varchar(20)
normalizedName varchar(180)
englishName    varchar(180)
localName      varchar(180)
emojiFlag      varchar(8)
area           bigint        KEY
population     bigint        KEY
continent      varchar(2)    KEY
currencyCode   varchar(3)    KEY
currencyName   varchar(64)
```

### region
```
geonameId    int          PK
countryCode  varchar(2)   (part of UNIQUE byCountryRegion)
regionCode   varchar(20)  (part of UNIQUE byCountryRegion)
englishName  varchar(180)
localName    varchar(180) NULL
```

### district
```
geonameId     int          PK
countryCode   varchar(2)
regionCode    varchar(20)
districtCode  varchar(40)
englishName   varchar(180)
localName     varchar(180) NULL
UNIQUE (countryCode, regionCode, districtCode)
```

### hierarchy
```
parentGeonameId  int      PK
childGeonameId   int      PK
relationType     tinyint
parentType       enum('country','region','district','city','borough')
childType        enum('country','region','district','city','borough')
```

### ipv4
```
ipMin                int unsigned  PK
ipMax                int unsigned  PK
geonameId            int           KEY
countryCode          varchar(2)    KEY
postcode             varchar(20)   KEY
latitude             double
longitude            double
accuracy             int
isAnonymousProxy     tinyint(1)
isSatelliteProvider  tinyint(1)
isAnycast            tinyint(1)
```

### ipv6
Same schema as ipv4, but `ipMin`/`ipMax` are `varbinary(16)`.

### location
```
geohash      varchar(31)    KEY
publisherId  varbinary(31)
streamName   varbinary(255)
insertedTime timestamp
```

### autocomplete
```
query      varchar(127)  PK
types      varchar(31)   PK
latitude   double        PK
longitude  double        PK
meters     double        PK
results    text
insertedTime timestamp
updatedTime  timestamp
```

## Client-Side Tools

`Places/location` — Google Maps integration with marker, search, and geolocation. `Places/user/location` — User location setter with map and address input. `Places/address` — Address autocomplete input using Google Places API. `Places/areas` — Venue area management (floors, columns, tables). `Places/directions` — Driving/walking/biking directions between points. `Places/countries` — Country selector dropdown. `Places/cities` — City selector. `Places/globe` — D3/planetary.js 3D globe visualization. `Places/location/preview` — Compact location preview card.

## Configuration

```json
{
    "Places": {
        "nearby": {
            "meters": [1000, 5000, 10000, 15000, 20000, 25000, 50000, 75000, 100000],
            "defaultMeters": 50000,
            "units": "km"
        },
        "google": {
            "keys": { "server": "...", "web": "..." }
        },
        "location": {
            "cache": { "duration": 2592000 },
            "default": { "latitude": 40.7537, "longitude": -73.9992 },
            "ip": { "changed": 1, "meters": 1000 }
        },
        "geolocation": {
            "requireLogin": false,
            "allowClientQueries": false
        }
    }
}
```

`Places.nearby.meters` — distance tiers for the grid system. All `forSubscribers` calls must use one of these values. `Places.google.keys.server` / `web` — Google API keys for server-side calls and client-side Maps JS. `Places.location.default` — fallback coordinates when user location is unknown. `Places.location.ip.changed` — max number of IP-based location updates (prevents overwriting geolocation data). `Places.location.cache.duration` — TTL in seconds for cached Google API responses.
## Address & Sub-Premise (Unit/Apartment) Lookup

*Added in 1.8.* The Places plugin now supports sub-premise address resolution — looking up individual apartments, suites, and units within a building. This is essential for canvassing, delivery, and any application that needs to know not just the building but the specific unit.

### Architecture

```
Places_Address             ← Global: Loqate Find/Retrieve + caching
    ↓ defers to
Places_NYC                 ← NYC-specific: free open data
    ↓ falls back to
Unit generation            ← BldgClass + YearBuilt → numbering scheme
    ↓ improved by
User corrections           ← "my apartment isn't listed"
```

### Places_Address (Global — Loqate)

The primary interface. Handles any address worldwide via [Loqate](https://www.loqate.com/) (formerly PCA Predict / Addressy).

**Key insight:** Loqate's Find API (autocomplete + container drill-down) is **free**. Only the Retrieve call (full address details) costs 1 credit. So drilling down to see all units in a building costs nothing — you only pay when the user selects their specific unit.

```php
// Autocomplete (FREE)
$results = Places_Address::find("350 W 42nd St");
// → [{ Id: "US|...|A", Type: "Address", Text: "350 W 42nd St..." },
//    { Id: "US|...|C", Type: "BuildingNumber", Text: "350 W 42nd St" }]

// Drill down into a container to see all units (FREE)
$units = Places_Address::find("", ['container' => $containerId]);
// → [{ Id: "...", Type: "Address", Text: "Apt 12B" }, ...]

// Get full details for selected unit (1 CREDIT)
$details = Places_Address::retrieve($addressId);
// → { SubBuilding: "Apt 12B", BuildingNumber: "350", Street: "W 42nd St", ... }

// The main method — handles everything automatically:
$result = Places_Address::units("350 W 42nd St, New York, NY 10036");
// → { units: ["2A","2B",...,"PH1"], source: "cache|nyc|loqate|generated", building: {...} }
```

### Places_NYC (NYC-Specific — Free Open Data)

For NYC addresses, three free APIs provide building metadata and often real unit numbers without any paid service:

**1. GeoSearch** — address autocomplete, returns BBL (Borough/Block/Lot) and BIN.
```
GET https://geosearch.planninglabs.nyc/v2/autocomplete?text=350+w+42
```

**2. PLUTO (dataset `64uk-42ks`)** — building shape: floors, units, building class, year built.
```
GET https://data.cityofnewyork.us/resource/64uk-42ks.json?$where=bbl='1010140001'
```

**3. Property Assessment Roll (dataset `8y4t-faws`)** — real apartment numbers for condos.
```
GET https://data.cityofnewyork.us/resource/8y4t-faws.json
    ?$where=boro='1' AND block='01014' AND aptno IS NOT NULL
```

```php
// NYC-specific autocomplete (free, no key)
$results = Places_NYC::autocomplete("350 w 42");

// Building metadata from PLUTO (free)
$building = Places_NYC::building("1010140001");
// → { floors: 20, unitsRes: 114, bldgClass: "D1", yearBuilt: 1962, ... }

// Units: tries assessment roll first, then generates
$result = Places_NYC::units("350 W 42nd St, New York, NY 10036");
// → { units: [...], source: "assessment_roll"|"generated", scheme: "letters" }
```

### Unit Generation

When real unit numbers aren't available (co-ops, rentals), the plugin generates them from building metadata. The numbering scheme is chosen from `BldgClass` + `YearBuilt`:

| Building Type | BldgClass | Scheme | Example |
|---|---|---|---|
| Walk-up, ≤6 floors | C1–C7 | front/rear | `2F`, `2R`, `2FE`, `2FW` |
| Elevator, pre-1990 | D1–D9 | letters | `12A`, `12B`, `12C` |
| Elevator, post-1990, tall | D1–D9 | four-digit | `1201`, `1202` |
| Small walk-up, ≤3 floors | any | sequential | `1`, `2`, `3` |

**Generation rules:**
- Floor 13 is skipped (absent in most NYC buildings)
- Letter `I` is skipped (reads as `1`)
- Ground floor skipped if building has commercial units (`unitsTotal - unitsRes > 0`)
- Top floor uses `PH` prefix for taller buildings
- Leftover units (from imperfect division) become `PH1`–`PHn`

### Caching & User Corrections

All lookups are cached in the `places_address_unit` table. The first person who looks up a building pays the API cost (usually zero for NYC); everyone after gets instant results.

When a user's apartment isn't in the generated list, they can submit a correction via the "not listed" escape hatch. Corrections are stored as `source = 'user_correction'` and appear for all future lookups of that building.

```php
// User correction
Places_AddressUnit::correct("350 W 42nd St, New York, NY 10036", "14G", "1010140001");
```

### Database

The `address_unit` table (added in schema `1.8-Places.mysql`):

```
id            BIGINT AUTO_INCREMENT PK
address       VARCHAR(500)           — normalized building address
unit          VARCHAR(31)            — unit/apartment number
floor         INT NULL               — parsed floor number
source        ENUM('loqate','assessment_roll','generated','user_correction')
fullData      TEXT NULL               — full Loqate Retrieve response JSON
bbl           VARCHAR(15) NULL        — NYC BBL if known
insertedTime  TIMESTAMP

UNIQUE (address, unit)
INDEX (bbl)
```

### Configuration

```json
{
    "Places": {
        "loqate": {
            "key": "YOUR_LOQATE_API_KEY",
            "endpoint": "https://api.addressy.com/Capture/Interactive/Find/v1.10/json3.ws",
            "retrieveEndpoint": "https://api.addressy.com/Capture/Interactive/Retrieve/v1.2/json3.ws"
        },
        "nyc": {
            "appToken": "OPTIONAL_SOCRATA_APP_TOKEN"
        },
        "address": {
            "provider": "auto"
        }
    }
}
```

`Places.loqate.key` — Loqate API key. Required for non-NYC addresses.
`Places.nyc.appToken` — Optional Socrata app token to avoid throttling on NYC open data APIs. Free to register.
`Places.address.provider` — `"auto"` (try NYC first, fall back to Loqate), `"loqate"` (always use Loqate), `"nyc"` (NYC only).

### API Endpoint

```
GET /Places/address?text=350+w+42          → autocomplete results
GET /Places/address?address=350+W+42nd+St  → unit list for building
POST /Places/address  { address, unit }    → user correction
```
