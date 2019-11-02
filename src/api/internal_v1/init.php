<?php
declare(strict_types = 1);
namespace vaporfs\api\v1;

require_once (__DIR__ . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'config.php');

assert(is_readable(config()->inode_folder));
assert(substr(config()->inode_folder, - 1) === DIRECTORY_SEPARATOR);

// misc functions below
function getDB(): \PDO
{
    /** @var \PDO @db */
    static $db = null;
    if ($db === null) {
        $db = new \PDO(config()->db_dsn, config()->db_username, config()->db_password, array(
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ));
    }
    return $db;
}

function json_encode_pretty($data, int $extra_flags = 0, int $exclude_flags = 0): string
{
    // prettiest flags for: 7.3.9
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined("JSON_UNESCAPED_LINE_TERMINATORS") ? JSON_UNESCAPED_LINE_TERMINATORS : 0) | JSON_PRESERVE_ZERO_FRACTION | (defined("JSON_THROW_ON_ERROR") ? JSON_THROW_ON_ERROR : 0);
    $flags = ($flags | $extra_flags) & ~ $exclude_flags;
    return (json_encode($data, $flags));
}

class Gzip
{

    public static function compress_string(string $data, int $level = 6)
    {
        if ($level < 1 || $level > 9) {
            throw new \InvalidArgumentException("level must be between 1-9 inclusive (level: {$level})");
        }
        $stdout = $stderr = "";
        $gzip_ret = hhb_shell_exec1("gzip --stdout --force -{$level}", $data, $stdout, $stderr);
        if ($gzip_ret !== 0) {
            throw new \RuntimeException("gzip returned non-zero: {$gzip_ret} - stderr: {$stderr}");
        }
        return $stdout;
    }

    public static function decompress_string(string $data): string
    {
        $stdout = $stderr = "";
        $gzip_ret = hhb_shell_exec1("gzip --stdout --decompress --force", $data, $stdout, $stderr);
        if ($gzip_ret !== 0) {
            throw new \RuntimeException("gzip returned non-zero: {$gzip_ret} - stderr: {$stderr}");
        }
        return $stdout;
    }
}

class B2sum
{

    public static function hash_string(string $data, bool $raw = true, int $truncate_bytes = 64): string
    {
        if ($truncate_bytes < 0) {
            throw new \InvalidArgumentException("truncate_bytes < 0");
        }
        if ($truncate_bytes > 64) {
            throw new \InvalidArgumentException("truncate_bytes > 64 (64 is full blake2b length)");
        }
        $stdout = $stderr = "";
        $b2sum_ret = hhb_shell_exec1("b2sum --binary -", $data, $stdout, $stderr);
        if ($b2sum_ret !== 0) {
            throw new \RuntimeException("b2sum returned non-zero: {$b2sum_ret} - stderr: {$stderr}");
        }
        $ret = substr($stdout, 0, $truncate_bytes * 2);
        return ($raw ? hex2bin($ret) : $ret);
    }

    public static function hash_file(string $filename, bool $raw = true, int $truncate_bytes = 64): string
    {
        if ($truncate_bytes < 0) {
            throw new \InvalidArgumentException("truncate_bytes < 0");
        }
        if ($truncate_bytes > 64) {
            throw new \InvalidArgumentException("truncate_bytes > 64 (64 is full blake2b length)");
        }
        $output = [];
        $b2sum_ret = null;
        exec("b2sum --binary " . escapeshellarg($filename) . " 2>&1", $output, $b2sum_ret);
        if ($b2sum_ret !== 0) {
            throw new \RuntimeException("b2sum returned non-zero: {$b2sum_ret} - stdout+stderr: " . implode("\n", $output));
        }
        $ret = substr($output[0], 0, $truncate_bytes * 2);
        return ($raw ? hex2bin($ret) : $ret);
    }
}

function var_dump_encrypt(): string
{
    return call_user_func_array('\vaporfs\api\v1\var_dump_encrypt_v1', func_get_args());
}

