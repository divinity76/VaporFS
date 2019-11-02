<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

const BLACKLISTED_METHODS = array(
    '.',
    '..',
    'conf',
    'debug'
);
require_once ('init.php');

ini_set('html_errors', '0');
ini_set('xdebug.overload_var_dump', '2');

function get_methods(): array
{
    return array_values(array_filter(array_diff(glob("*", GLOB_ONLYDIR), BLACKLISTED_METHODS), function (string $foldername) {
        return file_exists(__DIR__ . DIRECTORY_SEPARATOR . $foldername . DIRECTORY_SEPARATOR . "api_endpoint.php");
    }));
}

class Vaporfs_standard_response
{

    public $disabled = false;

    public $code = 500;

    public $status_text = "";

    public $data = [];
}

function vaporfs_response(): \vaporfs\api\v1\Vaporfs_standard_response
{
    static $initialized = false;
    /** @var \vaporfs\api\v1\Vaporfs_standard_response $response */
    static $response;
    if (! $initialized) {
        $response = new Vaporfs_standard_response();
        register_shutdown_function(function () use (&$response) {
            if (! $response->disabled) {
                unset($response->disabled);
                assert(headers_sent() === false);
                if ($response->code === 500 && empty($response->status_text)) {
                    $response->status_text = "internal server error (default response not modified! bug probably)";
                } elseif ($response->code === 200 && empty($response->status_text)) {
                    $response->status_text = "ok";
                }
                http_response_code($response->code);
                header("Content-Type: application/json");
                echo json_encode_pretty($response);
            }
        });
        set_exception_handler(function (\Throwable $ex) use (&$response) {
            if (true || ! $response->disabled) {
                $original_response = $response;
                $response = new Vaporfs_standard_response();
                $response->code = 500;
                $response->status_text = "500 internal server error, uncaught exception - encrypted debug info in data...";
                $response->data["debug_info"] = var_dump_encrypt($ex, $original_response);
            }
            throw $ex;
        });
        $initialized = true;
    }
    return $response;
}
vaporfs_response();
$uri = $_SERVER['REQUEST_URI'];
if (0 !== stripos($uri, "/api/v1/")) {
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "error: router accessed without api_base_url: " . config()->api_basse_url;
    return;
}
$uri = substr($uri, strlen(config()->api_basse_url));
$uri_args = explode("/", $uri);
$method = strtolower($uri_args[0] ?? '');
unset($uri_args[0]);
$uri_args = array_values($uri_args);
if (0 === strlen($method)) {
    // no method supplied
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "no method supplied";
    vaporfs_response()->data["debug_available_methods"] = get_methods();
    return;
}
if (in_array($method, BLACKLISTED_METHODS, false)) {
    // blacklisted method supplied
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "blacklisted method: {$method}";
    vaporfs_response()->data["debug_available_methods"] = get_methods();
    return;
}
if (! is_file(__DIR__ . DIRECTORY_SEPARATOR . $method . DIRECTORY_SEPARATOR . "api_endpoint.php")) {
    // unknown method..
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "unknown method: {$method}";
    vaporfs_response()->data["debug_available_methods"] = get_methods();
    return;
}
define("VAPORFS_ROUTED", true);
require_once (__DIR__ . DIRECTORY_SEPARATOR . $method . DIRECTORY_SEPARATOR . "api_endpoint.php");
