<?php
// ============================================================
// includes/cities.php — International city recognition
//
// Logika:
//   1. If the coordinates are near (<25 km) a known city from the local
//      list — its prefix is used (fast, no API).
//   2. Kitaip — Google Geocoding API (reverse geocode) randa
//      the nearest city anywhere in the world. The prefix
//      is generated from the city name (3 letters).
//   3. If the API is unreachable — the "GEO" prefix.
//
// IMPORTANT: the Geocoding API must be enabled in the Google Cloud Console
// in the same project as the Maps JavaScript API. If the key
// is restricted by HTTP referrer, create a separate key for server
// requests without referrer restriction (with IP restriction).
// ============================================================

/**
 * Local list of known cities — a fast path without an API request.
 * [prefix, name, lat, lng]
 * You can extend it with your most common locations.
 */
function cityList(): array {
    return [
        // Lietuva
        ['VLN', 'Vilnius',      54.6872, 25.2797],
        ['KNS', 'Kaunas',       54.8985, 23.9036],
        ['KLP', 'Klaipėda',     55.7033, 21.1443],
        ['SLI', 'Šiauliai',     55.9349, 23.3137],
        ['PNV', 'Panevėžys',    55.7348, 24.3575],
        ['ALT', 'Alytus',       54.3963, 24.0459],
        ['MRJ', 'Marijampolė',  54.5560, 23.3540],
        ['UTN', 'Utena',        55.4986, 25.6032],
        ['PLG', 'Palanga',      55.9175, 21.0686],
        ['TRK', 'Trakai',       54.6378, 24.9343],
        ['DRS', 'Druskininkai', 54.0186, 23.9745],
        ['NRG', 'Neringa',      55.3036, 21.0053],
        // Neighboring capitals
        ['RIX', 'Riga',         56.9496, 24.1052],
        ['TLL', 'Tallinn',      59.4370, 24.7536],
        ['WAW', 'Warsaw',       52.2297, 21.0122],
        ['MSQ', 'Minsk',        53.9045, 27.5615],
        // Major European cities
        ['LON', 'London',       51.5074, -0.1278],
        ['PAR', 'Paris',        48.8566,  2.3522],
        ['BER', 'Berlin',       52.5200, 13.4050],
        ['AMS', 'Amsterdam',    52.3676,  4.9041],
        ['STO', 'Stockholm',    59.3293, 18.0686],
        ['HEL', 'Helsinki',     60.1699, 24.9384],
        ['CPH', 'Copenhagen',   55.6761, 12.5683],
        ['OSL', 'Oslo',         59.9139, 10.7522],
    ];
}

/** Maximum distance (km) to a local-list city */
const LOCAL_CITY_MAX_KM = 25;

/**
 * Haversine distance between two coordinates (km).
 */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * asin(sqrt($a));
}

/**
 * Generates a 3-letter prefix from a city name.
 * "São Paulo" → SAO, "New York" → NEW, "København" → KOB
 */
function makePrefix(string $cityName): string {
    // Transliteration to ASCII (ą→a, ü→u, ø→o ...)
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cityName) ?: $cityName;
    // Keep only letters, uppercase
    $letters = strtoupper(preg_replace('/[^a-zA-Z]/', '', $ascii));
    $prefix  = substr($letters, 0, 3);
    return $prefix !== '' ? $prefix : 'GEO';
}

/**
 * Google Geocoding API — the nearest city anywhere in the world.
 * Returns ['city' => ..., 'country' => ...] or null.
 */
