<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

if (! defined("VAPORFS_ROUTED")) {
    http_response_code(500); // this file should be UNREACHABLE without router.php, hence 500 Internal Server Error...
    die("Internal server error: api_endpoint.php reached without VAPORFS_ROUTER, should be impossible!");
    return;
}
vaporfs_response()->disabled = true;
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = base64_encode(random_bytes(9));
}
if (! empty($_POST)) {
    $csrf_valid = (! empty($_SESSION['csrf_token']) && ! empty($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']));
    if (! $csrf_valid) {
        echo "invalid csrf!";
    } else {
        $encryption_password = (string) ($_POST['encryption_password'] ?? '');
        $data = (string) ($_POST['encrypted'] ?? '');
        try {
            echo (var_dump_decrypt($data, $encryption_password));
        } catch (\InvalidArgumentException $ex) {
            echo "decryption error: " . $ex->getMessage();
        }
    }
    return;
}
?>
<!DOCTYPE HTML>
<html>
<head>
<script
	src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
<title>dunno</title>
</head>
<body>
<?php //var_dump($_POST);?>
<input type="button" id="update" value="update">
	<br />
	<input type="hidden" id="csrf_token"
		value="<?php echo hhb_tohtml($_SESSION['csrf_token']);?>" />
	encryption password:
	<br />
	<input type="password" id="encryption_password"
		name="encryption_password">
	<br> paste encrypted data here:
	<br />
	<textarea name="encrypted" id="encrypted"
		placeholder="paste your encrypted string in here."></textarea>
	<br /> result:
	<br />
	<!--  white-space: pre-line; -->
	<pre id="result" style="background-color: grey;">(result will go here)</pre>
	<script>
$("#update").on("click",function(){
	$("#result").text("loading...");
	var xhr=new XMLHttpRequest();
	xhr.addEventListener("readystatechange",function(ev){
		if(xhr.readyState<3){
			$("#result")[0].textContent+=xhr.readyState+"..";
			return;
		}
		$("#result")[0].textContent=xhr.responseText;
	});
	xhr.open("POST","");
	var fd=new FormData();
	fd.append("encryption_password",$("#encryption_password").val());
	fd.append("encrypted",$("#encrypted").val().trim());
	fd.append("csrf_token",$("#csrf_token").val());
	xhr.send(fd);
});
</script>
</body>
</html>