function var_dump_encrypt_v1(): string
{
    // checksum = first 10 bytes of blake2b(iv+random_byte+data)
    // hhb_var_dump_encrypted_v1:1:(number of bytes of the following base64):base64(iv+aes256ctr(checksum+random_byte+data))
    $encrypt = config()->debug_data_enable_encryption;
    $data = "";
    if ($encrypt) {
        $data = "crypt_sucess\n";
    } else {
        $data = "crypt_disabled\n";
    }
    $data .= "at " . date(\DateTime::ATOM) . ":\n";
    ob_start();
    echo "backtrace: \n";
    debug_print_backtrace();
    echo "\narguments: \n";
    call_user_func_array('var_dump', func_get_args());
    $data .= ob_get_clean();
    if ($encrypt) {
        $method = "aes-256-ctr";
        $key = config()->debugdata_encryption_key;
        $flags = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
        $data = Gzip::compress_string($data, 9);
        $iv = random_bytes(16);
        $random_byte = random_bytes(1);
        $data = $random_byte . $data;
        $checksum = B2sum::hash_string($iv . $data, true, 10);
        $data = $checksum . $data;
        $data = openssl_encrypt($data, $method, $key, $flags, $iv);
        $data = base64_encode($iv . $data);
    }
    $data = "hhb_var_dump_encrypted_v1:" . ($encrypt ? "1" : "0") . ":" . strlen($data) . ":" . $data;
    return $data;
}

function var_dump_decrypt(string $data, string $key): string
{
    return var_dump_decrypt_v1($data, $key);
}

function var_dump_decrypt_v1(string $data, string $key): string
{
    $header = "hhb_var_dump_encrypted_v1:";
    if (0 !== strpos($data, $header)) {
        throw new \InvalidArgumentException('missing header "' . $header . '"');
    }
    $data = substr($data, strlen($header));
    $is_encrypted_header = substr($data, 0, 2);
    if ($is_encrypted_header === "0:") {
        $end_of_size_header = strpos($data, ":", 2);
        $data = substr($data, $end_of_size_header);
        return $data;
    }
    if ($is_encrypted_header !== "1:") {
        throw new \InvalidArgumentException("invalid is_encrypted header! (neither 0: or 1:)");
    }
    $data = substr($data, 2);
    $len_end = strpos($data, ":");
    if (false === $len_end) {
        throw new \InvalidArgumentException("missing length-header ending colon delimiter.");
    }
    $len = substr($data, 0, $len_end);
    if (false === ($len = filter_var($len, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 4 // should probably be significantly higher..
        ]
    ]))) {
        throw new \InvalidArgumentException("length header is not a valid positive integer!");
    }
    $data = substr($data, $len_end + strlen(":"));
    $data = preg_replace("/\s+/", "", $data); // begone newlines if any
    if (($dlen = strlen($data)) < $len) {
        throw new \InvalidArgumentException("length header says base64 is {$len} bytes long, insufficient ({$dlen}) base64 bytes provided!");
    }
    $data = base64_decode(substr($data, 0, $len), true); // bytes beyond $len, if any, is discarded and ignored.
    if (false === $data) {
        throw new \InvalidArgumentException('invalid base64 characters!');
    }
    // (16=IV)+(10=hash)+(1=random_byte)=27
    // should gzip header be added?
    if (strlen($data) < 27) {
        throw new \InvalidArgumentException("base64-decoded data less than 27 bytes! ..");
    }
    $iv = substr($data, 0, 16);
    $data = substr($data, 16);
    $method = "aes-256-ctr";
    $flags = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
    $data = openssl_decrypt($data, $method, $key, $flags, $iv);
    if (false === $data) {
        throw new \InvalidArgumentException("openssl_decrypt failed!");
    }
    $checksum = substr($data, 0, 10);
    $data = substr($data, strlen($checksum));
    if (! hash_equals(B2sum::hash_string($iv . $data, true, strlen($checksum)), $checksum)) {
        throw new \InvalidArgumentException("incorrect checksum, corrupt data or wrong password.");
    }
    $data = substr($data, 1); // remove random_byte.
    $data = Gzip::decompress_string($data);
    if (0 !== strpos($data, "crypt_sucess\n")) {
        throw new \InvalidArgumentException("decompressed data did not start with \"crypt_success\\n\" - perhaps wrong password?");
    }
    return $data;
}

