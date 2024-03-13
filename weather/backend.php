<?php

$route = isset($_GET['route']) ? $_GET['route'] : '';
$version = isset($_GET['version']) ? $_GET['version'] : '';

// Global settings
$cacheLifetime = 302; // Cache lifetime in seconds
$cacheDirectory = __DIR__ . '/cache/'; // Cache directory

$envFilePath = '.env';
if (file_exists($envFilePath)) {
    $dotenv = parse_ini_file($envFilePath);
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => '.env file not found.']);
    exit;
}

define('OPENWEATHERMAP_API_KEY', $dotenv["OPENWEATHERMAP_APIKEY"]);
define('OPENWEATHERMAP_BASE_URL', 'http://api.openweathermap.org/data/2.5/');


// // OpenAPI/Swagger specification
// $specFilePath = __DIR__ . '/openapi_spec_v1.json';
// $swaggerSpec = json_decode(file_get_contents($specFilePath), true);

// Simple router based on route and version query parameters
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
} elseif ($version === 'v1.1') {
    switch ($route) {
        case 'spec':
            handleSpec($version);
            break;

        case 'current':
            $cityId = $_GET['cityId'] ?? 'unknown';
            handleCurrentWeather($cityId);
            break;  

        case 'forecast':
            // Assuming cityId is passed as a query parameter
            $cityId = $_GET['cityId'] ?? 'unknown';
            handleForecast($cityId);
            break;

        case 'health':
            handleHealthCheck();
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
} else {
    // Handle version not supported
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'API version not supported']);
}

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


function handleForecast($cityId) {
    // Mock response for demonstration
    header('Content-Type: application/json');
    echo json_encode(["cityId" => $cityId, "forecast" => "Rainy tomorrow"]);
}

function handleHealthCheck() {
    // Mock response for demonstration
    header('Content-Type: application/json');
    echo json_encode(["status" => "OK", "uptime" => "4711"]);
}

///////////////////////////////////////

// function to fetch current weather data
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

// Utility cache functions
function getCacheFilePath($key) {
    // Utility function to get the cache file path
    global $cacheDirectory;
    return $cacheDirectory . 'weather_cache_backend_' . md5($key) . '.json';
}

function isCacheValid($filePath) {
    // Utility function to check if the cache is valid
    global $cacheLifetime;
    if (!file_exists($filePath)) return false;
    $fileTime = filemtime($filePath);
    return (time() - $fileTime) < $cacheLifetime;
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
    global $cacheDirectory;
    $files = glob($cacheDirectory . 'weather_cache_backend_*.json');
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
        'cacheDirectory' => $cacheDirectory,
        'cacheFiles' => $files,
        'deletedFiles' => $deletedFiles,
        'deleteCount' => $deleteCount
    ]);
    exit;
}

