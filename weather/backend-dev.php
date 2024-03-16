<?php

namespace Backend {

//-------------------------------------
//          Initialization
//-------------------------------------
#region initialization

// Temporarily set the PHP environment to use UTC
$defaultTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');

// Make sure the logs directory exists and is writable
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Set the PHP error log to a file in the logs directory
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');


$route = isset($_GET['route']) ? $_GET['route'] : '';
$version = isset($_GET['version']) ? $_GET['version'] : '';

// Determine the requested response format
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? 'application/json'; // Default to JSON if no Accept header is present
$responseFormat = 'json'; // Default response format

if (strpos($acceptHeader, 'text/html____________________________________________') !== false) { //todo
    $responseFormat = 'html';
} elseif (strpos($acceptHeader, 'application/json') !== false) {
    $responseFormat = 'json';
}


$cityId = filter_input(INPUT_GET, 'cityId', FILTER_VALIDATE_INT);
if ($cityId === false) {
    // Handle invalid cityId
    echo json_encode(['error' => 'Invalid city ID. Please provide a valid integer.']);
    exit;
}
#endregion initialization


//-------------------------------------
//          Configuration
//-------------------------------------
#region configuration

$envFilePath = '.env';
if (file_exists($envFilePath)) {
    $dotenv = parse_ini_file($envFilePath);
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => '.env file not found.']);
    exit;
}

define('CACHE_LIFETIME', 602); // Cache lifetime in seconds
define('CACHE_DIRECTORY', __DIR__ . '/cache/');

define('OPENWEATHERMAP_API_KEY', $dotenv["OPENWEATHERMAP_APIKEY"]);
define('OPENWEATHERMAP_BASE_URL', 'http://api.openweathermap.org/data/2.5/');

define('OPENROUTERAI_API_KEY', $dotenv["OPENROUTERAI_APIKEY"]);
define('OPENROUTERAI_BASE_URL', 'https://openrouter.ai/api/v1');
define('OPENROUTERAI_MODEL', 'anthropic/claude-3-haiku:beta');

define('DAYS_BEFORE_WEEKEND', 4);
define('WEEKEND_DAY_START_HOUR', 8);
define('WEEKEND_DAY_END_HOUR', 19);
//define('WEEKEND_START_HOUR', 18);
//define('WEEKEND_END_HOUR', 19);


define('WEEKEND_COUNTDOWN_START_DAY', 'Wednesday'); // Assuming the countdown starts on Wednesday
define('WEEKEND_START_DAY', 'Friday');
define('WEEKEND_START_HOUR', 18); // 6 PM, marking the start of weekend evening
define('WEEKEND_END_DAY', 'Sunday');
define('WEEKEND_END_HOUR', 21); // 9 PM, marking the end of the weekend's evening
define('DAYTIME_START_HOUR', 8); // 8 AM
define('DAYTIME_END_HOUR', 19); // 7 PM


#endregion configuration


//-------------------------------------
//          Router
//-------------------------------------
// map route to handler functions
#region simplerouter
if ($version === 'v1') {
    switch ($route) {
        case 'spec':
            handleSpec($version);
            break;

        case 'current':
            $citiesId = $_GET['citiesId'] ?? 'unknown';
            handleCurrentWeather($citiesId, 'group');
            break; 

        case 'admin_clear-cache':
            // Ensure this endpoint is protected by authentication or similar
            handleClearCache();
            break;

        default:
            // Handle 404 Not Found for unknown routes
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Route not found']);
            break;
    }
} elseif ($version === 'v0.9') {
    switch ($route) {
        case 'spec':
            handleSpec($version);
            break;

        default:
            // Handle 404 Not Found for unknown routes
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Route not found']);
            break;
    }
} elseif ($version === 'v1.9') {
    switch ($route) {
        case 'spec':
            handleSpec($version);
            break;

        case 'current':
            v2HandleCurrentWeather($cityId, $responseFormat);
            break;  

        case 'forecast':
            // Assuming cityId is passed as a query parameter
            v2HandleForecast($cityId);
            break;

        case 'admin_clear-cache':
            // Ensure this endpoint is protected by authentication or similar
            handleClearCache();
            break;

        case 'weekend-activities':
            v2HandleWeekendActivities($cityId);
            break;

        case 'preview':
            v2HandleCurrentWeather($cityId, $responseFormat);
            break;

        case 'preview2':
            //v2CalculateWeekendBoundaries
            $result = v2FetchWeatherData($cityId, 'weather');
            $weatherResponse = new OpenWeatherMap\v2_5\WeatherResponse($result);
            $locationCharacteristics = transformExternalToInternalData($weatherResponse);

            //$result2 = v2FetchWeatherData($cityId, 'forecast');
            //$forecastResponse = new OpenWeatherMap\v2_5\ForecastResponse($result2);

            $result3 = v2UpcomingWeekendWeather($cityId, $locationCharacteristics->weekend->begin, $locationCharacteristics->weekend->end);
            //$result2 = v2UpcomingWeekendWeather($cityId, 1711130400, 1711312790);
            echo json_encode(['begin' => $locationCharacteristics->weekend->begin, 'end' => $locationCharacteristics->weekend->end, 'weekend' => $result3, 'locationCharacteristics' => $locationCharacteristics]);
            break;

        default:
            // Handle 404 Not Found for unknown routes
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Route not found']);
            break;
    }
} else {
    // Handle version not supported
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'API version not supported']);
}
#endregion simplerouter


//-------------------------------------
//          Handlers
//-------------------------------------
// call fetchers, process, end with setting HTTP header Content-Type and echo json encoded payload
// todo: unified response structure incl error
#region handlerfunctions
/**
 * Serves the OpenAPI specification for the API based on the requested version.
 * This function looks for a JSON specification file matching the API version,
 * sets the appropriate HTTP content type, and outputs the spec.
 * If the spec file for the requested version is not found, it responds with a 404 Not Found status.
 *
 * Used By:
 * - API v0.9
 * - API v1
 * - API v1.9
 * 
 * @param string $version The requested API version, used to locate the corresponding spec file.
 */
