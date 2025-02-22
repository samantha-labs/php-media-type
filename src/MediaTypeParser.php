<?php

namespace Neoncitylights\MediaType;

use Wikimedia\Assert\AssertionException;
use Wikimedia\Assert\InvariantException;

/**
 * @see https://mimesniff.spec.whatwg.org/#parsing-a-mime-type
 */
final class MediaTypeParser {
	public function parseOrNull( string|null $s ): MediaType|null {
		try {
			return $this->parseOrThrow( $s );
		} catch ( MediaTypeParserException | AssertionException ) {
			return null;
		}
	}

	/**
	 * @deprecated Deprecated in `v3.1.0`. Call `parseOrThrow()` instead.
	 * @throws MediaTypeParserException|InvariantException
	 */
	public function parse( string|null $s ): MediaType {
		return $this->parseOrThrow( $s );
	}

	/**
	 * @throws MediaTypeParserException|InvariantException
	 */
	public function parseOrThrow( string|null $s ): MediaType {
		if ( $s === null ) {
			throw new MediaTypeParserException();
		}

		$normalized = Utf8Utils::trimHttpWhitespace( $s );
		if ( $normalized === '' ) {
			throw new MediaTypeParserException();
		}

		$length = \strlen( $normalized );
		$position = 0;

		$type = $this->collectType( $normalized, $length, $position );
		$subType = $this->collectSubType( $normalized, $position );
		$parameters = $this->collectParameters( $normalized, $length, $position );

		return new MediaType( $type, $subType, $parameters );
	}

	/**
	 * @throws MediaTypeParserException
	 */
	private function collectType( string $s, int $length, int &$position ): string {
		$type = Utf8Utils::collectCodepoints(
			$s, $position,
			static fn ( string $c ) => $c !== Token::Slash->value
		);

		if ( $type === '' ) {
			throw new MediaTypeParserException( "type: is empty" );
		}

		$onlyContainsHttpCodepoints = Utf8Utils::onlyContains(
			$type, static fn ( string $c ) => Utf8Utils::isHttpTokenCodepoint( $c ) );
		if ( !$onlyContainsHttpCodepoints ) {
			throw new MediaTypeParserException( "type: should only contain HTTP codepoints" );
		}

		if ( $position > $length ) {
			throw new MediaTypeParserException( "type: position > length" );
		}

		$position++;

		return \strtolower( $type );
	}

	/**
	 * @throws MediaTypeParserException
	 */
	private function collectSubType( string $s, int &$position ): string {
		$subType = Utf8Utils::collectCodepoints(
			$s, $position,
			static fn ( string $c ) => $c !== Token::Semicolon->value
		);
		$subType = Utf8Utils::trimHttpWhitespace( $subType );

		if ( $subType === '' ) {
			throw new MediaTypeParserException( "subtype: is empty" );
		}

		$onlyContainsHttpCodepoints = Utf8Utils::onlyContains(
			$subType, static fn ( string $c ) => Utf8Utils::isHttpTokenCodepoint( $c ) );
		if ( !$onlyContainsHttpCodepoints ) {
			throw new MediaTypeParserException( "subtype: should only contain HTTP codepoints" );
		}

		return \strtolower( $subType );
	}

	/**
	 * @throws InvariantException
	 */
	private function collectParameters( string $s, int $length, int &$position ): array {
		$parameters = [];
		while ( $position < $length ) {
			$position++;

			// skip whitespace
			Utf8Utils::collectCodepoints(
				$s, $position,
				static fn ( string $c ) => Utf8Utils::isHttpWhitespace( $c )
			);

			// collect parameter name
			$parameterName = Utf8Utils::collectCodepoints(
				$s, $position,
				static fn ( string $c ) => $c !== Token::Semicolon->value && $c !== Token::Equal->value
			);
			$parameterName = \strtolower( $parameterName );

			// skip parameter delimiters
			if ( $position < $length ) {
				if ( $s[$position] === Token::Semicolon->value ) {
					continue;
				}
				$position++;
			}
			if ( $position > $length ) {
				break;
			}

			// collect parameter value
			$parameterValue = null;
			if ( $s[$position] === '"' ) {
				$parameterValue = Utf8Utils::collectHttpQuotedString( $s, $position, true );
				Utf8Utils::collectCodepoints( $s, $position,
					static fn ( string $c ) => $c !== Token::Semicolon->value );
			} else {
				$parameterValue = Utf8Utils::collectCodepoints( $s, $position,
					static fn ( string $c ) => $c !== Token::Semicolon->value );
				$parameterValue = Utf8Utils::trimHttpWhitespace( $parameterValue );

				if ( $parameterValue === '' ) {
					continue;
				}
			}

			// check that parameter name and parameter values are valid
			if (
				$parameterName !== ''
				&& Utf8Utils::onlyContains( $parameterName,
					static fn ( string $c ) => Utf8Utils::isHttpTokenCodepoint( $c ) )
				&& Utf8Utils::onlyContains( $parameterValue,
					static fn ( string $c ) => Utf8Utils::isHttpQuotedStringTokenCodepoint( $c ) )
				&& !\array_key_exists( $parameterName, $parameters )
			) {
				$parameters[$parameterName] = $parameterValue;
			}
		}

		return $parameters;
	}
}
