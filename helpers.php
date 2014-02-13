<?php
namespace WP_Deploy_Command;

class Helpers {

	static function get_rsync( $source, $dest, $delete = true, $compress = true ) {

		$exclude = array(
			'.git',
			'cache',
			'.DS_Store',
			'thumbs.db'
		);

		$rsync = self::unplaceholdit(
			/** The command template. */
			'rsync -av%%compress%% -progress -e ssh%%delete%% %%src%% %%dest%% %%exclude%%',
			/** The arguments. */
			array(
				'compress' => ( $compress ? 'z' : '' ),
				'delete' => ( $delete ? ' --delete' : '' ),
				'src' => $source,
				/** TODO Remove this explicit call */
				'dest' => ( ! is_string( $dest ) ? "$ssh_user@$ssh_host:$ssh_path" : $dest ),
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

	static function trim_url( $url ) {

		/** In case scheme relative URI is passed, e.g., //www.google.com/ */
		$url = trim( $url, '/' );

		/** If scheme not included, prepend it */
		if ( ! preg_match( '#^http(s)?://#', $url ) ) {
			$url = 'http://' . $url;
		}

		/** Remove www. */
		$url_parts = parse_url( $url );
		$domain = preg_replace( '/^www\./', '', $url_parts['host'] );

		return $domain;
	}

	/** Returns and unique hash to identify the environment. */
	static function get_hash() {
		$siteurl = self::trim_url( get_option( 'siteurl' ) );
		return substr( sha1( DB_NAME . $siteurl ), 0, 8 );
	}
}