function handleSpec($version) {
    // Define the base path to your API specs
    $basePath = './assets/api_specs/';
    
    // Construct the file path based on the version
    $filePath = $basePath . $version . '/openapi_spec.json';

    // Check if the spec file exists
    if (file_exists($filePath)) {
        // Load and decode the JSON content of the spec file
        $specContent = json_decode(file_get_contents($filePath), true);
        
        // Set the content type to application/json
        header('Content-Type: application/json');
        
        // Echo the JSON encoded content
        echo json_encode($specContent);
    } else {
        // Handle cases where the spec file does not exist or the version is not supported
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => "API spec for version $version not found."]);
    }
}

/**
 * 
 * Used By:
 * - API v1.9
 */
function v2HandleCurrentWeather($cityId, $format = 'json') {

    $result = v2FetchWeatherData($cityId, 'weather');
    $weatherResponse = new OpenWeatherMap\v2_5\WeatherResponse($result);
    $locationCharacteristics = transformExternalToInternalData($weatherResponse);

    $response = generateSuccessResponse($locationCharacteristics, null, $format);
    sendResponse($response);

}

/**
 * 
 * Used By:
 * - API v1.9
 */
function v2HandleForecast($cityId, $format = 'json') {
    $data = v2FetchWeatherData($cityId, 'forecast');

    $response = generateSuccessResponse($data, null, $format);
    sendResponse($response);
}

/**
 * 
 * Used By:
 * - API v1
 */
function handleCurrentWeather($cityId, $endpoint='weather') {
    $key = "current_$cityId";
    $filePath = getCacheFilePath($key);

    if (isCacheValid($filePath)) {
        serveFromCache($filePath);
    } else {
        // Fetch new data
        $newData = fetchCurrentWeatherData($cityId, $endpoint);
        
        // Check if newData contains an error
        if (isset($newData['error'])) {
            // Serve the error response directly without caching
            header('Content-Type: application/json');
            echo json_encode($newData);
        } else {
            // Cache and serve the new data
            cacheAndServe($key, $newData);
        }
    }
}

/**
 * 
 * Used By:
 * - 
 */
function handleHealthCheck() {
    // Mock response for demonstration
    header('Content-Type: application/json');
    echo json_encode(["status" => "OK", "uptime" => "4711"]);
}

/**
 * 
 * Used By:
 * - API v1.9
 */
function v2HandleWeekendActivities($cityId) {
    
    $result1 = v2FetchWeatherData($cityId, 'weather');
    $result2 = generateWeekendForecast($cityId, $result1['timezone']);
    $forecastMain = implode(', ', $result2['aggregate']['uniqueWeatherMains']);
    $forecastDescription = implode(', ', $result2['aggregate']['uniqueWeatherDescriptions']);
    
    $result = fetchWeekendActivitiesSuggestions($result1['name'], $result2['aggregate']['uniqueWeatherDescriptions']);
    echo json_encode(['activities' => $result['choices'][0]['message']['content'], 'model' => OPENROUTERAI_MODEL, 'forecast' => ['main' => $forecastMain, 'description' => $forecastDescription]]);

    //$response = generateSuccessResponse($data, $cacheStatus, 'json');
    //sendResponse($response);
}
#endregion handlerfunctions


//-------------------------------------
//          Data Fetching
//-------------------------------------
// fetch from cache or API
#region fetching
function v2FetchWeatherData($cityId, $endpoint='weather') {
    $key = "v2_{$endpoint}_{$cityId}";
    $filePath = getCacheFilePath($key);

    // Check if cache is valid
    if (isCacheValid($filePath)) {
        // Serve from cache
        $data = json_decode(file_get_contents($filePath), true);
        // Calculate cache age
        $cacheAge = time() - filemtime($filePath);
        // Add cache headers
        header('Content-Type: application/json');
        header('X-Cache: HIT');
        header("X-Cache-Age: $cacheAge");
        // adding a flag to indicate this is from cache if necessary
        $data['_fromCache'] = true;
        $data['_cacheAge'] = $cacheAge;
        return $data;
    } else {
        // Fetch new data
        $params = ['id' => $cityId, 'units' => 'metric'];
        $response = callOpenWeatherMapAPI($endpoint, $params);

        // Check for error in response before caching
        if (!isset($response['error'])) {
            // Cache the new data
            file_put_contents($filePath, json_encode($response));
            // Add cache headers for fresh data
            header('Content-Type: application/json');
            header('X-Cache: MISS');
            // Optionally, add a flag or modify the response to indicate fresh data
            $response['_fromCache'] = false;
            $response['_cacheAge'] = 0;
        } else { //fixme
            // Handle error, including adding relevant headers if necessary
            header('Content-Type: application/json');
            // Assuming error handling is done within the callOpenWeatherMapAPI function,
            // and it sets appropriate HTTP status codes.
        }
        return $response;
    }
}

function v2FetchForecastData($cityId, $endpoint='forecast') {
    $key = "{$endpoint}_{$cityId}";
    $filePath = getCacheFilePath($key);

    // Check if cache is valid
    if (isCacheValid($filePath)) {
        // Serve from cache
        $data = json_decode(file_get_contents($filePath), true);
        // Calculate cache age
        $cacheAge = time() - filemtime($filePath);
        // Add cache headers
        header('Content-Type: application/json');
        header('X-Cache: HIT');
        header("X-Cache-Age: $cacheAge");
        // adding a flag to indicate this is from cache if necessary
        $data['_fromCache'] = true;
        return $data;
    } else {
        // Fetch new data
        $params = ['id' => $cityId, 'units' => 'metric'];
        $response = callOpenWeatherMapAPI($endpoint, $params);

        // Check for error in response before caching
        if (!isset($response['error'])) {
            // Cache the new data
            file_put_contents($filePath, json_encode($response));
            // Add cache headers for fresh data
            header('Content-Type: application/json');
            header('X-Cache: MISS');
            // Optionally, add a flag or modify the response to indicate fresh data
            $response['_fromCache'] = false;
        } else {
            // Handle error, including adding relevant headers if necessary
            header('Content-Type: application/json');
            // Assuming error handling is done within the callOpenWeatherMapAPI function,
            // and it sets appropriate HTTP status codes.
        }
        return $response;
    }
}

