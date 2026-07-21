<?php
$body = "OK";
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
