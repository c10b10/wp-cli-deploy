### Help

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
define( 'DEV_DB_PASSWORD', 'the_remote_db_passoword' );
```

=> `wp deploy push dev ...`

You can define as many constant groups as deployment eviroments you wish to have.

TODO: Explain subcommands <-> constants dependency

## EXAMPLES

```sh
    # Deploy the local db to the staging environment
    wp deploy push staging --what=db

    # Pull both the production database and uploads
    wp deploy pull production --what=db,uploads

    # Dump the local db with the siteurl replaced
    wp deploy dump andrew
```

#### Credits

https://github.com/demental/wp-deploy-flow for inspiration.
