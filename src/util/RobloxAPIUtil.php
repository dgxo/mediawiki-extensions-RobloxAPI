<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\RobloxAPI\util;

use FormatJson;
use MediaWiki\Config\Config;
use MediaWiki\Extension\RobloxAPI\data\args\ArgumentSpecification;
use MediaWiki\MediaWikiServices;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\Stats\Exceptions\IllegalOperationException;

/**
 * Provides utilities for working with the Roblox API.
 */
class RobloxAPIUtil {

	/**
	 * Checks whether a numeric ID is valid.
	 * @param string|null $string
	 * @return bool
	 */
	public static function isValidId( ?string $string ): bool {
		if ( $string === null ) {
			return false;
		}

		return preg_match( '/^\d{1,16}$/', $string );
	}

	/**
	 * Checks whether multiple numeric IDs are valid.
	 * @param array $strings
	 * @return bool
	 */
	public static function areValidIds( array $strings ): bool {
		foreach ( $strings as $string ) {
			if ( !self::isValidId( $string ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed ...$strings
	 * @return void
	 * @throws RobloxAPIException if any of the IDs are invalid
	 */
	public static function assertValidIds( ...$strings ): void {
		foreach ( $strings as $string ) {
			if ( !self::isValidId( $string ) ) {
				throw new RobloxAPIException( 'robloxapi-error-invalid-id', $string );
			}
		}
	}

	// TODO merge this with assertArgsAllowed

	/**
	 * Asserts that the given args are valid
	 * @param string[] $expectedArgs The expected arg types
	 * @param string[] $args The actual args
	 * @return void
	 * @throws RobloxAPIException if the args are invalid
	 */
	public static function assertValidArgs( array $expectedArgs, array $args ): void {
		foreach ( $args as $index => $arg ) {
			$expectedType = $expectedArgs[$index];
			self::assertValidArg( $expectedType, $arg );
		}
	}

	/**
	 * Asserts that the given arg is valid
	 * @param string $expectedType The expected arg type
	 * @param string $arg The actual arg
	 * @return void
	 * @throws RobloxAPIException if the arg is invalid
	 */
	public static function assertValidArg( string $expectedType, string $arg ) {
		if ( substr( strtolower( $expectedType ), -2 ) === 'id' ) {
			self::assertValidIds( $arg );
		} else {
			switch ( $expectedType ) {
				case 'ThumbnailSize':
					if ( !preg_match( '/^\d{1,3}x\d{1,3}$/', $arg ) ) {
						throw new RobloxAPIException( 'robloxapi-error-invalid-thumbnail-size', $arg );
					}
					break;
				case 'Username':
					if ( !preg_match( '/^(?=^[^_]+_?[^_]+$)\w{3,20}$/', $arg ) ) {
						throw new RobloxAPIException( 'robloxapi-error-invalid-username', $arg );
					}
					break;
				case 'Boolean':
					if ( !in_array( strtolower( $arg ), [ 'true', 'false' ] ) ) {
						throw new RobloxAPIException( 'robloxapi-error-invalid-boolean', $arg );
					}
					break;
				case 'String':
					break;
				case 'ThumbnailFormat':
					if ( !in_array( $arg, [ 'Png', 'Webp' ] ) ) {
						throw new RobloxAPIException( 'robloxapi-error-invalid-thumbnail-format', $arg );
					}
					break;
				case 'ReturnPolicy':
					if ( !in_array( $arg,
						[ 'PlaceHolder', 'ForcePlaceHolder', 'AutoGenerated', 'ForceAutoGenerated' ] ) ) {
						throw new RobloxAPIException( 'robloxapi-error-invalid-return-policy', $arg );
					}
					break;
				default:
					throw new IllegalOperationException( "Unknown expected arg type: $expectedType" );
			}
		}
	}

	/**
	 * Asserts that the given args are allowed
	 * @param Config $config The config object
	 * @param array $expectedArgs The expected arg types
	 * @param array $args The actual args
	 * @throws RobloxAPIException if the args are invalid
	 */
	public static function assertArgsAllowed(
		Config $config, array $expectedArgs, array $args
	) {
		foreach ( $args as $index => $arg ) {
			$expectedType = $expectedArgs[$index];
			self::assertArgAllowed( $config, $expectedType, $arg );
		}
	}

	/**
	 * Asserts that the given arg is allowed
	 * @param Config $config The config object
	 * @param string $expectedType The expected arg type
	 * @param string $arg The actual arg
	 * @throws RobloxAPIException if the arg is invalid
	 */
	public static function assertArgAllowed( Config $config, string $expectedType, string $arg ) {
		$allowedArgs = $config->get( 'RobloxAPIAllowedArguments' ) ?? [];
		if ( !array_key_exists( $expectedType, $allowedArgs ) ) {
			return;
		}
		$allowedValues = $allowedArgs[$expectedType];
		if ( empty( $allowedValues ) ) {
			// all values are allowed
			return;
		}
		if ( !in_array( $arg, $allowedValues ) ) {
			throw new RobloxAPIException( 'robloxapi-error-arg-not-allowed', $arg, $expectedType );
		}
	}

	/**
	 * Verifies that a URL is a Roblox CDN URL
	 * @param string $url The URL to verify
	 * @param UrlUtils|null $urlUtils The URL utils object
	 * @return bool
	 */
	public static function verifyIsRobloxCdnUrl( string $url, ?UrlUtils $urlUtils = null ): bool {
		if ( $urlUtils === null ) {
			$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
		}
		$urlParts = $urlUtils->parse( $url );
		if ( $urlParts === null ) {
			return false;
		}
		if ( isset( $urlParts['port'] ) || isset( $urlParts['query'] ) || isset( $urlParts['fragment'] ) ) {
			return false;
		}
		if ( $urlParts['scheme'] !== 'https' ) {
			return false;
		}
		if ( !preg_match( "/^[a-zA-Z0-9]{2}\.rbxcdn\.com$/", $urlParts['host'] ) ) {
			return false;
		}
		if ( !preg_match( "/[0-9A-Za-z\-\/]*\.(png|webp)?$/", $urlParts['path'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Decides whether a result should be returned as JSON
	 * @param mixed $value The value to check
	 * @return bool
	 */
	public static function shouldReturnJson( $value ): bool {
		return $value instanceof \stdClass || is_array( $value );
	}

	/**
	 * Creates a JSON result
	 * @param mixed $jsonObject The JSON object
	 * @param array $optionalArgs The optional arguments
	 * @return string
	 */
	public static function createJsonResult( $jsonObject, array $optionalArgs ): string {
		$pretty = isset( $optionalArgs['pretty'] ) && strtolower( $optionalArgs['pretty'] ) === 'true';
		// only return the value of json_key in the JSON object
		if ( is_object( $jsonObject ) && !empty( $optionalArgs['json_key'] ) ) {
			$jsonObject = self::getJsonKey( $jsonObject, $optionalArgs['json_key'] );

			if ( !is_object( $jsonObject ) ) {
				return $jsonObject ?? 'null';
			}
		}

		return FormatJson::encode( $jsonObject, $pretty );
	}

	/**
	 * Get a JSON key from a JSON object. This accepts recursively nested keys using '->' as a separator.
	 * @param \stdClass|mixed|null $jsonObject The JSON object
	 * @param string $jsonKey The JSON key
	 * @return \stdClass|mixed|null
	 */
	public static function getJsonKey( $jsonObject, string $jsonKey ) {
		if ( !is_object( $jsonObject ) ) {
			return null;
		}

		// recursion
		if ( str_contains( $jsonKey, '->' ) ) {
			// split only once by ->
			$parts = explode( '->', $jsonKey, 2 );
			$firstPart = $parts[0];
			$secondPart = $parts[1];

			return self::getJsonKey( self::getJsonKey( $jsonObject, $firstPart ), $secondPart );
		}

		if ( !property_exists( $jsonObject, $jsonKey ) ) {
			return null;
		}

		return $jsonObject->{$jsonKey};
	}

	/**
	 * Verifies that the given arguments are valid
	 * @param ArgumentSpecification $argumentSpecification The argument specification
	 * @param string[] $args The arguments
	 * @param Config $config The config object
	 * @return array[]
	 * @throws RobloxAPIException if the arguments are invalid
	 */
	public static function parseArguments( ArgumentSpecification $argumentSpecification, array $args, Config $config
	): array {
		// TODO extract some parts of this method into separate methods
		$requiredArgs = [];
		$optionalArgs = [];

		foreach ( $argumentSpecification->requiredArgs as $type ) {
			if ( count( $args ) === 0 ) {
				throw new RobloxAPIException( 'robloxapi-error-missing-argument', $type );
			}
			$value = array_shift( $args );
			self::assertValidArg( $type, $value );
			self::assertArgAllowed( $config, $type, $value );
			$requiredArgs[] = $value;
		}

		$first = true;

		// optional args are named, e.g. name=value
		foreach ( $args as $string ) {
			$parts = explode( '=', $string, 2 );

			if ( count( $parts ) === 1 ) {
				if ( $first ) {
					// assume that the user intended to provide more required args than actually required
					throw new RobloxAPIException( 'robloxapi-error-too-many-required-args', $parts[0] );
				}
				throw new RobloxAPIException( 'robloxapi-error-missing-optional-argument-value', $parts[0] );
			}

			$key = $parts[0];
			$key = strtolower( $key );
			$value = $parts[1];

			if ( !array_key_exists( $key, $argumentSpecification->optionalArgs ) ) {
				throw new RobloxAPIException( 'robloxapi-error-unknown-optional-argument', $key );
			}

			$type = $argumentSpecification->optionalArgs[$key];
			self::assertValidArg( $type, $value );
			self::assertArgAllowed( $config, $type, $value );

			$optionalArgs[$key] = $value;

			$first = false;
		}

		return [ $requiredArgs, $optionalArgs ];
	}

}