function fetchCurrentWeatherData($cityId, $endpoint='weather') {
    // Specify the parameters for the API call
    $params = [
        'id' => $cityId,
        'units' => 'metric' // Example parameter to get temperature in Celsius
    ];

    // Call the OpenWeatherMap API using the wrapper function
    $response = callOpenWeatherMapAPI($endpoint, $params);

    if (!isset($response['error'])) {
        // Process the API response as needed for your application
        return $response;
        // return [
        //     'cityId' => $cityId,
        //     'temperature' => $response['main']['temp'] . "°C",
        //     'description' => $response['weather'][0]['description'],
        //     'icon' => $response['weather'][0]['icon']
        // ];
    } else {
        // Return the error from the API call
        return [
            'error' => true,
            'message' => $response['message'] ?? 'Unknown error occurred.',
            'code' => $response['cod']
        ];
    }
}

/**
 * 
 * Used By:
 * - 
 */
function fetchForecastData($cityId, $endpoint='forecast') {
    // Specify the parameters for the API call
    $params = [
        'id' => $cityId,
        'units' => 'metric' // Example parameter to get temperature in Celsius
    ];

    // Call the OpenWeatherMap API using the wrapper function
    $response = callOpenWeatherMapAPI($endpoint, $params);

    if (!isset($response['error'])) {
        // Process the API response as needed for your application
        return $response; // Directly return the full API response for maximum flexibility
        // Optionally, you could process and return a structured response similar to the fetchCurrentWeatherData function if needed
    } else {
        // Return the error from the API call
        return [
            'error' => true,
            'message' => $response['message'] ?? 'Unknown error occurred.',
            'code' => $response['cod']
        ];
    }
}

/**
 * Calls the OpenWeatherMap API.
 *
 * @param string $endpoint The API endpoint (e.g., 'weather', 'forecast').
 * @param array $params Additional parameters for the API call.
 * @return array The decoded JSON response as an associative array or error.
 */
function callOpenWeatherMapAPI($endpoint, array $params = []) {
    // Build the query parameters, including the API key
    $params['appid'] = OPENWEATHERMAP_API_KEY;
    $queryString = http_build_query($params);

    // Construct the full URL
    $url = OPENWEATHERMAP_BASE_URL . $endpoint . '?' . $queryString;

    // Initialize cURL session
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

    // Execute the cURL session
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($curl);

    // Check for successful response
    if ($httpCode == 200) {
        // Decode JSON response
        $data = json_decode($response, true);
        return $data;
    } else {
        // Provide debug information in case of error
        return [
            'error' => 'Unable to fetch data from OpenWeatherMap.',
            'debugInfo' => [
                'endpoint' => $endpoint,
                'httpCode' => $httpCode,
                'requestedUrl' => $url
            ]
        ];
    }
}

function fetchWeekendActivitiesSuggestions($cityName, $arrForecasts) {
    $cacheKey = "activities_" . md5($cityName . implode('_', $arrForecasts));
    $cacheFilePath = getCacheFilePath($cacheKey);

    if (isCacheValid($cacheFilePath)) {
        return json_decode(file_get_contents($cacheFilePath), true);
    }

    // Prepare the prompt with dynamic content
    $aggregatedForecasts = implode(' and ', $arrForecasts);
    $promptContent = "Given the weather forecast for $cityName, this weekend is forecasted $aggregatedForecasts, I'm looking for suggestions on what to do as a local, given the current weather conditions. list 3 locations to visit tailored for a local resident. Return a comma-separated list consisting of the location names only and nothing else. do not paraphrase the request";

    $postData = [
        "model" => OPENROUTERAI_MODEL,
        "messages" => [
            ["role" => "system", "content" => "You are a helpful assistant."],
            ["role" => "user", "content" => $promptContent]
        ],
        "response_format" => ["type" => "json_object"],
        "temperature" => 0.1,
        "top_p" => 0,
        "top_a" => 1,
        "max_tokens" => 64
    ];

    $curl = curl_init(OPENROUTERAI_BASE_URL . '/chat/completions');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTERAI_API_KEY
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode == 200) {
        $data = json_decode($response, true);
        file_put_contents($cacheFilePath, json_encode($data));
        return $data;
    } else {
        // Handle the error accordingly. Here's a basic error structure.
        return [
            'error' => true,
            'message' => 'Failed to fetch activity suggestions.',
            'httpCode' => $httpCode
        ];
    }
}


#endregion


//-------------------------------------
//          Data Processing
//-------------------------------------
#region processing


