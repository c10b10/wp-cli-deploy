<?php
require 'helpers.php';
require 'runner.php';
use \WP_Deploy_Command\Helpers as Util;
use \WP_Deploy_Command\Command_Runner as Runner;

/**
 * Deploys the local WordPress database or uploads directory.
 *
 * The tool requires defining a set of constants in your wp-config.php file.
 * The constants should be prefixed with the environment handle which you will use as the first paramater for your desired subcommand. An example configuration for a "dev" environment:
 *
 * define( 'DEV_URL', 'the-remote-website-url.com' );
 * define( 'DEV_WP_PATH', '/path/to/the/wp/dir/on/the/server' );
 * define( 'DEV_HOST', 'ssh_hosr' );
 * define( 'DEV_USER', 'ssh_user' );
 * define( 'DEV_PATH', '/path/to/a/writable/dir/on/the/server' );
 * define( 'DEV_UPLOADS_PATH', '/path/to/the/remote/uploads/directory' );
 * define( 'DEV_DB_HOST', 'the_remote_db_host' );
 * define( 'DEV_DB_NAME', 'the_remote_db_name' );
 * define( 'DEV_DB_USER', 'the_remote_db_user' );
 * define( 'DEV_DB_PASSWORD', 'the_remote_db_passoword' );
 *
 * => wp deploy push dev ...
 *
 * You can define as many constant groups as deployment eviroments you wish to have.
 *
 * TODO: Explain subcommands <-> constants dependency
 *
 * ## EXAMPLES
 *
 *     # Deploy the local db to the staging environment
 *     wp deploy push staging --what=db
 *
 *     # Pull both the production database and uploads
 *     wp deploy pull production --what=db,uploads
 *
 *     # Dump the local db with the siteurl replaced
 *     wp deploy dump andrew
 */
class WP_Deploy_Command extends WP_CLI_Command {

    /**
     * TODO:
     * Post push
	 * Update paths in messages to be relative to wordpress dir.
     */

	/** The config holder. */
	private static $config;

	private static $env;

	private static $default_verbosity;

	private static $runner;

	private static $config_dependencies;

	public function __construct() {
        if ( 1 || defined( 'WP_DEPLOY_DEBUG' ) && WP_DEPLOY_DEBUG ) {
            ini_set( 'display_errors', 'STDERR' );
        }

		self::$default_verbosity = 1;

		/** Define the constants dependencies. */
        self::$config_dependencies = array(
            'push' => array(
                'global' => array(
                    'user',
                    'host',
                    'path',
                ),
                'db' => array(
                    'url',
                    'wp_path',
                    'db_host',
                    'db_name',
                    'db_user',
                    'db_password',
                ),
                'uploads' => array( 'uploads_path' )
            ),
            'pull' => array(
                'global' => array(
                    'user',
                    'host',
                ),
                'db' => array(
                    'path',
                    'url',
                    'wp_path',
                    'db_host',
                    'db_name',
                    'db_user',
                    'db_password',
                ),
                'uploads' => array( 'uploads_path' )
            ),
            'dump' => array(
                'wp_path',
                'url'
            ),
        );

		/**
		 * Depending paths need to be under the
		 * paths they depend on.
		 */
		self::$config = array(
            'env' => '%%env%%',

            /** Constants which refer to remote. */
            'host' => '%%host%%',
            'user' => '%%user%%',
            'path' => '%%path%%',
            'url' => '%%url%%',
            'wp' => '%%wp_path%%',
            'uploads' => '%%uploads_path%%',
            'db_host' => '%%db_host%%',
            'db_name' => '%%db_name%%',
            'db_user' => '%%db_user%%',
            'db_password' => '%%db_password%%',
            /** TODO Safe mode for all commands */

            /** Helpers which refer to local. */
            'abspath' => '%%abspath%%',
			'wd' => '%%abspath%%/%%env%%_%%hash%%',
            'timestamp' => '%%pretty_date%%',
			'tmp_path' => '%%wd%%/tmp',
			'bk_path' => '%%wd%%/bk',
			'tmp' => '%%tmp_path%%/%%rand%%',
            'local_hostname' => '%%hostname%%',
			'ssh' => '%%user%%@%%host%%',
            'local_uploads' => '%%local_uploads%%',
            'siteurl' => '%%siteurl%%',
		);

	}

