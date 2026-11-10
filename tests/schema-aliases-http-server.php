<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false); echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$expected = ['/record' => '{"id":1,"status":"open","name":"Alice"}', '/node' => '{"id":1,"next":{"id":2}}', '/choice' => '{"id":1,"name":"Alice"}', '/map' => '{"one":1}', '/status' => '"open"', '/filter' => '"open"'];
foreach ($expected as $path => $payload) {
    $connection = stream_socket_accept($socket, 15); if ($connection === false) { throw new RuntimeException('Missing alias request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete alias body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    [$method, $target] = explode(' ', $line); $url = parse_url($target);
    $valid = ($url['path'] ?? '') === $path && ($headers['accept'] ?? '') === 'application/json';
    if ($path === '/filter') {
        parse_str($url['query'] ?? '', $query); $valid = $valid && $method === 'GET' && $body === '' && $query === ['filter' => ['state' => 'closed']];
    } else { $valid = $valid && $method === 'POST' && ($headers['content-type'] ?? '') === 'application/json' && json_decode($body, false, 512, JSON_THROW_ON_ERROR) == json_decode($payload, false, 512, JSON_THROW_ON_ERROR); }
    fwrite($connection, ($valid ? 'HTTP/1.1 200 OK' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);
    fclose($connection); if (!$valid) { throw new RuntimeException('Alias schema types or HTTP values changed.'); }
}
fclose($socket);
