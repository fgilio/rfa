<?php

// TEMPORARY CI diagnostic: time Playwright protocol calls and in-process HTTP requests.

$log = "(getenv('PEST_DIAG_LOG') ?: '/tmp/pest-diag.log')";
$base = __DIR__.'/../../vendor/pestphp/pest-plugin-browser/src';

$client = "$base/Playwright/Client.php";
$s = file_get_contents($client);
$s = str_replace(
    "        \$this->websocketConnection->sendText(\$requestJson);\n",
    "        \$this->websocketConnection->sendText(\$requestJson);\n".
    "        \$__start = hrtime(true);\n".
    "        \$__log = function (string \$outcome) use (\$__start, \$method, \$timeout, \$params): void {\n".
    "            \$ms = (int) ((hrtime(true) - \$__start) / 1_000_000);\n".
    "            if (\$ms >= 300 || \$outcome !== 'ok') {\n".
    "                file_put_contents($log, sprintf(\"%s pid=%d PW %s %s %dms timeout=%d %s %s\\n\", (new \\DateTimeImmutable)->format('H:i:s.v'), getmypid(), \$outcome, \$method, \$ms, \$timeout, json_encode(array_intersect_key(\$params, ['url' => 1, 'waitUntil' => 1, 'selector' => 1])), function_exists('test') && test() ? test()->name() : ''), FILE_APPEND);\n".
    "            }\n".
    "        };\n",
    $s, $count1);
$s = str_replace(
    "                throw new ExpectationFailedException(\$message);\n            }\n\n            yield \$response;",
    "                \$__log('ERR '.substr(str_replace(\"\\n\", ' | ', \$message), 0, 600));\n                throw new ExpectationFailedException(\$message);\n            }\n\n            yield \$response;",
    $s, $count2);
$s = str_replace(
    "            if (isset(\$response['id']) && \$response['id'] === \$requestId) {\n                break;",
    "            if (isset(\$response['id']) && \$response['id'] === \$requestId) {\n                \$__log('ok');\n                break;",
    $s, $count3);
file_put_contents($client, $s);

$server = "$base/Drivers/LaravelHttpServer.php";
$s = file_get_contents($server);
$s = str_replace(
    'new ClosureRequestHandler($this->handleRequest(...)),',
    'new ClosureRequestHandler(function (AmpRequest $request): Response { $__start = hrtime(true); try { return $this->handleRequest($request); } finally { $ms = (int) ((hrtime(true) - $__start) / 1_000_000); if ($ms >= 150) { file_put_contents('.$log.', sprintf("%s pid=%d HTTP %s %s %dms\n", (new \\DateTimeImmutable)->format("H:i:s.v"), getmypid(), $request->getMethod(), $request->getUri()->getPath(), $ms), FILE_APPEND); } } }),',
    $s, $count4);
file_put_contents($server, $s);

$manager = "$base/ServerManager.php";
$s = file_get_contents($manager);
$s = str_replace("'.'.DIRECTORY_SEPARATOR.'node_modules'", "'exec .'.DIRECTORY_SEPARATOR.'node_modules'", $s);
$s = str_replace("--mode launchServer',", "--mode launchServer 2>>/tmp/pw-server.log',", $s, $count5);
file_put_contents($manager, $s);

$client = "$base/Playwright/Client.php";
$s = file_get_contents($client);
$s = str_replace("                \$__log('ERR '", "                file_put_contents($log, shell_exec('date +%T.%N; uptime; ps -eo pid,ppid,etime,pcpu,rss,stat,args --sort=-pcpu | cut -c1-200 | head -40'), FILE_APPEND);\n                \$__log('ERR '", $s, $count6);
file_put_contents($client, $s);

echo "patched: $count5 $count6\n";
echo "patched: $count1 $count2 $count3 $count4\n";
exit($count1 && $count2 && $count3 && $count4 && $count5 && $count6 ? 0 : 1);
