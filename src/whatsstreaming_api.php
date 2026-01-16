<?php
/**
 * What's Streaming? - Stream Fetching API
 */

error_reporting(0);

$cfg_file = "/boot/config/plugins/whatsstreaming/whatsstreaming.cfg";
$servers_file = "/boot/config/plugins/whatsstreaming/servers.json";

// Load configuration
if (!file_exists($cfg_file)) {
    echo "<div style='padding:15px; text-align:center; opacity:0.6;'>_(Configuration missing)_</div>";
    exit;
}
$cfg = parse_ini_file($cfg_file);

// Load servers
if (!file_exists($servers_file)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured.)_
          </div>";
    exit;
}
$servers = json_decode(file_get_contents($servers_file), true);
if (empty($servers)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured.)_
          </div>";
    exit;
}

/**
 * Helper to format seconds
 */
function formatTime($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) return sprintf("%d:%02d:%02d", $hours, $minutes, $secs);
    return sprintf("%d:%02d", $minutes, $secs);
}

/**
 * Fetch functions (Plex, Emby, Jellyfin)
 * Note: These reuse the logic from your original plugin, just ensuring CSS classes match 'ws-'
 */
function fetchPlexStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/status/sessions?X-Plex-Token={$server['token']}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) return ['error' => 'Connection failed'];
    
    $data = json_decode($response, true);
    $streams = [];
    if (isset($data['MediaContainer']['Metadata'])) {
        foreach ($data['MediaContainer']['Metadata'] as $session) {
            $title = $session['title'] ?? 'Unknown';
            if (isset($session['grandparentTitle'])) {
                $title = $session['grandparentTitle'] . ' - ' . $title;
                if (isset($session['parentIndex']) && isset($session['index'])) {
                    $title = $session['grandparentTitle'] . " - S{$session['parentIndex']}E{$session['index']}";
                }
            }
            $user = $session['User']['title'] ?? 'Unknown';
            $device = $session['Player']['device'] ?? $session['Player']['product'] ?? 'Unknown';
            $state = $session['Player']['state'] ?? 'playing';
            $viewOffset = isset($session['viewOffset']) ? $session['viewOffset'] / 1000 : 0;
            $duration = isset($session['duration']) ? $session['duration'] / 1000 : 0;
            
            // Transcode info
            $isTranscoding = isset($session['TranscodeSession']);
            $transcodeDetails = []; // Add detailed logic here if needed from previous file
            
            $streams[] = [
                'server_name' => $server['name'], 'server_type' => 'plex', 'title' => $title,
                'user' => $user, 'device' => $device, 'state' => $state === 'paused' ? 'paused' : 'playing',
                'progress' => $viewOffset, 'duration' => $duration, 'transcoding' => $isTranscoding, 'transcode_details' => $transcodeDetails
            ];
        }
    }
    return ['streams' => $streams];
}

function fetchEmbyStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/emby/Sessions?api_key={$server['token']}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) return ['error' => 'Connection failed'];
    
    $sessions = json_decode($response, true);
    $streams = [];
    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;
            $item = $session['NowPlayingItem'];
            $title = $item['Name'] ?? 'Unknown';
            if (isset($item['SeriesName'])) $title = $item['SeriesName'] . ' - ' . $title;
            $user = $session['UserName'] ?? 'Unknown';
            $device = $session['DeviceName'] ?? 'Unknown';
            $isPaused = isset($session['PlayState']['IsPaused']) && $session['PlayState']['IsPaused'];
            $position = isset($session['PlayState']['PositionTicks']) ? $session['PlayState']['PositionTicks'] / 10000000 : 0;
            $duration = isset($item['RunTimeTicks']) ? $item['RunTimeTicks'] / 10000000 : 0;
            $isTranscoding = ($session['PlayState']['PlayMethod'] ?? '') === 'Transcode';
            
            $streams[] = [
                'server_name' => $server['name'], 'server_type' => 'emby', 'title' => $title,
                'user' => $user, 'device' => $device, 'state' => $isPaused ? 'paused' : 'playing',
                'progress' => $position, 'duration' => $duration, 'transcoding' => $isTranscoding, 'transcode_details' => []
            ];
        }
    }
    return ['streams' => $streams];
}

function fetchJellyfinStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/Sessions";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: {$server['token']}"]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) return ['error' => 'Connection failed'];
    
    $sessions = json_decode($response, true);
    $streams = [];
    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;
            $item = $session['NowPlayingItem'];
            $title = $item['Name'] ?? 'Unknown';
            if (isset($item['SeriesName'])) $title = $item['SeriesName'] . ' - ' . $title;
            $user = $session['UserName'] ?? 'Unknown';
            $device = $session['DeviceName'] ?? 'Unknown';
            $isPaused = isset($session['PlayState']['IsPaused']) && $session['PlayState']['IsPaused'];
            $position = isset($session['PlayState']['PositionTicks']) ? $session['PlayState']['PositionTicks'] / 10000000 : 0;
            $duration = isset($item['RunTimeTicks']) ? $item['RunTimeTicks'] / 10000000 : 0;
            $isTranscoding = ($session['PlayState']['PlayMethod'] ?? '') === 'Transcode';
            
            $streams[] = [
                'server_name' => $server['name'], 'server_type' => 'jellyfin', 'title' => $title,
                'user' => $user, 'device' => $device, 'state' => $isPaused ? 'paused' : 'playing',
                'progress' => $position, 'duration' => $duration, 'transcoding' => $isTranscoding, 'transcode_details' => []
            ];
        }
    }
    return ['streams' => $streams];
}

// Fetch all
$allStreams = [];
$errors = [];
foreach ($servers as $server) {
    switch ($server['type']) {
        case 'plex': $result = fetchPlexStreams($server); break;
        case 'emby': $result = fetchEmbyStreams($server); break;
        case 'jellyfin': $result = fetchJellyfinStreams($server); break;
        default: $result = ['error' => 'Unknown type'];
    }
    if (isset($result['error'])) $errors[] = "{$server['name']}: {$result['error']}";
    elseif (isset($result['streams'])) $allStreams = array_merge($allStreams, $result['streams']);
}

// Output HTML
if (empty($allStreams)) {
    if (!empty($errors)) {
        echo "<div style='padding:15px; text-align:center; color:#d44;'><i class='fa fa-exclamation-circle'></i> " . implode(', ', $errors) . "</div>";
    } else {
        echo "<div style='padding:15px; text-align:center; opacity:0.6; font-style:italic;'>_(No active streams)_</div>";
    }
} else {
    foreach ($allStreams as $s) {
        $title = htmlspecialchars($s['title']);
        $user = htmlspecialchars($s['user']);
        $device = htmlspecialchars($s['device']);
        $statusColor = ($s['state'] === 'paused') ? "#f0ad4e" : "#8cc43c";
        $statusIcon = ($s['state'] === 'paused') ? "fa-pause" : "fa-play";
        $timeDisplay = formatTime($s['progress']) . " / " . formatTime($s['duration']);
        
        $typeColors = ['plex' => '#e5a00d', 'emby' => '#52b54b', 'jellyfin' => '#00a4dc'];
        $typeColor = $typeColors[$s['server_type']] ?? '#888';
        
        $transcodeHtml = $s['transcoding'] ? " <i class='fa fa-random' style='color:#e5a00d; cursor:help;' title='Transcoding'></i>" : "";
        
        echo "<div class='ws-row'>";
        echo "<span class='ws-server' style='color:$typeColor;' title='{$s['server_name']}'><i class='fa fa-circle' style='font-size:8px;'></i></span>";
        echo "<span class='ws-name' title='$title'>$title</span>";
        echo "<span class='ws-device' title='$device'>$device</span>";
        echo "<span class='ws-user' title='$user'>$user</span>";
        echo "<span class='ws-time' style='color:$statusColor;' title='$timeDisplay'><i class='fa $statusIcon' style='font-size:9px;'></i>$transcodeHtml <span style='margin-left:4px;'>$timeDisplay</span></span>";
        echo "</div>";
    }
}
?>
