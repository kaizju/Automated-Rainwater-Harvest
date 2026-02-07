const chart = new Chart(document.getElementById('bar-chart'), {
  type: 'bar',
  data: {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    datasets: [{
      label: 'RainWater Collection',
      data: [0, 0, 0, 0, 0, 0, 0],
      backgroundColor: '#007bff'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: { beginAtZero: true }
    }
  }
})
const CONFIG = {
    apiKey: 'a5712e740541248ce7883f0af8581be4',
    latitude: 8.360015,
    longitude: 124.868419,
    units: 'metric'
};

// Weather icon mapping
function getWeatherIcon(description, rainAmount) {
    if (rainAmount > 5) return '🌧️';
    if (rainAmount > 0) return '🌦️';
    if (description.includes('cloud')) return '☁️';
    if (description.includes('clear') || description.includes('sun')) return '☀️';
    return '🌤️';
}

// Calculate chance of rain based on conditions
function calculateRainChance(item) {
    const hasRain = item.rain && item.rain['3h'] > 0;
    const humidity = item.main.humidity;
    const clouds = item.clouds.all;
    
    if (hasRain) {
        return Math.min(Math.round(humidity * 0.7 + clouds * 0.3), 95);
    } else if (humidity > 80 && clouds > 70) {
        return Math.round((humidity + clouds) / 2 * 0.5);
    } else if (humidity > 70) {
        return Math.round(humidity * 0.3);
    }
    return Math.round(clouds * 0.2);
}

// Fetch weather data from OpenWeatherMap API
async function fetchWeatherData() {
    const url = `https://api.openweathermap.org/data/2.5/forecast?lat=${CONFIG.latitude}&lon=${CONFIG.longitude}&appid=${CONFIG.apiKey}&units=${CONFIG.units}`;
    
    try {
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error fetching weather data:', error);
        throw error;
    }
}

// Process forecast data for 3 days
function processForecastData(data) {
    const dailyData = {};
    
    data.list.forEach(item => {
        const date = new Date(item.dt * 1000);
        const day = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        const dayShort = date.toLocaleDateString('en-US', { weekday: 'long' });
        
        if (!dailyData[day]) {
            dailyData[day] = {
                dayShort: dayShort,
                rainfall: [],
                rainChances: [],
                weather: item.weather[0].description,
                items: []
            };
        }
        
        dailyData[day].rainfall.push(item.rain ? (item.rain['3h'] || 0) : 0);
        dailyData[day].rainChances.push(calculateRainChance(item));
        dailyData[day].items.push(item);
    });
    
    const dailyForecasts = [];
    
    // Only process first 3 days
    Object.keys(dailyData).slice(0, 3).forEach((day, index) => {
        const totalRain = dailyData[day].rainfall.reduce((a, b) => a + b, 0);
        const avgRainChance = Math.round(dailyData[day].rainChances.reduce((a, b) => a + b, 0) / dailyData[day].rainChances.length);
        
        // Prepare daily forecast
        const dayLabel = index === 0 ? 'Today' : index === 1 ? 'Tomorrow' : dailyData[day].dayShort.substring(0, 3);
        dailyForecasts.push({
            day: dayLabel,
            chance: avgRainChance,
            amount: totalRain,
            icon: getWeatherIcon(dailyData[day].weather, totalRain),
            description: dailyData[day].weather
        });
    });
    
    return { dailyForecasts };
}

// Display rainfall forecast (only 3 days)
function displayRainfallForecast(forecasts) {
    const forecastHTML = forecasts.map(forecast => {
        return `
            <div class="forecast-item">
                <div class="forecast-icon">${forecast.icon}</div>
                <div class="forecast-details">
                    <div class="forecast-day">${forecast.day}</div>
                    <div class="forecast-chance">${forecast.chance}% chance</div>
                </div>
            </div>
        `;
    }).join('');
    
    document.getElementById('rainfallForecast').innerHTML = forecastHTML;
}

// Initialize
async function init() {
    try {      
        const data = await fetchWeatherData();       
        document.getElementById('locationName').textContent = `Rainfall Forecast - ${data.city.name}, ${data.city.country}`;
        const processedData = processForecastData(data);
        
        displayRainfallForecast(processedData.dailyForecasts);
        
        
        document.getElementById('forecastSection').style.display = 'block';
        
    } catch (error) {
        document.getElementById('loading').style.display = 'none';
        document.getElementById('error').style.display = 'block';
        document.getElementById('error').textContent = `Error loading weather data: ${error.message}. Please check your API key and try again.`;
    }
}

// Run on page load
init();