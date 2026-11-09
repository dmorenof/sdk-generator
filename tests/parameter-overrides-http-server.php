<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
if ($address === false) { throw new RuntimeException('No server address.'); }
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($index = 0; $index < 2; ++$index) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing HTTP request.'); }
    stream_set_timeout($connection, 15);
    $line = fgets($connection); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$name, $value] = explode(':', $header, 2); $headers[strtolower($name)][] = trim($value);
    }
    $body = ''; $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $expectedLine = $index === 0 ? 'GET /items/.a?body=query%20body&selector=0 HTTP/1.1' : 'POST /items/;id=a?body=query%20body&selector=x%2Fy HTTP/1.1';
    $valid = trim((string) $line) === $expectedLine && ($headers['selector'] ?? []) === [$index === 0 ? 'hdr' : 'h'] && ($headers['cookie'] ?? []) === ['theme=dark']
        && ($index === 0 ? $body === '' : $body === '{"name":"body name"}');
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Parameter override changed request semantics.'); }
}
fclose($socket);
