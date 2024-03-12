<?php
/**
 * OpenWeatherMap Proxy Script
 * 
 * This PHP script serves as a proxy to fetch weather data from OpenWeatherMap's API.
 * It includes functionality to cache responses to minimize API calls and enhance performance.
 * The script checks for cached data before making a new API call. If the cached data
 * is still valid, it serves the cached response; otherwise, it fetches fresh data from
 * OpenWeatherMap, caches it, and then serves it. The script uses environment variables
 * for configuration settings like the API key to enhance security.
 *
 * Tested with PHP version 8.1
 *
 * @author     Christian Prior-Mamulyan
 * @license    CC-BY
 * @version    1.1
 */

$envFilePath = '.env';
if (file_exists($envFilePath)) {
    $dotenv = parse_ini_file($envFilePath);
    $apiKey = $dotenv["OPENWEATHERMAP_APIKEY"];
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => '.env file not found.']);
    exit;
}

// Define a default set of city IDs
$defaultCityIds = '2806654,616052';

// Check if city IDs are provided in the request, use the default if not
$cityIds = isset($_GET['ids']) && !empty($_GET['ids']) ? $_GET['ids'] : $defaultCityIds;

// Cache parameters
$cacheFileName = 'weather_cache_' . md5($cityIds) . '.json'; // Unique file name
$cacheFile = __DIR__ . '/cache/' . $cacheFileName; // Ensure the 'cache' directory exists and is writable
$cacheTime = 303; // Cache duration in seconds

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
