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

if (empty($uri_args[0])) {
    // ...
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "no file id requested";
    return;
}
$file_id = $uri_args[0];
if (false === ($file_id = filter_var($file_id, FILTER_VALIDATE_INT))) {
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "invalid file id format";
    return;
}
unset($uri_args[0]);
$uri_args = array_values($uri_args);
// this can be optimized for public = 1, but currently is.. inadvertedly practically optimized for public = 0, o well not that it matters (yet?)
$res = getDB()->query("SELECT files.inode_id,files.name,files.public,inodes.hash_blake2b512_160 AS `hash` FROM files INNER JOIN inodes ON files.inode_id = inodes.id WHERE files.id = " . ((int) $file_id))->fetch(\PDO::FETCH_ASSOC);

if (false === $res) {
    vaporfs_response()->code = 404;
    vaporfs_response()->status_text = "file not found";
    vaporfs_response()->data['file_id'] = $file_id;
    return;
}

if (! $res['public']) {
    if (empty($uri_args[0]) || ! hash_equals($res['hash'], $uri_args[0])) {
        vaporfs_response()->code = 403;
        vaporfs_response()->status_text = "this file is not public, and invalid key supplied.";
        vaporfs_response()->data['debug_supplied_invalid_key'] = ($uri_args[0] ?? '');
        return;
    }
    // key is correct.
    unset($uri_args[0]);
    $uri_args = array_values($uri_args);
}

// disabled (for now?) *UNTESTED* redirect code...
if (false) {
    $supplied_url_file_name = '/' . str_replace('%2F', '/', implode('/', $uri_args));
    $correct_url_file_name = "/" . str_replace('%2F', '/', $res['name']);
    var_dump('$supplied_url_file_name', $supplied_url_file_name, '$uri', $uri, '$method', $method, '$uri_args', $uri_args, '$res', $res) & die();
    // / redirect them?
    if ($supplied_url_file_name !== $correct_url_file_name) {
        $redirect = config()->api_complete_base_url . "file_get/{$file_id}/";
        if (! $res['public']) {
            $redirect .= base64url_encode($res['hash']) . "/";
        }
        $redirect .= $correct_url_file_name;
        // vaporfs_response()->disabled = true;
        vaporfs_response()->code = 307;
        header("Location: {$redirect}");
        vaporfs_response()->status_text = "you're being HTTP 307 redirected..";
        vaporfs_response()->data['redirect_url'] = $redirect;
        return;
    }
}
// time to serve the actual file i guess.
vaporfs_response()->disabled = true;
$header = "X-Accel-Redirect: /api/inodes/{$res['inode_id']}";
//var_dump(headers_list(), $header);
//header($header);
header("Content-Type: text/html;charset=utf-8");
header("X-Accel-Redirect: /inodes/7");


