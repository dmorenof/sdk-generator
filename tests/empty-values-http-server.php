<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
if ($address === false) { throw new RuntimeException('Missing server address.'); }
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 4; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing HTTP request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)][] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $query = $i === 1 ? 'body=&count=0&enabled=false&requiredEmpty=&strict=x' : 'body=&count=0&csv=&enabled=false&labels=&q=&requiredEmpty=&strict=x';
    $expected = ($i < 2 ? 'GET' : 'POST') . ($i === 3 ? ' /json?' : ' /items?') . $query . ' HTTP/1.1';
    $valid = $line === $expected && ($headers['x-trace'] ?? []) === ['trace'];
    if ($i < 2) { $valid = $valid && $body === ''; }
    elseif ($i === 2) { $valid = $valid && ($headers['content-type'] ?? []) === ['application/x-www-form-urlencoded'] && $body === 'count=0&enabled=false&labels=&name=&strict=x'; }
    else { $valid = $valid && ($headers['content-type'] ?? []) === ['application/json'] && json_decode($body, true, 512, JSON_THROW_ON_ERROR) === ['count' => 0, 'enabled' => false, 'labels' => [''], 'name' => '', 'nullable' => null, 'strict' => 'x']; }
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Empty-value semantics or query/body separation changed.'); }
}
fclose($socket);
