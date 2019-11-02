this folder just exists to make nginx redirect v1 to v1/
(instead of v1 giving 404 and v1/ giving the api)

the nginx redirect looks like
location ~ ^/api/v1/ {
	# empty file first because try_files limitation (it requires a minimum of 2 arguments and the last argument is special, and router needs last-argumet magic)
	try_files "" /api/internal_v1/router.php$is_args$args;
}
location ~ ^/api/internal_v1 {
	internal;
	include snippets/fastcgi-php.conf;
	fastcgi_pass unix:/var/run/php/php7.2-fpm.sock;
}