	/**
	 * Pushes the local database and / or uploads from local to remote.
	 *
	 * ## OPTIONS
	 *
	 * <environment>
     * : The handle of of the environment. This is the prefix of the constants
     * defined in wp-config.
	 *
	 * `--what`=<what>
     * : What needs to be deployed on the server. Suports multiple comma
     * sepparated values.Valid options are:
     *      db: pushes the database to the remote server
	 *      uploads: pushes the uploads to the remote server
	 *
	 * [`--v`=<v>]
	 * : Verbosity level. Default 1. 0 is highest and 2 is lowest.
	 *
	 * ## EXAMPLE
	 *
	 *    # Push the database and the uploads for to "staging" environment.
	 *    # You must have STAGING_* constants defined for this to work.
	 *
	 *    wp deploy push staging --what=db,uploads
	 *
	 * @synopsis <environment> --what=<what> [--v=<v>]
	 */
	public function push( $args, $assoc_args ) {

		$args = self::sanitize_args( __FUNCTION__, $args, $assoc_args );

        if ( ! $args ) {
            WP_Cli::line( 'Nothing happened.' );
			return false;
        }

		/**
		 * 'what' accepts comma separated values.
		 */
        $class = __CLASS__;
        array_map( function( $item ) use ( $class ) {
            call_user_func( "$class::push_$item" );
        }, explode( ',', $assoc_args['what'] ) );
		$what = explode( ',', $assoc_args['what'] );

		self::wow();
	}

	/**
	 * Pulls the database and / or uploads from remote to local. After pulling
	 * the uploads, they need to copied to the correct location.
	 *
	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.
	 *
	 * `--what`=<what>:
	 * : What needs to be pulled. Suports multiple comma sepparated values. This
	 * determines the order of execution for deployments. Valid options are: 'db'
	 * (pulls the databse with the url and paths replaced) and 'uploads' (pulls
	 * the uploads folder).
	 *
	 * `--backup`=<backup>
	 * : Optional. Wether the local db should be backup up beofore importing
	 * the new db. Defaults to true.
	 *
	 * [`--v`=<verbosity>]
	 * : Verbosity level. Default 1. 0 is highest and 2 is lowest.
	 *
	 * ## EXAMPLES
	 *
	 *    # Pulls database and uploads folder
	 *    wp deploy pull staging --what=db,uploads
	 *
	 *    # Pull the remote db without prior local backup
	 *    wp deploy pull staging --what=db --backup=false
	 *
	 * @synopsis <environment> --what=<what> [--cleanup] [--backup=<backup>] [--v=<v>]
	 */
	public function pull( $args, $assoc_args ) {

		$args = self::sanitize_args( __FUNCTION__, $args, $assoc_args );

        if ( ! $args ) {
            WP_Cli::line( 'Nothing happened.' );
			return false;
        }

		/**
		 * 'what' accepts comma separated values.
		 */
        $class = __CLASS__;
        array_map( function( $item ) use ( $class ) {
            call_user_func( "$class::pull_$item" );
        }, explode( ',', $assoc_args['what'] ) );
		$what = explode( ',', $assoc_args['what'] );

		self::wow();
	}

	/**
	 * Dumps the local database and / or uploads from local to remote. The
	 * database will be prepared for upload to the specified environment.
	 *
	 * ## OPTIONS
	 *
	 * <environment>
     * : The name of the environment. This is the prefix of the constants
     * defined in wp-config.php.
	 *
	 * [`--what`=<what>]
	 * : What needs to be dumped. Currently, only the "db" option is supported.

	 * [`--v`=<v>]
	 * : Verbosity level. Default 1. 0 is highest and 2 is lowest.
	 *
	 * ## EXAMPLE
	 *
     *    # Dumps database for to "staging" environment.
     *    wp deploy dump staging --what=db
	 *
	 * @synopsis <environment> [--what=<what>] [--file=<file>] [--v=<v>]
	 */
	public function dump( $args, $assoc_args ) {

		$args = self::sanitize_args( __FUNCTION__, $args, $assoc_args );

        if ( ! $args ) {
            WP_Cli::line( 'Nothing happened.' );
			return false;
        }

        self::dump_db();

		self::wow();
	}


    /** Pushes the database to the server. */
	private function push_db() {

        $c = self::$config;

        $dump_file = self::dump_db( array( 'wd' => $c->tmp_path ) );
        $server_file = "{$c->local_hostname}_{$c->env}.sql";

		$runner = self::$runner;

		$runner->add(
			Util::get_rsync(
				$dump_file,
				"$c->ssh:$c->path/$server_file"
			),
			'Uploaded the database to the server.',
            'Failed to upload the database to the server'
		);

        /** Removing the dump file after upload. */
		$runner->add( "rm -f $dump_file" );

		$runner->add(
            "ssh $c->ssh 'cd $c->path;"
            . " mysql --user=$c->db_user --password=$c->db_password --host=$c->db_host"
            . " $c->db_name < $server_file'",
			'Deployed the database on server.',
			'Failed deploying the db to server.'
		);

		$runner->run();
	}