function geocodeCity(float $lat, float $lng, ?array &$debug = null): ?array {
    $debug = ['status' => null, 'error' => null];

    if (!defined('GMAPS_API_KEY') || GMAPS_API_KEY === '' || GMAPS_API_KEY === 'YOUR_GOOGLE_MAPS_API_KEY') {
        $debug['error'] = 'GMAPS_API_KEY nenustatytas config.php';
        return null;
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
         . '?latlng=' . $lat . ',' . $lng
         . '&result_type=locality|administrative_area_level_2|administrative_area_level_1'
         . '&language=en'
         . '&key=' . urlencode(GMAPS_API_KEY);

    // curl if available; otherwise file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($url, false, $ctx);
    }

    if (!$json) {
        $debug['error'] = 'HTTP užklausa į Google nepavyko (tinklas/ugniasienė?)';
        return null;
    }
    $data = json_decode($json, true);
    $debug['status'] = $data['status'] ?? 'NO_STATUS';
    if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
        // Most common causes:
        // REQUEST_DENIED  - the key is restricted by HTTP referrer or the Geocoding API is not enabled
        // OVER_QUERY_LIMIT - virsyta kvota
        // ZERO_RESULTS    - a location without a city (ocean, etc.)
        $debug['error'] = $data['error_message'] ?? ('Google statusas: ' . ($data['status'] ?? '?'));
        return null;
    }

    $city = $country = null;
    foreach ($data['results'][0]['address_components'] as $comp) {
        $types = $comp['types'];
        if (!$city && in_array('locality', $types))                          $city = $comp['long_name'];
        if (!$city && in_array('administrative_area_level_2', $types))      $city = $comp['long_name'];
        if (!$city && in_array('administrative_area_level_1', $types))      $city = $comp['long_name'];
        if (in_array('country', $types))                                     $country = $comp['short_name'];
    }

    return $city ? ['city' => $city, 'country' => $country] : null;
}

/**
 * Main function: the nearest city by coordinates.
 * Veikia visame pasaulyje.
 *
 * @return array{prefix: string, city: string, distance_km: float}
 */
/** Maximum fallback distance when the Google API is unreachable */
const FALLBACK_MAX_KM = 50;

function nearestCity(float $lat, float $lng): array {
    // 1. Local list — the fast path
    $best = null;
    $bestDist = PHP_FLOAT_MAX;
    foreach (cityList() as [$prefix, $name, $cLat, $cLng]) {
        $d = haversineKm($lat, $lng, $cLat, $cLng);
        if ($d < $bestDist) {
            $bestDist = $d;
            $best = ['prefix' => $prefix, 'city' => $name, 'distance_km' => round($d, 1)];
        }
    }
    if ($best && $best['distance_km'] <= LOCAL_CITY_MAX_KM) {
        return $best + ['source' => 'local'];
    }

    // 2. Google Geocoding — any place in the world
    $debug = null;
    $geo = geocodeCity($lat, $lng, $debug);
    if ($geo) {
        return [
            'prefix'      => makePrefix($geo['city']),
            'city'        => $geo['city'] . ($geo['country'] ? ", {$geo['country']}" : ''),
            'distance_km' => 0.0,
            'source'      => 'google',
        ];
    }

    // 3. Fallback — the local list ONLY if close enough (<=50 km).
    //    There used to be a bug here: a distant city (e.g. Radun, BY → DRS
    //    Druskininkai 60 km away) got the wrong prefix.
    if ($best && $best['distance_km'] <= FALLBACK_MAX_KM) {
        return $best + ['source' => 'fallback', 'geo_error' => $debug['error'] ?? null];
    }

    // 4. Nothing nearby, API down — an honest GEO prefix + the error cause
    return [
        'prefix'      => 'GEO',
        'city'        => 'Unknown',
        'distance_km' => 0.0,
        'source'      => 'none',
        'geo_error'   => $debug['error'] ?? 'Geocoding API nepasiekiamas',
    ];
}

