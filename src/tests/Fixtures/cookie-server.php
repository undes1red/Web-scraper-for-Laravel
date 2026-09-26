<?php

header('Content-Type: text/plain');
header('Set-Cookie: session=rotated; Path=/; HttpOnly');

echo $_SERVER['HTTP_COOKIE'] ?? '';
