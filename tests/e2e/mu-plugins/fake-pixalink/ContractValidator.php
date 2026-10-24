<?php
/**
 * JSON Schema validation against tests/contract/schemas.
 *
 * @package Pixalink\Loyalty\Tests
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Tests\FakeServer;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Thin opis/json-schema wrapper shared by the fake server and the PHPUnit contract suite.
 */
final class ContractValidator {

	public const ID_PREFIX = 'https://schemas.pixalink.io/commerce/v1/';

	/**
	 * Shared validator.
	 *
	 * @var Validator|null
	 */
	private static ?Validator $validator = null;

	/**
	 * Decoded endpoints.json.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $manifest = null;

	/**
	 * Whether opis/json-schema is installed (dev dependencies).
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( Validator::class );
	}

	/**
	 * Absolute path of the schema directory.
	 *
	 * @return string
	 */
	public static function schema_dir(): string {
		if ( defined( 'PIXALINK_LOYALTY_DIR' ) && is_dir( constant( 'PIXALINK_LOYALTY_DIR' ) . 'tests/contract/schemas' ) ) {
			return constant( 'PIXALINK_LOYALTY_DIR' ) . 'tests/contract/schemas';
		}

		return dirname( __DIR__, 3 ) . '/contract/schemas';
	}

	/**
	 * Endpoint manifest.
	 *
	 * @return list<array{method:string,path:string,auth:string,nonce:string|null,request:string|null,response:string,query?:string}>
	 */
	public static function endpoints(): array {
		if ( null === self::$manifest ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
			$decoded        = json_decode( (string) file_get_contents( self::schema_dir() . '/endpoints.json' ), true );
			self::$manifest = is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Endpoint list.
		 *
		 * @var list<array{method:string,path:string,auth:string,nonce:string|null,request:string|null,response:string,query?:string}> $endpoints
		 */
		$endpoints = self::$manifest['endpoints'] ?? array();

		return $endpoints;
	}

	/**
	 * Manifest entry for a method + route template.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route template, e.g. "customers/{id}".
	 * @return array{method:string,path:string,auth:string,nonce:string|null,request:string|null,response:string,query?:string}|null
	 */
	public static function endpoint( string $method, string $route ): ?array {
		foreach ( self::endpoints() as $endpoint ) {
			if ( strtoupper( $method ) === $endpoint['method'] && $route === $endpoint['path'] ) {
				return $endpoint;
			}
		}

		return null;
	}

	/**
	 * Validate data against a schema file.
	 *
	 * @param string $schema_file File name inside the schema directory, e.g. "quote.response.json".
	 * @param mixed  $data        PHP value (arrays/objects); empty objects must be \stdClass.
	 * @return list<string> Violations as "pointer: message"; empty when valid.
	 */
	public static function validate( string $schema_file, $data ): array {
		$result = self::validator()->validate( self::to_json_value( $data ), self::ID_PREFIX . $schema_file );
		if ( $result->isValid() ) {
			return array();
		}

		$error = $result->error();
		if ( null === $error ) {
			return array( 'unknown validation error' );
		}

		$violations = array();
		foreach ( ( new ErrorFormatter() )->format( $error, true ) as $pointer => $messages ) {
			foreach ( (array) $messages as $message ) {
				$violations[] = ( '' === $pointer ? '/' : $pointer ) . ': ' . ( is_string( $message ) ? $message : (string) wp_json_encode( $message ) );
			}
		}

		return $violations;
	}

	/**
	 * Convert PHP arrays to the object/array structure JSON decoding produces.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function to_json_value( $data ) {
		return json_decode( (string) wp_json_encode( $data ) );
	}

	/**
	 * Validator with the schema directory registered under the $id prefix.
	 *
	 * @return Validator
	 */
	private static function validator(): Validator {
		if ( null === self::$validator ) {
			self::$validator = new Validator();
			self::$validator->setMaxErrors( 20 );
			$resolver = self::$validator->resolver();
			if ( null !== $resolver ) {
				$resolver->registerPrefix( self::ID_PREFIX, self::schema_dir() );
			}
		}

		return self::$validator;
	}
}