function transformExternalToInternalData(OpenWeatherMap\v2_5\WeatherResponse $weatherResponse): Location\LocationCharacteristics {
    $locChar = new Location\LocationCharacteristics();
    
    // // Mapping data from OpenWeatherMap to LocationCharacteristics properties
    //$locChar->epoch = $weatherResponse->dt;
    $locChar->location->countrycode = $weatherResponse->sys->country;
    $locChar->location->name = $weatherResponse->name;
    $locChar->location->openweathercityid = $weatherResponse->id;
    $locChar->location->lat = $weatherResponse->coordinates->lat;
    $locChar->location->lon = $weatherResponse->coordinates->lon;
    $locChar->location->timezoneOffset = $weatherResponse->timezone;

    $dsi = date_sun_info($weatherResponse->dt, $weatherResponse->coordinates->lat, $weatherResponse->coordinates->lon); // todo implement true and false return values e.g. polar day
    $locChar->astronomicalData->sunrise = $weatherResponse->sys->sunrise;
    $locChar->astronomicalData->sunset = $weatherResponse->sys->sunset;
    $locChar->astronomicalData->trueNoon = $dsi['transit'];
    $locChar->astronomicalData->trueNoonDisplay = formatDateSunInfo($dsi['transit'], $locChar->location->timezoneOffset, 'transit');
    $locChar->astronomicalData->civil_twilight_begin = formatDateSunInfo($dsi['civil_twilight_begin'], $locChar->location->timezoneOffset, 'civil_twilight_begin');
    $locChar->astronomicalData->civil_twilight_end = formatDateSunInfo($dsi['civil_twilight_end'], $locChar->location->timezoneOffset, 'civil_twilight_end');
    $locChar->astronomicalData->nautical_twilight_begin = formatDateSunInfo($dsi['nautical_twilight_begin'], $locChar->location->timezoneOffset, 'nautical_twilight_begin');
    $locChar->astronomicalData->nautical_twilight_end = formatDateSunInfo($dsi['nautical_twilight_end'], $locChar->location->timezoneOffset, 'nautical_twilight_end');
    $locChar->astronomicalData->astronomical_twilight_begin = formatDateSunInfo($dsi['astronomical_twilight_begin'], $locChar->location->timezoneOffset, 'astronomical_twilight_begin');
    $locChar->astronomicalData->astronomical_twilight_end = formatDateSunInfo($dsi['astronomical_twilight_end'], $locChar->location->timezoneOffset, 'astronomical_twilight_end');
    //$unixTimestamp, $timezoneOffset, $sunsetUnixtime
    $locChar->astronomicalData->daylightAfter5pm = calculateDaylightAfter5PM($weatherResponse->dt, $weatherResponse->timezone, $weatherResponse->sys->sunset);

    // Example: transforming weather data
    if (!empty($weatherResponse->weather)) {
        $firstWeatherCondition = $weatherResponse->weather[0];
        //$locChar->epoch = $weatherResponse->dt;
        $locChar->weather->epoch = $weatherResponse->dt;
        $localTime = convertUnixtimeToLocaltime($weatherResponse->dt, $locChar->location->timezoneOffset);
        $locChar->weather->localDateTime = $localTime->format('c');
        $locChar->weather->localTime = $localTime->format('H:i');
        $locChar->weather->isCached = $weatherResponse->_fromCache;
        $locChar->weather->cacheAge = $weatherResponse->_cacheAge;
        $locChar->weather->temperature = $weatherResponse->main->temp;
        $locChar->weather->group = $firstWeatherCondition->main;
        $locChar->weather->condition = $firstWeatherCondition->description;
        $locChar->weather->humidity = $weatherResponse->main->humidity;
        $locChar->weather->iconUrl = "https://openweathermap.org/img/wn/" . $firstWeatherCondition->icon . ".png";
    }
    $wkEndBounds = v2CalculateWeekendBoundaries($weatherResponse->dt, $weatherResponse->timezone);
    $locChar->weekend->begin = $wkEndBounds['weekendBegin'];
    $locChar->weekend->end = $wkEndBounds['weekendEnd'];

    return $locChar;
}

function calculateDaylightAfter5PM($unixTimestamp, $timezoneOffset, $sunsetUnixtime) {
    // Convert the given unix timestamp and sunset time to DateTime objects with UTC timezone
    $currentDateTime = (new \DateTime())->setTimestamp($unixTimestamp)->setTimezone(new \DateTimeZone('UTC'));
    $sunsetDateTime = (new \DateTime())->setTimestamp($sunsetUnixtime)->setTimezone(new \DateTimeZone('UTC'));

    // Convert to local time by adding the timezone offset
    $localDateTime = convertUnixtimeToLocaltime($unixTimestamp, $timezoneOffset);
    $localSunsetDateTime = convertUnixtimeToLocaltime($sunsetUnixtime, $timezoneOffset);

    // Create a DateTime object for 5PM on the same day as the local time
    $fivePMDateTime = clone $localDateTime;
    $fivePMDateTime->setTime(17, 0, 0); // 17:00 is 5 PM

    // Calculate the difference in seconds between sunset and 5 PM
    $diffSeconds = 0;
    if ($localSunsetDateTime > $fivePMDateTime) {
        $diff = $localSunsetDateTime->getTimestamp() - $fivePMDateTime->getTimestamp();
        $diffSeconds = $diff > 0 ? $diff : "-";
    } else {
        $diffSeconds = "-";
    }

    return $diffSeconds;
}

function convertUnixtimeToLocaltime($unixTimestamp, $offsetInSeconds) {
    // Create a DateTime object from the Unix timestamp
    $dateTime = new \DateTime('@' . $unixTimestamp);
    $dateTime->setTimezone(new \DateTimeZone('UTC')); // Set to UTC to ensure correct conversion

    // Determine the sign and format the offset
    $sign = $offsetInSeconds >= 0 ? '+' : '-';
    $offsetFormatted = $sign . gmdate('H:i', abs($offsetInSeconds));

    // Set the desired timezone with the formatted offset
    $timezone = new \DateTimeZone($offsetFormatted);
    $dateTime->setTimezone($timezone);

    // Return the datetime in ISO 8601 format with timezone
    return $dateTime;
}

function v2CalculateWeekendBoundaries($currentUnixTime, $timezoneOffset) {
    //date_default_timezone_set('UTC');
    $adjustedTime = $currentUnixTime + $timezoneOffset;
    
    $currentDateTime = new \DateTime("@$adjustedTime");
    $currentDayOfWeek = $currentDateTime->format('l');
    $currentHour = (int)$currentDateTime->format('G');
    
    $weekendStatus = [
        'isWeekend' => false,
        'upcomingWeekend' => false,
        'weekendBegin' => null,
        'weekendEnd' => null,
    ];
    
    // Determine the start and end of the weekend based on constants
    $weekendStart = (new \DateTime("Friday this week", new \DateTimeZone('UTC')))
                        ->setTime(WEEKEND_START_HOUR, 0)
                        ->getTimestamp() + $timezoneOffset;
    
    $weekendEnd = (new \DateTime("Sunday this week", new \DateTimeZone('UTC')))
                      ->setTime(WEEKEND_END_HOUR, 0)
                      ->getTimestamp() + $timezoneOffset;
    
    if ($currentDayOfWeek == 'Friday' && $currentHour >= WEEKEND_START_HOUR) {
        $weekendStatus['isWeekend'] = true;
    } elseif ($currentDayOfWeek == 'Saturday' || 
             ($currentDayOfWeek == 'Sunday' && $currentHour < WEEKEND_END_HOUR)) {
        $weekendStatus['isWeekend'] = true;
    } else {
        $weekendStatus['upcomingWeekend'] = true;
    }
    
    if ($currentDayOfWeek == 'Sunday' && $currentHour >= WEEKEND_END_HOUR) {
        // Adjust for the next weekend
        $weekendStart += 7 * 24 * 60 * 60; // Add a week
        $weekendEnd += 7 * 24 * 60 * 60;
        $weekendStatus['upcomingWeekend'] = true;
        $weekendStatus['isWeekend'] = false;
    }
    
    $weekendStatus['weekendBegin'] = $weekendStart;
    $weekendStatus['weekendEnd'] = $weekendEnd;

    return $weekendStatus;
}