// ── Capital list for map centering ───────────────────────
// Used by admin.php so the user can choose a country
// and the map automatically centers on its capital.
function capitalList(): array {
    return [
        // code => [name LT/EN, capital, lat, lng, zoom]
        // 195+ sovereign states (193 UN members + Vatican + Palestine,
        // plus Taiwan and Kosovo as de facto states with capitals).
        'LT' => ['Lietuva / Lithuania', 'Vilnius', 54.6872, 25.2797, 12],
        'LV' => ['Latvija / Latvia', 'Riga', 56.9496, 24.1052, 12],
        'EE' => ['Estija / Estonia', 'Tallinn', 59.437, 24.7536, 12],
        'PL' => ['Lenkija / Poland', 'Warsaw', 52.2297, 21.0122, 11],
        'DE' => ['Vokietija / Germany', 'Berlin', 52.52, 13.405, 11],
        'FR' => ['Prancūzija / France', 'Paris', 48.8566, 2.3522, 11],
        'GB' => ['Did. Britanija / UK', 'London', 51.5074, -0.1278, 11],
        'SE' => ['Švedija / Sweden', 'Stockholm', 59.3293, 18.0686, 11],
        'FI' => ['Suomija / Finland', 'Helsinki', 60.1699, 24.9384, 11],
        'NO' => ['Norvegija / Norway', 'Oslo', 59.9139, 10.7522, 11],
        'DK' => ['Danija / Denmark', 'Copenhagen', 55.6761, 12.5683, 11],
        'NL' => ['Nyderlandai / Netherlands', 'Amsterdam', 52.3676, 4.9041, 11],
        'ES' => ['Ispanija / Spain', 'Madrid', 40.4168, -3.7038, 11],
        'IT' => ['Italija / Italy', 'Rome', 41.9028, 12.4964, 11],
        'US' => ['JAV / USA', 'Washington', 38.9072, -77.0369, 11],
        'CA' => ['Kanada / Canada', 'Ottawa', 45.4215, -75.6972, 11],
        'AU' => ['Australija / Australia', 'Canberra', -35.2809, 149.13, 11],
        'JP' => ['Japonija / Japan', 'Tokyo', 35.6762, 139.6503, 11],
        'AT' => ['Austrija / Austria', 'Vienna', 48.2082, 16.3738, 11],
        'BE' => ['Belgija / Belgium', 'Brussels', 50.8503, 4.3517, 11],
        'CH' => ['Šveicarija / Switzerland', 'Bern', 46.948, 7.4474, 12],
        'PT' => ['Portugalija / Portugal', 'Lisbon', 38.7223, -9.1393, 11],
        'IE' => ['Airija / Ireland', 'Dublin', 53.3498, -6.2603, 11],
        'GR' => ['Graikija / Greece', 'Athens', 37.9838, 23.7275, 11],
        'CZ' => ['Čekija / Czechia', 'Prague', 50.0755, 14.4378, 11],
        'SK' => ['Slovakija / Slovakia', 'Bratislava', 48.1486, 17.1077, 11],
        'HU' => ['Vengrija / Hungary', 'Budapest', 47.4979, 19.0402, 11],
        'RO' => ['Rumunija / Romania', 'Bucharest', 44.4268, 26.1025, 11],
        'BG' => ['Bulgarija / Bulgaria', 'Sofia', 42.6977, 23.3219, 11],
        'HR' => ['Kroatija / Croatia', 'Zagreb', 45.815, 15.9819, 11],
        'SI' => ['Slovėnija / Slovenia', 'Ljubljana', 46.0569, 14.5058, 12],
        'RS' => ['Serbija / Serbia', 'Belgrade', 44.7866, 20.4489, 11],
        'BA' => ['Bosnija ir Hercegovina / Bosnia', 'Sarajevo', 43.8563, 18.4131, 12],
        'ME' => ['Juodkalnija / Montenegro', 'Podgorica', 42.4304, 19.2594, 12],
        'MK' => ['Šiaurės Makedonija / North Macedonia', 'Skopje', 41.9981, 21.4254, 12],
        'AL' => ['Albanija / Albania', 'Tirana', 41.3275, 19.8187, 12],
        'XK' => ['Kosovas / Kosovo', 'Pristina', 42.6629, 21.1655, 12],
        'MD' => ['Moldova / Moldova', 'Chisinau', 47.0105, 28.8638, 12],
        'UA' => ['Ukraina / Ukraine', 'Kyiv', 50.4501, 30.5234, 11],
        'BY' => ['Baltarusija / Belarus', 'Minsk', 53.9006, 27.559, 11],
        'RU' => ['Rusija / Russia', 'Moscow', 55.7558, 37.6173, 10],
        'IS' => ['Islandija / Iceland', 'Reykjavik', 64.1466, -21.9426, 12],
        'LU' => ['Liuksemburgas / Luxembourg', 'Luxembourg', 49.6116, 6.1319, 12],
        'MT' => ['Malta / Malta', 'Valletta', 35.8989, 14.5146, 13],
        'CY' => ['Kipras / Cyprus', 'Nicosia', 35.1856, 33.3823, 12],
        'AD' => ['Andora / Andorra', 'Andorra la Vella', 42.5063, 1.5218, 13],
        'MC' => ['Monakas / Monaco', 'Monaco', 43.7384, 7.4246, 14],
        'SM' => ['San Marinas / San Marino', 'San Marino', 43.9424, 12.4578, 13],
        'VA' => ['Vatikanas / Vatican', 'Vatican City', 41.9029, 12.4534, 14],
        'LI' => ['Lichtenšteinas / Liechtenstein', 'Vaduz', 47.141, 9.5209, 13],
        'CN' => ['Kinija / China', 'Beijing', 39.9042, 116.4074, 10],
        'IN' => ['Indija / India', 'New Delhi', 28.6139, 77.209, 11],
        'KR' => ['Pietų Korėja / South Korea', 'Seoul', 37.5665, 126.978, 11],
        'KP' => ['Šiaurės Korėja / North Korea', 'Pyongyang', 39.0392, 125.7625, 11],
        'ID' => ['Indonezija / Indonesia', 'Jakarta', -6.2088, 106.8456, 11],
        'TH' => ['Tailandas / Thailand', 'Bangkok', 13.7563, 100.5018, 11],
        'VN' => ['Vietnamas / Vietnam', 'Hanoi', 21.0285, 105.8542, 11],
        'PH' => ['Filipinai / Philippines', 'Manila', 14.5995, 120.9842, 11],
        'MY' => ['Malaizija / Malaysia', 'Kuala Lumpur', 3.139, 101.6869, 11],
        'SG' => ['Singapūras / Singapore', 'Singapore', 1.3521, 103.8198, 12],
        'MM' => ['Mianmaras / Myanmar', 'Naypyidaw', 19.7633, 96.0785, 11],
        'KH' => ['Kambodža / Cambodia', 'Phnom Penh', 11.5564, 104.9282, 12],
        'LA' => ['Laosas / Laos', 'Vientiane', 17.9757, 102.6331, 12],
        'BD' => ['Bangladešas / Bangladesh', 'Dhaka', 23.8103, 90.4125, 11],
        'PK' => ['Pakistanas / Pakistan', 'Islamabad', 33.6844, 73.0479, 11],
        'LK' => ['Šri Lanka / Sri Lanka', 'Sri Jayawardenepura Kotte', 6.9271, 79.8612, 12],
        'NP' => ['Nepalas / Nepal', 'Kathmandu', 27.7172, 85.324, 12],
        'BT' => ['Butanas / Bhutan', 'Thimphu', 27.4728, 89.639, 12],
        'MV' => ['Maldyvai / Maldives', 'Male', 4.1755, 73.5093, 13],
        'AF' => ['Afganistanas / Afghanistan', 'Kabul', 34.5553, 69.2075, 11],
        'KZ' => ['Kazachstanas / Kazakhstan', 'Astana', 51.1605, 71.4704, 11],
        'UZ' => ['Uzbekistanas / Uzbekistan', 'Tashkent', 41.2995, 69.2401, 11],
        'TM' => ['Turkmėnistanas / Turkmenistan', 'Ashgabat', 37.9601, 58.3261, 12],
        'KG' => ['Kirgizija / Kyrgyzstan', 'Bishkek', 42.8746, 74.5698, 12],
        'TJ' => ['Tadžikistanas / Tajikistan', 'Dushanbe', 38.5598, 68.787, 12],
        'MN' => ['Mongolija / Mongolia', 'Ulaanbaatar', 47.8864, 106.9057, 11],
        'TW' => ['Taivanas / Taiwan', 'Taipei', 25.033, 121.5654, 11],
        'BN' => ['Brunėjus / Brunei', 'Bandar Seri Begawan', 4.9031, 114.9398, 12],
        'TL' => ['Rytų Timoras / Timor-Leste', 'Dili', -8.5569, 125.5603, 12],
        'TR' => ['Turkija / Turkey', 'Ankara', 39.9334, 32.8597, 11],
        'SA' => ['Saudo Arabija / Saudi Arabia', 'Riyadh', 24.7136, 46.6753, 11],
        'AE' => ['JAE / UAE', 'Abu Dhabi', 24.4539, 54.3773, 11],
        'IL' => ['Izraelis / Israel', 'Jerusalem', 31.7683, 35.2137, 12],
        'PS' => ['Palestina / Palestine', 'Ramallah', 31.9038, 35.2034, 12],
        'JO' => ['Jordanija / Jordan', 'Amman', 31.9454, 35.9284, 12],
        'LB' => ['Libanas / Lebanon', 'Beirut', 33.8938, 35.5018, 12],
        'SY' => ['Sirija / Syria', 'Damascus', 33.5138, 36.2765, 11],
        'IQ' => ['Irakas / Iraq', 'Baghdad', 33.3152, 44.3661, 11],
        'IR' => ['Iranas / Iran', 'Tehran', 35.6892, 51.389, 11],
        'KW' => ['Kuveitas / Kuwait', 'Kuwait City', 29.3759, 47.9774, 12],
        'QA' => ['Kataras / Qatar', 'Doha', 25.2854, 51.531, 12],
        'BH' => ['Bahreinas / Bahrain', 'Manama', 26.2285, 50.586, 13],
        'OM' => ['Omanas / Oman', 'Muscat', 23.588, 58.3829, 11],
        'YE' => ['Jemenas / Yemen', 'Sanaa', 15.3694, 44.191, 11],
        'GE' => ['Gruzija / Georgia', 'Tbilisi', 41.7151, 44.8271, 11],
        'AM' => ['Armėnija / Armenia', 'Yerevan', 40.1792, 44.4991, 12],
        'AZ' => ['Azerbaidžanas / Azerbaijan', 'Baku', 40.4093, 49.8671, 11],
        'EG' => ['Egiptas / Egypt', 'Cairo', 30.0444, 31.2357, 11],
        'ZA' => ['PAR / South Africa', 'Pretoria', -25.7479, 28.2293, 11],
        'NG' => ['Nigerija / Nigeria', 'Abuja', 9.0765, 7.3986, 11],
        'KE' => ['Kenija / Kenya', 'Nairobi', -1.2921, 36.8219, 11],
        'ET' => ['Etiopija / Ethiopia', 'Addis Ababa', 9.032, 38.7469, 11],
        'GH' => ['Gana / Ghana', 'Accra', 5.6037, -0.187, 11],
        'TZ' => ['Tanzanija / Tanzania', 'Dodoma', -6.163, 35.7516, 11],
        'UG' => ['Uganda / Uganda', 'Kampala', 0.3476, 32.5825, 11],
        'DZ' => ['Alžyras / Algeria', 'Algiers', 36.7538, 3.0588, 11],
        'MA' => ['Marokas / Morocco', 'Rabat', 34.0209, -6.8416, 11],
        'TN' => ['Tunisas / Tunisia', 'Tunis', 36.8065, 10.1815, 11],
        'LY' => ['Libija / Libya', 'Tripoli', 32.8872, 13.1913, 11],
        'SD' => ['Sudanas / Sudan', 'Khartoum', 15.5007, 32.5599, 11],
        'SS' => ['Pietų Sudanas / South Sudan', 'Juba', 4.8594, 31.5713, 12],
        'SN' => ['Senegalas / Senegal', 'Dakar', 14.7167, -17.4677, 12],
        'CI' => ['Dramblio Kaulo Krantas / Ivory Coast', 'Yamoussoukro', 6.8276, -5.2893, 12],
        'CM' => ['Kamerūnas / Cameroon', 'Yaounde', 3.848, 11.5021, 12],
        'CD' => ['Kongo DR / DR Congo', 'Kinshasa', -4.4419, 15.2663, 11],
        'CG' => ['Kongas / Congo', 'Brazzaville', -4.2634, 15.2429, 12],
        'AO' => ['Angola / Angola', 'Luanda', -8.839, 13.2894, 11],
        'MZ' => ['Mozambikas / Mozambique', 'Maputo', -25.9692, 32.5732, 11],
        'ZW' => ['Zimbabvė / Zimbabwe', 'Harare', -17.8252, 31.0335, 11],
        'ZM' => ['Zambija / Zambia', 'Lusaka', -15.3875, 28.3228, 11],
        'MW' => ['Malavis / Malawi', 'Lilongwe', -13.9626, 33.7741, 12],
        'RW' => ['Ruanda / Rwanda', 'Kigali', -1.9441, 30.0619, 12],
        'BI' => ['Burundis / Burundi', 'Gitega', -3.4264, 29.9306, 12],
        'SO' => ['Somalis / Somalia', 'Mogadishu', 2.0469, 45.3182, 11],
        'DJ' => ['Džibutis / Djibouti', 'Djibouti', 11.5721, 43.1456, 12],
        'ER' => ['Eritrėja / Eritrea', 'Asmara', 15.3229, 38.9251, 12],
        'ML' => ['Malis / Mali', 'Bamako', 12.6392, -8.0029, 12],
        'BF' => ['Burkina Fasas / Burkina Faso', 'Ouagadougou', 12.3714, -1.5197, 12],
        'NE' => ['Nigeris / Niger', 'Niamey', 13.5117, 2.1251, 12],
        'TD' => ['Čadas / Chad', 'N\'Djamena', 12.1348, 15.0557, 12],
        'MR' => ['Mauritanija / Mauritania', 'Nouakchott', 18.0735, -15.9582, 12],
        'GN' => ['Gvinėja / Guinea', 'Conakry', 9.6412, -13.5784, 12],
        'BJ' => ['Beninas / Benin', 'Porto-Novo', 6.4969, 2.6283, 12],
        'TG' => ['Togas / Togo', 'Lome', 6.1256, 1.2254, 12],
        'SL' => ['Siera Leonė / Sierra Leone', 'Freetown', 8.4657, -13.2317, 12],
        'LR' => ['Liberija / Liberia', 'Monrovia', 6.2907, -10.7605, 12],
        'GM' => ['Gambija / Gambia', 'Banjul', 13.4549, -16.579, 12],
        'GW' => ['Bisau Gvinėja / Guinea-Bissau', 'Bissau', 11.8817, -15.6178, 12],
        'GA' => ['Gabonas / Gabon', 'Libreville', 0.4162, 9.4673, 12],
        'GQ' => ['Pusiaujo Gvinėja / Eq. Guinea', 'Malabo', 3.7523, 8.7742, 12],
        'CF' => ['CAR / Central African Rep.', 'Bangui', 4.3947, 18.5582, 12],
        'BW' => ['Botsvana / Botswana', 'Gaborone', -24.6282, 25.9231, 11],
        'NA' => ['Namibija / Namibia', 'Windhoek', -22.5609, 17.0658, 11],
        'LS' => ['Lesotas / Lesotho', 'Maseru', -29.3151, 27.4869, 12],
        'SZ' => ['Esvatinis / Eswatini', 'Mbabane', -26.3054, 31.1367, 12],
        'MG' => ['Madagaskaras / Madagascar', 'Antananarivo', -18.8792, 47.5079, 11],
        'MU' => ['Mauricijus / Mauritius', 'Port Louis', -20.1609, 57.5012, 12],
        'SC' => ['Seišeliai / Seychelles', 'Victoria', -4.6191, 55.4513, 13],
        'KM' => ['Komorai / Comoros', 'Moroni', -11.7172, 43.2473, 12],
        'CV' => ['Žaliasis Kyšulys / Cape Verde', 'Praia', 14.933, -23.5133, 12],
        'ST' => ['San Tomė ir Prinsipė / Sao Tome', 'Sao Tome', 0.3302, 6.7333, 13],
        'MX' => ['Meksika / Mexico', 'Mexico City', 19.4326, -99.1332, 11],
        'GT' => ['Gvatemala / Guatemala', 'Guatemala City', 14.6349, -90.5069, 11],
        'CU' => ['Kuba / Cuba', 'Havana', 23.1136, -82.3666, 11],
        'HT' => ['Haitis / Haiti', 'Port-au-Prince', 18.5944, -72.3074, 12],
        'DO' => ['Dominikos Resp. / Dominican Rep.', 'Santo Domingo', 18.4861, -69.9312, 11],
        'HN' => ['Hondūras / Honduras', 'Tegucigalpa', 14.0723, -87.1921, 12],
        'NI' => ['Nikaragva / Nicaragua', 'Managua', 12.1149, -86.2362, 12],
        'SV' => ['Salvadoras / El Salvador', 'San Salvador', 13.6929, -89.2182, 12],
        'CR' => ['Kosta Rika / Costa Rica', 'San Jose', 9.9281, -84.0907, 12],
        'PA' => ['Panama / Panama', 'Panama City', 8.9824, -79.5199, 11],
        'BZ' => ['Belizas / Belize', 'Belmopan', 17.2514, -88.7705, 12],
        'JM' => ['Jamaika / Jamaica', 'Kingston', 17.9712, -76.7936, 12],
        'TT' => ['Trinidadas ir Tobagas / Trinidad', 'Port of Spain', 10.6596, -61.5086, 12],
        'BS' => ['Bahamai / Bahamas', 'Nassau', 25.0443, -77.3504, 12],
        'BB' => ['Barbadosas / Barbados', 'Bridgetown', 13.1132, -59.5988, 13],
        'LC' => ['Sent Lusija / Saint Lucia', 'Castries', 14.0101, -60.9875, 13],
        'GD' => ['Grenada / Grenada', 'St. George\'s', 12.0561, -61.7488, 13],
        'VC' => ['Sent Vinsentas / St. Vincent', 'Kingstown', 13.16, -61.2248, 13],
        'AG' => ['Antigva ir Barbuda / Antigua', 'Saint John\'s', 17.1274, -61.8468, 13],
        'DM' => ['Dominika / Dominica', 'Roseau', 15.3092, -61.379, 13],
        'KN' => ['Sent Kitsas ir Nevis / St. Kitts', 'Basseterre', 17.3026, -62.7177, 13],
        'BR' => ['Brazilija / Brazil', 'Brasilia', -15.7975, -47.8919, 11],
        'AR' => ['Argentina / Argentina', 'Buenos Aires', -34.6037, -58.3816, 11],
        'CO' => ['Kolumbija / Colombia', 'Bogota', 4.711, -74.0721, 11],
        'PE' => ['Peru / Peru', 'Lima', -12.0464, -77.0428, 11],
        'VE' => ['Venesuela / Venezuela', 'Caracas', 10.4806, -66.9036, 11],
        'CL' => ['Čilė / Chile', 'Santiago', -33.4489, -70.6693, 11],
        'EC' => ['Ekvadoras / Ecuador', 'Quito', -0.1807, -78.4678, 11],
        'BO' => ['Bolivija / Bolivia', 'La Paz', -16.4897, -68.1193, 11],
        'PY' => ['Paragvajus / Paraguay', 'Asuncion', -25.2637, -57.5759, 11],
        'UY' => ['Urugvajus / Uruguay', 'Montevideo', -34.9011, -56.1645, 11],
        'GY' => ['Gajana / Guyana', 'Georgetown', 6.8013, -58.1551, 12],
        'SR' => ['Surinamas / Suriname', 'Paramaribo', 5.852, -55.2038, 12],
        'NZ' => ['Naujoji Zelandija / New Zealand', 'Wellington', -41.2865, 174.7762, 11],
        'PG' => ['Papua N. Gvinėja / Papua New Guinea', 'Port Moresby', -9.4438, 147.1803, 11],
        'FJ' => ['Fidžis / Fiji', 'Suva', -18.1416, 178.4419, 12],
        'SB' => ['Saliamono salos / Solomon Is.', 'Honiara', -9.4456, 159.9729, 12],
        'VU' => ['Vanuatu / Vanuatu', 'Port Vila', -17.7334, 168.322, 12],
        'WS' => ['Samoa / Samoa', 'Apia', -13.8506, -171.7513, 12],
        'TO' => ['Tonga / Tonga', 'Nukualofa', -21.1393, -175.2046, 12],
        'KI' => ['Kiribatis / Kiribati', 'Tarawa', 1.329, 172.979, 12],
        'FM' => ['Mikronezija / Micronesia', 'Palikir', 6.9248, 158.1611, 12],
        'MH' => ['Maršalo salos / Marshall Is.', 'Majuro', 7.1164, 171.1858, 12],
        'PW' => ['Palau / Palau', 'Ngerulmud', 7.5006, 134.6242, 13],
        'NR' => ['Nauru / Nauru', 'Yaren', -0.5477, 166.9209, 13],
        'TV' => ['Tuvalu / Tuvalu', 'Funafuti', -8.5211, 179.1962, 13],
    ];
}

