<?php
// config/delay_factors.php
require_once __DIR__ . '/config.php';

function getRushHourDelay(): array {
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $time = $now->format('H:i');
    
    // Rush windows: Morning (06:00-09:00), Noon (12:00-13:00), Afternoon (16:00-18:00)
    $isMorningRush   = ($time >= '06:00' && $time <= '09:00');
    $isNoonRush      = ($time >= '12:00' && $time <= '13:00');
    $isAfternoonRush = ($time >= '16:00' && $time <= '18:00');

    if ($isMorningRush || $isNoonRush || $isAfternoonRush) {
        return ['minutes' => 4.0, 'reason' => 'Rush hour traffic'];
    }
    return ['minutes' => 0.0, 'reason' => 'Normal traffic'];
}
function getWeatherDelay(float $lat, float $lng, string $apiKey): array {
    if (empty($apiKey) || $apiKey === 'your_api_key_here') {
        return ['minutes' => 0.0, 'condition' => 'Clear', 'temp' => null];
    }

    $cacheFile = sys_get_temp_dir() . '/weather_' . round($lat, 2) . '_' . round($lng, 2) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 600)) {
        $data = json_decode(file_get_contents($cacheFile), true);
    } else {
        $url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lng}&appid={$apiKey}&units=metric";
        $resp = @file_get_contents($url);
        if (!$resp) return ['minutes' => 0.0, 'condition' => 'Unknown', 'temp' => null];
        file_put_contents($cacheFile, $resp);
        $data = json_decode($resp, true);
    }

    $weatherMain = $data['weather'][0]['main'] ?? 'Clear';
    $delay = 0.0;
    if (in_array($weatherMain, ['Rain', 'Drizzle', 'Thunderstorm'])) {
        $delay = 4.0;
    } elseif ($weatherMain === 'Squall' || $weatherMain === 'Tornado') {
        $delay = 10.0;
    }

    return [
        'minutes'   => $delay,
        'condition' => $weatherMain,
        'temp'      => $data['main']['temp'] ?? null
    ];
}

function calculateDelayFactors(float $lat, float $lng): array {
    $apiKey = defined('OPENWEATHER_API_KEY') ? OPENWEATHER_API_KEY : '';
    $weather = getWeatherDelay($lat, $lng, $apiKey);
    $rush    = getRushHourDelay();

    $totalDelay = $weather['minutes'] + $rush['minutes'];

    return [
        'ok'               => true,
        'total_delay_min'  => round($totalDelay, 1),
        'weather_delay_min'=> $weather['minutes'],
        'weather_condition'=> $weather['condition'],
        'rush_delay_min'   => $rush['minutes'],
        'rush_reason'      => $rush['reason']
    ];
}