//depreceated!?!
function determineWeekendStatus($currentUnixTime, $timezoneOffset) {
    // Define constant values for days and hours

    // Calculate the current day and time in the local timezone
    $localUnixTime = $currentUnixTime + $timezoneOffset;
    $dayOfWeek = date('N', $localUnixTime); // 1 (for Monday) through 7 (for Sunday)
    $hour = date('G', $localUnixTime); // Hour in 24-hour format without leading zeros

    $upcomingWeekend = false;
    $isWeekend = false;
    $weekendTimeframe = ['begin' => null, 'end' => null];

    // Determine if it's before the countdown to the weekend starts
    if ($dayOfWeek >= 1 && $dayOfWeek <= 5 - DAYS_BEFORE_WEEKEND) {
        $upcomingWeekend = false;
        $isWeekend = false;
    } elseif ($dayOfWeek < 5 || ($dayOfWeek == 5 && $hour < WEEKEND_START_HOUR)) {
        // It's within the countdown period to the weekend but before the weekend starts
        $upcomingWeekend = true;
        $isWeekend = false;
    } elseif (($dayOfWeek == 5 && $hour >= WEEKEND_START_HOUR) || $dayOfWeek > 5 || ($dayOfWeek == 7 && $hour <= WEEKEND_END_HOUR)) {
        // It's the weekend
        $upcomingWeekend = false;
        $isWeekend = true;
    }

    if ($upcomingWeekend || $isWeekend) {
        // Adjust the logic for calculating the beginning of the weekend
        if ($dayOfWeek >= 5 && $dayOfWeek <= 7) {
            // If it's already Friday or the weekend, use "last Friday" to ensure we're pointing to the correct date
            $weekendBeginTime = strtotime("last Friday " . WEEKEND_START_HOUR . ":00", $currentUnixTime) + $timezoneOffset - date('Z');
        } else {
            // Otherwise, use "this Friday" to point to the upcoming Friday
            $weekendBeginTime = strtotime("this Friday " . WEEKEND_START_HOUR . ":00", $currentUnixTime) + $timezoneOffset - date('Z');
        }

        // Calculate end of the weekend: Sunday at 21:00 local time
        $weekendEndTime = strtotime("this Sunday " . WEEKEND_END_HOUR . ":00", $currentUnixTime) + $timezoneOffset - date('Z');

        $weekendTimeframe = ['begin' => $weekendBeginTime, 'end' => $weekendEndTime];
    }

    return [
        'upcomingWeekend' => $upcomingWeekend,
        'isWeekend' => $isWeekend,
        'weekendTimeframe' => $weekendTimeframe
    ];
}

//v1.9
//todo check if int is necessary or was just a one-time dev-level code error while dev-testing
function v2UpcomingWeekendWeather($cityId, $weekendBegin, $weekendEnd) {
    $forecastRawData = v2FetchForecastData($cityId, 'forecast');
    $forecastResponse = new \Backend\OpenWeatherMap\v2_5\ForecastResponse($forecastRawData);

    $dayForecasts = [];
    $nightForecasts = [];
    $uniqueMains = [];
    $uniqueDescriptions = [];
    $totalTempDay = 0;
    $totalTempNight = 0;
    $countDay = 0;
    $countNight = 0;
    $debugDayForecasts = []; //todo remove
    $debugNightForecasts = []; //todo remove

    foreach ($forecastResponse->list as $forecastItem) {
        $dt = $forecastItem->dt;
        if ($dt >= (int)$weekendBegin && $dt <= (int)$weekendEnd) {
            $hour = (int)date('G', $dt + $forecastResponse->city->timezone);

            // Determine if this forecast is for day or night based on predefined constants
            if ($hour >= DAYTIME_START_HOUR && $hour < DAYTIME_END_HOUR) {
                // Day forecasts
                $dayForecasts[] = $forecastItem;
                $totalTempDay += $forecastItem->main->temp;
                $debugDayForecasts[] = $forecastItem->main->temp; //todo remove
                $countDay++;
            } else {
                // Night forecasts
                $nightForecasts[] = $forecastItem;
                $totalTempNight += $forecastItem->main->temp;
                $debugNightForecasts[] = $forecastItem->main->temp; //todo remove
                $countNight++;
            }

            // Collect unique "main" and "description" keys
            foreach ($forecastItem->weather as $weather) {
                $uniqueMains[$weather->main] = true;
                $uniqueDescriptions[$weather->description] = true;
            }
        }
    }

    $averageTempDay = $countDay > 0 ? round($totalTempDay / $countDay, 2) : 0;
    $averageTempNight = $countNight > 0 ? round($totalTempNight / $countNight, 2) : 0;

    $lastForecastTimestamp = end($forecastResponse->list)->dt;
    $coversEntireWeekend = $lastForecastTimestamp >= (int)$weekendEnd;
    

    return [
        'dayForecasts' => $dayForecasts,
        'nightForecasts' => $nightForecasts,
        'averageTempDay' => $averageTempDay,
        'averageTempNight' => $averageTempNight,
        'uniqueMains' => array_keys($uniqueMains),
        'uniqueDescriptions' => array_keys($uniqueDescriptions),
        'debugDayForecasts' => $debugDayForecasts,
        'debugDayCount' => $countDay,
        'debugNightForecasts' => $debugNightForecasts,
        'debugNightCount' => $countNight,
        'weekendEnd' => (int)$weekendEnd,
        'lastForecastTimestamp' => $lastForecastTimestamp,
        'coversEntireWeekend' => $coversEntireWeekend,
        'forecastResponse' => $forecastResponse,
    ];
}




