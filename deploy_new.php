<?php
require 'helpers.php';
require 'runner.php';
use \WP_Deploy_Command\Helpers as Util;
use \WP_Deploy_Command\Command_Runner as Runner;

class WP_Deploy_Command extends WP_CLI_Command {

	/** The config holder. */
	private static $config;

	private static $config_dependencies;

	private static $env;

	public function __construct() {
		ini_set( 'display_errors', 'STDERR' );

		/**
		 * Depending paths need to be under the
		 * paths they depend on.
		 */

		/** Define the constants dependencies. */
		self::$config_dependencies = array(
			'global' => array(
			),
			'commands' => array(
				'push' => array(
					'url',
					'wp_path',
                    // DEV
                    'host',
                    'user',
                    'path',
                    'url',
                    'wp_path',
                    'uploads_path',
                    'db_host',
                    'db_name',
                    'db_user',
                    'db_password',
				),
				'pull' => array(
				),
				'dump' => array(
					'wp_path',
					'url'
				),
			)
		);


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

            /** Helpers which refer to local. */
            'abspath' => '%%abspath%%',
			'wd' => '%%abspath%%/%%env%%_%%hash%%',
            'timestamp' => '%%pretty_date%%',
			'tmp_path' => '%%wd%%/tmp',
			'tmp' => '%%tmp_path%%/%%rand%%',
            'local_hostname' => '%%hostname%%',
			'ssh' => '%%user%%@%%host%%',
            'local_uploads' => '%%local_uploads%%',
            'siteurl' => '%%siteurl%%',
		);

	}

	private static function sanitize_args( $command, $args, $assoc_args = null ) {

		self::$env = $args[0];

        $constants = self::validate_config( $command, self::$env );

		/** Expand the paths placeholders. */
        self::$config = self::expand( self::$config, $constants );

        /** Create paths. */
        Runner::get_result( 'mkdir -p ' . self::$config->tmp_path . ';' );

        return $args;
	}


	/** Replace the placeholders in the paths with actual data. */
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
                return trailingslashit( Runner::get_result(
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
                'object' => (object) $constants,
			) );
		}

        /** Make it an object. */
		return (object) $config;
	}

	/**
	 * Verifies that all required constants are defined.
	 * Constants must be of the form: "%ENV%_%NAME%"
	 */
	private static function validate_config( $command, $env ) {

		$required_constants = array_unique( array_merge(
			self::$config_dependencies['commands'][$command],
			self::$config_dependencies['global']
		) );

		$errors = array();
		$constants = array();
		foreach ( $required_constants as $const ) {
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
			WP_Cli::error( 'The missing contants are required in order to run this subcommand.' );
		}

        return $constants;
	}

	/**
	 * Pushes the local database and / or uploads from local to remote.
	 *
	 * ## OPTIONS
	 *
	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.
	 *
	 * `--what`=<what>
	 * : What needs to be pushed. Suports multiple comma sepparated values. Valid
	 * options are: 'db' (pushes the database to the remote server) and
	 * 'uploads' (pushes the uploads to the remote server).
	 *
	 * ## EXAMPLE
	 *
	 *    # Push the database and the uploads for to "staging" environment.
	 *    # You must have STAGING_* constants defined for this to work.
	 *
	 *    wp deploy push staging --what=db,uploads
	 *
	 * @synopsis <environment> --what=<what> [--safe]
	 */
	public function push( $args, $assoc_args ) {

		$args = self::sanitize_args( __FUNCTION__, $args, $assoc_args );

		if ( ! $args )
			return false;

		/* self::dump_db( 'bk_db.sql' ); */
        /* self::push_db(); */
        self::push_uploads();
	}

	private function push_db() {

        $c = self::$config;

        $dump_file = self::dump_db( array( 'wd' => $c->tmp_path ) );
        $server_file = "{$c->local_hostname}_{$c->env}.sql";

		$runner = new Runner();

		$runner->add(
			Util::get_rsync(
				$dump_file,
				"$c->ssh:$c->path/$server_file"
			),
			'Uploading the database to the server.',
            'Failed to upload the database to the server'
		);

        /** Removing the dump file after upload. */
		$runner->add( "rm -f $dump_file" );

		$runner->add(
            "ssh $c->ssh 'cd $c->path;"
            . " mysql --user=$c->db_user --password=$c->db_password --host=$c->db_host"
            . " $c->db_name < $server_file'",
			'Deploying the database on server.',
			'Failed deploying the db to server.'
		);


        var_dump( $runner->commands );
		/* $runner->run(); */
	}

	private function push_uploads( $args = array() ) {

        $c = self::$config;

		$runner = new Runner();

		$runner->add(
			Util::get_rsync(
				$c->local_uploads,
				"$c->ssh:$c->uploads"
                // if safe, upload to path and do the shit by hand
			),
			'Syncing local uploads to the server.',
            'Failed to upload the database to the server'
		);

        var_dump( $runner->commands );

        /* $runner->run(); */

		WP_CLI::success( "Deployed the uploads to server." );
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
	 * : What needs to be pull. Suports multiple comma sepparated values. This
	 * determines the order of execution for deployments. Valid options are: 'db'
	 * (pulls the databse with the url and paths replaced) and 'uploads' (pulls
	 * the uploads folder).
	 *
	 * `--backup`=<backup>
	 * : Optional. Wether the local db should be backup up beofore importing
	 * the new db. Defaults to true.
	 *
	 * ## EXAMPLES
	 *
	 *    # Pulls database and uploads folder
	 *    wp deploy pull staging --what=db,uploads
	 *
	 *    # Pull the remote db without prior local backup
	 *    wp deploy pull staging --what=db --backup=false
	 *
	 * @synopsis <environment> --what=<what> [--cleanup] [--backup=<backup>]
	 */
	public function pull( $args, $assoc_args ) {
        self::pull_db();
        /* self::push_uploads(); */
	}

	/**
	 * Dumps the local database and / or uploads from local to remote. The
	 * database will be prepared for upload to the specified environment.
	 *
	 * ## OPTIONS
	 *
	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.php.
	 *
	 * `--what`=<what>
	 * : What needs to be dumped. Suports multiple comma sepparated values. Valid
	 * options are: 'db' (dumps the databse with the url and paths replaced) and
	 * 'uploads' (creates an archive of the uploads folder in the current directory).
	 *
	 * [`--file`=<file>]
	 * : [REMOVED] Optional. What should the dump be called. Default: '%date_time% _%env%.sql' for 'db', 'uploads.tar.gz' for
	 * 'uploads'.
	 *
	 * ## EXAMPLE
	 *
	 *    # Dumps database for to "staging" environment. You must have STAGING_*
	 *    # constants defined for this to work
	 *    wp deploy dump staging --what=db
	 *
	 * @synopsis <environment> --what=<what> [--file=<file>]
	 */
	public function dump( $args, $assoc_args ) {
	}

	private static function dump_db( $args = array() ) {

        $c = self::$config;

        $args = wp_parse_args( $args, array(
            'name' => "{$c->env}_{$c->timestamp}",
            'wd' => $c->wd
        ) );
        $path = "{$args['wd']}/{$args['name']}.sql";

		$runner = new Runner();

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
}

WP_CLI::add_command( 'deploy', 'WP_Deploy_Command' );