/**
 * Lithuanian capital names (only those that differ from the
 * international/English variant). Used for the bilingual country/city
 * selector in the admin UI. If a capital is not here — the Lithuanian and
 * English names coincide (e.g. Vilnius, Riga, Tokyo), so
 * vertimo nereikia.
 *
 * @param string $cityEn English/international capital name
 * @return string The Lithuanian name, or the same $cityEn if it does not differ
 */
function capitalNameLt(string $cityEn): string {
    static $map = [
        'Athens' => 'Atėnai', 'Belgrade' => 'Belgradas', 'Berlin' => 'Berlynas',
        'Bern' => 'Bernas', 'Bratislava' => 'Bratislava', 'Brussels' => 'Briuselis',
        'Bucharest' => 'Bukareštas', 'Budapest' => 'Budapeštas', 'Cairo' => 'Kairas',
        'Copenhagen' => 'Kopenhaga', 'Dublin' => 'Dublinas', 'Helsinki' => 'Helsinkis',
        'Jerusalem' => 'Jeruzalė', 'Kyiv' => 'Kyjivas', 'Lisbon' => 'Lisabona',
        'Ljubljana' => 'Liubliana', 'London' => 'Londonas', 'Luxembourg' => 'Liuksemburgas',
        'Madrid' => 'Madridas', 'Minsk' => 'Minskas', 'Monaco' => 'Monakas',
        'Moscow' => 'Maskva', 'Nicosia' => 'Nikozija', 'Oslo' => 'Oslas',
        'Paris' => 'Paryžius', 'Prague' => 'Praha', 'Pristina' => 'Priština',
        'Reykjavik' => 'Reikjavikas', 'Rome' => 'Roma', 'Sarajevo' => 'Sarajevas',
        'Skopje' => 'Skopjė', 'Sofia' => 'Sofija', 'Stockholm' => 'Stokholmas',
        'Tallinn' => 'Talinas', 'Tirana' => 'Tirana', 'Vienna' => 'Viena',
        'Vatican City' => 'Vatikanas', 'Warsaw' => 'Varšuva', 'Zagreb' => 'Zagrebas',
        'Amsterdam' => 'Amsterdamas', 'Beijing' => 'Pekinas', 'Tokyo' => 'Tokijas',
        'Washington' => 'Vašingtonas', 'New Delhi' => 'Naujasis Delis', 'Tehran' => 'Teheranas',
        'Baghdad' => 'Bagdadas', 'Damascus' => 'Damaskas', 'Beirut' => 'Beirutas',
        'Ankara' => 'Ankara', 'Tunis' => 'Tunisas',
        'Tripoli' => 'Tripolis', 'Algiers' => 'Alžyras', 'Rabat' => 'Rabatas',
        'Mexico City' => 'Meksikas', 'Havana' => 'Havana', 'Lima' => 'Lima',
        'Bogota' => 'Bogota', 'Santiago' => 'Santjagas', 'Buenos Aires' => 'Buenos Airės',
        'Brasilia' => 'Brazilija', 'Canberra' => 'Kanbera', 'Wellington' => 'Velingtonas',
        'Singapore' => 'Singapūras', 'Bangkok' => 'Bankokas', 'Hanoi' => 'Hanojus',
        'Manila' => 'Manila', 'Jakarta' => 'Džakarta', 'Seoul' => 'Seulas',
        'Pyongyang' => 'Pchenjanas', 'Kuala Lumpur' => 'Kvala Lumpūras', 'Tbilisi' => 'Tbilisis',
        'Yerevan' => 'Jerevanas', 'Baku' => 'Baku', 'Astana' => 'Astana',
        'Tashkent' => 'Taškentas', 'Kabul' => 'Kabulas', 'Islamabad' => 'Islamabadas',
        'Dhaka' => 'Daka', 'Kathmandu' => 'Katmandu', 'Nairobi' => 'Nairobis',
        'Addis Ababa' => 'Adis Abeba', 'Khartoum' => 'Chartumas', 'Pretoria' => 'Pretorija',
        'Ottawa' => 'Otava', 'Riyadh' => 'Rijadas', 'Amman' => 'Amanas',
        'Doha' => 'Doha', 'Kuwait City' => 'Kuveitas', 'Manama' => 'Manama',
        'Muscat' => 'Maskatas', 'Sanaa' => 'Sana',
    ];
    return $map[$cityEn] ?? $cityEn;
}

// Returns the capital coordinates by country code (or Vilnius by default).
function capitalCoords(string $countryCode): array {
    $list = capitalList();
    $c = $list[$countryCode] ?? $list['LT'];
    return ['lat' => $c[2], 'lng' => $c[3], 'zoom' => $c[4], 'city' => $c[1]];
}