function upcomingWeekendWeather($cityId) {
    $forecastData = v2FetchForecastData($cityId, 'forecast');

    $timezoneOffset = $forecastData['city']['timezone']; // Timezone offset in seconds

    $currentUnixTime = time();
    $weekendStatus = determineWeekendStatus($currentUnixTime, $timezoneOffset);

    $result = ['openweatherdata' => [], 'forecast-weather' => []];

    if ($weekendStatus['upcomingWeekend'] || $weekendStatus['isWeekend']) {
        $weekendBegin = $weekendStatus['weekendTimeframe']['begin'];
        $weekendEnd = $weekendStatus['weekendTimeframe']['end'];

        foreach ($forecastData['list'] as $forecast) {
            // Adjust forecast timestamp to UTC for accurate local hour calculation
            $forecastLocalTime = $forecast['dt'] + $timezoneOffset;
            // Now, 'date' functions as if in UTC, giving accurate local time conversion
            $forecastHour = (int)date('G', $forecastLocalTime); // 'G' returns hours in 24-hour format without leading zeros

            // Include debug information within each forecast data
            $forecast['_debug'] = [
                'dt' => $forecast['dt'],
                'timezoneOffset' => $timezoneOffset,
                'localHour' => $forecastHour,
            ];

            if ($forecast['dt'] >= $weekendBegin && $forecast['dt'] <= $weekendEnd && $forecastHour >= WEEKEND_DAY_START_HOUR && $forecastHour < WEEKEND_DAY_END_HOUR) {
                // Collect forecast data and weather condition for aggregation
                $result['openweatherdata'][] = $forecast;
            }
        }
    }

    return $result;
}

function aggregateWeekendWeather($forecastData) {
    $temperatureMin = PHP_INT_MAX;
    $temperatureMax = PHP_INT_MIN;
    $weatherMains = [];
    $weatherConditions = [];
    $uniqueWeatherForecasts = [];
    $uniqueForecastWeatherMain = [];
    $uniqueForecastWeatherDescription = [];

    foreach ($forecastData['openweatherdata'] as $forecast) {
        // Aggregate temperature data
        $temperatureMin = min($temperatureMin, $forecast['main']['temp_min']);
        $temperatureMax = max($temperatureMax, $forecast['main']['temp_max']);
        
        // Collect unique weather conditions
        foreach ($forecast['weather'] as $weather) {
            $weatherMain = $weather['main'];
            $weatherDescription = $weather['description'];

            if (!in_array($weatherMain, $weatherMains)) {
                $weatherMains[] = $weatherMain;
            }
            if (!in_array($weatherDescription, $weatherConditions)) {
                $weatherConditions[] = $weatherDescription;
            }
        }
    }

    // Prepare unique weather forecasts summary
    $uniqueForecastWeatherDescription = array_unique($weatherConditions);
    sort($uniqueForecastWeatherDescription); // Optional, for a sorted list of conditions
    $uniqueForecastWeatherMain = array_unique($weatherMains);
    sort($uniqueForecastWeatherMain); // Optional, for a sorted list of conditions

    // Prepare the aggregate data
    $aggregateData = [
        'temperatureRange' => sprintf("%.1f°C to %.1f°C", $temperatureMin, $temperatureMax),
        'overallWeatherMain' => implode(", ", $uniqueForecastWeatherDescription),
        'overallWeatherDescription' => implode(", ", $uniqueForecastWeatherMain),
        'uniqueWeatherDescriptions' => $uniqueForecastWeatherDescription,
        'uniqueWeatherMains' => $uniqueForecastWeatherMain,
        'uniqueWeatherForecasts' => $uniqueForecastWeatherMain
    ];

    return $aggregateData;
}

function generateWeekendForecast($cityId, $timezoneOffset) {
    $unixtimeNow = time();    
    $weekendStatus = determineWeekendStatus($unixtimeNow, $timezoneOffset);
    
    if (!$weekendStatus['upcomingWeekend'] && !$weekendStatus['isWeekend']) {
        // It's not close to the weekend, so we might not need to proceed.
        return ['message' => 'No weekend forecast available at this time.'];
    }
    
    $forecastData = upcomingWeekendWeather($cityId);
    if (isset($forecastData['error'])) {
        // Handle errors, such as city not found or API issues.
        return ['error' => true, 'message' => $forecastData['message']];
    }
    
    // Aggregate weekend weather from the forecast data.
    $aggregateData = aggregateWeekendWeather($forecastData);


    // Return both raw and aggregated data for detailed analysis and simple overview.
    return [
        'debug' => $weekendStatus,
        'openweatherdata' => $forecastData['openweatherdata'],
        'aggregate' => $aggregateData
    ];
}
#endregion processing


//-------------------------------------
//          Helpers
//-------------------------------------
#region helpers
function getCacheFilePath($key) {
    // Utility function to get the cache file path
    return CACHE_DIRECTORY . 'weather_cache_backend_' . md5($key) . '.json';
}

function isCacheValid($filePath) {
    // Utility function to check if the cache is valid
    if (!file_exists($filePath)) return false;
    $fileTime = filemtime($filePath);
    return (time() - $fileTime) < CACHE_LIFETIME;
}

function serveFromCache($filePath) {
    $cacheAge = time() - filemtime($filePath);
    header('Content-Type: application/json');
    header('X-Cache: HIT');
    header("X-Cache-Age: $cacheAge");
    readfile($filePath);
    exit;
}

