<?php

// CurlTransportTest 使用的 keep-alive 服务器：同一连接上连续处理多个请求，响应正文是自己监听的地址
[, $ip, $port] = $argv;
$server = stream_socket_server("tcp://{$ip}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
while ($connection = @stream_socket_accept($server, -1)) {
    while (fgets($connection) !== false) {
        // 跳过请求头（测试只发 GET，没有请求体）
        while (($line = fgets($connection)) !== false && rtrim($line) !== '') {
        }
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($ip) . "\r\nConnection: keep-alive\r\n\r\n{$ip}");
    }
    fclose($connection);
}
