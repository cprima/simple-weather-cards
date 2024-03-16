<?php

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
define('WEEKEND_START_HOUR', 18);
define('WEEKEND_END_HOUR', 21);

#endregion configuration


//-------------------------------------
//          Router
//-------------------------------------
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
            v2HandleCurrentWeather($cityId, 'weather');
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
            $result = v2FetchWeatherData($cityId, 'weather');
            //echo json_encode(['debug' => $result]);
            $result2 = generateWeekendForecast($cityId, $result['timezone']);
            echo json_encode(['debug' => $result2['aggregate'], 'forecast' => $result2]);
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
function v2HandleCurrentWeather($cityId) {
    $data = v2FetchWeatherData($cityId, 'weather');
    // Serve the response
    header('Content-Type: application/json');
    echo json_encode($data);
}

/**
 * 
 * Used By:
 * - API v1.9
 */
function v2HandleForecast($cityId) {
    $data = v2FetchWeatherData($cityId, 'forecast');
    // Serve the response
    header('Content-Type: application/json');
    echo json_encode($data);
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
}
#endregion handlerfunctions


//-------------------------------------
//          Data Fetching
//-------------------------------------
#region fetching
function v2FetchWeatherData($cityId, $endpoint) {
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
#endregion helpers


//-------------------------------------
//          Teardown
//-------------------------------------

// Restore the original default timezone
date_default_timezone_set($defaultTimezone);