    /** Pushes the uploads to the server. */
	private function push_uploads( $args = array() ) {

        $c = self::$config;

		$runner = self::$runner;

        /** TODO safe mode */
        $path = isset( $c->safe_mode ) ? $c->path : $c->uploads;

		$runner->add(
			Util::get_rsync(
                // When pushing safe, we push the dir, hence no trailing slash
                "$c->local_uploads/",
				"$c->ssh:$path"
			),
			"Synced local uploads to '$path' on '$c->host'.",
            'Failed to upload the database to the server'
		);

        $runner->run();
	}

    /** Pulls the database from the server. */
    private function pull_db() {

        $c = self::$config;

        $server_file = "{$c->env}_{$c->timestamp}.sql";

		$runner = self::$runner;

		$runner->add(
            "ssh $c->ssh 'mkdir -p $c->path; cd $c->path;"
            . " mysqldump --user=$c->db_user --password=$c->db_password --host=$c->db_host"
            . " --add-drop-table $c->db_name > $server_file'",
			"Dumped the remote database to '$c->path/$server_file' on the server.",
			'Failed dumping the remote database.'
		);

		$runner->add(
			Util::get_rsync(
				"$c->ssh:$c->path/$server_file",
				"$c->wd/$server_file",
                false, false // No delete or compression
			),
			"Copied the database from the server to '$c->wd/$server_file'."
		);

        $runner->add(
            "ssh $c->ssh 'cd $c->path; rm -f $server_file'",
            'Deleted the server dump.'
        );

        /** TODO Finalize safe mode. */
        $runner->add(
            ! isset( $c->safe_mode ),
            "wp db export $c->bk_path/$c->timestamp.sql",
            "Backed up local database to '$c->bk_path/$c->timestamp.sql'"
        );

        $runner->add(
            "wp db import $c->wd/$server_file",
            'Imported the remote database.'
        );

        $runner->add(
            ( $c->siteurl != $c->url ),
            "wp search-replace --network $c->url $c->siteurl",
            "Replaced '$c->url' with '$c->siteurl' on the imported database."
        );

		$runner->add(
			( $c->abspath != $c->wp ),
			"wp search-replace --network $c->wp $c->abspath",
			"Replaced '$c->wp' with '$c->abspath' on local database."
		);

        $runner->run();
    }

    /** Pulls the uploads from the server. */
    private static function pull_uploads() {

        $c = self::$config;

		$runner = self::$runner;

        /** TODO Finalize safe mode. */
        $runner->add(
            isset( $c->safe_mode ),
            "cp -rf $c->local_uploads $c->bk_path/uploads_$c->timestamp",
            'Backed up local uploads.'
        );

		$runner->add(
			Util::get_rsync(
				"$c->ssh:$c->uploads/",
				"$c->local_uploads"
			),
			"Pulled the $c->env uploads locally."
		);

        $runner->run();
    }

    /** Dumps the local database after performing search-replace. */
	private static function dump_db( $args = array() ) {

        $c = self::$config;

        $args = wp_parse_args( $args, array(
            'name' => "{$c->env}_{$c->timestamp}",
            'wd' => $c->wd
        ) );
        $path = "{$args['wd']}/{$args['name']}.sql";

		$runner = self::$runner;

		$runner->add(
			( $c->abspath != $c->wp ) || ( $c->url != $c->siteurl ),
			"wp db export $c->tmp",
			'Exported local backup.'
		);

		$runner->add(
			( $c->siteurl != $c->url ),
			"wp search-replace --network $c->siteurl $c->url",
			"Replaced $c->siteurl with $c->url on local database."
		);

		$runner->add(
			( $c->abspath != $c->wp ),
			"wp search-replace --network $c->abspath $c->wp",
			"Replaced $c->abspath with with $c->wp on local db."
		);

		$runner->add(
			"wp db export $path",
			"Dumped the database to $path"
		);

		$runner->add(
			( $c->abspath != $c->wp ) || ( $c->url != $c->siteurl ),
			"wp db import $c->tmp",
			'Imported local backup.'
		);

		$runner->add(
			( $c->abspath != $c->wp ) || ( $c->url != $c->siteurl ),
			"rm -f $c->tmp"
		);

		$runner->run();

        return $path;
	}

