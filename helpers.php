<?php
namespace WP_Deploy_Command;

class Helpers {

	static function get_rsync( $source, $dest, $port, $delete = true, $compress = true, $user_excludes = false ) {

		$exclude = array(
			'.git',
			'cache',
			'.DS_Store',
			'thumbs.db',
			'.sass-cache'
		);

		$user_excludes = $user_excludes ? explode( ':', (string) $user_excludes ) : array();

		$exclude = array_merge( $exclude, $user_excludes );

		$rsync = self::unplaceholdit(
			/** The command template. */
			'rsync -av%%compress%% -progress -e "ssh -p %%port%%"%%delete%% %%src%% %%dest%% %%exclude%%',
			/** The arguments. */
			array(
				'compress' => ( $compress ? 'z' : '' ),
				'delete' => ( $delete ? ' --delete' : '' ),
				'src' => $source,
				'port' => $port,
				'dest' => $dest,
				'exclude' => '--exclude ' . implode(
					' --exclude ',
					array_map( 'escapeshellarg', $exclude )
				)
			)
		);

		return $rsync;
	}

	static function unplaceholdit( $template, $content, $object_key = 'object' ) {

		/** Early bailout? */
		if ( strpos( $template, '%%' ) === false )
			return $template;

		/** First, get a list of all placeholders. */
		$matches = $replaces = array();
		preg_match_all( '/%%([^%]+)%%/u', $template, $matches, PREG_SET_ORDER );

		$searches = wp_list_pluck( $matches, 0 );

		/* Cast the object */
		$object = array_key_exists( $object_key, $content ) ? (array) $content[$object_key] : false;

		foreach ( $matches as $match ) {
			/**
			 * 0 => %%template_tag%%
			 * 1 => variable_name
			 */
			if( $object && isset( $object[$match[1]] ) )
				array_push( $replaces, $object[$match[1]] );
			else if( isset( $content[$match[1]] ) )
				array_push( $replaces, $content[$match[1]] );
			else
				array_push( $replaces, $match[0] );
		}

		return str_replace( $searches, $replaces, $template );
	}

	static function trim_url( $url, $path = false ) {

		/** In case scheme relative URI is passed, e.g., //www.google.com/ */
		$url = trim( $url, '/' );

		/** If scheme not included, prepend it */
		if ( ! preg_match( '#^http(s)?://#', $url ) ) {
			$url = 'http://' . $url;
		}

		/** Remove www. */
		$url_parts = parse_url( $url );
		$domain = preg_replace( '/^www\./', '', $url_parts['host'] ) . ( ! empty( $url_parts['port'] ) ? ':' . $url_parts['port'] : '' );

		/** Add directory path if needed **/
		if ( $path && $url_parts['path'] )
			$domain .= $url_parts['path'];

		return $domain;
	}

	/** Returns and unique hash to identify the environment. */
	static function get_hash() {
		$siteurl = self::trim_url( get_option( 'siteurl' ) );
		return substr( sha1( DB_NAME . $siteurl ), 0, 8 );
	}

	/**
	 * Create a bar that spans with width of the console
	 *
	 * ## OPTIONS
	 *
	 * [<character>]
	 * : The character(s) to make the bar with. Default =
	 *
	 * [--c=<c>]
	 * : Color for bar. Default %p
	 *
 	 *
	 * ## EXAMPLES
	 *
	 *     wp <command> bar
	 *
	 *     wp <command> bar '-~' --c='%r'
	 *
	 *     wp <command> bar '+-' --c='%r%3'
	 */
	function bar( $args = array(), $assoc_args = array() ) {
		$char = isset( $args[0] ) ? $args[0] : '=';
		$cols = \cli\Shell::columns();
		$line = substr( str_repeat($char, $cols), 0, $cols );

		if ( ! isset( $assoc_args['c'] ) ) {
			$color = '%p'; // https://github.com/jlogsdon/php-cli-tools/blob/master/lib/cli/Colors.php#L113
		} else {
			$color = $assoc_args['c'];
			$color = '%'. trim( $color, '%' );
		}

		WP_CLI::line( WP_CLI::colorize( $color . $line .'%n' ) );
	}
}