function cacheAndServe($key, $data) {
    $filePath = getCacheFilePath($key);
    file_put_contents($filePath, json_encode($data));
    serveData($data, false);
}

function serveData($data, $fromCache) {
    header('Content-Type: application/json');
    if (!$fromCache) {
        header('X-Cache: MISS');
    }
    echo json_encode($data);
    exit;
}

function handleClearCache() {
    $files = glob(CACHE_DIRECTORY . 'weather_cache_backend_*.json');
    $deletedFiles = [];

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file); // Delete the file
            $deletedFiles[] = basename($file); // Add the name of the deleted file to the array
        }
    }

    // Calculate the count of deleted files from the array
    $deleteCount = count($deletedFiles);

    // After clearing the cache, return a success response with details
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Cache cleared successfully.',
        'cacheDirectory' => CACHE_DIRECTORY,
        'cacheFiles' => $files,
        'deletedFiles' => $deletedFiles,
        'deleteCount' => $deleteCount
    ]);
    exit;
}

//v1.9
function formatDateSunInfo($timestamp, $offset, $eventType) {
    // Check if the event does not occur
    if ($timestamp === false) {
        return "-";
    }
    // Assuming a polar day/summer scenario where the sun is up all day
    elseif ($timestamp === true) {
        if (in_array($eventType, ['sunrise', 'civil_twilight_begin', 'nautical_twilight_begin', 'astronomical_twilight_begin'])) {
            return '00:00';
        } elseif (in_array($eventType, ['sunset', 'civil_twilight_end', 'nautical_twilight_end', 'astronomical_twilight_end'])) {
            return '23:59';
        }
    }
    // General case: format the UNIX timestamp
    else {
        $localDateTime = convertUnixtimeToLocaltime($timestamp, $offset);
        return $localDateTime->format('H:i'); //todo round properly
    }
}

//v1.9
function generateSuccessResponse($data, $cacheStatus = null, $format = 'json') {
    $response = [
        'status' => 'success',
        'code' => 200,
        'message' => 'Data retrieved successfully.',
        'data' => $data,
        'meta' => [
            'requestId' => uniqid('req_', true),
            'timestamp' => date(DATE_ATOM),
            'cacheStatus' => $cacheStatus,
        ],
    ];
    return formatResponse($response, $format);
}

function generateErrorResponse($errorCode, $errorMessage, $errors = [], $format = 'json') {
    $response = [
        'status' => 'error',
        'code' => $errorCode,
        'message' => $errorMessage,
        'errors' => $errors,
        'meta' => [
            'requestId' => uniqid('req_', true),
            'timestamp' => date(DATE_ATOM),
        ],
    ];
    return formatResponse($response, $format);
}

function formatResponse($response, $format = 'json') {
    switch ($format) {
        case 'html':
            // This is a placeholder for HTML formatting
            // Initially performs a null operation and can be expanded to parse data into an HTML template
            return renderHtmlResponse($response);
        case 'json':
        default:
            // Default to JSON format if not specified or if the format is unknown
            header('Content-Type: application/json');
            echo json_encode($response);
            break;
    }
    exit; // Ensure script termination after response is sent
}

function renderHtmlResponse($response) {
    // Placeholder for HTML rendering logic
    // For now, we'll just indicate that HTML output is not implemented
    echo "HTML output format is not implemented.";
    exit;
}


#endregion helpers


//-------------------------------------
//          Teardown
//-------------------------------------

// Restore the original default timezone
date_default_timezone_set($defaultTimezone);


} #end namespace Backend


#region nsowm25

namespace Backend\OpenWeatherMap\v2_5 {


    class Coordinates {
        public float $lon;
        public float $lat;
    
        public function __construct(array $data) {
            $this->lon = $data['lon'];
            $this->lat = $data['lat'];
        }
    }
    
    class Weather {
        public int $id;
        public string $main;
        public string $description;
        public string $icon;
    
        public function __construct(array $data) {
            $this->id = $data['id'];
            $this->main = $data['main'];
            $this->description = $data['description'];
            $this->icon = $data['icon'];
        }
    }
    
    class Main {
        public float $temp;
        public float $feels_like;
        public float $temp_min;
        public float $temp_max;
        public int $pressure;
        public int $humidity;
        public ?int $sea_level = null;
        public ?int $grnd_level = null;
        public ?float $temp_kf = null;
    
