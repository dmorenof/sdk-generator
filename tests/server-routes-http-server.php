<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false); echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$targets = ['/configured/root/a%2Fb', '/audit/items/a%2Fb?body=query%20body', '/export/items/a%2Fb?body=query%20body', '/configured/equivalent/a%2Fb', '/configured/mirror/a%2Fb/a%2Fb', '/configured/assets/report.csv'];
foreach ($targets as $index => $target) {
    $connection = stream_socket_accept($socket, 15); if ($connection === false) { throw new RuntimeException('Missing server-route request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete server-route body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $valid = $line === ($index === 2 ? 'POST ' : 'GET ') . $target . ' HTTP/1.1' && ($headers['accept'] ?? '') === 'application/json';
    if ($index === 1 || $index === 2) { $valid = $valid && ($headers['x-trace'] ?? '') === 'trace'; }
    if ($index === 2) { $valid = $valid && $body === '{"value":"valid"}' && ($headers['content-type'] ?? '') === 'application/json'; }
    else { $valid = $valid && $body === '' && !isset($headers['content-type']); }
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Expanded server, URI placeholders or parameter locations changed at ' . $index); }
}
fclose($socket);
