<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

if (! defined("VAPORFS_ROUTED")) {
    http_response_code(500); // this file should be UNREACHABLE without router.php, hence 500 Internal Server Error...
    die("Internal server error: api_endpoint.php reached without VAPORFS_ROUTER, should be impossible!");
    return;
}
// throw new \Exception("lol");

/** @var string $uri */
/** @var string $method */
/** @var string[] $uri_args */

vaporfs_response()->code = 200;
vaporfs_response()->status_text = "hello, world! status!";
vaporfs_response()->data["testdata"] = "Hello world!";
vaporfs_response()->data["testdata2"] = array(
    'uri' => $uri,
    'method' => $method,
    'uri_args' => $uri_args,
    'foo' => (new File_db())->get_inode_by_b2sum(str_repeat("\x00",20), false)
);
//vaporfs_response()->disabled=true;
//var_dump(vaporfs_response());