function fwrite_all($handle, string $data): void
{
    $len = $original_len = strlen($data);
    $written_total = 0;
    while ($len > 0) {
        $written_now = fwrite($handle, $data);
        if ($written_now === $len) {
            return;
        }
        if ($written_now <= 0) {
            throw new \RuntimeException("could only write {$written_total}/{$original_len} bytes!");
        }
        $written_total += $written_now;
        $data = substr($data, $written_now);
        $len -= $written_now;
        assert($len > 0);
    }
}

function hhb_shell_exec1(string $cmd, string $stdin = null, string &$stdout = null, string &$stderr = null): int
{
    // use a tmpfile in case stdout is so large that the pipe gets full before we read it, which would result in a deadlock
    $stdout_handle = tmpfile();
    $stderr_handle = tmpfile();
    $descriptorspec = array(
        // stdin is *inherited* by default, so even if $stdin is empty, we should create a stdin pipe just so we can close it.
        0 => array(
            "pipe",
            "rb"
        ),
        1 => $stdout_handle,
        2 => $stderr_handle
    );
    $pipes = [];
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    if (! $proc) {
        throw \RuntimeException("proc_exec failed!");
    }
    if (! is_null($stdin)) {
        fwrite_all($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $ret = proc_close($proc);
    rewind($stdout_handle); // stream_get_contents can seek but it has let me down earlier, https://bugs.php.net/bug.php?id=76268
    rewind($stderr_handle); //
    $stdout = stream_get_contents($stdout_handle);
    fclose($stdout_handle);
    $stderr = stream_get_contents($stderr_handle);
    fclose($stderr_handle);
    // echo "done!\n";
    return $ret;
}

/**
 * convert any string to valid HTML, as losslessly as possible, assuming UTF-8
 *
 * @param string $str
 * @return string
 */
function hhb_tohtml(string $str): string
{
    return htmlentities($str, ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8', true);
}

class Add_file_argument
{

    // these 4 are required
    public $file_path = null;

    public $name = null;

    public $owner_id = 0;

    public $upload_ip = null;

    // optional below
    /** @var bool $must_copy can the source file be moved (probably much faster), or must the source file be copied? (probably slower) */
    public $must_copy = false;

    public $b2sum_160_raw = null;

    public $upload_time = null;

    public $inode = null;
}

class File_db
{

    /** @var \PDO $db */
    public $db;

    function __construct(\PDO $db = null)
    {
        $this->db = (is_null($db) ? getDB() : $db);
    }

    public function has_b2sum(string $b2sum, bool $hex): bool
    {
        // return $this->get_inode_by_b2sum($b2sum) === null;
        if ($hex) {
            $b2sum = hex2bin($b2sum);
        }
        $stm = $this->db->prepare("SELECT EXISTS(SELECT 1 FROM inodes WHERE hash_blake2b512_160 = ?) AS result");
        $stm->execute([
            $b2sum
        ]);
        return (! ! ($stm->fetch(\PDO::FETCH_NUM)[0]));
    }

    public function get_inode_by_b2sum(string $b2sum, bool $hex): ?int
    {
        if ($hex) {
            $b2sum = hex2bin($b2sum);
        }
        $stm = $this->db->prepare('SELECT id FROM inodes WHERE hash_blake2b512_160 = ?');
        $stm->execute(array(
            $b2sum
        ));
        $res = $stm->fetch(\PDO::FETCH_NUM);
        if (! $res) {
            return null;
        }
        return $res[0];
    }

    public function get_b2sum_by_inode(int $inode): ?string
    {
        $stm = $this->db->prepare('SELECT hash_blake2b512_160 FROM inodes WHERE id = ?');
        $stm->execute(array(
            $inode
        ));
        $res = $stm->fetch(\PDO::FETCH_NUM);
        if (! $res) {
            return null;
        }
        $ret = $res[0];
        assert(strlen($ret) === 20);
        return $ret;
    }

    /**
     * add new file to database
     *
     * @param Add_file_argument $f
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return int file id
     */
    public function add_file(Add_file_argument &$f): int
    {
        if (empty($f->inode)) {
            if (empty($f->file_path)) {
                throw new \InvalidArgumentException('file_path cannot be empty!');
            }
            if (! is_readable($f->file_path)) {
                throw new \InvalidArgumentException('file_path does not point to a readable file!');
            }
        }
        if (empty($f->name)) {
            throw new \InvalidArgumentException('name cannot be empty!');
        }
        if (! isset($f->owner_id)) {
            throw new \InvalidArgumentException('owner_id cannot be empty! (i guess you can set it to 0 for anonymous? idk honestly)');
        }
        if (empty($f->upload_ip)) {
            throw new \InvalidArgumentException('upload_ip cannot be empty! (set it to 0.0.0.0 if you truly dont know)');
        }
        if (empty($f->upload_time)) {
            $f->upload_time = time();
        }
        if (empty($f->b2sum_160_raw)) {
            if (empty($f->inode)) {
                $f->b2sum_160_raw = B2sum::hash_file($f->file_path, true, 20);
            } else {
                $f->b2sum_160_raw = $this->get_b2sum_by_inode($f->inode);
            }
        }
        assert(strlen($f->b2sum_160_raw) === 20);
        if (empty($f->inode)) {
            $f->inode = $this->get_inode_by_b2sum($f->b2sum_160_raw, false);
            if (null === $f->inode) {
                // id bigint(20) unsigned
                // hash_blake2b512_160 binary(20)
                // size bigint(20)
                // compressed_size bigint(20)
                // compression_type tinyint(4)
                // create_time timestamp
                $this->db->beginTransaction();
                $stm = $this->db->prepare("INSERT INTO `inodes` (`hash_blake2b512_160`,`size`,`compression_type`) VALUES(:hash_blake2b512_160,:size,0);");
                $stm->execute(array(
                    ':hash_blake2b512_160' => $f->b2sum_160_raw,
                    ':size' => filesize($f->file_path)
                ));
                $f->inode = (int) $this->db->lastInsertId();
                assert(is_int($f->inode) && $f->inode > 0);
                $inode_file_path = config()->inode_folder . ((string) $f->inode);
                if ($f->must_copy) {
                    if (! copy($f->file_path, $inode_file_path)) {
                        $last_error = error_get_last();
                        $this->db->rollBack();
                        throw new \RuntimeException('failed to copy file_path to inode folder! (filesize bytes: ' . filesize($f->file_path) . ' - free disk space bytes: ' . disk_free_space(config()->inode_folder) . ' - last_error: ' . print_r($last_error, true) . ')');
                    }
                } else {
                    if (! rename($f->file_path, $inode_file_path)) {
                        $last_error = error_get_last();
                        $this->db->rollBack();
                        throw new \RuntimeException('failed to move file_path to inode folder! (filesize bytes: ' . filesize($f->file_path) . ' - free disk space bytes: ' . disk_free_space(config()->inode_folder) . ' - last error: ' . print_r($last_error, true) . ')');
                    }
                }
                $this->db->commit();
                $f->file_path = $inode_file_path;
                unset($stm, $inode_file_path);
            }
        }
        assert(is_int($f->inode) && $f->inode > 0);
        // id bigint(20) unsigned
        // inode_id bigint(20) unsigned
        // owner_id int(11)
        // name varchar(200)
        // upload_time timestamp
        // upload_ip varbinary(16)
        $stm = $this->db->prepare('INSERT INTO files (inode_id,owner_id,name,upload_time,upload_ip) VALUES (:inode_id,:owner_id,:name,:upload_time,:upload_ip)');
        $stm->execute(array(
            ':inode_id' => $f->inode,
            ':owner_id' => $f->owner_id,
            ':name' => $f->name,
            ':upload_time' => $f->upload_time,
            ':upload_ip' => inet_pton($f->upload_ip)
        ));
        return ((int) $this->db->lastInsertId());
    }
}

