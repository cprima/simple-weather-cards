# Simple HTML Weather Display 

This project is a simple web application that displays weather information for predefined cities. It leverages OpenWeatherMap's API to fetch weather data and presents it in a user-friendly web interface. The application includes a PHP proxy to securely handle API requests without exposing the API key on the client side. It also features a basic caching mechanism to enhance performance and reduce API request load.

<table>
  <tr>
    <th>Set Name</th>
    <th>Preview</th>
    <th>Sample URL</th>
    <th>Color Pair</th>
  </tr>
  <tr>
    <td>Desert Cities</td>
    <td><img src="https://raw.githubusercontent.com/cprima/simple-weather-cards/docs/images/DesertCities.png" width="320" alt="Desert Cities"/></td>
    <td><a href="https://cpr.in-berlin.de/weather/?cities=108410,5506956,2077895,292223,2449067&primaryColor=EDC9AF&secondaryColor=696969">View Weather</a></td>
    <td>Desert Sand & Dim Gray</td>
  </tr>
  <tr>
    <td>Extreme Climates</td>
    <td><img src="https://raw.githubusercontent.com/cprima/simple-weather-cards/docs/images/ExtremeClimates.png" width="320" alt="Extreme Climates"/></td>
    <td><a href="https://cpr.in-berlin.de/weather/?cities=2013465,5509952,2122311,285787,0&primaryColor=0000FF&secondaryColor=FF4500">View Weather</a></td>
    <td>Cold Blue & Orange Red</td>
  </tr>
  <tr>
    <td>Island Paradises</td>
    <td><img src="https://raw.githubusercontent.com/cprima/simple-weather-cards/docs/images/IslandParadises.png" width="320" alt="Island Paradises"/></td>
    <td><a href="https://cpr.in-berlin.de/weather/?cities=5850027,0,252920,1282027,1648759&primaryColor=2E8B57&secondaryColor=20B2AA">View Weather</a></td>
    <td>Sea Green & Light Sea Green</td>
  </tr>
  <tr>
    <td>New York, Rio, Tokyo</td>
    <td><img src="https://raw.githubusercontent.com/cprima/simple-weather-cards/docs/images/NewyorkRioTokyo.png" width="320" alt="New York, Rio, Tokyo"/></td>
    <td><a href="https://cpr.in-berlin.de/weather/?cities=5128581,3451190,1850147&primaryColor=FF007F&secondaryColor=7DF9FF">View Weather</a></td>
    <td>Neon Pink & Electric Blue</td>
  </tr>
</table>


## Features

- Displays weather information for a set of predefined cities.
- Uses OpenWeatherMap's API to fetch real-time weather data.
- Implements a PHP proxy for secure API calls, hiding the API key from client-side exposure.
- Includes a file-based caching mechanism to minimize API requests and improve response times.

## Prerequisites

- PHP server with cURL enabled (for the proxy script).
- Access to OpenWeatherMap API (API key required).
- Basic web server setup (Apache, Nginx, or similar) capable of serving PHP.

## Installation

Follow these steps to set up the weather display application on your server:

1. **Clone the Repository**: First, clone this repository to your web server's document root or a subdirectory. You can use the following command, replacing `your-repo.git` with the actual repository URL:

    ```
    git clone https://example.com/your-repo.git
    ```

2. **Set Up Environment Variables**:
   
    a. Create a `.env` file in the root directory of your project (the same location as your `openweathermap_proxy.php` file).
    
    b. Add the following line to the `.env` file, replacing `YOUR_API_KEY_HERE` with your actual OpenWeatherMap API key:
    
       ```
       OPENWEATHERMAP_APIKEY=YOUR_API_KEY_HERE
       ```
    
    c. Ensure the `.env` file is properly secured and not accessible from the web. If your web server uses Apache, you might already have an `.htaccess` file in place that denies access to dotfiles. If not, consider adding the following to your `.htaccess` file:
    
       ```apache
       <Files .env>
           Order allow,deny
           Deny from all
       </Files>
       ```

3. **Configure Web Server**: No special configuration beyond standard PHP support is typically required. However, ensure your web server is configured to serve the directory where you've placed the project files.

4. **Permissions**: Make sure the `cache` directory within your project is writable by the web server. This directory is used for caching weather data fetched from OpenWeatherMap.

## Contributing

Contributions to improve the application are welcome. Please feel free to fork the repository, make your changes, and submit a pull request.

## License

@see: [LICENCE](LICENCE.md) and [AUTHORS](AUTHORS.md).


