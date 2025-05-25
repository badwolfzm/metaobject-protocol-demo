<?php
require_once "MetaCompiler.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$compiler = new MetaCompiler();
$compiler->compile(file_get_contents("routes.dsl"));
$routes = $compiler->exportApiRoutes();

$path = $_GET["path"] ?? "/";
$method = $_SERVER["REQUEST_METHOD"];

foreach ($routes as $route) {
    if ($route["path"] === $path && $route["method"] === $method) {
        $handler = $route["handler"];
        try {
            $params = [];

            if ($method === "POST") {
                $raw = file_get_contents("php://input");
                $params = json_decode($raw, true) ?: [];
            } else {
                $params = $_GET;
            }

            unset($params["path"]);
            $args = $compiler->getOrderedArgsAsObjects($handler->methods["execute"], $params);
            $result = $handler->call("execute", ...$args);

            header("Content-Type: application/json");
            echo json_encode(["status" => "ok", "result" => $result]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
            exit;
        }
    }
}

http_response_code(404);
echo json_encode(["error" => "Not Found"]);