        public function __construct(array $data) {
            foreach ($data as $key => $value) {
                if(property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
    
    class Wind {
        public float $speed;
        public int $deg;
        public ?float $gust = null;
    
        public function __construct(array $data) {
            $this->speed = $data['speed'];
            $this->deg = $data['deg'];
            $this->gust = $data['gust'] ?? null;
        }
    }
    
    class Clouds {
        public int $all;
    
        public function __construct(array $data) {
            $this->all = $data['all'];
        }
    }
    
    class Sys {
        public ?int $type = null;
        public ?int $id = null;
        public ?string $country = null;
        public ?int $sunrise = null;
        public ?int $sunset = null;
        public ?string $pod = null;
    
        public function __construct(array $data) {
            foreach ($data as $key => $value) {
                if(property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
    
    class ForecastItem {
        public int $dt;
        public Main $main;
        public array $weather = [];
        public Wind $wind;
        public Clouds $clouds;
        public ?int $visibility = null;
        public float $pop;
        public Sys $sys;
        public string $dt_txt;
    
        public function __construct(array $data) {
            $this->dt = $data['dt'];
            $this->main = new Main($data['main']);
            foreach ($data['weather'] as $weather) {
                $this->weather[] = new Weather($weather);
            }
            $this->wind = new Wind($data['wind']);
            $this->clouds = new Clouds($data['clouds']);
            //$this->visibility = $data['visibility'];
            $this->visibility = $data['visibility'] ?? null;
            //$this->pop = $data['pop'];
            //$this->pop = $data['pop'] ?? 0;
            $this->sys = new Sys($data['sys']);
            $this->dt_txt = $data['dt_txt'];
        }
    }
    
    class WeatherResponse {
        public Coordinates $coordinates;
        public array $weather = [];
        public string $base;
        public Main $main;
        public ?int $visibility = null;
        public Wind $wind;
        public Clouds $clouds;
        public int $dt;
        public Sys $sys;
        public int $timezone;
        public int $id;
        public string $name;
        public int $cod;
        public bool $_fromCache;
        public int $_cacheAge;
    
        public function __construct(array $data) {
            $this->coordinates = new Coordinates($data['coord']);
            foreach ($data['weather'] as $weather) {
                $this->weather[] = new Weather($weather);
            }
            $this->base = $data['base'];
            $this->main = new Main($data['main']);
            //$this->visibility = $data['visibility'];
            $this->visibility = $data['visibility'] ?? null;
            $this->wind = new Wind($data['wind']);
            $this->clouds = new Clouds($data['clouds']);
            $this->dt = $data['dt'];
            $this->sys = new Sys($data['sys']);
            $this->timezone = $data['timezone'];
            $this->id = $data['id'];
            $this->name = $data['name'];
            $this->cod = $data['cod'];
            $this->_fromCache = $data['_fromCache'];
            $this->_cacheAge = $data['_cacheAge'];
        }
    }
    
    class ForecastResponse {
        public int $cod;
        public int $message;
        public int $cnt;
        public array $list = [];
        public City $city;
        public bool $_fromCache;
    
        public function __construct(array $data) {
            $this->cod = $data['cod'];
            $this->message = $data['message'];
            $this->cnt = $data['cnt'];
            foreach ($data['list'] as $item) {
                $this->list[] = new ForecastItem($item);
            }
            $this->city = new City($data['city']);
            $this->_fromCache = $data['_fromCache'];
        }
    }
    
    class City {
        public int $id;
        public string $name;
        public Coordinates $coordinates;
        public string $country;
        public int $population;
        public int $timezone;
        public int $sunrise;
        public int $sunset;
    
        public function __construct(array $data) {
            $this->id = $data['id'];
            $this->name = $data['name'];
            $this->coordinates = new Coordinates($data['coord']);
            $this->country = $data['country'];
            $this->population = $data['population'];
            $this->timezone = $data['timezone'];
            $this->sunrise = $data['sunrise'];
            $this->sunset = $data['sunset'];
        }
    }
    
    
} #end namespace OpenWeatherMap\v2_5

#endregion nsowm25

#region nslc
namespace Backend\Location {

/**
 * Represents the comprehensive characteristics of a location, including weather,
 * astronomical data, and suggested activities.
 * LocationCharacteristics class effectively bridges external API data with the needs of the frontend.
 */
class LocationInfo {
    public string $name = '';
    public string $countrycode = '';
    public int $openweathercityid;
    public int $timezoneOffset = 0;
    public float $lat = 0.0;
    public float $lon = 0.0;
}

class AstronomicalData {
    public string $sunrise = '';
    public string $sunset = '';
    public string $trueNoon = '';
    public string $daylightAfter5pm = ''; // todo check polar day
    public string $civil_twilight_begin = '';
    public string $civil_twilight_end = '';
    public string $nautical_twilight_begin = '';
    public string $nautical_twilight_end = '';
    public string $astronomical_twilight_begin = '';
    public string $astronomical_twilight_end = '';
}

class WeatherCondition {
    public float $temperature = 0.0;
    public string $group = ''; //todo check rename plural
    public string $condition = ''; //todo check rename plural
    public int $humidity = 0;
    public string $iconUrl = '';
    public bool $isCached = false;
    public int $cacheAge = 0;
    public ?int $epoch = null;
    public ?string $localDateTime = null;
    public ?string $localTime = null;
}

class WeatherConditionFIXME {
    public float $temperature = 0.0;
    public string $group = ''; //todo check rename plural
    public string $condition = ''; //todo check rename plural
    public int $humidity = 0;
    public string $iconUrl = '';
    public bool $isCached = false;
    public int $cacheAge = 0;
}

class ForecastCondition {
    public int $epoch = 0;
    public string $localTime = '';
    public float $tempMin = 0.0;
    public float $tempMax = 0.0;
    public string $group = '';
    public string $condition = '';
}

class SuggestedActivity {
    public string $activity = '';
}

//todo fix wording
class AggregatedWeekendForecast {
    public string $begin = '';
    public string $end = '';
    public WeatherConditionFIXME $day; // todo make specific (aggregate has no single timestamp)
    public WeatherConditionFIXME $night;
    public array $suggestedActivities = [];

    public function __construct() {
        $this->day = new WeatherConditionFIXME();
        $this->night = new WeatherConditionFIXME();
        $this->addSuggestedActivity('test');
    }
    
    public function addSuggestedActivity(string $activity): void {
        //$this->suggestedActivities[] = new SuggestedActivity($activity);
        //todo fix / remove / remove class / toString
        $this->suggestedActivities[] = 'foo';
        $this->suggestedActivities[] = 'bar';
        $this->suggestedActivities[] = 'baz';
    }
}

class LocationCharacteristics {
    public bool $isCached = false;
    public int $cacheAge = -1;
    public ?int $epoch = null;
    public ?string $localDateTime = null;
    public ?string $localTime = null;
    public LocationInfo $location;
    public AstronomicalData $astronomicalData; //todo check rename to astro
    public WeatherCondition $weather;
    public array $forecast = [];
    //public array $suggestedActivities = [];
    public AggregatedWeekendForecast $weekend; //todo fix naming, remove point-in-time properties

    public function __construct() {
        $this->location = new LocationInfo();
        $this->astronomicalData = new AstronomicalData();
        $this->weather = new WeatherCondition();
        $this->weekend = new AggregatedWeekendForecast();
    }

    public function addWeatherForecast(ForecastCondition $forecastCondition): void {
        $this->forecast[] = $forecastCondition;
    }
    
    public function addSuggestedActivity(string $activity): void {
        $this->suggestedActivities[] = new SuggestedActivity($activity);
    }

}


} # end namespace Backend\Location

#endregion nslc