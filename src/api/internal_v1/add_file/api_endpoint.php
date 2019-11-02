<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

if (! defined("VAPORFS_ROUTED")) {
    http_response_code(500); // this file should be UNREACHABLE without router.php, hence 500 Internal Server Error...
    die("Internal server error: api_endpoint.php reached without VAPORFS_ROUTER, should be impossible!");
    return;
}

/** @var string $uri */
/** @var string $method */
/** @var string[] $uri_args */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vaporfs_response()->code = 405; // HTTP 405 Method Not Allowed
    vaporfs_response()->status_text = "only POST requests are allowed on this endpoint.";
    vaporfs_response()->data["debug_request_type"] = $_SERVER['REQUEST_METHOD'];
    return;
}
$api_key = (string) ($_POST['api_key'] ?? '');
if (strlen($api_key) > 20) {
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "api key too long: api key max length is 20 bytes, you sent an api key of " . strlen($api_key) . " bytes...";
    return;
}
$f = new Add_file_argument();
$user_id = null; // 1=nobody
if (empty($api_key)) {
    if (! (getDB()->query("SELECT enabled FROM user_api_keys WHERE id = 1")->fetch(\PDO::FETCH_NUM)[0])) {
        vaporfs_response()->code = 403; // forbidden
        vaporfs_response()->status_text = "uploads without api_key is disabled - api key is required to upload.";
        return;
    }
    $user_id = 1; // nobody
} else {
    $tmp = getDB()->query("SELECT enabled,user_id FROM user_api_keys WHERE api_key = " . getDB()->quote($api_key))
        ->fetch(\PDO::FETCH_NUM);
    //
    if (! $tmp) {
        vaporfs_response()->code = 403; // forbidden
        vaporfs_response()->status_text = "invalid api key (key not found)";
        return;
    }
    if (! $tmp[0]) {
        vaporfs_response()->code = 403; // forbidden
        vaporfs_response()->status_text = "api key disabled.";
        return;
    }
    $user_id = $tmp[1];
    unset($tmp);
}
$f->owner_id = $user_id;
assert($user_id >= 1);
$name = (string) ($_POST['name'] ?? '');
if (empty($name)) {
    if (! empty($_FILES['file']['name'])) {
        $name = (string) ($_FILES['file']['name']);
    }
}
if (strlen($name) < 1) {
    // ....
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "files with no name is not allowed (for now at least?)";
    return;
}
if (! mb_check_encoding($name, 'UTF-8')) {
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "name MUST be UTF-8 encoded! it appears the name is not in utf-8";
    return;
}
if (($utf8len = mb_strlen($name, 'UTF-8')) > 200) {
    // ... 200 * 4 = 800 bytes for storage? hmm
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "name cannot be longer than 200 UTF-8 characters! (was " . $utf8len . " utf8 characters)";
    return;
}
$f->name = $name;

$b2sum = call_user_func(function (): ?string {
    $tmp = (string) ($_POST['b2sum_hex'] ?? '');
    if (strlen($tmp) >= 40) {
        return substr(hex2bin($tmp), 0, 20);
    }
    $tmp = (string) ($_POST['b2sum_raw'] ?? '');
    if (strlen($tmp) >= 20) {
        return substr($tmp, 0, 20);
    }
    return null;
});
$f->b2sum_160_raw = $b2sum;
$inode = null;
if (! empty($b2sum)) {
    $inode = (new File_db())->get_inode_by_b2sum($b2sum, false);
}
$f->inode = $inode;
if ($inode === null) {
    /*
     * array (size=1)
     * 'file' =>
     * array (size=5)
     * 'name' => string 'testfile.txt' (length=12)
     * 'type' => string 'text/plain' (length=10)
     * 'tmp_name' => string '/tmp/phpB0jPhI' (length=14)
     * 'error' => int 0
     * 'size' => int 7
     *
     */
    if (empty($_FILES['file'])) {
        vaporfs_response()->code = 400;
        vaporfs_response()->status_text = "no file uploaded!";
        return;
    }
    if ($_FILES['file']['error'] !== 0) {
        // ....
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_INI_SIZE): " . "The uploaded file exceeds the upload_max_filesize directive in php.ini";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_FORM_SIZE): " . "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
                break;
            case UPLOAD_ERR_PARTIAL:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_PARTIAL): " . "The uploaded file was only partially uploaded";
                break;
            case UPLOAD_ERR_NO_FILE:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_NO_FILE): " . "No file was uploaded";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_NO_TMP_DIR): " . "Missing a temporary folder";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_CANT_WRITE): " . "Failed to write file to disk";
                break;
            case UPLOAD_ERR_EXTENSION:
                vaporfs_response()->status_text = "file upload error (PHP: UPLOAD_ERR_EXTENSION): " . "File upload stopped by extension";
                break;
            default:
                vaporfs_response()->status_text = "Unknown upload error";
                break;
        }
        vaporfs_response()->code = 400;
        return;
    }
    // TODO: content-type: $_FILES['file']['type']
    $f->file_path = $_FILES['file']['tmp_name'];
}

$f->upload_ip = $_SERVER['REMOTE_ADDR'];
try {
    $file_id = (new File_db())->add_file($f);
} catch (\InvalidArgumentException $ex) {
    vaporfs_response()->code = 400;
    vaporfs_response()->status_text = "add_file() error: " . $ex->getMessage();
    return;
} // can also throw RuntimeException, but in that case we will deliberately not catch it (a HTTP 500 is appropriate.)

vaporfs_response()->code = 200;


vaporfs_response()->status_text = "hello, world! status!";
vaporfs_response()->data["testdata"] = "Hello world!";
vaporfs_response()->data["testdata2"] = array(
    'uri' => $uri,
    'method' => $method,
    'uri_args' => $uri_args,
    'foo' => (new File_db())->get_inode_by_b2sum(str_repeat("\x00", 20), false)
);
//vaporfs_response()->disabled=true;
//var_dump(vaporfs_response());