    /** Sanitizes the arguments, and sets the configuration. */
	private static function sanitize_args( $command, $args, $assoc_args = null ) {

		self::$env = $args[0];

        /** If what is available, it needs to refer to an existing method. */
        if ( isset( $assoc_args['what'] ) ) {
            foreach( explode( ',', $assoc_args['what'] ) as $item ) {
                if ( ! method_exists( __CLASS__, "{$command}_$item" ) ) {
                    WP_Cli::error( "Using unknown '$item' parameter for --what argument." );
                }
            }
        }

		/**
		 * Eeeek! So ugly.
		 * TODO. Fix this.
		 */
		$verbosity = self::$default_verbosity;
		if ( isset( $assoc_args['v'] ) && in_array( $assoc_args['v'], range( 0, 2 ) ) )
			$verbosity = $assoc_args['v'];
		self::$runner = new Runner( $verbosity );

        /** Get the environmental and set the tool config. */
        $subcommand = in_array( $command, array( 'push', 'pull' ) ) ? $assoc_args['what'] : '';
        $constants = self::validate_config( $command, $subcommand, self::$env );
        self::$config = self::expand( self::$config, $constants );

        /** Create paths. */
        Runner::get_result( 'mkdir -p ' . self::$config->tmp_path . ';' );
        Runner::get_result( 'mkdir -p ' . self::$config->bk_path . ';' );

        return $args;
	}

	/** Determines the verbosity level: 1, 2, or 3 */
	private static function get_verbosity( $string, $default ) {
		$number = count_chars_unicode( $string, 'v' );
		if ( $number )
			return min( $number, 2 );
		return $default;
	}

	/**
	 * Verifies that all required constants are defined.
	 * Constants must be of the form: "%ENV%_%NAME%"
	 */
	private static function validate_config( $command, $subcommand, $env ) {

        /** Get the required contstants from the dependency array. */
        $required = self::$config_dependencies[$command];
        if ( $subcommand ) {
            $required = array_unique( array_merge(
                $required[$subcommand],
                $required['global']
            ) );
        }

		$errors = array();
		$constants = array();
		foreach ( $required as $const ) {
			/** The constants template */
			$required_constant = strtoupper( $env . '_' . $const );
			if ( ! defined( $required_constant ) ) {
				$errors[] = "Required constant $required_constant is not defined.";
			} else {
				$constants[$const] = constant( $required_constant );
			}
		}

		if ( count( $errors ) ) {
			foreach ( $errors as $error ) {
				WP_Cli::line( "$error" );
			}
			WP_Cli::error( "The missing constants are required in order to run this subcommand.\nType `wp help deploy` for more information." );
		}

        return $constants;
	}

	/** Replaces the placeholders in the paths with actual data. */
	private static function expand( $config, $constants ) {

		$data = array(
			'env' => self::$env,
			'hash' => Util::get_hash(),
			'abspath' => untrailingslashit( ABSPATH ),
			'pretty_date' => date( 'Y_m_d-H_i' ),
			'rand' => substr( sha1( time() ), 0, 8 ),
			'hostname' => Runner::get_result( "hostname" ),
            'local_uploads' => call_user_func( function() {
                $uploads_dir = wp_upload_dir();
                return untrailingslashit( Runner::get_result(
                    "cd {$uploads_dir['basedir']}; pwd -P;"
                ) );
            } ),
            'siteurl' => untrailingslashit( Util::trim_url(
                get_option( 'siteurl' )
            ) ),
		);

		foreach ( $config as &$item ) {
			$item = Util::unplaceholdit( $item, $data + array(
				/** This ensures that we can have dependencies. */
				'wd' => $config['wd'],
				'tmp_path' => $config['tmp_path'],
                'object' => (object) array_map( 'untrailingslashit', $constants ),
			) );
		}

        /** Return the config in object form. */
		return (object) $config;
	}

	private static function wow() {
		$doge = array( 'wow', 'many', 'such', 'so' );
		$words = array( 'finish', 'done', 'end', 'deploy' );
		WP_Cli::line( "\n" );
		WP_Cli::success(
			$doge[array_rand( $doge, 1 )] . ' ' .
			$words[array_rand( $words, 1 )] . '!'
		);
	}
}

WP_CLI::add_command( 'deploy', 'WP_Deploy_Command' );
