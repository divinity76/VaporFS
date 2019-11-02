<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

require_once ('functions.inc.php');
if (! defined("VAPORFS_ROUTED")) {
    http_response_code(500); // this file should be UNREACHABLE without router.php, hence 500 Internal Server Error...
    die("Internal server error: api_endpoint.php reached without VAPORFS_ROUTED, should be impossible!");
    return;
}
/** @var string[] $uri_args */

vaporfs_response()->code = 200;
vaporfs_response()->data["result"] = (new File_db())->hash_b2sum("lol", false);
