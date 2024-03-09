<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$dotenv = parse_ini_file('.env');
$apiKey = $dotenv["OPENWEATHERMAP_APIKEY"];

// Define a default set of city IDs
$defaultCityIds = '2946447,3067696,1273294,1264527,6697380';

// Check if city IDs are provided in the request, use the default if not
$cityIds = isset($_GET['ids']) && !empty($_GET['ids']) ? $_GET['ids'] : $defaultCityIds;

// Cache parameters
$cacheFileName = 'weather_cache_' . md5($cityIds) . '.json'; // Unique file name
$cacheFile = __DIR__ . '/cache/' . $cacheFileName; // Ensure the 'cache' directory exists and is writable
$cacheTime = 300; // Cache duration in seconds

$cacheAge = file_exists($cacheFile) ? time() - filemtime($cacheFile) : $cacheTime;

// Check if the cache file exists and is still valid
if ($cacheAge < $cacheTime) {
    // Cache is valid, return the cached data
    header('Content-Type: application/json');
    header('X-Cache: HIT');
    header("X-Cache-Age: $cacheAge"); // Include the age of the cache in the header
    echo file_get_contents($cacheFile);
    exit;
}

// Cache is invalid or does not exist, fetch new data
$url = "https://api.openweathermap.org/data/2.5/group?id={$cityIds}&units=metric&appid={$apiKey}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpStatusCode != 200) {
    // Error handling: API call failed
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to fetch data from OpenWeatherMap.']);
    exit;
}

// Save the response to the cache file
file_put_contents($cacheFile, $response);

// Return the fresh data
header('Content-Type: application/json');
header('X-Cache: MISS');
echo $response;