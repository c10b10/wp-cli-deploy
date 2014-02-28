Current version: 1.0.0

Deploys the local WordPress database or uploads directory.

The tool requires defining a set of constants in your wp-config.php file.
The constants should be prefixed with the environment handle which you will use as the first paramater for your desired subcommand. An example configuration for a "dev" environment:

```php
<?php
define( 'DEV_URL', 'the-remote-website-url.com' );
define( 'DEV_WP_PATH', '/path/to/the/wp/dir/on/the/server' );
define( 'DEV_HOST', 'ssh_hosr' );
define( 'DEV_USER', 'ssh_user' );
define( 'DEV_PATH', '/path/to/a/writable/dir/on/the/server' );
define( 'DEV_UPLOADS_PATH', '/path/to/the/remote/uploads/directory' );
define( 'DEV_DB_HOST', 'the_remote_db_host' );
define( 'DEV_DB_NAME', 'the_remote_db_name' );
define( 'DEV_DB_USER', 'the_remote_db_user' );
define( 'DEV_DB_PASSWORD', 'the_remote_db_password' );
define( 'DEV_POST_HOOK', 'echo "something to be executed when the command
finishes"' );
```

=> `wp deploy push dev ...`

Not all commands / subcommands require all constants to be defined. To test what
a subcommand requires, execute it with a non-existing environment handle. e.g.
`wp deploy dump johndoe`.

You can define as many constant groups as deployment eviroments you wish to have.

## EXAMPLES

    # Deploy the local db to the staging environment
    wp deploy push staging --what=db

    # Pull both the production database and uploads
    wp deploy pull production --what=db && wp deploy pull production --what=uploads

    # Dump the local db with the siteurl replaced
    wp deploy dump andrew

## CONSTANTS

### `%%ENV%%_POST_HOOK`

You can optionally define a constant with bash code which is called at the
end of the subcommand execution.

You can use placeholders with the deploy environment variables. Some of the
list of environment variables is:
* `env`: The environment handle
* `command`: The subcommand (Currently `push`, `pull`, or `dump`).
* `what`: The what argument value for the `push` or `pull` subcommand.
* `wd`: The path to the working directory for the deploy command. This is
the directory where the database is pulled, and other temporary files are
created.
* `timestamp`: The date formatted with "Y_m_d-H_i"
* `tmp_path`: The path to the temporary files directory used by the deploy
tool.
* `bk_path`: The path to the backups directory used by the deploy tool.
* `local_uploads`: The path to the local WordPress instance uploads
directory.
* `ssh`: The ssh server handle in the `user@host` format.


#### Usage Example

Here's an example of a `DEV_POST_HOOK` that posts a message to a hipchat
room after a `pull` or a `push` is performed using the HipChat REST API
(https://github.com/hipchat/hipchat-cli).
For pushes, it also clears the cache.

```php
<?php
$hipchat_message = "http://%%url%%"
	. "\njeandoe has successfully %%command%%ed %%what%%";
$command = "if [[ '%%command%%' != 'dump' ]]; then "
		. "echo '$hipchat_message' | %%abspath%%/hipchat-cli/hipchat_room_message -t 1245678 -r 123456 -f 'WP-Cli Deploy';"
	. "fi;"
	. "if [[ '%%command%%' == 'push' ]]; then "
		. "curl -Ss http://example.com/clear_cache.php?token=12385328523;"
	. "fi;";
define( 'DEV_POST_HOOK', $command );
```

### Dependencies

Some subcommands depend on certain constants being defined in order to work.
Here's the dependency list:

* `wp deploy push`: In order to push to your server, you need to define the
ssh credentials, and a path to a writable directory on the server. _These
constants are needed whatever the arguments passed to the `push` subcommand._
 	* `%%ENV%%_USER`
 	* `%%ENV%%_HOST`
 	* `%%ENV%%_PATH`
 * `wp deploy push %%env%% --what=db`: In order to deploy the database to your
 server, you need to define the url of your WordPress website, the path to
 the WordPress code on your server, and the credentials to the database on
 the server:
 	* `%%ENV%%_URL`
 	* `%%ENV%%_WP_PATH`
 	* `%%ENV%%_DB_HOST`
 	* `%%ENV%%_DB_NAME`
 	* `%%ENV%%_DB_USER`
 	* `%%ENV%%_DB_PASSWORD`
 * `wp deploy push %%env%% --what=uploads`: In order to push the uploads directory,
 you need to define the path to the uploads directory on your server:
 	* `%%ENV%%_UPLOADS_PATH`
* `wp deploy pull`: In order to pull to your server, you need to define the
ssh credentials constants. _These constants are needed whatever the arguments
passed to the `pull` subcommand._
 	* `%%ENV%%_USER`
 	* `%%ENV%%_HOST`
 * `wp deploy pull %%env%% --what=db`: In order to pull the database to from your
 server, you need to define the url of your remote WordPress website, the
 path to the WordPress code on your server, and the credentials to the
 database on the server:
 	* `%%ENV%%_PATH`
 	* `%%ENV%%_URL`
 	* `%%ENV%%_WP_PATH`
 	* `%%ENV%%_DB_HOST`
 	* `%%ENV%%_DB_NAME`
 	* `%%ENV%%_DB_USER`
 	* `%%ENV%%_DB_PASSWORD`
 * `wp deploy push %%env%% --what=uploads`: As in the `push` command's case, in
 order to pull the remote server uploads, we need their path on the server.
 	* `%%ENV%%_UPLOADS_PATH`
 * `wp dump %%env%%`: This subcommand only requires the path to the target
 WordPress path and its URL.

#### Credits

https://github.com/demental/wp-deploy-flow for inspiration.
